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

    $versionsBytes = 0;
    try {
        $stmtVersionsUsed = $pdo->prepare('SELECT COALESCE(SUM(file_size), 0) FROM file_versions WHERE user_id = :user_id');
        $stmtVersionsUsed->execute([':user_id' => $userId]);
        $versionsBytes = (int)$stmtVersionsUsed->fetchColumn();
    } catch (Throwable) {
        $versionsBytes = 0;
    }

    $used = (int)($statsRow['total_used'] ?? 0) + $versionsBytes;
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

    // 4. Datei wurde mit dem Benutzer über den Chat (messages.attached_file_id) oder eine Rundnachricht geteilt
    $stmtChatAtt = $pdo->prepare(
        'SELECT id FROM messages
         WHERE attached_file_id = :fid
           AND (recipient_id = :uid1 OR sender_id = :uid2 OR is_broadcast = 1)
         LIMIT 1'
    );
    $stmtChatAtt->execute([
        ':fid'  => $fileId,
        ':uid1' => $userId,
        ':uid2' => $userId,
    ]);
    if ($stmtChatAtt->fetch()) {
        return $file;
    }

    return null;
}

/**
 * Ermittelt die Anzahl ungelesener Chat-Nachrichten (Direktnachrichten + Admin-Rundnachrichten) für einen Benutzer.
 */
function getUnreadMessagesCount(PDO $pdo, int $userId): int
{
    if ($userId <= 0) {
        return 0;
    }

    try {
        $stmtDirect = $pdo->prepare(
            'SELECT COUNT(*) FROM messages WHERE recipient_id = :uid AND is_read = 0'
        );
        $stmtDirect->execute([':uid' => $userId]);
        $unreadDirect = (int)$stmtDirect->fetchColumn();

        $stmtBroadcast = $pdo->prepare(
            'SELECT COUNT(*) FROM messages
             WHERE is_broadcast = 1
               AND sender_id <> :uid1
               AND id > COALESCE((SELECT last_read_broadcast_id FROM message_broadcast_reads WHERE user_id = :uid2), 0)'
        );
        $stmtBroadcast->execute([':uid1' => $userId, ':uid2' => $userId]);
        $unreadBroadcast = (int)$stmtBroadcast->fetchColumn();

        return $unreadDirect + $unreadBroadcast;
    } catch (Throwable) {
        return 0;
    }
}

/**
 * Prüft, ob ein Benutzer Schreibberechtigung (can_write = 1) auf eine freigegebene Datei
 * oder einen freigegebenen Ordner eines anderen Benutzers besitzt.
 */
function canUserWriteSharedFileOrFolder(PDO $pdo, int $userId, int $ownerId, ?int $fileId = null, string $folderPath = ''): bool
{
    if ($userId === $ownerId) {
        return true;
    }

    if ($fileId !== null && $fileId > 0) {
        $stmtFileShare = $pdo->prepare(
            'SELECT id FROM user_shares
             WHERE file_id = :file_id AND shared_with_user_id = :uid AND can_write = 1
             LIMIT 1'
        );
        $stmtFileShare->execute([
            ':file_id' => $fileId,
            ':uid'     => $userId,
        ]);
        if ($stmtFileShare->fetch()) {
            return true;
        }
    }

    if ($folderPath !== '') {
        $stmtFolderShares = $pdo->prepare(
            'SELECT folder_path FROM user_shares
             WHERE owner_id = :owner_id AND shared_with_user_id = :uid AND folder_path IS NOT NULL AND can_write = 1'
        );
        $stmtFolderShares->execute([
            ':owner_id' => $ownerId,
            ':uid'      => $userId,
        ]);
        foreach ($stmtFolderShares->fetchAll(PDO::FETCH_COLUMN) as $sharedFp) {
            $sharedFpStr = (string)$sharedFp;
            if ($sharedFpStr !== '' && ($folderPath === $sharedFpStr || str_starts_with($folderPath, $sharedFpStr . '/'))) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Prüft, ob ein Benutzer Löschberechtigung (can_delete = 1) auf eine freigegebene Datei
 * oder einen freigegebenen Ordner eines anderen Benutzers besitzt.
 */
function canUserDeleteSharedFileOrFolder(PDO $pdo, int $userId, int $ownerId, ?int $fileId = null, string $folderPath = ''): bool
{
    if ($userId === $ownerId) {
        return true;
    }

    if ($fileId !== null && $fileId > 0) {
        $stmtFileShare = $pdo->prepare(
            'SELECT id FROM user_shares
             WHERE file_id = :file_id AND shared_with_user_id = :uid AND can_delete = 1
             LIMIT 1'
        );
        $stmtFileShare->execute([
            ':file_id' => $fileId,
            ':uid'     => $userId,
        ]);
        if ($stmtFileShare->fetch()) {
            return true;
        }
    }

    if ($folderPath !== '') {
        $stmtFolderShares = $pdo->prepare(
            'SELECT folder_path FROM user_shares
             WHERE owner_id = :owner_id AND shared_with_user_id = :uid AND folder_path IS NOT NULL AND can_delete = 1'
        );
        $stmtFolderShares->execute([
            ':owner_id' => $ownerId,
            ':uid'      => $userId,
        ]);
        foreach ($stmtFolderShares->fetchAll(PDO::FETCH_COLUMN) as $sharedFp) {
            $sharedFpStr = (string)$sharedFp;
            if ($sharedFpStr !== '' && ($folderPath === $sharedFpStr || str_starts_with($folderPath, $sharedFpStr . '/'))) {
                return true;
            }
        }
    }

    return false;
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
 * Lädt die Systemeinstellungen (Anwendungstitel, Untertitel, Logo, Impressum, Absender-E-Mail & SMTP) aus der Datenbank.
 *
 * @return array{app_title: string, app_subtitle: string, logo_data_uri: string, imprint_text: string, mail_from: string, mail_transport: string, smtp_host: string, smtp_port: string, smtp_encryption: string, smtp_user: string, smtp_pass: string}
 */
function getAppSettings(?PDO $pdo = null): array
{
    $defaults = [
        'app_title'                     => APP_NAME,
        'app_subtitle'                  => 'Private Cloud',
        'logo_data_uri'                 => '',
        'imprint_text'                  => '',
        'mail_from'                     => '',
        'mail_transport'                => 'mail',
        'smtp_host'                     => '',
        'smtp_port'                     => '465',
        'smtp_encryption'               => 'ssl',
        'smtp_user'                     => '',
        'smtp_pass'                     => '',
        'allow_registration'            => '0',
        'registration_code'             => '',
        'registration_default_quota_mb' => '100',
        'enable_calendar'               => '1',
        'enable_chat'                   => '1',
        'enable_tasks'                  => '1',
        'enable_notes'                  => '1',
        'enable_contacts'               => '1',
        'enable_webdav'                 => '1',
        'github_repo'                   => 'uWiiiMiniCloud/MiniCloud',
        'installed_commit_sha'          => '',
        'installed_commit_date'         => '',
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
    if (!in_array($defaults['mail_transport'], ['mail', 'smtp'], true)) {
        $defaults['mail_transport'] = 'mail';
    }
    if (!in_array($defaults['smtp_encryption'], ['ssl', 'tls', 'none'], true)) {
        $defaults['smtp_encryption'] = 'ssl';
    }
    if (trim($defaults['smtp_port']) === '' || (int)$defaults['smtp_port'] <= 0) {
        $defaults['smtp_port'] = $defaults['smtp_encryption'] === 'tls' ? '587' : '465';
    }
    if (trim($defaults['github_repo']) === '' || strcasecmp(trim($defaults['github_repo']), 'uwebenzelrath/MiniCloud') === 0) {
        $defaults['github_repo'] = 'uWiiiMiniCloud/MiniCloud';
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
 * Liest eine mehrzeilige SMTP-Serverantwort und gibt den 3-stelligen Statuscode sowie den Text zurück.
 *
 * @param resource $socket
 * @return array{code: int, text: string}
 */
function readSmtpResponse($socket): array
{
    $lines = '';
    $code = 0;
    while (!feof($socket)) {
        $line = fgets($socket, 1024);
        if ($line === false) {
            break;
        }
        $lines .= $line;
        if (preg_match('/^(\d{3})([ -])/', $line, $m)) {
            $code = (int)$m[1];
            if ($m[2] === ' ') {
                break;
            }
        } else {
            break;
        }
    }
    return ['code' => $code, 'text' => trim($lines)];
}

/**
 * Sendet einen SMTP-Befehl und prüft, ob der Statuscode in $expectedCodes enthalten ist.
 *
 * @param resource $socket
 * @param int[] $expectedCodes
 */
function sendSmtpCommand($socket, string $command, array $expectedCodes, ?string &$errorOut = null): bool
{
    if (@fwrite($socket, $command . "\r\n") === false) {
        $errorOut = 'Schreibfehler auf der SMTP-Verbindung.';
        return false;
    }
    $resp = readSmtpResponse($socket);
    if (!in_array($resp['code'], $expectedCodes, true)) {
        $errorOut = sprintf('SMTP-Antwort (%d): %s', $resp['code'], $resp['text']);
        return false;
    }
    return true;
}

/**
 * Versendet eine E-Mail direkt über einen konfigurierten SMTP-Server (SSL/TLS Port 465, STARTTLS Port 587 oder Plain).
 *
 * @param array<string, string> $settings
 */
function sendSmtpEmail(
    array $settings,
    string $fromEmail,
    string $toEmail,
    string $encodedSubject,
    string $body,
    string $headerStr,
    ?string &$errorOut = null
): bool {
    $host = trim((string)($settings['smtp_host'] ?? ''));
    $port = (int)($settings['smtp_port'] ?? 465);
    $enc  = (string)($settings['smtp_encryption'] ?? 'ssl');
    $user = trim((string)($settings['smtp_user'] ?? ''));
    $pass = (string)($settings['smtp_pass'] ?? '');

    if ($host === '' || $port <= 0 || $port > 65535) {
        $errorOut = 'Ungültiger SMTP-Host oder Port.';
        return false;
    }

    $remote = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $ctx = stream_context_create([
        'ssl' => [
            'verify_peer'       => true,
            'verify_peer_name'  => true,
            'allow_self_signed' => false,
        ],
    ]);

    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_client($remote, $errno, $errstr, 12.0, STREAM_CLIENT_CONNECT, $ctx);
    if (!is_resource($socket)) {
        $errorOut = sprintf('Verbindung zu %s:%d fehlgeschlagen (%s).', $host, $port, $errstr ?: ('Code ' . $errno));
        return false;
    }

    stream_set_timeout($socket, 12);

    $greeting = readSmtpResponse($socket);
    if ($greeting['code'] !== 220) {
        fclose($socket);
        $errorOut = 'Ungültige SMTP-Begrüßung: ' . $greeting['text'];
        return false;
    }

    $ehloDomain = preg_replace('/[^a-zA-Z0-9.-]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';
    if (!sendSmtpCommand($socket, 'EHLO ' . $ehloDomain, [250], $errorOut)) {
        fclose($socket);
        return false;
    }

    if ($enc === 'tls') {
        if (!sendSmtpCommand($socket, 'STARTTLS', [220], $errorOut)) {
            fclose($socket);
            return false;
        }
        $cryptoMethod = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
            $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        }
        if (!@stream_socket_enable_crypto($socket, true, $cryptoMethod)) {
            fclose($socket);
            $errorOut = 'STARTTLS-Verschlüsselung konnte nicht ausgehandelt werden.';
            return false;
        }
        if (!sendSmtpCommand($socket, 'EHLO ' . $ehloDomain, [250], $errorOut)) {
            fclose($socket);
            return false;
        }
    }

    if ($user !== '') {
        if (!sendSmtpCommand($socket, 'AUTH LOGIN', [334], $errorOut)) {
            fclose($socket);
            return false;
        }
        if (!sendSmtpCommand($socket, base64_encode($user), [334], $errorOut)) {
            fclose($socket);
            return false;
        }
        if (!sendSmtpCommand($socket, base64_encode($pass), [235], $errorOut)) {
            fclose($socket);
            $errorOut = 'SMTP-Authentifizierung fehlgeschlagen (Benutzername oder Passwort falsch).';
            return false;
        }
    }

    if (!sendSmtpCommand($socket, 'MAIL FROM:<' . $fromEmail . '>', [250], $errorOut)) {
        fclose($socket);
        return false;
    }
    if (!sendSmtpCommand($socket, 'RCPT TO:<' . $toEmail . '>', [250, 251], $errorOut)) {
        fclose($socket);
        return false;
    }
    if (!sendSmtpCommand($socket, 'DATA', [354], $errorOut)) {
        fclose($socket);
        return false;
    }

    // Dot-Stuffing nach RFC 5321
    $normalizedBody = str_replace(["\r\n", "\r"], "\n", $body);
    $stuffedLines = [];
    foreach (explode("\n", $normalizedBody) as $line) {
        if (str_starts_with($line, '.')) {
            $line = '.' . $line;
        }
        $stuffedLines[] = $line;
    }

    $msgId = '<' . bin2hex(random_bytes(12)) . '@' . $ehloDomain . '>';
    $dataPayload = implode("\r\n", [
        'Date: ' . date('r'),
        'To: <' . $toEmail . '>',
        'Subject: ' . $encodedSubject,
        'Message-ID: ' . $msgId,
        $headerStr,
        '',
        implode("\r\n", $stuffedLines),
        '.',
    ]);

    if (!sendSmtpCommand($socket, $dataPayload, [250], $errorOut)) {
        fclose($socket);
        return false;
    }

    @fwrite($socket, "QUIT\r\n");
    fclose($socket);
    return true;
}

/**
 * Zentrale Versandfunktion für System-E-Mails (nutzt je nach Einstellung PHP mail()/Postfix oder SMTP).
 */
function sendSystemEmail(PDO $pdo, string $toEmail, string $rawSubject, string $body, ?string &$errorOut = null): bool
{
    $toEmail = trim($toEmail);
    if (filter_var($toEmail, FILTER_VALIDATE_EMAIL) === false) {
        $errorOut = 'Ungültige Empfänger-E-Mail-Adresse.';
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
    $smtpUserEmail  = trim($settings['smtp_user']);
    if (filter_var($configuredFrom, FILTER_VALIDATE_EMAIL) !== false) {
        $fromEmail = $configuredFrom;
    } elseif ($settings['mail_transport'] === 'smtp' && filter_var($smtpUserEmail, FILTER_VALIDATE_EMAIL) !== false) {
        $fromEmail = $smtpUserEmail;
    } else {
        $fromEmail = 'noreply@' . $domain;
    }

    $cleanTitle = preg_replace('/[\r\n"]/', '', $appTitle) ?: APP_NAME;
    $encodedFromName = '=?UTF-8?B?' . base64_encode($cleanTitle) . '?=';
    $encodedSubject  = '=?UTF-8?B?' . base64_encode($rawSubject) . '?=';

    $headers = [
        'From: ' . $encodedFromName . ' <' . $fromEmail . '>',
        'Reply-To: ' . $fromEmail,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'X-Mailer: MiniCloud-2FA',
    ];
    $headerStr = implode("\r\n", $headers);

    if ($settings['mail_transport'] === 'smtp') {
        return sendSmtpEmail($settings, $fromEmail, $toEmail, $encodedSubject, $body, $headerStr, $errorOut);
    }

    $sent = @mail($toEmail, $encodedSubject, $body, $headerStr, '-f ' . escapeshellarg($fromEmail));
    if (!$sent) {
        $sent = @mail($toEmail, $encodedSubject, $body, $headerStr);
    }
    if (!$sent) {
        $errorOut = 'Der lokale PHP mail()-Aufruf ist fehlgeschlagen.';
    }

    return (bool)$sent;
}

/**
 * Versendet den 6-stelligen 2FA-Sicherheitscode per E-Mail an den Benutzer.
 */
function sendTwoFactorEmail(PDO $pdo, string $toEmail, string $username, string $code): bool
{
    $settings = getAppSettings($pdo);
    $cleanTitle = preg_replace('/[\r\n"]/', '', $settings['app_title'] ?: APP_NAME) ?: APP_NAME;
    $rawSubject = sprintf('[%s] Ihr 2FA-Anmeldecode: %s', $cleanTitle, $code);

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

    return sendSystemEmail($pdo, $toEmail, $rawSubject, $body);
}

/**
 * Versendet einen einmaligen, 30 Minuten gültigen Link zum Zurücksetzen eines vergessenen Passworts per E-Mail.
 */
function sendPasswordResetEmail(PDO $pdo, string $toEmail, string $username, string $resetUrl): bool
{
    $settings = getAppSettings($pdo);
    $cleanTitle = preg_replace('/[\r\n"]/', '', $settings['app_title'] ?: APP_NAME) ?: APP_NAME;
    $rawSubject = sprintf('[%s] Passwort zurücksetzen', $cleanTitle);

    $body = implode("\r\n", [
        sprintf('Hallo %s,', $username),
        '',
        sprintf('für Ihr Benutzerkonto bei %s wurde das Zurücksetzen des Passworts angefordert.', $cleanTitle),
        'Über den folgenden Link können Sie innerhalb der nächsten 30 Minuten ein neues Passwort vergeben:',
        '',
        $resetUrl,
        '',
        'Dieser Link ist aus Sicherheitsgründen nur 30 Minuten lang und nur für eine einmalige Verwendung gültig.',
        '',
        'Falls Sie diese Anfrage nicht selbst gestellt haben, können Sie diese E-Mail einfach ignorieren – Ihr bisheriges Passwort bleibt dann unverändert gültig.',
        '',
        '--',
        $cleanTitle . ' (' . getBaseUrl() . ')',
    ]);

    return sendSystemEmail($pdo, $toEmail, $rawSubject, $body);
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

/**
 * Prüft anhand des 8-Byte-Magics "MCVAULT1", ob eine physische Datei im Tresor-Format verschlüsselt ist.
 */
function isVaultEncryptedFile(string $filePath): bool
{
    if (!is_file($filePath) || !is_readable($filePath)) {
        return false;
    }
    $fh = @fopen($filePath, 'rb');
    if ($fh === false) {
        return false;
    }
    $magic = (string)fread($fh, 8);
    fclose($fh);

    return $magic === 'MCVAULT1';
}

/**
 * Verschlüsselt eine bestehende Datei speicherschonend (Chunked AES-256-GCM mit PBKDF2-HMAC-SHA256)
 * direkt vor Ort. Das Passwort wird nirgendwo gespeichert (Zero-Knowledge).
 *
 * Dateiaufbau (Header = 72 Bytes):
 * - 8 Bytes : Magic "MCVAULT1"
 * - 8 Bytes : Original-Dateigröße (uint64 Big-Endian)
 * - 16 Bytes: PBKDF2 Salt
 * - 8 Bytes : Nonce-Präfix
 * - 16 Bytes: Verschlüsselter Prüfblock ("MCVAULT1_VERIFY!")
 * - 16 Bytes: GCM-Tag des Prüfblocks
 * Gefolgt von 1-MB-Chunks: [4 Bytes Chunk-Länge][16 Bytes GCM-Tag][Ciphertext]
 */
function encryptFileInPlaceWithVault(string $filePath, string $password): bool
{
    if ($password === '' || !is_file($filePath) || !is_readable($filePath)) {
        return false;
    }
    if (isVaultEncryptedFile($filePath)) {
        return false;
    }

    $origSize = (int)filesize($filePath);
    $salt = random_bytes(16);
    $noncePrefix = random_bytes(8);
    $key = hash_pbkdf2('sha256', $password, $salt, 100000, 32, true);

    $verifyTag = '';
    $verifyCipher = openssl_encrypt(
        'MCVAULT1_VERIFY!',
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $noncePrefix . pack('N', 0),
        $verifyTag,
        'HEADER',
        16
    );
    if ($verifyCipher === false || strlen($verifyCipher) !== 16 || strlen($verifyTag) !== 16) {
        return false;
    }

    $tmpPath = $filePath . '.vault_enc_' . bin2hex(random_bytes(4));
    $inFh = @fopen($filePath, 'rb');
    if ($inFh === false) {
        return false;
    }

    $outFh = @fopen($tmpPath, 'wb');
    if ($outFh === false) {
        fclose($inFh);
        return false;
    }

    $header = 'MCVAULT1'
        . pack('J', $origSize)
        . $salt
        . $noncePrefix
        . $verifyCipher
        . $verifyTag;

    if (fwrite($outFh, $header) !== 72) {
        fclose($inFh);
        fclose($outFh);
        @unlink($tmpPath);
        return false;
    }

    $chunkIndex = 1;
    $chunkSize = 1024 * 1024; // 1 MB pro Block
    $ok = true;

    while (!feof($inFh)) {
        $plainChunk = fread($inFh, $chunkSize);
        if ($plainChunk === false) {
            $ok = false;
            break;
        }
        if ($plainChunk === '') {
            break;
        }

        $chunkTag = '';
        $iv = $noncePrefix . pack('N', $chunkIndex);
        $aad = pack('N', $chunkIndex);
        $cipherChunk = openssl_encrypt(
            $plainChunk,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $chunkTag,
            $aad,
            16
        );

        if ($cipherChunk === false || strlen($chunkTag) !== 16) {
            $ok = false;
            break;
        }

        $lenBytes = pack('N', strlen($cipherChunk));
        if (
            fwrite($outFh, $lenBytes) !== 4
            || fwrite($outFh, $chunkTag) !== 16
            || fwrite($outFh, $cipherChunk) !== strlen($cipherChunk)
        ) {
            $ok = false;
            break;
        }

        $chunkIndex++;
    }

    fclose($inFh);
    fclose($outFh);

    if (!$ok) {
        @unlink($tmpPath);
        return false;
    }

    if (!@rename($tmpPath, $filePath)) {
        @unlink($tmpPath);
        return false;
    }

    @chmod($filePath, 0640);
    return true;
}

/**
 * Prüft in wenigen Millisekunden anhand des 72-Byte-Headers, ob das Tresor-Passwort für eine Datei stimmt.
 *
 * @param array{orig_size: int, key: string, nonce_prefix: string}|null $headerInfoOut
 */
function verifyVaultFilePassword(string $filePath, string $password, ?array &$headerInfoOut = null): bool
{
    if ($password === '' || !is_file($filePath) || !is_readable($filePath)) {
        return false;
    }

    $fh = @fopen($filePath, 'rb');
    if ($fh === false) {
        return false;
    }
    $hdr = (string)fread($fh, 72);
    fclose($fh);

    if (strlen($hdr) !== 72 || substr($hdr, 0, 8) !== 'MCVAULT1') {
        return false;
    }

    $unpacked = unpack('Jorig_size', substr($hdr, 8, 8));
    $origSize = isset($unpacked['orig_size']) ? (int)$unpacked['orig_size'] : 0;
    $salt = substr($hdr, 16, 16);
    $noncePrefix = substr($hdr, 32, 8);
    $verifyCipher = substr($hdr, 40, 16);
    $verifyTag = substr($hdr, 56, 16);

    $key = hash_pbkdf2('sha256', $password, $salt, 100000, 32, true);
    $decryptedVerify = openssl_decrypt(
        $verifyCipher,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $noncePrefix . pack('N', 0),
        $verifyTag,
        'HEADER'
    );

    if ($decryptedVerify !== 'MCVAULT1_VERIFY!') {
        return false;
    }

    $headerInfoOut = [
        'orig_size'    => $origSize,
        'key'          => $key,
        'nonce_prefix' => $noncePrefix,
    ];
    return true;
}

/**
 * Entschlüsselt eine im Tresor-Format verschlüsselte Datei dauerhaft zurück in die Originaldatei.
 */
function decryptFileInPlaceFromVault(string $filePath, string $password): bool
{
    $headerInfo = null;
    if (!verifyVaultFilePassword($filePath, $password, $headerInfo) || !is_array($headerInfo)) {
        return false;
    }

    $inFh = @fopen($filePath, 'rb');
    if ($inFh === false) {
        return false;
    }
    if (fseek($inFh, 72) !== 0) {
        fclose($inFh);
        return false;
    }

    $tmpPath = $filePath . '.vault_dec_' . bin2hex(random_bytes(4));
    $outFh = @fopen($tmpPath, 'wb');
    if ($outFh === false) {
        fclose($inFh);
        return false;
    }

    $key = $headerInfo['key'];
    $noncePrefix = $headerInfo['nonce_prefix'];
    $chunkIndex = 1;
    $ok = true;

    while (!feof($inFh)) {
        $lenBytes = fread($inFh, 4);
        if ($lenBytes === false || $lenBytes === '') {
            break;
        }
        if (strlen($lenBytes) !== 4) {
            $ok = false;
            break;
        }

        $chunkLen = (int)(unpack('Nlen', $lenBytes)['len'] ?? 0);
        if ($chunkLen <= 0 || $chunkLen > 4 * 1024 * 1024) {
            $ok = false;
            break;
        }

        $chunkTag = fread($inFh, 16);
        if ($chunkTag === false || strlen($chunkTag) !== 16) {
            $ok = false;
            break;
        }

        $cipherChunk = '';
        $remaining = $chunkLen;
        while ($remaining > 0 && !feof($inFh)) {
            $part = fread($inFh, $remaining);
            if ($part === false || $part === '') {
                break;
            }
            $cipherChunk .= $part;
            $remaining -= strlen($part);
        }

        if (strlen($cipherChunk) !== $chunkLen) {
            $ok = false;
            break;
        }

        $iv = $noncePrefix . pack('N', $chunkIndex);
        $aad = pack('N', $chunkIndex);
        $plainChunk = openssl_decrypt(
            $cipherChunk,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $chunkTag,
            $aad
        );

        if ($plainChunk === false || fwrite($outFh, $plainChunk) !== strlen($plainChunk)) {
            $ok = false;
            break;
        }

        $chunkIndex++;
    }

    fclose($inFh);
    fclose($outFh);

    if (!$ok) {
        @unlink($tmpPath);
        return false;
    }

    if (!@rename($tmpPath, $filePath)) {
        @unlink($tmpPath);
        return false;
    }

    @chmod($filePath, 0640);
    return true;
}

/**
 * Streamt eine verschlüsselte Tresor-Datei nach erfolgreicher Passwortprüfung direkt entschlüsselt an den Browser.
 *
 * @param array{orig_size: int, key: string, nonce_prefix: string} $headerInfo
 */
function streamDecryptedVaultFile(string $filePath, array $headerInfo, int $maxBytes = 0): void
{
    $inFh = @fopen($filePath, 'rb');
    if ($inFh === false) {
        return;
    }
    if (fseek($inFh, 72) !== 0) {
        fclose($inFh);
        return;
    }

    $key = $headerInfo['key'];
    $noncePrefix = $headerInfo['nonce_prefix'];
    $chunkIndex = 1;
    $sentBytes = 0;

    while (!feof($inFh)) {
        $lenBytes = fread($inFh, 4);
        if ($lenBytes === false || strlen($lenBytes) !== 4) {
            break;
        }
        $chunkLen = (int)(unpack('Nlen', $lenBytes)['len'] ?? 0);
        if ($chunkLen <= 0 || $chunkLen > 4 * 1024 * 1024) {
            break;
        }
        $chunkTag = fread($inFh, 16);
        if ($chunkTag === false || strlen($chunkTag) !== 16) {
            break;
        }

        $cipherChunk = '';
        $remaining = $chunkLen;
        while ($remaining > 0 && !feof($inFh)) {
            $part = fread($inFh, $remaining);
            if ($part === false || $part === '') {
                break;
            }
            $cipherChunk .= $part;
            $remaining -= strlen($part);
        }
        if (strlen($cipherChunk) !== $chunkLen) {
            break;
        }

        $iv = $noncePrefix . pack('N', $chunkIndex);
        $aad = pack('N', $chunkIndex);
        $plainChunk = openssl_decrypt(
            $cipherChunk,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $chunkTag,
            $aad
        );
        if ($plainChunk === false) {
            break;
        }

        if ($maxBytes > 0 && ($sentBytes + strlen($plainChunk)) > $maxBytes) {
            $sliceLen = max(0, $maxBytes - $sentBytes);
            if ($sliceLen > 0) {
                echo substr($plainChunk, 0, $sliceLen);
            }
            break;
        }

        echo $plainChunk;
        $sentBytes += strlen($plainChunk);
        if (function_exists('flush')) {
            flush();
        }

        $chunkIndex++;
    }

    fclose($inFh);
}

/**
 * Erzeugt einen kryptografisch sicheren Base32-Geheimschlüssel (160 Bit / 32 Zeichen)
 * für Google Authenticator / TOTP gemäß RFC 6238 & RFC 4648.
 */
function generateTotpSecret(int $length = 32): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    for ($i = 0; $i < $length; $i++) {
        $secret .= $alphabet[random_int(0, 31)];
    }
    return $secret;
}

/**
 * Dekodiert einen Base32-kodierten TOTP-Schlüssel in Binärbytes (RFC 4648).
 */
function base32Decode(string $base32): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $clean = strtoupper((string)preg_replace('/[^A-Za-z2-7]/', '', $base32));
    $buffer = 0;
    $bitsLeft = 0;
    $out = '';

    $len = strlen($clean);
    for ($i = 0; $i < $len; $i++) {
        $val = strpos($alphabet, $clean[$i]);
        if ($val === false) {
            continue;
        }
        $buffer = ($buffer << 5) | $val;
        $bitsLeft += 5;
        if ($bitsLeft >= 8) {
            $bitsLeft -= 8;
            $out .= chr(($buffer >> $bitsLeft) & 0xFF);
        }
    }

    return $out;
}

/**
 * Berechnet den 6-stelligen TOTP-Code (RFC 6238, HMAC-SHA1, 30s-Intervall) für einen Zeit-Slot.
 */
function getTotpCode(string $base32Secret, ?int $timeSlice = null): string
{
    if ($timeSlice === null) {
        $timeSlice = (int)floor(time() / 30);
    }

    $secretKey = base32Decode($base32Secret);
    if ($secretKey === '') {
        return '';
    }

    // 64-Bit Big-Endian Zeit-Zähler
    $timeBytes = pack('N*', 0, $timeSlice);
    $hmac = hash_hmac('sha1', $timeBytes, $secretKey, true);
    $offset = ord($hmac[19]) & 0x0F;

    $binary = ((ord($hmac[$offset]) & 0x7F) << 24)
        | ((ord($hmac[$offset + 1]) & 0xFF) << 16)
        | ((ord($hmac[$offset + 2]) & 0xFF) << 8)
        | (ord($hmac[$offset + 3]) & 0xFF);

    $otp = $binary % 1000000;
    return str_pad((string)$otp, 6, '0', STR_PAD_LEFT);
}

/**
 * Prüft einen 6-stelligen TOTP-Code aus einer Authenticator-App (Google Authenticator, Authy, Bitwarden etc.)
 * mit einer Toleranz von ±1 Zeitfenster (±30 Sekunden) gegen leichte Uhrzeitabweichungen.
 */
function verifyTotpCode(string $base32Secret, string $codeInput, int $window = 1): bool
{
    $cleanCode = (string)preg_replace('/\s+/', '', trim($codeInput));
    if (!preg_match('/^\d{6}$/', $cleanCode) || trim($base32Secret) === '') {
        return false;
    }

    $currentSlice = (int)floor(time() / 30);
    for ($i = -$window; $i <= $window; $i++) {
        $calc = getTotpCode($base32Secret, $currentSlice + $i);
        if ($calc !== '' && hash_equals($calc, $cleanCode)) {
            return true;
        }
    }

    return false;
}

/**
 * Erstellt die standardisierte otpauth:// URI für Google Authenticator & kompatible TOTP-Apps.
 */
function buildTotpOtpAuthUri(string $issuer, string $username, string $secret): string
{
    $cleanIssuer = trim((string)preg_replace('/[:\r\n]/', ' ', $issuer)) ?: APP_NAME;
    $cleanUser   = trim((string)preg_replace('/[:\r\n]/', '_', $username)) ?: 'user';
    $label       = rawurlencode($cleanIssuer) . ':' . rawurlencode($cleanUser);

    return sprintf(
        'otpauth://totp/%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
        $label,
        rawurlencode($secret),
        rawurlencode($cleanIssuer)
    );
}

/**
 * Erzeugt lokal (100 % offline & DSGVO-konform ohne externe Server) einen ISO/IEC 18004 konformen
 * QR-Code als SVG-Grafik (Byte-Modus, Fehlerkorrekturlevel L, Versionen 1–10).
 */
function renderQrCodeSvg(string $data, int $pixelSize = 180): string
{
    // Kapazitäten und Reed-Solomon-Parameter für QR Versionen 1–10 (Error Correction Level L):
    // [totalCodewords, ecCodewordsPerBlock, numBlocksGroup1, dataCwGroup1, numBlocksGroup2, dataCwGroup2, alignCenters]
    $versions = [
        1  => [26,   7,  1, 19,  0, 0,  []],
        2  => [44,   10, 1, 34,  0, 0,  [6, 18]],
        3  => [70,   15, 1, 55,  0, 0,  [6, 22]],
        4  => [100,  20, 1, 80,  0, 0,  [6, 26]],
        5  => [134,  26, 1, 108, 0, 0,  [6, 30]],
        6  => [172,  18, 2, 68,  0, 0,  [6, 34]],
        7  => [196,  20, 2, 78,  0, 0,  [6, 22, 38]],
        8  => [242,  24, 2, 97,  0, 0,  [6, 24, 42]],
        9  => [292,  30, 2, 116, 0, 0,  [6, 26, 46]],
        10 => [346,  18, 2, 68,  2, 69, [6, 28, 50]],
    ];

    $dataLen = strlen($data);
    $ver = 0;
    foreach ($versions as $v => $cfg) {
        $totalDataCw = $cfg[2] * $cfg[3] + $cfg[4] * $cfg[5];
        $headerBits = ($v <= 9) ? 12 : 20; // 4 Bit Mode + 8/16 Bit Länge
        $maxBytes = (int)floor(($totalDataCw * 8 - $headerBits) / 8);
        if ($dataLen <= $maxBytes) {
            $ver = $v;
            break;
        }
    }
    if ($ver === 0) {
        return '';
    }

    [$totalCw, $ecCwPerBlock, $b1, $d1, $b2, $d2, $align] = $versions[$ver];
    $totalDataCw = $b1 * $d1 + $b2 * $d2;

    // 1. Bitstrom aufbauen (Byte-Modus = 0100)
    $bits = '0100';
    $lenBits = ($ver <= 9) ? 8 : 16;
    $bits .= str_pad(decbin($dataLen), $lenBits, '0', STR_PAD_LEFT);
    for ($i = 0; $i < $dataLen; $i++) {
        $bits .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
    }

    $maxDataBits = $totalDataCw * 8;
    $terminator = min(4, $maxDataBits - strlen($bits));
    if ($terminator > 0) {
        $bits .= str_repeat('0', $terminator);
    }
    while ((strlen($bits) % 8) !== 0) {
        $bits .= '0';
    }

    $padBytes = ['11101100', '00010001'];
    $pIdx = 0;
    while (strlen($bits) < $maxDataBits) {
        $bits .= $padBytes[$pIdx % 2];
        $pIdx++;
    }

    $dataCodewords = [];
    for ($i = 0; $i < $totalDataCw; $i++) {
        $dataCodewords[] = (int)bindec(substr($bits, $i * 8, 8));
    }

    // 2. Galois-Feld GF(256) Tabellen für Reed-Solomon Fehlerkorrektur
    $gfExp = array_fill(0, 512, 0);
    $gfLog = array_fill(0, 256, 0);
    $x = 1;
    for ($i = 0; $i < 255; $i++) {
        $gfExp[$i] = $x;
        $gfLog[$x] = $i;
        $x <<= 1;
        if ($x & 0x100) {
            $x ^= 0x11D;
        }
    }
    for ($i = 255; $i < 512; $i++) {
        $gfExp[$i] = $gfExp[$i - 255];
    }

    $gfMul = static function (int $a, int $b) use ($gfExp, $gfLog): int {
        if ($a === 0 || $b === 0) {
            return 0;
        }
        return $gfExp[$gfLog[$a] + $gfLog[$b]];
    };

    // Generator-Polynom für $ecCwPerBlock erzeugen
    $gen = [1];
    for ($i = 0; $i < $ecCwPerBlock; $i++) {
        $next = array_fill(0, count($gen) + 1, 0);
        for ($j = 0, $gLen = count($gen); $j < $gLen; $j++) {
            $next[$j] ^= $gen[$j];
            $next[$j + 1] ^= $gfMul($gen[$j], $gfExp[$i]);
        }
        $gen = $next;
    }

    // Blöcke aufteilen & EC-Codewörter berechnen
    $dataBlocks = [];
    $ecBlocks = [];
    $offset = 0;
    $blockCounts = array_merge(array_fill(0, $b1, $d1), $b2 > 0 ? array_fill(0, $b2, $d2) : []);

    foreach ($blockCounts as $bLen) {
        $block = array_slice($dataCodewords, $offset, $bLen);
        $offset += $bLen;
        $dataBlocks[] = $block;

        $msg = array_merge($block, array_fill(0, $ecCwPerBlock, 0));
        for ($i = 0; $i < $bLen; $i++) {
            $coef = $msg[$i];
            if ($coef !== 0) {
                for ($j = 0; $j <= $ecCwPerBlock; $j++) {
                    $msg[$i + $j] ^= $gfMul($gen[$j], $coef);
                }
            }
        }
        $ecBlocks[] = array_slice($msg, $bLen);
    }

    // Interleaving der Daten- und EC-Blöcke
    $finalCw = [];
    $maxDataBlockLen = max($d1, $d2);
    for ($i = 0; $i < $maxDataBlockLen; $i++) {
        foreach ($dataBlocks as $blk) {
            if (isset($blk[$i])) {
                $finalCw[] = $blk[$i];
            }
        }
    }
    for ($i = 0; $i < $ecCwPerBlock; $i++) {
        foreach ($ecBlocks as $eblk) {
            $finalCw[] = $eblk[$i];
        }
    }

    // 3. QR-Matrix (Größe = 17 + 4 * Version) aufbauen
    $dim = 17 + 4 * $ver;
    $matrix = array_fill(0, $dim, array_fill(0, $dim, null));
    $reserved = array_fill(0, $dim, array_fill(0, $dim, false));

    $placeFinder = static function (int $r0, int $c0) use (&$matrix, &$reserved, $dim): void {
        for ($r = -1; $r <= 7; $r++) {
            for ($c = -1; $c <= 7; $c++) {
                $rr = $r0 + $r;
                $cc = $c0 + $c;
                if ($rr < 0 || $rr >= $dim || $cc < 0 || $cc >= $dim) {
                    continue;
                }
                $inOuter = ($r >= 0 && $r <= 6 && $c >= 0 && $c <= 6);
                $inBorder = ($r === 0 || $r === 6 || $c === 0 || $c === 6);
                $inCenter = ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4);
                $matrix[$rr][$cc] = ($inOuter && ($inBorder || $inCenter)) ? 1 : 0;
                $reserved[$rr][$cc] = true;
            }
        }
    };

    $placeFinder(0, 0);
    $placeFinder(0, $dim - 7);
    $placeFinder($dim - 7, 0);

    // Alignment-Patterns
    $aCount = count($align);
    for ($i = 0; $i < $aCount; $i++) {
        for ($j = 0; $j < $aCount; $j++) {
            if (($i === 0 && $j === 0) || ($i === 0 && $j === $aCount - 1) || ($i === $aCount - 1 && $j === 0)) {
                continue;
            }
            $ar = $align[$i];
            $ac = $align[$j];
            for ($r = -2; $r <= 2; $r++) {
                for ($c = -2; $c <= 2; $c++) {
                    $matrix[$ar + $r][$ac + $c] = (abs($r) === 2 || abs($c) === 2 || ($r === 0 && $c === 0)) ? 1 : 0;
                    $reserved[$ar + $r][$ac + $c] = true;
                }
            }
        }
    }

    // Timing-Patterns
    for ($i = 8; $i < $dim - 8; $i++) {
        if (!$reserved[6][$i]) {
            $matrix[6][$i] = ($i % 2 === 0) ? 1 : 0;
            $reserved[6][$i] = true;
        }
        if (!$reserved[$i][6]) {
            $matrix[$i][6] = ($i % 2 === 0) ? 1 : 0;
            $reserved[$i][6] = true;
        }
    }

    // Dark Module & Format-Info-Bereiche reservieren
    $matrix[4 * $ver + 9][8] = 1;
    $reserved[4 * $ver + 9][8] = true;
    for ($i = 0; $i < 9; $i++) {
        if ($i < $dim) {
            $reserved[8][$i] = true;
            $reserved[$i][8] = true;
        }
    }
    for ($i = 0; $i < 8; $i++) {
        $reserved[8][$dim - 1 - $i] = true;
        $reserved[$dim - 1 - $i][8] = true;
    }

    // Version-Info ab Version 7
    if ($ver >= 7) {
        $vInfo = $ver << 12;
        for ($i = 5; $i >= 0; $i--) {
            if ($vInfo & (1 << ($i + 12))) {
                $vInfo ^= (0x1F25 << $i);
            }
        }
        $vBits = ($ver << 12) | $vInfo;
        for ($i = 0; $i < 18; $i++) {
            $bit = ($vBits >> $i) & 1;
            $r = (int)floor($i / 3);
            $c = $dim - 11 + ($i % 3);
            $matrix[$r][$c] = $bit;
            $reserved[$r][$c] = true;
            $matrix[$c][$r] = $bit;
            $reserved[$c][$r] = true;
        }
    }

    // 4. Datenbits im Zickzack von rechts unten eintragen (mit Maske 0: (r + c) % 2 === 0)
    $allBits = '';
    foreach ($finalCw as $cw) {
        $allBits .= str_pad(decbin($cw), 8, '0', STR_PAD_LEFT);
    }
    $allBits .= str_repeat('0', 7);

    $bitIdx = 0;
    $totalBitsLen = strlen($allBits);
    $up = true;
    for ($col = $dim - 1; $col > 0; $col -= 2) {
        if ($col === 6) {
            $col--;
        }
        for ($step = 0; $step < $dim; $step++) {
            $row = $up ? ($dim - 1 - $step) : $step;
            for ($cOff = 0; $cOff < 2; $cOff++) {
                $cc = $col - $cOff;
                if (!$reserved[$row][$cc]) {
                    $bit = ($bitIdx < $totalBitsLen && $allBits[$bitIdx] === '1') ? 1 : 0;
                    $bitIdx++;
                    if ((($row + $cc) % 2) === 0) {
                        $bit ^= 1;
                    }
                    $matrix[$row][$cc] = $bit;
                }
            }
        }
        $up = !$up;
    }

    // 5. Format-Information für Level L (01) + Maske 0 (000) = 01000 -> BCH & XOR 0x5412 => 0x77C4
    // Bit 0 (LSB) bis Bit 14 (MSB) gemäß ISO/IEC 18004 Section 8.9
    $fmtBits = 0x77C4;
    $fCoords1 = [
        [0, 8], [1, 8], [2, 8], [3, 8], [4, 8], [5, 8], [7, 8], [8, 8],
        [8, 7], [8, 5], [8, 4], [8, 3], [8, 2], [8, 1], [8, 0],
    ];
    for ($i = 0; $i < 15; $i++) {
        $bit = ($fmtBits >> $i) & 1;
        [$r1, $c1] = $fCoords1[$i];
        $matrix[$r1][$c1] = $bit;

        if ($i < 8) {
            $matrix[8][$dim - 1 - $i] = $bit;
        } else {
            $matrix[$dim - 15 + $i][8] = $bit;
        }
    }

    // 6. Kompaktes SVG-Path-Rendering (inkl. 2 Module Ruhezone)
    $margin = 2;
    $viewSize = $dim + 2 * $margin;
    $pathParts = [];
    for ($r = 0; $r < $dim; $r++) {
        for ($c = 0; $c < $dim; $c++) {
            if ($matrix[$r][$c] === 1) {
                $xPos = $c + $margin;
                $yPos = $r + $margin;
                $pathParts[] = sprintf('M%d,%dh1v1h-1z', $xPos, $yPos);
            }
        }
    }

    return sprintf(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="%d" height="%d" shape-rendering="crispEdges" class="rounded-xl bg-white p-1.5 border border-slate-200 shadow-xs"><rect width="100%%" height="100%%" fill="#ffffff"/><path d="%s" fill="#0f172a"/></svg>',
        $viewSize,
        $viewSize,
        $pixelSize,
        $pixelSize,
        implode('', $pathParts)
    );
}

/**
 * Protokolliert ein sicherheits- oder systemrelevantes Ereignis in der Tabelle audit_logs
 * (und hält das Protokoll automatisch schlank auf maximal 90 Tagen).
 */
function logAuditEvent(
    PDO $pdo,
    string $eventType,
    string $description,
    ?int $userId = null,
    ?string $username = null
): void {
    try {
        if ($userId === null && isset($_SESSION['user_id'])) {
            $userId = (int)$_SESSION['user_id'];
        }
        if ($username === null && isset($_SESSION['username'])) {
            $username = (string)$_SESSION['username'];
        }

        $rawIp = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        // IP-Adresse datenschutzfreundlich anonymisieren (letztes Oktett bei IPv4 / letzte Blöcke bei IPv6 maskieren)
        if (filter_var($rawIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $parts = explode('.', $rawIp);
            $parts[3] = 'x';
            $maskedIp = implode('.', $parts);
        } elseif (filter_var($rawIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $parts = explode(':', $rawIp);
            $maskedIp = implode(':', array_slice($parts, 0, 3)) . '::x';
        } else {
            $maskedIp = 'lokal';
        }

        $stmt = $pdo->prepare(
            'INSERT INTO audit_logs (user_id, username, event_type, description, ip_address)
             VALUES (:uid, :uname, :etype, :descr, :ip)'
        );
        $stmt->execute([
            ':uid'   => ($userId !== null && $userId > 0) ? $userId : null,
            ':uname' => mb_substr(trim((string)($username ?? 'Gast')), 0, 100),
            ':etype' => mb_substr(trim($eventType), 0, 50),
            ':descr' => mb_substr(trim($description), 0, 500),
            ':ip'    => mb_substr($maskedIp, 0, 64),
        ]);

        // Gelegentliche automatische Bereinigung alter Einträge (> 90 Tage)
        if (random_int(1, 25) === 1) {
            $pdo->exec("DELETE FROM `audit_logs` WHERE `created_at` < DATE_SUB(NOW(), INTERVAL 90 DAY)");
        }
    } catch (Throwable) {
        // Protokollfehler dürfen niemals die eigentliche Benutzeraktion blockieren
    }
}

/**
 * Erstellt vor dem Überschreiben/Speichern einer Datei automatisch einen Sicherungsstand in file_versions
 * (und behält pro Datei maximal die 5 jüngsten Vorgängerversionen).
 *
 * @param array<string, mixed> $fileRow
 */
function createFileVersionSnapshot(PDO $pdo, array $fileRow, string $versionNote = 'Vorheriger Stand', int $maxVersions = 5): bool
{
    $fileId     = (int)($fileRow['id'] ?? 0);
    $ownerId    = (int)($fileRow['user_id'] ?? 0);
    $storedName = basename((string)($fileRow['filename'] ?? ''));
    $mimeType   = (string)($fileRow['mime_type'] ?? 'application/octet-stream');

    if ($fileId <= 0 || $ownerId <= 0 || $storedName === '') {
        return false;
    }

    $srcPath = UPLOAD_DIR . $storedName;
    if (!is_file($srcPath) || !is_readable($srcPath)) {
        return false;
    }

    // Verschlüsselte Tresor-Dateien nicht doppelt versionieren
    if ((int)($fileRow['is_encrypted'] ?? 0) === 1 || isVaultEncryptedFile($srcPath)) {
        return false;
    }

    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0755, true);
    }

    do {
        $verStoredName = generateSafeStoredFilename();
        $destPath = UPLOAD_DIR . $verStoredName;
    } while (file_exists($destPath));

    if (!@copy($srcPath, $destPath)) {
        return false;
    }
    @chmod($destPath, 0640);
    $verSize = (int)(@filesize($destPath) ?: (int)($fileRow['file_size'] ?? 0));

    $stmtIns = $pdo->prepare(
        'INSERT INTO file_versions (file_id, user_id, stored_filename, file_size, mime_type, version_note)
         VALUES (:fid, :uid, :sf, :sz, :mime, :note)'
    );
    $stmtIns->execute([
        ':fid'  => $fileId,
        ':uid'  => $ownerId,
        ':sf'   => $verStoredName,
        ':sz'   => $verSize,
        ':mime' => mb_substr($mimeType, 0, 120),
        ':note' => mb_substr(trim($versionNote), 0, 190),
    ]);

    // Ältere Versionen über dem Limit ($maxVersions) bereinigen
    $stmtAll = $pdo->prepare('SELECT id, stored_filename FROM file_versions WHERE file_id = :fid ORDER BY id DESC');
    $stmtAll->execute([':fid' => $fileId]);
    $allVers = $stmtAll->fetchAll();

    if (count($allVers) > $maxVersions) {
        $stmtDelVer = $pdo->prepare('DELETE FROM file_versions WHERE id = :id');
        foreach (array_slice($allVers, $maxVersions) as $oldVer) {
            $oldPath = UPLOAD_DIR . basename((string)$oldVer['stored_filename']);
            if (is_file($oldPath)) {
                @unlink($oldPath);
            }
            $stmtDelVer->execute([':id' => (int)$oldVer['id']]);
        }
    }

    return true;
}

/**
 * Löscht alle physischen Vorgängerversionen einer Datei aus dem Dateisystem (uploads/).
 */
function deletePhysicalFileVersions(PDO $pdo, int $fileId): void
{
    if ($fileId <= 0) {
        return;
    }
    try {
        $stmt = $pdo->prepare('SELECT stored_filename FROM file_versions WHERE file_id = :fid');
        $stmt->execute([':fid' => $fileId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $sf) {
            $p = UPLOAD_DIR . basename((string)$sf);
            if (is_file($p)) {
                @unlink($p);
            }
        }
    } catch (Throwable) {
        // Ignorieren
    }
}

/**
 * Stellt sicher, dass das Upload-Verzeichnis (UPLOAD_DIR) existiert und per .htaccess gegen direkten Webzugriff geschützt ist.
 */
function ensureUploadDirReady(): void
{
    if (!is_dir(UPLOAD_DIR)) {
        @mkdir(UPLOAD_DIR, 0755, true);
    }
    $htaccessPath = rtrim(UPLOAD_DIR, '/\\') . '/.htaccess';
    if (!is_file($htaccessPath)) {
        $htaccessContent = <<<'HTACCESS'
# MiniCloud Security: Direkten HTTP-Zugriff und Skript-Ausführung im Upload-Verzeichnis verbieten
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order deny,allow
    Deny from all
</IfModule>
Options -Indexes -ExecCGI
RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .php8 .phar .pl .py .cgi .sh
php_flag engine off
HTACCESS;
        @file_put_contents($htaccessPath, $htaccessContent . "\n");
    }
}

/**
 * Liefert die ID des aktuell angemeldeten Benutzers (oder 0).
 */
function currentUserId(): int
{
    return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
}

/**
 * Erzeugt ein verstecktes HTML-Input-Feld mit dem aktuellen CSRF-Token.
 */
function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(getCsrfToken()) . '">';
}

/**
 * Liefert alle für den angegebenen Benutzer zum Teilen sichtbaren anderen Benutzer.
 *
 * @return array<int, array{id: int, username: string}>
 */
function getVisibleUsersForUser(PDO $pdo, int $userId): array
{
    $isAdm = isAdmin() ? 1 : 0;
    $stmt = $pdo->prepare(
        'SELECT id, username
         FROM users
         WHERE id <> :uid AND (allow_user_sharing = 1 OR is_admin = 1 OR :adm = 1)
         ORDER BY username ASC'
    );
    $stmt->execute([':uid' => $userId, ':adm' => $isAdm]);
    return $stmt->fetchAll();
}

/**
 * Liefert alle Benutzergruppen, in denen der Benutzer Mitglied ist (oder alle Gruppen für Admins).
 *
 * @return array<int, array{id: int, name: string}>
 */
function getGroupsForUser(PDO $pdo, int $userId): array
{
    if (isAdmin()) {
        $stmt = $pdo->query('SELECT id, name FROM `groups` ORDER BY name ASC');
        return $stmt ? $stmt->fetchAll() : [];
    }
    $stmt = $pdo->prepare(
        'SELECT g.id, g.name
         FROM `groups` g
         INNER JOIN group_members gm ON gm.group_id = g.id
         WHERE gm.user_id = :uid
         ORDER BY g.name ASC'
    );
    $stmt->execute([':uid' => $userId]);
    return $stmt->fetchAll();
}

/**
 * Alias für sanitizeOriginalFilename().
 */
function sanitizeFileName(string $filename): string
{
    return sanitizeOriginalFilename($filename);
}

/**
 * Alias für generateSafeStoredFilename().
 */
function generateStoredFileName(string $ext = ''): string
{
    return generateSafeStoredFilename();
}

/**
 * Alias für extractSanitizedFolderPath().
 */
function sanitizeFolderPath(string $path): string
{
    return extractSanitizedFolderPath($path);
}

/**
 * Escaping für vCard 3.0 Textfelder.
 */
function vcardEscape(string $val): string
{
    $val = str_replace(["\r\n", "\r"], "\n", $val);
    $val = str_replace(['\\', ';', ',', "\n"], ['\\\\', '\;', '\,', '\n'], $val);
    return $val;
}

/**
 * Erzeugt einen RFC-2426 / CardDAV-konformen vCard 3.0 Block für einen Kontakt (inkl. UID und REV).
 *
 * @param array<string, mixed> $c
 */
function buildVcardEntry(array $c): string
{
    $id    = (int)($c['id'] ?? 0);
    $uid   = !empty($c['vcard_uid']) ? (string)$c['vcard_uid'] : ('uwiiiminicloud-contact-' . $id . '@minicloud');
    $first = trim((string)($c['first_name'] ?? ''));
    $last  = trim((string)($c['last_name'] ?? ''));
    $fn    = trim($first . ' ' . $last);
    if ($fn === '') {
        $fn = trim((string)($c['organization'] ?? 'Kontakt'));
    }

    $revTs = !empty($c['updated_at']) ? (strtotime((string)$c['updated_at']) ?: time()) : time();

    $lines = [
        'BEGIN:VCARD',
        'VERSION:3.0',
        'PRODID:-//uWiiiMiniCloud//CardDAV 1.0//DE',
        'UID:' . vcardEscape($uid),
        'REV:' . gmdate('Ymd\THis\Z', $revTs),
        'N:' . vcardEscape($last) . ';' . vcardEscape($first) . ';;;',
        'FN:' . vcardEscape($fn),
    ];

    if (!empty($c['organization'])) {
        $lines[] = 'ORG:' . vcardEscape((string)$c['organization']);
    }
    if (!empty($c['role_title'])) {
        $lines[] = 'TITLE:' . vcardEscape((string)$c['role_title']);
    }
    if (!empty($c['email'])) {
        $lines[] = 'EMAIL;TYPE=INTERNET:' . vcardEscape((string)$c['email']);
    }
    if (!empty($c['phone'])) {
        $lines[] = 'TEL;TYPE=WORK,VOICE:' . vcardEscape((string)$c['phone']);
    }
    if (!empty($c['mobile'])) {
        $lines[] = 'TEL;TYPE=CELL,VOICE:' . vcardEscape((string)$c['mobile']);
    }
    if (!empty($c['street']) || !empty($c['city']) || !empty($c['postal_code']) || !empty($c['country'])) {
        $lines[] = 'ADR;TYPE=HOME:;;'
            . vcardEscape((string)($c['street'] ?? '')) . ';'
            . vcardEscape((string)($c['city'] ?? '')) . ';;'
            . vcardEscape((string)($c['postal_code'] ?? '')) . ';'
            . vcardEscape((string)($c['country'] ?? ''));
    }
    if (!empty($c['website'])) {
        $lines[] = 'URL:' . vcardEscape((string)$c['website']);
    }
    if (!empty($c['birthday']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$c['birthday'])) {
        $lines[] = 'BDAY:' . (string)$c['birthday'];
    }
    if (!empty($c['notes'])) {
        $lines[] = 'NOTE:' . vcardEscape((string)$c['notes']);
    }

    $lines[] = 'END:VCARD';
    return implode("\r\n", $lines) . "\r\n";
}

/**
 * Parst den Inhalt einer .vcf-Datei (oder eines CardDAV PUT-Requests) und gibt ein Array von Kontakt-Datensätzen zurück.
 *
 * @return array<int, array<string, mixed>>
 */
function parseVcardFileContent(string $raw): array
{
    $unfolded = preg_replace("/\r\n[ \t]|\n[ \t]/", '', $raw) ?? $raw;
    $lines    = preg_split("/\r\n|\n|\r/", $unfolded) ?: [];

    $contacts = [];
    $current  = null;

    $decodeVal = static function (string $keyPart, string $valPart): string {
        if (stripos($keyPart, 'ENCODING=QUOTED-PRINTABLE') !== false) {
            $valPart = quoted_printable_decode($valPart);
        }
        $valPart = str_replace(['\n', '\N', '\,', '\;', '\\\\'], ["\n", "\n", ',', ';', '\\'], $valPart);
        return trim($valPart);
    };

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
            continue;
        }
        if (strcasecmp($trimmed, 'BEGIN:VCARD') === 0) {
            $current = [
                'vcard_uid'    => '',
                'first_name'   => '',
                'last_name'    => '',
                'organization' => '',
                'role_title'   => '',
                'email'        => '',
                'phone'        => '',
                'mobile'       => '',
                'street'       => '',
                'postal_code'  => '',
                'city'         => '',
                'country'      => '',
                'website'      => '',
                'birthday'     => null,
                'notes'        => '',
            ];
            continue;
        }
        if (strcasecmp($trimmed, 'END:VCARD') === 0) {
            if ($current !== null) {
                if ($current['first_name'] !== '' || $current['last_name'] !== '' || $current['organization'] !== '' || $current['email'] !== '' || $current['phone'] !== '' || $current['mobile'] !== '') {
                    $contacts[] = $current;
                }
            }
            $current = null;
            continue;
        }
        if ($current === null || !str_contains($trimmed, ':')) {
            continue;
        }

        [$propRaw, $valRaw] = explode(':', $trimmed, 2);
        // Optionale vCard-Gruppenpräfixe (z.B. "item1.EMAIL") abtrennen
        $propBase = $propRaw;
        if (str_contains($propBase, '.')) {
            $dotParts = explode('.', $propBase, 2);
            $propBase = $dotParts[1];
        }
        $propParts = explode(';', strtoupper($propBase));
        $propName  = $propParts[0];

        if ($propName === 'UID') {
            $current['vcard_uid'] = mb_substr($decodeVal($propRaw, $valRaw), 0, 120);
        } elseif ($propName === 'N') {
            $nParts = explode(';', $valRaw);
            $current['last_name']  = $decodeVal($propRaw, $nParts[0] ?? '');
            $current['first_name'] = $decodeVal($propRaw, $nParts[1] ?? '');
        } elseif ($propName === 'FN' && $current['first_name'] === '' && $current['last_name'] === '') {
            $fn = $decodeVal($propRaw, $valRaw);
            $fnParts = preg_split('/\s+/', $fn, 2) ?: [];
            $current['first_name'] = $fnParts[0] ?? '';
            $current['last_name']  = $fnParts[1] ?? '';
        } elseif ($propName === 'ORG') {
            $orgParts = explode(';', $valRaw);
            $current['organization'] = $decodeVal($propRaw, $orgParts[0] ?? '');
        } elseif ($propName === 'TITLE') {
            $current['role_title'] = $decodeVal($propRaw, $valRaw);
        } elseif ($propName === 'EMAIL' && $current['email'] === '') {
            $current['email'] = $decodeVal($propRaw, $valRaw);
        } elseif ($propName === 'TEL') {
            $tel = $decodeVal($propRaw, $valRaw);
            if (stripos($propRaw, 'CELL') !== false || stripos($propRaw, 'MOBILE') !== false || stripos($propRaw, 'IPHONE') !== false) {
                if ($current['mobile'] === '') {
                    $current['mobile'] = $tel;
                }
            } else {
                if ($current['phone'] === '') {
                    $current['phone'] = $tel;
                } elseif ($current['mobile'] === '') {
                    $current['mobile'] = $tel;
                }
            }
        } elseif ($propName === 'ADR' && $current['street'] === '' && $current['city'] === '') {
            $adrParts = explode(';', $valRaw);
            $current['street']      = $decodeVal($propRaw, $adrParts[2] ?? '');
            $current['city']        = $decodeVal($propRaw, $adrParts[3] ?? '');
            $current['postal_code'] = $decodeVal($propRaw, $adrParts[5] ?? '');
            $current['country']     = $decodeVal($propRaw, $adrParts[6] ?? '');
        } elseif ($propName === 'URL' && $current['website'] === '') {
            $current['website'] = $decodeVal($propRaw, $valRaw);
        } elseif ($propName === 'BDAY') {
            $bday = trim($valRaw);
            if (preg_match('/^(\d{4})-?(\d{2})-?(\d{2})/', $bday, $m)) {
                $current['birthday'] = $m[1] . '-' . $m[2] . '-' . $m[3];
            }
        } elseif ($propName === 'NOTE') {
            $current['notes'] = $decodeVal($propRaw, $valRaw);
        }
    }

    return $contacts;
}

/**
 * Lädt alle für einen Benutzer sichtbaren Kontakte (eigene, direkt geteilte und Gruppen-Kontakte).
 *
 * @return array<int, array<string, mixed>>
 */
function getAccessibleContactsForUser(PDO $pdo, int $userId): array
{
    $stmtGrp = $pdo->prepare('SELECT group_id FROM group_members WHERE user_id = :uid');
    $stmtGrp->execute([':uid' => $userId]);
    $groupIds = array_map('intval', $stmtGrp->fetchAll(PDO::FETCH_COLUMN));

    $sql = "SELECT c.*, u.`username` AS `owner_name`, su.`username` AS `shared_username`, g.`name` AS `group_name`
            FROM `contacts` c
            INNER JOIN `users` u ON u.`id` = c.`user_id`
            LEFT JOIN `users` su ON su.`id` = c.`shared_user_id`
            LEFT JOIN `groups` g ON g.`id` = c.`group_id`
            WHERE c.`user_id` = :uid OR c.`shared_user_id` = :suid";

    $params = [
        ':uid'  => $userId,
        ':suid' => $userId,
    ];

    if (!empty($groupIds)) {
        $placeholders = [];
        foreach ($groupIds as $idx => $gid) {
            $ph = ':gid_' . $idx;
            $placeholders[] = $ph;
            $params[$ph] = $gid;
        }
        $sql .= " OR c.`group_id` IN (" . implode(',', $placeholders) . ")";
    }

    $sql .= " ORDER BY c.`is_favorite` DESC, c.`last_name` ASC, c.`first_name` ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Ermittelt die Client-IP-Adresse (DSGVO-konform anonymisiert für Rate-Limiting & Audit-Log).
 */
function getClientIp(): string
{
    $rawIp = trim((string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'));
    if (filter_var($rawIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        $parts = explode('.', $rawIp);
        $parts[3] = 'x';
        return implode('.', $parts);
    }
    if (filter_var($rawIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
        $parts = explode(':', $rawIp);
        return implode(':', array_slice($parts, 0, 3)) . '::x';
    }
    return 'lokal';
}

/**
 * Prüft anhand des Audit-Logs, ob von dieser IP-Adresse in den letzten 5 Minuten
 * zu viele fehlgeschlagene Anmeldeversuche (WebDAV / CardDAV / Login) erfolgten.
 */
function isLoginRateLimited(PDO $pdo, string $clientIp, int $maxFailures = 10): bool
{
    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM `audit_logs`
             WHERE `event_type` = 'login_failed'
               AND `ip_address` = :ip
               AND `created_at` >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)"
        );
        $stmt->execute([':ip' => mb_substr($clientIp, 0, 64)]);
        return ((int)$stmt->fetchColumn()) >= $maxFailures;
    } catch (Throwable) {
        return false;
    }
}

/**
 * Protokolliert einen fehlgeschlagenen Anmeldeversuch für das Rate-Limiting.
 */
function recordLoginFailure(PDO $pdo, string $clientIp, string $username): void
{
    logAuditEvent($pdo, 'login_failed', 'Fehlgeschlagener Anmeldeversuch (WebDAV/CardDAV)', null, $username);
}

/**
 * Kompatibilitäts-Aliase für Modul-Hilfsfunktionen.
 */
function generateQrCodeSvg(string $text, int $size = 180): string
{
    return renderQrCodeSvg($text, $size);
}

function getStorageStats(PDO $pdo, int $userId): array
{
    return getUserStorageStats($pdo, $userId);
}

function ensureFolderHierarchyExist(PDO $pdo, int $userId, string $folderPath): void
{
    registerFolderHierarchy($pdo, $userId, $folderPath);
}

function formatDate(string $dateStr): string
{
    return formatDateTime($dateStr);
}






