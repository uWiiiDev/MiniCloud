<?php
/**
 * MiniCloud – Musik-, Podcast- & Hörbuch-Studio (music.php)
 * - Automatische Audio-Mediathek aller eigenen & mit mir geteilten Audiodateien (.mp3, .m4a, .wav, .ogg, .flac, .aac, .opus)
 * - 100 % lokaler ID3v2/ID3v1-Tag- & Album-Cover-Scanner (Titel, Interpret, Album, Jahr, Dauer, eingebettetes APIC-Cover)
 * - Echtes HTTP-Range-Streaming (206 Partial Content) für sofortiges Spulen auf Smartphone & PC
 * - Web Audio API Live-Frequenz-Visualizer & 4 Klang-Profile (Neutral, Bass-Boost, Klare Stimme/Hörbuch, Brillanz/Pop)
 * - Durchgehende Wiedergabe, Shuffle, Repeat, -15s/+15s Sprung, Geschwindigkeit (0.75x–2.0x), Sleep-Timer & Hörbuch-Gedächtnis
 * - Smartphone-Sperrbildschirm- & Bluetooth-Autoradio-Steuerung (MediaSession API)
 * - Eigene Playlists inkl. Freigabe für Cloud-Benutzer oder per öffentlichem Link & lokalem SVG-QR-Code
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$pdo = getDB();
$appSettings = getAppSettings($pdo);

if (($appSettings['enable_music'] ?? '1') !== '1') {
    if (isLoggedIn()) {
        setFlash('warning', 'Das Modul „Musik- & Hörbuch-Player“ ist durch den Administrator deaktiviert.');
        header('Location: index.php');
    } else {
        header('Location: login.php');
    }
    exit;
}

// Selbstheilende Tabellen für Playlists & Track-Metadaten/Hörbuch-Fortschritt
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `music_playlists` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` INT UNSIGNED NOT NULL,
        `title` VARCHAR(180) NOT NULL,
        `description` TEXT DEFAULT NULL,
        `shared_user_id` INT UNSIGNED DEFAULT NULL,
        `share_token` VARCHAR(64) DEFAULT NULL,
        `password_hash` VARCHAR(255) DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `idx_mp_token` (`share_token`),
        KEY `idx_mp_user` (`user_id`),
        KEY `idx_mp_shared` (`shared_user_id`),
        CONSTRAINT `fk_mp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS `music_playlist_items` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `playlist_id` INT UNSIGNED NOT NULL,
        `file_id` INT UNSIGNED NOT NULL,
        `sort_order` INT NOT NULL DEFAULT 0,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `idx_mpi_unique` (`playlist_id`, `file_id`),
        KEY `idx_mpi_file` (`file_id`),
        CONSTRAINT `fk_mpi_playlist` FOREIGN KEY (`playlist_id`) REFERENCES `music_playlists` (`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_mpi_file` FOREIGN KEY (`file_id`) REFERENCES `files` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS `music_track_meta` (
        `file_id` INT UNSIGNED NOT NULL,
        `user_id` INT UNSIGNED NOT NULL,
        `is_favorite` TINYINT(1) NOT NULL DEFAULT 0,
        `last_position_sec` INT UNSIGNED NOT NULL DEFAULT 0,
        `play_count` INT UNSIGNED NOT NULL DEFAULT 0,
        `cached_title` VARCHAR(255) DEFAULT NULL,
        `cached_artist` VARCHAR(255) DEFAULT NULL,
        `cached_album` VARCHAR(255) DEFAULT NULL,
        `cached_year` VARCHAR(16) DEFAULT NULL,
        `cached_duration_sec` INT UNSIGNED NOT NULL DEFAULT 0,
        `has_cover` TINYINT(1) NOT NULL DEFAULT 0,
        `meta_parsed` TINYINT(1) NOT NULL DEFAULT 0,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`file_id`, `user_id`),
        KEY `idx_mtm_user` (`user_id`),
        CONSTRAINT `fk_mtm_file` FOREIGN KEY (`file_id`) REFERENCES `files` (`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_mtm_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$playlistToken = isset($_GET['playlist_token']) && is_string($_GET['playlist_token']) ? trim($_GET['playlist_token']) : '';
$isGuestMode   = ($playlistToken !== '' && !isLoggedIn());

if ($playlistToken === '' && !isLoggedIn()) {
    requireLogin();
}

$userId   = isLoggedIn() ? (int)$_SESSION['user_id'] : 0;
$username = isLoggedIn() ? (string)($_SESSION['username'] ?? '') : '';

/**
 * Prüft, ob ein Benutzer oder Gast Zugriff auf eine Playlist hat.
 */
function canAccessMusicPlaylist(array $playlist, int $userId, string $token): bool
{
    if ($token !== '' && !empty($playlist['share_token']) && hash_equals((string)$playlist['share_token'], $token)) {
        return true;
    }
    if ($userId <= 0) {
        return false;
    }
    return (int)$playlist['user_id'] === $userId || (int)($playlist['shared_user_id'] ?? 0) === $userId;
}

/**
 * Prüft, ob eine Audiodatei vom aktuellen Benutzer oder über ein Playlist-Token abgespielt werden darf.
 */
function canPlayAudioFile(PDO $pdo, array $fileRow, int $userId, string $playlistToken): bool
{
    if (!empty($fileRow['deleted_at']) || (int)($fileRow['is_encrypted'] ?? 0) === 1) {
        return false;
    }
    $fid = (int)$fileRow['id'];

    if ($playlistToken !== '') {
        $stmt = $pdo->prepare(
            'SELECT mp.id, mp.password_hash
             FROM music_playlists mp
             INNER JOIN music_playlist_items mpi ON mpi.playlist_id = mp.id
             WHERE mp.share_token = :tok AND mpi.file_id = :fid
             LIMIT 1'
        );
        $stmt->execute([':tok' => $playlistToken, ':fid' => $fid]);
        $pl = $stmt->fetch();
        if ($pl) {
            if (!empty($pl['password_hash'])) {
                $unlocked = $_SESSION['unlocked_music_playlists'] ?? [];
                return !empty($unlocked[(int)$pl['id']]);
            }
            return true;
        }
    }

    if ($userId <= 0) {
        return false;
    }
    if ((int)$fileRow['user_id'] === $userId) {
        return true;
    }

    // Direktfreigabe oder Ordner-Freigabe prüfen
    $stmtShare = $pdo->prepare(
        'SELECT 1 FROM user_shares us
         WHERE us.shared_with_user_id = :uid
           AND (
               us.file_id = :fid
               OR (us.folder_path IS NOT NULL AND us.owner_id = :owner_id AND (:fp = us.folder_path OR :fp2 LIKE CONCAT(us.folder_path, "/%")))
           )
         LIMIT 1'
    );
    $fp = (string)($fileRow['folder_path'] ?? '');
    $stmtShare->execute([
        ':uid'      => $userId,
        ':fid'      => $fid,
        ':owner_id' => (int)$fileRow['user_id'],
        ':fp'       => $fp,
        ':fp2'      => $fp,
    ]);
    if ($stmtShare->fetchColumn()) {
        return true;
    }

    // Über eine geteilte Playlist sichtbar?
    $stmtPl = $pdo->prepare(
        'SELECT mp.* FROM music_playlists mp
         INNER JOIN music_playlist_items mpi ON mpi.playlist_id = mp.id
         WHERE mpi.file_id = :fid'
    );
    $stmtPl->execute([':fid' => $fid]);
    foreach ($stmtPl->fetchAll() as $plRow) {
        if (canAccessMusicPlaylist($plRow, $userId, '')) {
            return true;
        }
    }

    return false;
}

/**
 * Dekodiert einen ID3v2-Text-Frame (UTF-8, UTF-16LE/BE oder ISO-8859-1).
 */
function decodeId3TextFrame(string $payload): string
{
    if ($payload === '') {
        return '';
    }
    $encoding = ord($payload[0]);
    $text     = substr($payload, 1);
    if ($text === '') {
        return '';
    }
    if ($encoding === 0) {
        // ISO-8859-1 / Windows-1252
        $out = @mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
    } elseif ($encoding === 1) {
        // UTF-16 mit BOM
        $out = @mb_convert_encoding($text, 'UTF-8', 'UTF-16');
    } elseif ($encoding === 2) {
        // UTF-16BE ohne BOM
        $out = @mb_convert_encoding($text, 'UTF-8', 'UTF-16BE');
    } else {
        // UTF-8
        $out = $text;
    }
    $clean = trim(str_replace("\0", '', (string)$out));
    return preg_replace('/[\x00-\x1F\x7F]/u', '', $clean) ?: '';
}

/**
 * Liest ID3v2.3 / ID3v2.4 & ID3v1-Tags sowie optional das eingebettete Album-Cover (APIC)
 * 100 % lokal in reinem PHP aus einer Audiodatei aus.
 *
 * @return array{title:string,artist:string,album:string,year:string,duration:int,has_cover:int,cover_mime:string,cover_data:string}
 */
function parseLocalAudioMetadata(string $filePath, bool $extractCoverBytes = false): array
{
    $res = [
        'title'      => '',
        'artist'     => '',
        'album'      => '',
        'year'       => '',
        'duration'   => 0,
        'has_cover'  => 0,
        'cover_mime' => '',
        'cover_data' => '',
    ];

    if (!is_file($filePath) || !is_readable($filePath)) {
        return $res;
    }

    $fileSize = (int)@filesize($filePath);
    if ($fileSize < 128) {
        return $res;
    }

    $fp = @fopen($filePath, 'rb');
    if (!$fp) {
        return $res;
    }

    $header = (string)fread($fp, 10);
    $id3v2Size = 0;

    if (strlen($header) === 10 && substr($header, 0, 3) === 'ID3') {
        $versionMajor = ord($header[3]);
        $flags        = ord($header[5]);
        // Synchsafe 28-bit Integer
        $id3v2Size = ((ord($header[6]) & 0x7F) << 21)
                   | ((ord($header[7]) & 0x7F) << 14)
                   | ((ord($header[8]) & 0x7F) << 7)
                   | (ord($header[9]) & 0x7F);

        $readLimit = min($id3v2Size, $extractCoverBytes ? 2097152 : 524288);
        if ($readLimit > 0 && ($versionMajor === 3 || $versionMajor === 4)) {
            $tagData = (string)fread($fp, $readLimit);
            $pos     = 0;
            $len     = strlen($tagData);

            // Extended Header überspringen, falls vorhanden
            if (($flags & 0x40) !== 0 && $len >= 4) {
                $extSize = ($versionMajor === 4)
                    ? (((ord($tagData[0]) & 0x7F) << 21) | ((ord($tagData[1]) & 0x7F) << 14) | ((ord($tagData[2]) & 0x7F) << 7) | (ord($tagData[3]) & 0x7F))
                    : ((ord($tagData[0]) << 24) | (ord($tagData[1]) << 16) | (ord($tagData[2]) << 8) | ord($tagData[3]));
                $pos += max(4, $extSize);
            }

            while ($pos + 10 <= $len) {
                $frameId = substr($tagData, $pos, 4);
                if (!preg_match('/^[A-Z0-9]{4}$/', $frameId)) {
                    break;
                }
                if ($versionMajor === 4) {
                    $frameSize = ((ord($tagData[$pos + 4]) & 0x7F) << 21)
                               | ((ord($tagData[$pos + 5]) & 0x7F) << 14)
                               | ((ord($tagData[$pos + 6]) & 0x7F) << 7)
                               | (ord($tagData[$pos + 7]) & 0x7F);
                } else {
                    $frameSize = (ord($tagData[$pos + 4]) << 24)
                               | (ord($tagData[$pos + 5]) << 16)
                               | (ord($tagData[$pos + 6]) << 8)
                               | ord($tagData[$pos + 7]);
                }

                $pos += 10;
                if ($frameSize <= 0 || $pos + $frameSize > $len) {
                    break;
                }

                $payload = substr($tagData, $pos, $frameSize);
                $pos    += $frameSize;

                if ($frameId === 'TIT2' && $res['title'] === '') {
                    $res['title'] = mb_substr(decodeId3TextFrame($payload), 0, 250);
                } elseif ($frameId === 'TPE1' && $res['artist'] === '') {
                    $res['artist'] = mb_substr(decodeId3TextFrame($payload), 0, 250);
                } elseif ($frameId === 'TALB' && $res['album'] === '') {
                    $res['album'] = mb_substr(decodeId3TextFrame($payload), 0, 250);
                } elseif (($frameId === 'TYER' || $frameId === 'TDRC') && $res['year'] === '') {
                    $yr = decodeId3TextFrame($payload);
                    if (preg_match('/(\d{4})/', $yr, $m)) {
                        $res['year'] = $m[1];
                    }
                } elseif ($frameId === 'APIC' && strlen($payload) > 32) {
                    // Eingebettetes Album-Cover (JPEG oder PNG) suchen
                    $jpegPos = strpos($payload, "\xFF\xD8\xFF");
                    $pngPos  = strpos($payload, "\x89PNG\r\n\x1A\n");
                    if ($jpegPos !== false && ($pngPos === false || $jpegPos < $pngPos)) {
                        $res['has_cover']  = 1;
                        $res['cover_mime'] = 'image/jpeg';
                        if ($extractCoverBytes) {
                            $res['cover_data'] = substr($payload, $jpegPos);
                        }
                    } elseif ($pngPos !== false) {
                        $res['has_cover']  = 1;
                        $res['cover_mime'] = 'image/png';
                        if ($extractCoverBytes) {
                            $res['cover_data'] = substr($payload, $pngPos);
                        }
                    }
                }
            }
        }
    }

    // Fallback: ID3v1 am Dateiende (letzte 128 Bytes) prüfen
    if (($res['title'] === '' || $res['artist'] === '') && $fileSize > 128) {
        if (@fseek($fp, -128, SEEK_END) === 0) {
            $v1 = (string)fread($fp, 128);
            if (strlen($v1) === 128 && substr($v1, 0, 3) === 'TAG') {
                if ($res['title'] === '') {
                    $res['title'] = trim((string)@mb_convert_encoding(substr($v1, 3, 30), 'UTF-8', 'Windows-1252'));
                }
                if ($res['artist'] === '') {
                    $res['artist'] = trim((string)@mb_convert_encoding(substr($v1, 33, 30), 'UTF-8', 'Windows-1252'));
                }
                if ($res['album'] === '') {
                    $res['album'] = trim((string)@mb_convert_encoding(substr($v1, 63, 30), 'UTF-8', 'Windows-1252'));
                }
                if ($res['year'] === '') {
                    $yr = trim(substr($v1, 93, 4));
                    if (preg_match('/^\d{4}$/', $yr)) {
                        $res['year'] = $yr;
                    }
                }
            }
        }
    }

    // MP3-Frame-Header für Dauer-Schätzung (anhand der Bitrate des ersten MPEG-Frames nach ID3v2)
    $audioOffset = $id3v2Size > 0 ? ($id3v2Size + 10) : 0;
    if ($audioOffset + 4096 < $fileSize && @fseek($fp, $audioOffset, SEEK_SET) === 0) {
        $probe = (string)fread($fp, 4096);
        $pLen  = strlen($probe);
        $bitratesMpeg1L3 = [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320, 0];
        for ($i = 0; $i < $pLen - 4; $i++) {
            if (ord($probe[$i]) === 0xFF && (ord($probe[$i + 1]) & 0xE0) === 0xE0) {
                $b1 = ord($probe[$i + 1]);
                $b2 = ord($probe[$i + 2]);
                $mpegVer = ($b1 >> 3) & 0x03; // 3 = MPEG1
                $layer   = ($b1 >> 1) & 0x03; // 1 = Layer III
                $brIdx   = ($b2 >> 4) & 0x0F;
                if ($mpegVer === 3 && $layer === 1 && isset($bitratesMpeg1L3[$brIdx]) && $bitratesMpeg1L3[$brIdx] > 0) {
                    $kbps = $bitratesMpeg1L3[$brIdx];
                    $audioBytes = max(0, $fileSize - $audioOffset);
                    $res['duration'] = (int)round(($audioBytes * 8) / ($kbps * 1000));
                    break;
                }
            }
        }
    }

    fclose($fp);
    return $res;
}

// 1. Eingebettetes Album-Cover ausliefern (?cover_file=...)
if (isset($_GET['cover_file'])) {
    $coverId  = (int)$_GET['cover_file'];
    $stmtFile = $pdo->prepare('SELECT * FROM files WHERE id = :id LIMIT 1');
    $stmtFile->execute([':id' => $coverId]);
    $fileRow = $stmtFile->fetch();

    if (!$fileRow || !canPlayAudioFile($pdo, $fileRow, $userId, $playlistToken)) {
        http_response_code(403);
        exit;
    }

    $physicalPath = UPLOAD_DIR . basename((string)$fileRow['filename']);
    $parsed       = parseLocalAudioMetadata($physicalPath, true);
    if ($parsed['has_cover'] === 1 && $parsed['cover_data'] !== '') {
        header('Content-Type: ' . ($parsed['cover_mime'] ?: 'image/jpeg'));
        header('Content-Length: ' . (string)strlen($parsed['cover_data']));
        header('Cache-Control: private, max-age=86400');
        header('X-Content-Type-Options: nosniff');
        echo $parsed['cover_data'];
        exit;
    }
    http_response_code(404);
    exit;
}

// 2. HTTP-Range Audio-Streaming-Endpunkt (?stream_file=...)
if (isset($_GET['stream_file'])) {
    $streamId = (int)$_GET['stream_file'];
    $stmtFile = $pdo->prepare('SELECT * FROM files WHERE id = :id LIMIT 1');
    $stmtFile->execute([':id' => $streamId]);
    $fileRow = $stmtFile->fetch();

    if (!$fileRow || !canPlayAudioFile($pdo, $fileRow, $userId, $playlistToken)) {
        http_response_code(403);
        exit('Kein Zugriff auf diese Audiodatei.');
    }

    $physicalPath = UPLOAD_DIR . basename((string)$fileRow['filename']);
    if (!is_file($physicalPath)) {
        http_response_code(404);
        exit('Audiodatei nicht gefunden.');
    }

    $ext = strtolower(pathinfo((string)$fileRow['original_name'], PATHINFO_EXTENSION));
    $mimeMap = [
        'mp3'  => 'audio/mpeg',
        'm4a'  => 'audio/mp4',
        'aac'  => 'audio/aac',
        'wav'  => 'audio/wav',
        'ogg'  => 'audio/ogg',
        'opus' => 'audio/ogg',
        'flac' => 'audio/flac',
        'webm' => 'audio/webm',
    ];
    $contentType = $mimeMap[$ext] ?? 'audio/mpeg';
    $fileSize    = (int)filesize($physicalPath);

    if (isset($_GET['download']) && $_GET['download'] === '1') {
        $safeDownloadName = str_replace(['"', "\r", "\n"], '', (string)$fileRow['original_name']);
        header('Content-Type: ' . $contentType);
        header('Content-Length: ' . (string)$fileSize);
        header('Content-Disposition: attachment; filename="' . $safeDownloadName . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($physicalPath);
        exit;
    }

    $start = 0;
    $end   = $fileSize - 1;
    $isPartial = false;

    if (!empty($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/i', (string)$_SERVER['HTTP_RANGE'], $matches)) {
        $isPartial = true;
        if ($matches[1] !== '') {
            $start = (int)$matches[1];
        }
        if ($matches[2] !== '') {
            $end = (int)$matches[2];
        }
        if ($start > $end || $start >= $fileSize) {
            http_response_code(416);
            header("Content-Range: bytes */$fileSize");
            exit;
        }
        $end = min($end, $fileSize - 1);
    }

    $length = $end - $start + 1;
    if ($isPartial) {
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$fileSize");
    } else {
        http_response_code(200);
    }

    header('Content-Type: ' . $contentType);
    header('Accept-Ranges: bytes');
    header('Content-Length: ' . (string)$length);
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');

    $fp = fopen($physicalPath, 'rb');
    if ($fp) {
        if ($start > 0) {
            fseek($fp, $start);
        }
        $remaining = $length;
        while ($remaining > 0 && !feof($fp)) {
            $chunk = fread($fp, min(65536, $remaining));
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk;
            $remaining -= strlen($chunk);
            if (connection_aborted()) {
                break;
            }
        }
        fclose($fp);
    }
    exit;
}

// 3. POST-Aktionen (Favorit, Hörbuch-Fortschritt, Direkt-Upload, Playlists)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    $action    = (string)($_POST['action'] ?? '');

    if (!verifyCsrfToken(is_string($csrfToken) ? $csrfToken : null)) {
        if (in_array($action, ['toggle_favorite', 'save_progress'], true)) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => false, 'error' => 'Ungültiges CSRF-Token']);
            exit;
        }
        setFlash('error', 'Ungültiges Sicherheits-Token (CSRF). Bitte Seite neu laden.');
        header('Location: music.php' . ($playlistToken !== '' ? '?playlist_token=' . urlencode($playlistToken) : ''));
        exit;
    }

    // Passwortgeschützte Gast-Playlist entsperren
    if ($action === 'unlock_playlist') {
        $plId = (int)($_POST['playlist_id'] ?? 0);
        $pass = (string)($_POST['playlist_password'] ?? '');
        $stmtP = $pdo->prepare('SELECT id, password_hash, share_token FROM music_playlists WHERE id = :id LIMIT 1');
        $stmtP->execute([':id' => $plId]);
        $plRow = $stmtP->fetch();

        if ($plRow && !empty($plRow['password_hash']) && password_verify($pass, (string)$plRow['password_hash'])) {
            $_SESSION['unlocked_music_playlists'][(int)$plRow['id']] = true;
            setFlash('success', 'Playlist erfolgreich entsperrt.');
        } else {
            setFlash('error', 'Das eingegebene Passwort ist leider falsch.');
        }
        header('Location: music.php?playlist_token=' . urlencode((string)($plRow['share_token'] ?? $playlistToken)));
        exit;
    }

    requireLogin();

    // AJAX: Favorit umschalten (❤️)
    if ($action === 'toggle_favorite') {
        header('Content-Type: application/json; charset=UTF-8');
        $fid = (int)($_POST['file_id'] ?? 0);
        $stmtCur = $pdo->prepare('SELECT is_favorite FROM music_track_meta WHERE file_id = :fid AND user_id = :uid LIMIT 1');
        $stmtCur->execute([':fid' => $fid, ':uid' => $userId]);
        $cur = (int)$stmtCur->fetchColumn();
        $newVal = $cur === 1 ? 0 : 1;

        $pdo->prepare(
            'INSERT INTO music_track_meta (file_id, user_id, is_favorite)
             VALUES (:fid, :uid, :fav)
             ON DUPLICATE KEY UPDATE is_favorite = :fav2'
        )->execute([':fid' => $fid, ':uid' => $userId, ':fav' => $newVal, ':fav2' => $newVal]);

        echo json_encode(['ok' => true, 'is_favorite' => $newVal === 1]);
        exit;
    }

    // AJAX: Hörbuch-/Wiedergabeposition & Play-Counter speichern
    if ($action === 'save_progress') {
        header('Content-Type: application/json; charset=UTF-8');
        $fid     = (int)($_POST['file_id'] ?? 0);
        $posSec  = max(0, (int)($_POST['position_sec'] ?? 0));
        $incPlay = !empty($_POST['inc_play']) ? 1 : 0;

        if ($fid > 0) {
            $pdo->prepare(
                'INSERT INTO music_track_meta (file_id, user_id, last_position_sec, play_count)
                 VALUES (:fid, :uid, :pos, :inc)
                 ON DUPLICATE KEY UPDATE
                    last_position_sec = :pos2,
                    play_count = play_count + :inc2'
            )->execute([
                ':fid'  => $fid,
                ':uid'  => $userId,
                ':pos'  => $posSec,
                ':inc'  => $incPlay,
                ':pos2' => $posSec,
                ':inc2' => $incPlay,
            ]);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    // Direkt-Upload von Audiodateien in den Ordner "Musik"
    if ($action === 'upload_audio') {
        $targetPlaylistId = (int)($_POST['playlist_id'] ?? 0);
        $safeFolder = 'Musik';

        $stmtChkFolder = $pdo->prepare('SELECT id FROM user_folders WHERE user_id = :uid AND folder_path = :fp AND group_folder_id IS NULL LIMIT 1');
        $stmtChkFolder->execute([':uid' => $userId, ':fp' => $safeFolder]);
        if (!$stmtChkFolder->fetchColumn()) {
            $pdo->prepare('INSERT INTO user_folders (user_id, folder_path) VALUES (:uid, :fp)')
                ->execute([':uid' => $userId, ':fp' => $safeFolder]);
        }

        $uploadedCount = 0;
        if (!empty($_FILES['audio_files']['name']) && is_array($_FILES['audio_files']['name'])) {
            $fileCount   = count($_FILES['audio_files']['name']);
            $allowedExts = ['mp3', 'm4a', 'wav', 'ogg', 'flac', 'aac', 'opus'];

            for ($i = 0; $i < $fileCount; $i++) {
                if (($_FILES['audio_files']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    continue;
                }
                $tmpPath  = (string)$_FILES['audio_files']['tmp_name'][$i];
                $origName = basename((string)$_FILES['audio_files']['name'][$i]);
                $fileSize = (int)$_FILES['audio_files']['size'][$i];
                $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

                if (!in_array($ext, $allowedExts, true) || $fileSize <= 0 || $fileSize > MAX_FILE_SIZE) {
                    continue;
                }
                if (!hasAvailableQuota($pdo, $userId, $fileSize)) {
                    setFlash('warning', 'Dein Cloud-Speicherkontingent ist ausgeschöpft.');
                    break;
                }

                $storedName = generateSafeStoredFilename();
                if (!is_dir(UPLOAD_DIR)) {
                    mkdir(UPLOAD_DIR, 0750, true);
                }
                $destPath = UPLOAD_DIR . $storedName;

                if (move_uploaded_file($tmpPath, $destPath)) {
                    @chmod($destPath, 0640);
                    $mimeMap = [
                        'mp3'  => 'audio/mpeg',
                        'm4a'  => 'audio/mp4',
                        'aac'  => 'audio/aac',
                        'wav'  => 'audio/wav',
                        'ogg'  => 'audio/ogg',
                        'opus' => 'audio/ogg',
                        'flac' => 'audio/flac',
                    ];
                    $mimeType = $mimeMap[$ext] ?? 'audio/mpeg';

                    $stmtIns = $pdo->prepare(
                        'INSERT INTO files (user_id, folder_path, filename, original_name, file_size, mime_type)
                         VALUES (:uid, :fp, :fn, :on, :fs, :mt)'
                    );
                    $stmtIns->execute([
                        ':uid' => $userId,
                        ':fp'  => $safeFolder,
                        ':fn'  => $storedName,
                        ':on'  => $origName,
                        ':fs'  => $fileSize,
                        ':mt'  => $mimeType,
                    ]);
                    $newFileId = (int)$pdo->lastInsertId();

                    if ($targetPlaylistId > 0) {
                        $stmtPl = $pdo->prepare('SELECT id FROM music_playlists WHERE id = :id AND (user_id = :uid OR shared_user_id = :uid2) LIMIT 1');
                        $stmtPl->execute([':id' => $targetPlaylistId, ':uid' => $userId, ':uid2' => $userId]);
                        if ($stmtPl->fetchColumn()) {
                            $pdo->prepare('INSERT IGNORE INTO music_playlist_items (playlist_id, file_id) VALUES (:pid, :fid)')
                                ->execute([':pid' => $targetPlaylistId, ':fid' => $newFileId]);
                        }
                    }
                    $uploadedCount++;
                }
            }
        }

        if ($uploadedCount > 0) {
            setFlash('success', $uploadedCount . ' Audiodatei(en) erfolgreich in deinen Ordner „Musik/“ hochgeladen!');
        } else {
            setFlash('warning', 'Bitte wähle gültige Audiodateien (.mp3, .m4a, .wav, .ogg, .flac, .aac, .opus) aus.');
        }
        header('Location: music.php' . ($targetPlaylistId > 0 ? '?playlist=' . $targetPlaylistId : ''));
        exit;
    }

    // Playlist erstellen oder bearbeiten
    if ($action === 'save_playlist') {
        $playlistId       = (int)($_POST['playlist_id'] ?? 0);
        $title            = trim((string)($_POST['title'] ?? ''));
        $description      = trim((string)($_POST['description'] ?? ''));
        $sharedUserId     = (int)($_POST['shared_user_id'] ?? 0) ?: null;
        $enablePublicLink = !empty($_POST['enable_public_link']);
        $newPassword      = trim((string)($_POST['playlist_password'] ?? ''));
        $clearPassword    = !empty($_POST['clear_password']);

        if ($title === '') {
            setFlash('error', 'Bitte gib einen Namen für die Playlist ein.');
            header('Location: music.php');
            exit;
        }

        if ($playlistId > 0) {
            $stmtChk = $pdo->prepare('SELECT * FROM music_playlists WHERE id = :id AND user_id = :uid LIMIT 1');
            $stmtChk->execute([':id' => $playlistId, ':uid' => $userId]);
            $existing = $stmtChk->fetch();
            if (!$existing) {
                setFlash('error', 'Playlist nicht gefunden oder keine Berechtigung.');
                header('Location: music.php');
                exit;
            }

            $shareToken = $existing['share_token'];
            if ($enablePublicLink && empty($shareToken)) {
                $shareToken = bin2hex(random_bytes(20));
            } elseif (!$enablePublicLink) {
                $shareToken = null;
            }

            $passwordHash = $existing['password_hash'];
            if ($clearPassword) {
                $passwordHash = null;
            } elseif ($newPassword !== '') {
                $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            }

            $pdo->prepare(
                'UPDATE music_playlists
                 SET title = :t, description = :d, shared_user_id = :su, share_token = :tok, password_hash = :pw
                 WHERE id = :id AND user_id = :uid'
            )->execute([
                ':t'   => mb_substr($title, 0, 180),
                ':d'   => $description !== '' ? $description : null,
                ':su'  => $sharedUserId,
                ':tok' => $shareToken,
                ':pw'  => $passwordHash,
                ':id'  => $playlistId,
                ':uid' => $userId,
            ]);
            setFlash('success', 'Playlist „' . $title . '“ wurde aktualisiert.');
            header('Location: music.php?playlist=' . $playlistId);
            exit;
        } else {
            $shareToken   = $enablePublicLink ? bin2hex(random_bytes(20)) : null;
            $passwordHash = $newPassword !== '' ? password_hash($newPassword, PASSWORD_DEFAULT) : null;

            $pdo->prepare(
                'INSERT INTO music_playlists (user_id, title, description, shared_user_id, share_token, password_hash)
                 VALUES (:uid, :t, :d, :su, :tok, :pw)'
            )->execute([
                ':uid' => $userId,
                ':t'   => mb_substr($title, 0, 180),
                ':d'   => $description !== '' ? $description : null,
                ':su'  => $sharedUserId,
                ':tok' => $shareToken,
                ':pw'  => $passwordHash,
            ]);
            $newId = (int)$pdo->lastInsertId();
            setFlash('success', 'Neue Playlist „' . $title . '“ wurde erstellt.');
            header('Location: music.php?playlist=' . $newId);
            exit;
        }
    }

    // Vorhandene Cloud-Audiodateien einer Playlist zuordnen
    if ($action === 'add_to_playlist') {
        $playlistId = (int)($_POST['playlist_id'] ?? 0);
        $fileIds    = isset($_POST['file_ids']) && is_array($_POST['file_ids']) ? array_map('intval', $_POST['file_ids']) : [];

        $stmtPl = $pdo->prepare('SELECT * FROM music_playlists WHERE id = :id LIMIT 1');
        $stmtPl->execute([':id' => $playlistId]);
        $pl = $stmtPl->fetch();

        if (!$pl || !canAccessMusicPlaylist($pl, $userId, '')) {
            setFlash('error', 'Keine Berechtigung für diese Playlist.');
            header('Location: music.php');
            exit;
        }

        $added = 0;
        $stmtIns = $pdo->prepare('INSERT IGNORE INTO music_playlist_items (playlist_id, file_id) VALUES (:pid, :fid)');
        foreach ($fileIds as $fid) {
            if ($fid <= 0) {
                continue;
            }
            $stmtF = $pdo->prepare('SELECT * FROM files WHERE id = :id LIMIT 1');
            $stmtF->execute([':id' => $fid]);
            $fRow = $stmtF->fetch();
            if ($fRow && canPlayAudioFile($pdo, $fRow, $userId, '')) {
                $stmtIns->execute([':pid' => $playlistId, ':fid' => $fid]);
                if ($stmtIns->rowCount() > 0) {
                    $added++;
                }
            }
        }
        setFlash('success', $added . ' Titel zur Playlist „' . $pl['title'] . '“ hinzugefügt.');
        header('Location: music.php?playlist=' . $playlistId);
        exit;
    }

    // Titel aus Playlist entfernen
    if ($action === 'remove_from_playlist') {
        $playlistId = (int)($_POST['playlist_id'] ?? 0);
        $fileId     = (int)($_POST['file_id'] ?? 0);
        $stmtPl = $pdo->prepare('SELECT * FROM music_playlists WHERE id = :id LIMIT 1');
        $stmtPl->execute([':id' => $playlistId]);
        $pl = $stmtPl->fetch();
        if ($pl && canAccessMusicPlaylist($pl, $userId, '')) {
            $pdo->prepare('DELETE FROM music_playlist_items WHERE playlist_id = :pid AND file_id = :fid')
                ->execute([':pid' => $playlistId, ':fid' => $fileId]);
            setFlash('success', 'Titel aus der Playlist entfernt (Originaldatei bleibt erhalten).');
        }
        header('Location: music.php?playlist=' . $playlistId);
        exit;
    }

    // Playlist löschen
    if ($action === 'delete_playlist') {
        $playlistId = (int)($_POST['playlist_id'] ?? 0);
        $pdo->prepare('DELETE FROM music_playlists WHERE id = :id AND user_id = :uid')
            ->execute([':id' => $playlistId, ':uid' => $userId]);
        setFlash('success', 'Die Playlist wurde gelöscht (alle Audiodateien bleiben in deiner Cloud erhalten).');
        header('Location: music.php');
        exit;
    }
}

// ============================================================================
// DATEN FÜR DIE MUSIK- & HÖRBUCH-MEDIATHEK LADEN
// ============================================================================
$activePlaylistId = isset($_GET['playlist']) ? (int)$_GET['playlist'] : 0;
$autoPlayFileId   = isset($_GET['play']) ? (int)$_GET['play'] : 0;
$viewFilter       = isset($_GET['filter']) && in_array($_GET['filter'], ['all', 'favorites', 'resume', 'albums'], true) ? $_GET['filter'] : 'all';
$folderFilter     = isset($_GET['folder']) && is_string($_GET['folder']) ? trim($_GET['folder']) : '';
$searchQuery      = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';

$playlists      = [];
$activePlaylist = null;

if ($playlistToken !== '') {
    $stmtPl = $pdo->prepare(
        'SELECT mp.*, u.username AS owner_name,
                (SELECT COUNT(*) FROM music_playlist_items mpi WHERE mpi.playlist_id = mp.id) AS track_count
         FROM music_playlists mp
         INNER JOIN users u ON u.id = mp.user_id
         WHERE mp.share_token = :tok
         LIMIT 1'
    );
    $stmtPl->execute([':tok' => $playlistToken]);
    $activePlaylist = $stmtPl->fetch();
    if (!$activePlaylist) {
        http_response_code(404);
        exit('Diese Playlist existiert nicht oder die Freigabe wurde beendet.');
    }
    $activePlaylistId = (int)$activePlaylist['id'];
} else {
    $stmtPl = $pdo->prepare(
        'SELECT mp.*, u.username AS owner_name, su.username AS shared_username,
                (SELECT COUNT(*) FROM music_playlist_items mpi WHERE mpi.playlist_id = mp.id) AS track_count
         FROM music_playlists mp
         INNER JOIN users u ON u.id = mp.user_id
         LEFT JOIN users su ON su.id = mp.shared_user_id
         WHERE mp.user_id = :uid1 OR mp.shared_user_id = :uid2
         ORDER BY mp.id DESC'
    );
    $stmtPl->execute([':uid1' => $userId, ':uid2' => $userId]);
    $playlists = $stmtPl->fetchAll();

    if ($activePlaylistId > 0) {
        foreach ($playlists as $pl) {
            if ((int)$pl['id'] === $activePlaylistId) {
                $activePlaylist = $pl;
                break;
            }
        }
    }
}

$isPlaylistLocked = false;
if ($activePlaylist && !empty($activePlaylist['password_hash']) && $userId !== (int)$activePlaylist['user_id']) {
    $unlocked = $_SESSION['unlocked_music_playlists'] ?? [];
    if (empty($unlocked[(int)$activePlaylist['id']])) {
        $isPlaylistLocked = true;
    }
}

// Alle zugänglichen Audiodateien laden (eigene + direkt mit mir geteilte)
$allAudioFiles = [];
$audioFolders  = [];

if ($userId > 0) {
    $stmtAudio = $pdo->prepare(
        "SELECT f.*, u.username AS owner_name,
                mtm.is_favorite, mtm.last_position_sec, mtm.play_count,
                mtm.cached_title, mtm.cached_artist, mtm.cached_album, mtm.cached_year,
                mtm.cached_duration_sec, mtm.has_cover, mtm.meta_parsed
         FROM files f
         INNER JOIN users u ON u.id = f.user_id
         LEFT JOIN music_track_meta mtm ON mtm.file_id = f.id AND mtm.user_id = :uid_meta
         WHERE f.deleted_at IS NULL
           AND f.is_encrypted = 0
           AND f.group_folder_id IS NULL
           AND (
               LOWER(f.original_name) REGEXP '\\\\.(mp3|m4a|wav|ogg|flac|aac|opus)$'
               OR f.mime_type LIKE 'audio/%'
           )
           AND (
               f.user_id = :uid_own
               OR EXISTS (
                   SELECT 1 FROM user_shares us
                   WHERE us.shared_with_user_id = :uid_share
                     AND (
                         us.file_id = f.id
                         OR (us.folder_path IS NOT NULL AND us.owner_id = f.user_id AND (f.folder_path = us.folder_path OR f.folder_path LIKE CONCAT(us.folder_path, '/%')))
                     )
               )
           )
         ORDER BY f.upload_date DESC, f.id DESC"
    );
    $stmtAudio->execute([
        ':uid_meta'  => $userId,
        ':uid_own'   => $userId,
        ':uid_share' => $userId,
    ]);
    $rawAudio = $stmtAudio->fetchAll();

    $stmtCacheMeta = $pdo->prepare(
        'INSERT INTO music_track_meta
            (file_id, user_id, cached_title, cached_artist, cached_album, cached_year, cached_duration_sec, has_cover, meta_parsed)
         VALUES (:fid, :uid, :t, :ar, :al, :yr, :dur, :cov, 1)
         ON DUPLICATE KEY UPDATE
            cached_title = :t2, cached_artist = :ar2, cached_album = :al2,
            cached_year = :yr2, cached_duration_sec = :dur2, has_cover = :cov2, meta_parsed = 1'
    );

    foreach ($rawAudio as $row) {
        $fid = (int)$row['id'];
        if ((int)($row['meta_parsed'] ?? 0) !== 1) {
            $phys = UPLOAD_DIR . basename((string)$row['filename']);
            $id3  = parseLocalAudioMetadata($phys, false);
            $row['cached_title']        = $id3['title'];
            $row['cached_artist']       = $id3['artist'];
            $row['cached_album']        = $id3['album'];
            $row['cached_year']         = $id3['year'];
            $row['cached_duration_sec'] = $id3['duration'];
            $row['has_cover']           = $id3['has_cover'];
            try {
                $stmtCacheMeta->execute([
                    ':fid'  => $fid,
                    ':uid'  => $userId,
                    ':t'    => $id3['title'] ?: null,
                    ':ar'   => $id3['artist'] ?: null,
                    ':al'   => $id3['album'] ?: null,
                    ':yr'   => $id3['year'] ?: null,
                    ':dur'  => $id3['duration'],
                    ':cov'  => $id3['has_cover'],
                    ':t2'   => $id3['title'] ?: null,
                    ':ar2'  => $id3['artist'] ?: null,
                    ':al2'  => $id3['album'] ?: null,
                    ':yr2'  => $id3['year'] ?: null,
                    ':dur2' => $id3['duration'],
                    ':cov2' => $id3['has_cover'],
                ]);
            } catch (Throwable) {}
        }

        // Falls Dateiname "Interpret - Titel.mp3" enthält und kein ID3-Tag vorliegt, intelligent zerlegen
        $fallbackBase = pathinfo((string)$row['original_name'], PATHINFO_FILENAME);
        $displayTitle  = trim((string)($row['cached_title'] ?? ''));
        $displayArtist = trim((string)($row['cached_artist'] ?? ''));
        if ($displayTitle === '') {
            if ($displayArtist === '' && str_contains($fallbackBase, ' - ')) {
                [$partArtist, $partTitle] = array_map('trim', explode(' - ', $fallbackBase, 2));
                $displayArtist = $partArtist;
                $displayTitle  = $partTitle;
            } else {
                $displayTitle = $fallbackBase;
            }
        }
        if ($displayArtist === '') {
            $displayArtist = (int)$row['user_id'] === $userId ? 'Unbekannter Interpret' : ('Geteilt von ' . $row['owner_name']);
        }

        $row['display_title']  = $displayTitle;
        $row['display_artist'] = $displayArtist;
        $row['display_album']  = trim((string)($row['cached_album'] ?? '')) ?: 'Cloud-Audio';

        $fp = trim((string)($row['folder_path'] ?? ''), '/');
        $folderLabel = $fp !== '' ? $fp : 'Hauptverzeichnis (/)';
        $row['folder_label'] = $folderLabel;
        $audioFolders[$folderLabel] = ($audioFolders[$folderLabel] ?? 0) + 1;

        $allAudioFiles[$fid] = $row;
    }
}

// Welche Titel werden aktuell in der Trackliste angezeigt?
$displayedTracks = [];
if ($activePlaylist && !$isPlaylistLocked) {
    $stmtItems = $pdo->prepare(
        'SELECT f.*, u.username AS owner_name,
                mtm.is_favorite, mtm.last_position_sec, mtm.play_count,
                mtm.cached_title, mtm.cached_artist, mtm.cached_album, mtm.cached_year,
                mtm.cached_duration_sec, mtm.has_cover, mtm.meta_parsed
         FROM music_playlist_items mpi
         INNER JOIN files f ON f.id = mpi.file_id
         INNER JOIN users u ON u.id = f.user_id
         LEFT JOIN music_track_meta mtm ON mtm.file_id = f.id AND mtm.user_id = :uid_meta
         WHERE mpi.playlist_id = :pid AND f.deleted_at IS NULL AND f.is_encrypted = 0
         ORDER BY mpi.sort_order ASC, mpi.id ASC'
    );
    $stmtItems->execute([
        ':uid_meta' => $userId > 0 ? $userId : (int)$activePlaylist['user_id'],
        ':pid'      => (int)$activePlaylist['id'],
    ]);
    foreach ($stmtItems->fetchAll() as $row) {
        $fid = (int)$row['id'];
        if (isset($allAudioFiles[$fid])) {
            $displayedTracks[] = $allAudioFiles[$fid];
        } else {
            $phys = UPLOAD_DIR . basename((string)$row['filename']);
            $id3  = parseLocalAudioMetadata($phys, false);
            $fallbackBase = pathinfo((string)$row['original_name'], PATHINFO_FILENAME);
            $row['display_title']       = $id3['title'] ?: $fallbackBase;
            $row['display_artist']      = $id3['artist'] ?: (string)$row['owner_name'];
            $row['display_album']       = $id3['album'] ?: (string)$activePlaylist['title'];
            $row['cached_duration_sec'] = $id3['duration'];
            $row['has_cover']           = $id3['has_cover'];
            $row['folder_label']        = trim((string)($row['folder_path'] ?? ''), '/') ?: 'Hauptverzeichnis (/)';
            $displayedTracks[] = $row;
        }
    }
} elseif (!$isGuestMode) {
    foreach ($allAudioFiles as $track) {
        if ($folderFilter !== '' && $track['folder_label'] !== $folderFilter) {
            continue;
        }
        if ($viewFilter === 'favorites' && (int)($track['is_favorite'] ?? 0) !== 1) {
            continue;
        }
        if ($viewFilter === 'resume' && (int)($track['last_position_sec'] ?? 0) < 10) {
            continue;
        }
        if ($searchQuery !== '') {
            $haystack = $track['display_title'] . ' ' . $track['display_artist'] . ' ' . $track['display_album'] . ' ' . $track['original_name'] . ' ' . $track['folder_label'];
            if (mb_stripos($haystack, $searchQuery) === false) {
                continue;
            }
        }
        $displayedTracks[] = $track;
    }
}

$shareableUsers = $userId > 0 ? getVisibleUsersForUser($pdo, $userId) : [];
$countFavorites = count(array_filter($allAudioFiles, static fn($t) => (int)($t['is_favorite'] ?? 0) === 1));
$countResume    = count(array_filter($allAudioFiles, static fn($t) => (int)($t['last_position_sec'] ?? 0) >= 10));

$pageTitle = $activePlaylist ? ('Playlist: ' . $activePlaylist['title']) : 'Musik- & Hörbuch-Studio';
require_once __DIR__ . '/includes/header.php';
?>

<main class="max-w-7xl mx-auto px-4 sm:px-6 py-6 space-y-6 pb-28">
    <!-- Kopfzeile -->
    <div class="mc-card p-5 sm:p-6 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
        <div class="space-y-1">
            <div class="flex items-center gap-2.5 flex-wrap">
                <span class="text-2xl sm:text-3xl">🎵</span>
                <h1 class="text-xl sm:text-2xl font-extrabold tracking-tight text-slate-900 dark:text-white">
                    <?php if ($activePlaylist): ?>
                        Playlist: <?= e((string)$activePlaylist['title']) ?>
                    <?php else: ?>
                        Musik-, Podcast- &amp; Hörbuch-Studio
                    <?php endif; ?>
                </h1>
                <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-rose-100 dark:bg-rose-950/80 text-rose-700 dark:text-rose-300 border border-rose-200 dark:border-rose-800">
                    <?= count($displayedTracks) ?> <?= count($displayedTracks) === 1 ? 'Titel' : 'Titel' ?>
                </span>
            </div>
            <p class="text-xs sm:text-sm text-slate-600 dark:text-slate-400">
                <?php if ($activePlaylist): ?>
                    <?= e((string)($activePlaylist['description'] ?: 'Erstellt von ' . $activePlaylist['owner_name'])) ?>
                <?php else: ?>
                    Spiele Musik, Podcasts und Hörbücher direkt aus deiner Cloud ab – inkl. ID3-Cover, Equalizer, Playlists, Hörbuch-Gedächtnis &amp; Smartphone-Sperrbildschirm-Steuerung.
                <?php endif; ?>
            </p>
        </div>

        <?php if (!$isGuestMode): ?>
            <div class="flex flex-wrap items-center gap-2">
                <?php if ($activePlaylist): ?>
                    <a href="music.php"
                       class="px-3.5 py-2 rounded-xl text-xs sm:text-sm font-semibold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-200 transition">
                        ← Alle Titel
                    </a>
                <?php endif; ?>

                <button type="button"
                        onclick="document.getElementById('modalUploadAudio').classList.remove('hidden')"
                        class="px-3.5 py-2 rounded-xl text-xs sm:text-sm font-semibold bg-emerald-600 hover:bg-emerald-700 text-white shadow-sm transition cursor-pointer">
                    📤 Musik / Hörbuch hochladen
                </button>

                <button type="button"
                        onclick="document.getElementById('modalCreatePlaylist').classList.remove('hidden')"
                        class="px-3.5 py-2 rounded-xl text-xs sm:text-sm font-semibold bg-rose-600 hover:bg-rose-700 text-white shadow-sm transition cursor-pointer">
                    ➕ Neue Playlist
                </button>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($activePlaylist && $isPlaylistLocked): ?>
        <!-- Passwort-Abfrage für geschützte Gast-Playlists -->
        <div class="mc-card max-w-md mx-auto p-6 text-center space-y-4">
            <div class="text-4xl">🔒</div>
            <h2 class="text-lg font-bold text-slate-900 dark:text-white">Passwortgeschützte Playlist</h2>
            <p class="text-xs sm:text-sm text-slate-600 dark:text-slate-400">
                Bitte gib das Passwort ein, um die Playlist <strong>„<?= e((string)$activePlaylist['title']) ?>“</strong> anzuhören.
            </p>
            <form method="post" class="space-y-3">
                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                <input type="hidden" name="action" value="unlock_playlist">
                <input type="hidden" name="playlist_id" value="<?= (int)$activePlaylist['id'] ?>">
                <input type="password" name="playlist_password" required autofocus
                       placeholder="Playlist-Passwort…"
                       class="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm">
                <button type="submit" class="w-full py-2.5 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-semibold text-sm">
                    🔓 Playlist entsperren
                </button>
            </form>
        </div>
    <?php else: ?>

        <!-- HAUPT-STUDIO-BEREICH: Links/Oben Player-Deck + Rechts Trackliste & Playlists -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">

            <!-- LINKE SPALTE (4 Spalten): STUDIO-PLAYER, VISUALIZER, KLANG-BOOSTER & SLEEP-TIMER -->
            <div class="lg:col-span-4 space-y-4 lg:sticky lg:top-20">
                <div class="mc-card p-5 bg-gradient-to-br from-slate-900 via-slate-900 to-rose-950 text-white rounded-2xl border border-slate-800 shadow-xl space-y-4">
                    <!-- Album-Cover / Vinyl + Live-Visualizer -->
                    <div class="relative flex flex-col items-center text-center">
                        <div id="playerCoverWrap" class="relative w-44 h-44 rounded-2xl overflow-hidden shadow-2xl border-2 border-white/15 bg-gradient-to-br from-rose-600 via-indigo-700 to-slate-900 flex items-center justify-center">
                            <img id="playerCoverImg" src="" alt="Cover" class="hidden w-full h-full object-cover">
                            <div id="playerVinylFallback" class="flex flex-col items-center justify-center">
                                <div id="playerVinylDisc" class="w-32 h-32 rounded-full bg-[radial-gradient(circle,#f43f5e_0%,#be123c_22%,#0f172a_24%,#1e293b_48%,#0f172a_52%,#1e293b_75%,#020617_100%)] border-4 border-white/20 flex items-center justify-center shadow-inner transition-transform duration-700">
                                    <div class="w-8 h-8 rounded-full bg-slate-950 border-2 border-rose-400 flex items-center justify-center text-xs">🎵</div>
                                </div>
                            </div>
                        </div>

                        <!-- Live-Frequenz-Visualizer (Web Audio API) -->
                        <canvas id="audioVisualizerCanvas" width="280" height="44" class="w-full h-11 mt-3 rounded-lg bg-black/25 px-2"></canvas>

                        <h2 id="playerTitle" class="mt-3 text-base sm:text-lg font-extrabold tracking-tight text-white line-clamp-1">
                            Kein Titel ausgewählt
                        </h2>
                        <p id="playerArtist" class="text-xs sm:text-sm text-rose-300 font-medium line-clamp-1">
                            Wähle rechts einen Titel oder klicke auf „▶️ Alle abspielen“
                        </p>
                        <p id="playerAlbumFolder" class="text-[11px] text-slate-400 line-clamp-1 mt-0.5"></p>

                        <!-- Hörbuch-Positions-Banner (falls gespeicherte Stelle vorhanden) -->
                        <button type="button" id="btnResumePosition" onclick="resumeSavedTrackPosition()"
                                class="hidden mt-2 px-3 py-1 rounded-full bg-amber-500/20 hover:bg-amber-500/30 border border-amber-400/40 text-amber-200 text-xs font-bold transition cursor-pointer">
                            🔖 Weiterhören ab <span id="resumePosLabel">00:00</span>
                        </button>
                    </div>

                    <!-- Fortschrittsbalken & Zeitanzeige -->
                    <div class="space-y-1">
                        <input type="range" id="playerSeekSlider" min="0" max="1000" value="0"
                               oninput="onSeekSliderInput(this.value)" onchange="onSeekSliderCommit(this.value)"
                               class="w-full accent-rose-500 cursor-pointer h-1.5 rounded-lg">
                        <div class="flex items-center justify-between text-[11px] font-mono text-slate-300">
                            <span id="playerTimeCurrent">00:00</span>
                            <span id="playerTimeRemaining" class="text-slate-400">-00:00</span>
                            <span id="playerTimeTotal">00:00</span>
                        </div>
                    </div>

                    <!-- Haupt-Steuertasten (Zurück, -15s, Play/Pause, +15s, Weiter) -->
                    <div class="flex items-center justify-center gap-2 sm:gap-3">
                        <button type="button" onclick="stepTrack(-1)" title="Vorheriger Titel"
                                class="w-10 h-10 rounded-full bg-white/10 hover:bg-white/20 flex items-center justify-center text-sm font-bold transition cursor-pointer">
                            ⏮️
                        </button>
                        <button type="button" onclick="seekRelative(-15)" title="15 Sekunden zurückspulen (ideal für Hörbücher)"
                                class="px-2.5 h-9 rounded-xl bg-white/10 hover:bg-white/20 text-xs font-bold transition cursor-pointer">
                            -15s
                        </button>
                        <button type="button" id="btnPlayPauseMain" onclick="toggleAudioPlay()" title="Abspielen / Pause (Leertaste)"
                                class="w-14 h-14 rounded-full bg-rose-600 hover:bg-rose-500 shadow-lg shadow-rose-600/40 flex items-center justify-center text-xl font-bold transition cursor-pointer">
                            ▶️
                        </button>
                        <button type="button" onclick="seekRelative(15)" title="15 Sekunden vorspulen"
                                class="px-2.5 h-9 rounded-xl bg-white/10 hover:bg-white/20 text-xs font-bold transition cursor-pointer">
                            +15s
                        </button>
                        <button type="button" onclick="stepTrack(1)" title="Nächster Titel"
                                class="w-10 h-10 rounded-full bg-white/10 hover:bg-white/20 flex items-center justify-center text-sm font-bold transition cursor-pointer">
                            ⏭️
                        </button>
                    </div>

                    <!-- Zusatz-Steuerung: Shuffle, Repeat, Lautstärke -->
                    <div class="flex items-center justify-between gap-2 pt-2 border-t border-white/10 text-xs">
                        <div class="flex items-center gap-1.5">
                            <button type="button" id="btnShuffle" onclick="toggleShuffle()"
                                    class="px-2.5 py-1.5 rounded-lg bg-white/10 hover:bg-white/20 font-semibold transition cursor-pointer"
                                    title="Zufallswiedergabe (Shuffle)">
                                🔀 Aus
                            </button>
                            <button type="button" id="btnRepeat" onclick="cycleRepeatMode()"
                                    class="px-2.5 py-1.5 rounded-lg bg-white/10 hover:bg-white/20 font-semibold transition cursor-pointer"
                                    title="Wiederholungs-Modus umschalten">
                                🔁 Alle
                            </button>
                        </div>

                        <div class="flex items-center gap-1.5">
                            <button type="button" id="btnMute" onclick="toggleMute()" class="text-sm cursor-pointer" title="Stummschalten">🔊</button>
                            <input type="range" id="playerVolumeSlider" min="0" max="100" value="90"
                                   oninput="setAudioVolume(this.value)"
                                   class="w-20 accent-rose-500 cursor-pointer">
                        </div>
                    </div>

                    <!-- Klang-Profil (Equalizer), Geschwindigkeit & Sleep-Timer -->
                    <div class="grid grid-cols-1 sm:grid-cols-3 lg:grid-cols-1 gap-2 pt-2 border-t border-white/10 text-xs">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-slate-300 font-semibold">🎛️ Klang-Profil:</span>
                            <select id="eqPresetSelect" onchange="applyEqualizerPreset(this.value)"
                                    class="px-2.5 py-1 rounded-lg bg-slate-800 border border-slate-700 text-white text-xs font-semibold">
                                <option value="flat">🎧 Studio Neutral</option>
                                <option value="bass">🔊 Bass-Boost</option>
                                <option value="voice">🎙️ Stimme / Hörbuch</option>
                                <option value="pop">🎸 Brillanz / Pop</option>
                            </select>
                        </div>

                        <div class="flex items-center justify-between gap-2">
                            <span class="text-slate-300 font-semibold">⏩ Tempo:</span>
                            <select id="playbackRateSelect" onchange="setAudioPlaybackRate(this.value)"
                                    class="px-2.5 py-1 rounded-lg bg-slate-800 border border-slate-700 text-white text-xs font-semibold">
                                <option value="0.75">0.75× (Langsam)</option>
                                <option value="1" selected>1.0× (Normal)</option>
                                <option value="1.25">1.25× (Hörbuch)</option>
                                <option value="1.5">1.5× (Schnell)</option>
                                <option value="2">2.0× (Doppelt)</option>
                            </select>
                        </div>

                        <div class="flex items-center justify-between gap-2">
                            <span class="text-slate-300 font-semibold">🌙 Einschlaf-Timer:</span>
                            <select id="sleepTimerSelect" onchange="setSleepTimer(this.value)"
                                    class="px-2.5 py-1 rounded-lg bg-slate-800 border border-slate-700 text-white text-xs font-semibold">
                                <option value="0">Aus</option>
                                <option value="15">15 Min.</option>
                                <option value="30">30 Min.</option>
                                <option value="45">45 Min.</option>
                                <option value="60">60 Min.</option>
                            </select>
                        </div>
                        <div id="sleepTimerBadge" class="hidden text-center py-1 rounded-lg bg-indigo-500/20 border border-indigo-400/30 text-indigo-200 font-mono text-[11px]">
                            🌙 Stoppt in <span id="sleepTimerCountdown">00:00</span>
                        </div>
                    </div>
                </div>

                <!-- PLAYLISTS-ÜBERSICHT (Links unter dem Player) -->
                <?php if (!$isGuestMode): ?>
                <div class="mc-card p-4 space-y-3">
                    <div class="flex items-center justify-between">
                        <h3 class="text-sm font-bold text-slate-900 dark:text-white flex items-center gap-1.5">
                            <span>📋</span> Meine &amp; geteilte Playlists (<?= count($playlists) ?>)
                        </h3>
                        <button type="button"
                                onclick="document.getElementById('modalCreatePlaylist').classList.remove('hidden')"
                                class="text-xs font-bold text-rose-600 dark:text-rose-400 hover:underline cursor-pointer">
                            + Neu
                        </button>
                    </div>

                    <?php if (empty($playlists)): ?>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            Noch keine Playlist angelegt. Erstelle z. B. eine Playlist für deine Lieblingssongs, Vereinslieder oder ein Hörbuch!
                        </p>
                    <?php else: ?>
                        <div class="space-y-1.5 max-h-60 overflow-y-auto pr-1">
                            <?php foreach ($playlists as $pl):
                                $pid = (int)$pl['id'];
                                $isAct = ($activePlaylistId === $pid);
                            ?>
                                <a href="music.php?playlist=<?= $pid ?>"
                                   class="flex items-center justify-between gap-2 px-3 py-2 rounded-xl text-xs font-semibold transition <?= $isAct ? 'bg-rose-600 text-white shadow-xs' : 'bg-slate-50 dark:bg-slate-800/70 hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-800 dark:text-slate-100' ?>">
                                    <div class="min-w-0 truncate">
                                        <span>💿 <?= e((string)$pl['title']) ?></span>
                                        <?php if (!empty($pl['share_token'])): ?>
                                            <span title="Öffentlicher Link aktiv">🔗</span>
                                        <?php endif; ?>
                                    </div>
                                    <span class="px-2 py-0.5 rounded-full text-[11px] <?= $isAct ? 'bg-white/20 text-white' : 'bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300' ?>">
                                        <?= (int)$pl['track_count'] ?>
                                    </span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- RECHTE SPALTE (8 Spalten): FILTER, AKTIVE PLAYLIST-AKTIONEN & TRACKLISTE -->
            <div class="lg:col-span-8 space-y-4">

                <?php if ($activePlaylist): ?>
                    <!-- Aktionsleiste für geöffnete Playlist (inkl. Teilen & QR-Code) -->
                    <div class="mc-card p-4 sm:p-5 space-y-3 border-l-4 border-l-rose-500">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                            <div>
                                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white flex items-center gap-2">
                                    <span>💿 <?= e((string)$activePlaylist['title']) ?></span>
                                    <?php if (!empty($activePlaylist['share_token'])): ?>
                                        <span class="px-2 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-100 dark:bg-emerald-950/70 text-emerald-700 dark:text-emerald-300">
                                            🔗 Gäste-Link aktiv
                                        </span>
                                    <?php endif; ?>
                                </h2>
                                <?php if (!empty($activePlaylist['description'])): ?>
                                    <p class="text-xs text-slate-600 dark:text-slate-300 mt-0.5"><?= e((string)$activePlaylist['description']) ?></p>
                                <?php endif; ?>
                            </div>

                            <div class="flex flex-wrap items-center gap-2">
                                <?php if (!empty($displayedTracks)): ?>
                                    <button type="button" onclick="playTrackAtIndex(0)"
                                            class="px-3.5 py-1.5 rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold cursor-pointer">
                                        ▶️ Playlist abspielen
                                    </button>
                                <?php endif; ?>

                                <?php if (!$isGuestMode && canAccessMusicPlaylist($activePlaylist, $userId, '')): ?>
                                    <button type="button"
                                            onclick="document.getElementById('modalAddExistingTracks').classList.remove('hidden')"
                                            class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 text-xs font-semibold text-slate-700 dark:text-slate-200 cursor-pointer">
                                        ➕ Cloud-Titel hinzufügen
                                    </button>
                                <?php endif; ?>

                                <?php if (!$isGuestMode && (int)$activePlaylist['user_id'] === $userId): ?>
                                    <button type="button"
                                            onclick="document.getElementById('modalEditPlaylist').classList.remove('hidden')"
                                            class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 text-xs font-semibold text-slate-700 dark:text-slate-200 cursor-pointer">
                                        ⚙️ Teilen / Bearbeiten
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if (!$isGuestMode && !empty($activePlaylist['share_token'])):
                            $publicPlUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
                                . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
                                . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\')
                                . '/music.php?playlist_token=' . urlencode((string)$activePlaylist['share_token']);
                        ?>
                            <div class="pt-3 border-t border-slate-200/70 dark:border-slate-700/70 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
                                <div class="flex-1 min-w-0 w-full">
                                    <label class="block text-[11px] font-semibold uppercase tracking-wider text-slate-400 mb-1">
                                        Öffentlicher Playlist-Link:
                                    </label>
                                    <div class="flex items-center gap-2">
                                        <input type="text" readonly value="<?= e($publicPlUrl) ?>" id="publicPlaylistUrlInput"
                                               class="w-full px-3 py-1.5 rounded-lg bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs font-mono text-slate-700 dark:text-slate-200">
                                        <button type="button"
                                                onclick="navigator.clipboard.writeText(document.getElementById('publicPlaylistUrlInput').value); this.textContent='✓ Kopiert';"
                                                class="px-3 py-1.5 rounded-lg bg-brand-600 text-white text-xs font-semibold shrink-0 cursor-pointer">
                                            Kopieren
                                        </button>
                                        <a href="qr.php?text=<?= urlencode($publicPlUrl) ?>" target="_blank"
                                           class="px-3 py-1.5 rounded-lg bg-slate-200 dark:bg-slate-700 text-slate-800 dark:text-slate-100 text-xs font-semibold shrink-0">
                                            📱 QR-Code
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- Filter- & Suchleiste (wenn keine einzelne Playlist geöffnet ist) -->
                <?php if (!$activePlaylist && !$isGuestMode): ?>
                    <div class="mc-card p-4 space-y-3">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <a href="music.php?filter=all<?= $folderFilter !== '' ? '&amp;folder=' . urlencode($folderFilter) : '' ?>"
                                   class="px-3 py-1.5 rounded-xl text-xs font-bold transition <?= $viewFilter === 'all' ? 'bg-rose-600 text-white shadow-2xs' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-200' ?>">
                                    🎵 Alle Titel (<?= count($allAudioFiles) ?>)
                                </a>
                                <a href="music.php?filter=favorites"
                                   class="px-3 py-1.5 rounded-xl text-xs font-bold transition <?= $viewFilter === 'favorites' ? 'bg-rose-600 text-white shadow-2xs' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-200' ?>">
                                    ❤️ Favoriten (<?= $countFavorites ?>)
                                </a>
                                <a href="music.php?filter=resume"
                                   class="px-3 py-1.5 rounded-xl text-xs font-bold transition <?= $viewFilter === 'resume' ? 'bg-rose-600 text-white shadow-2xs' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-200' ?>">
                                    🔖 Weiterhören / Hörbücher (<?= $countResume ?>)
                                </a>
                            </div>

                            <?php if (!empty($displayedTracks)): ?>
                                <button type="button" onclick="playTrackAtIndex(0)"
                                        class="px-3.5 py-1.5 rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold shadow-2xs cursor-pointer">
                                    ▶️ Alle abspielen
                                </button>
                            <?php endif; ?>
                        </div>

                        <form method="get" action="music.php" class="flex flex-wrap items-center gap-2 pt-2 border-t border-slate-200/70 dark:border-slate-800">
                            <input type="hidden" name="filter" value="<?= e($viewFilter) ?>">
                            <div class="relative flex-1 min-w-[200px]">
                                <input type="text" name="q" value="<?= e($searchQuery) ?>"
                                       placeholder="🔍 Titel, Interpret, Album oder Dateiname suchen…"
                                       class="w-full pl-3.5 pr-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/80 text-xs sm:text-sm text-slate-800 dark:text-slate-100">
                            </div>
                            <select name="folder" onchange="this.form.submit()"
                                    class="px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/80 text-xs sm:text-sm text-slate-800 dark:text-slate-100">
                                <option value="">📁 Alle Ordner (<?= count($allAudioFiles) ?>)</option>
                                <?php foreach ($audioFolders as $fName => $cnt): ?>
                                    <option value="<?= e($fName) ?>" <?= $folderFilter === $fName ? 'selected' : '' ?>>
                                        📁 <?= e($fName) ?> (<?= $cnt ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="px-3.5 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs sm:text-sm font-semibold cursor-pointer">
                                Filtern
                            </button>
                            <?php if ($searchQuery !== '' || $folderFilter !== ''): ?>
                                <a href="music.php?filter=<?= e($viewFilter) ?>" class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-xs font-semibold text-slate-600 dark:text-slate-300">
                                    ✕ Reset
                                </a>
                            <?php endif; ?>
                        </form>
                    </div>
                <?php endif; ?>

                <!-- TRACKLISTE -->
                <?php if (empty($displayedTracks)): ?>
                    <div class="mc-card p-10 text-center space-y-3">
                        <div class="text-5xl">🎧</div>
                        <h3 class="text-lg font-bold text-slate-800 dark:text-slate-100">
                            Keine Audiodateien in dieser Ansicht gefunden
                        </h3>
                        <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 max-w-md mx-auto">
                            Lade deine Lieblingsmusik, Vereinslieder, Podcasts oder Hörbücher (<code>.mp3</code>, <code>.m4a</code>, <code>.flac</code>, <code>.wav</code>, <code>.ogg</code>) direkt über den Button <strong>„📤 Musik / Hörbuch hochladen“</strong> hoch!
                        </p>
                    </div>
                <?php else: ?>
                    <div class="mc-card overflow-hidden">
                        <div class="divide-y divide-slate-200/80 dark:divide-slate-800" id="musicTrackListContainer">
                            <?php foreach ($displayedTracks as $idx => $t):
                                $fid     = (int)$t['id'];
                                $isFav   = (int)($t['is_favorite'] ?? 0) === 1;
                                $lastPos = (int)($t['last_position_sec'] ?? 0);
                                $durSec  = (int)($t['cached_duration_sec'] ?? 0);
                                $hasCov  = (int)($t['has_cover'] ?? 0) === 1;
                                $tokParam = $playlistToken !== '' ? '&album_token=' . urlencode($playlistToken) : '';
                                $coverUrl = $hasCov ? ('music.php?cover_file=' . $fid . ($playlistToken !== '' ? '&playlist_token=' . urlencode($playlistToken) : '')) : '';
                                $durFormatted = $durSec > 0 ? sprintf('%02d:%02d', intdiv($durSec, 60), $durSec % 60) : formatBytes((int)$t['file_size']);
                            ?>
                                <div id="trackRow_<?= $idx ?>"
                                     data-file-id="<?= $fid ?>"
                                     class="music-track-row group flex items-center justify-between gap-3 px-4 py-3 hover:bg-rose-50/50 dark:hover:bg-slate-800/60 transition">
                                    <!-- Links: Play-Nummer / Cover / Titel & Interpret -->
                                    <button type="button"
                                            onclick="playTrackAtIndex(<?= (int)$idx ?>)"
                                            class="flex items-center gap-3 min-w-0 flex-1 text-left cursor-pointer focus:outline-none">
                                        <div class="relative w-11 h-11 rounded-xl overflow-hidden bg-slate-200 dark:bg-slate-800 flex items-center justify-center shrink-0 border border-slate-300/60 dark:border-slate-700">
                                            <?php if ($hasCov): ?>
                                                <img src="<?= e($coverUrl) ?>" alt="" loading="lazy" class="w-full h-full object-cover">
                                            <?php else: ?>
                                                <span class="text-lg">🎵</span>
                                            <?php endif; ?>
                                            <span class="track-play-badge absolute inset-0 bg-black/55 text-white opacity-0 group-hover:opacity-100 flex items-center justify-center text-xs font-bold transition">
                                                ▶️
                                            </span>
                                        </div>

                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-center gap-2">
                                                <p class="track-title-label text-xs sm:text-sm font-bold text-slate-900 dark:text-white truncate">
                                                    <?= e((string)$t['display_title']) ?>
                                                </p>
                                                <?php if ($lastPos >= 10): ?>
                                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 dark:bg-amber-950/70 text-amber-800 dark:text-amber-300 shrink-0"
                                                          title="Gemerkte Hörbuch-/Podcast-Position">
                                                        🔖 <?= sprintf('%02d:%02d', intdiv($lastPos, 60), $lastPos % 60) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <p class="text-[11px] sm:text-xs text-slate-500 dark:text-slate-400 truncate">
                                                <?= e((string)$t['display_artist']) ?>
                                                <?php if (!empty($t['display_album']) && $t['display_album'] !== 'Cloud-Audio'): ?>
                                                    &bull; 💿 <?= e((string)$t['display_album']) ?>
                                                <?php endif; ?>
                                                &bull; 📁 <?= e((string)$t['folder_label']) ?>
                                            </p>
                                        </div>
                                    </button>

                                    <!-- Rechts: Dauer, Favorit, Download, ggf. aus Playlist entfernen -->
                                    <div class="flex items-center gap-1.5 shrink-0 text-xs">
                                        <span class="font-mono text-slate-400 dark:text-slate-500 hidden sm:inline mr-1">
                                            <?= e($durFormatted) ?>
                                        </span>

                                        <?php if (!$isGuestMode): ?>
                                            <button type="button"
                                                    onclick="toggleTrackFavorite(<?= $fid ?>, this)"
                                                    title="Als Favorit markieren"
                                                    class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 transition cursor-pointer">
                                                <?= $isFav ? '❤️' : '🤍' ?>
                                            </button>
                                        <?php endif; ?>

                                        <a href="music.php?stream_file=<?= $fid ?>&amp;download=1<?= $playlistToken !== '' ? '&amp;playlist_token=' . urlencode($playlistToken) : '' ?>"
                                           download
                                           title="Audiodatei herunterladen"
                                           class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 transition">
                                            ⬇️
                                        </a>

                                        <?php if ($activePlaylist && !$isGuestMode && canAccessMusicPlaylist($activePlaylist, $userId, '')): ?>
                                            <form method="post" class="inline" onsubmit="return confirm('Titel aus der Playlist entfernen? (Originaldatei bleibt erhalten)');">
                                                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                                <input type="hidden" name="action" value="remove_from_playlist">
                                                <input type="hidden" name="playlist_id" value="<?= (int)$activePlaylist['id'] ?>">
                                                <input type="hidden" name="file_id" value="<?= $fid ?>">
                                                <button type="submit" title="Aus Playlist entfernen"
                                                        class="p-1.5 rounded-lg hover:bg-red-50 dark:hover:bg-red-950/50 text-slate-400 hover:text-red-500 cursor-pointer">
                                                    ✕
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    <?php endif; ?>
</main>

<!-- UNTERE SCHWEBENDE MINI-PLAYER-LEISTE (für schnellen Zugriff beim Scrollen) -->
<div id="bottomMiniPlayer" class="hidden fixed bottom-0 inset-x-0 z-40 bg-slate-950/95 text-white border-t border-white/10 backdrop-blur-md px-4 py-2.5 shadow-2xl">
    <div class="max-w-7xl mx-auto flex items-center justify-between gap-3">
        <div class="flex items-center gap-3 min-w-0 flex-1">
            <span class="text-xl">🎵</span>
            <div class="min-w-0">
                <p id="miniPlayerTitle" class="text-xs sm:text-sm font-bold truncate">Titel</p>
                <p id="miniPlayerArtist" class="text-[11px] text-rose-300 truncate">Interpret</p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <button type="button" onclick="stepTrack(-1)" class="p-2 rounded-full bg-white/10 hover:bg-white/20 text-xs cursor-pointer">⏮️</button>
            <button type="button" id="btnPlayPauseMini" onclick="toggleAudioPlay()" class="w-10 h-10 rounded-full bg-rose-600 hover:bg-rose-500 flex items-center justify-center text-sm font-bold cursor-pointer">▶️</button>
            <button type="button" onclick="stepTrack(1)" class="p-2 rounded-full bg-white/10 hover:bg-white/20 text-xs cursor-pointer">⏭️</button>
        </div>
    </div>
</div>

<!-- Verstecktes HTML5-Audio-Element -->
<audio id="cloudAudioElement" preload="metadata" crossorigin="anonymous"></audio>

<?php if (!$isGuestMode): ?>
<!-- MODAL 1: MUSIK / HÖRBUCH HOCHLADEN -->
<div id="modalUploadAudio" class="hidden fixed inset-0 z-50 bg-black/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="mc-card w-full max-w-lg p-6 space-y-4">
        <div class="flex items-center justify-between">
            <h3 class="text-lg font-bold text-slate-900 dark:text-white">📤 Musik, Podcast oder Hörbuch hochladen</h3>
            <button type="button" onclick="document.getElementById('modalUploadAudio').classList.add('hidden')" class="text-slate-400 hover:text-slate-600">✕</button>
        </div>
        <form method="post" enctype="multipart/form-data" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="upload_audio">
            <input type="hidden" name="playlist_id" value="<?= $activePlaylist ? (int)$activePlaylist['id'] : 0 ?>">

            <div>
                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                    Audiodateien auswählen (.mp3, .m4a, .flac, .wav, .ogg, .aac, .opus) *
                </label>
                <input type="file" name="audio_files[]" accept=".mp3,.m4a,.wav,.ogg,.flac,.aac,.opus,audio/*" multiple required
                       class="w-full text-xs sm:text-sm text-slate-700 dark:text-slate-200 file:mr-3 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-rose-600 file:text-white hover:file:bg-rose-700">
                <p class="text-[11px] text-slate-500 mt-1.5">
                    Die Dateien werden automatisch in deinem Cloud-Ordner <code>Musik/</code> gespeichert und auf ID3-Tags &amp; Cover gescannt.
                </p>
            </div>

            <div class="flex justify-end gap-2 pt-2">
                <button type="button" onclick="document.getElementById('modalUploadAudio').classList.add('hidden')"
                        class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-xs font-semibold">
                    Abbrechen
                </button>
                <button type="submit" class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold cursor-pointer">
                    📤 Jetzt hochladen
                </button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL 2: NEUE PLAYLIST ANLEGEN -->
<div id="modalCreatePlaylist" class="hidden fixed inset-0 z-50 bg-black/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="mc-card w-full max-w-lg p-6 space-y-4 max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between">
            <h3 class="text-lg font-bold text-slate-900 dark:text-white">➕ Neue Playlist erstellen</h3>
            <button type="button" onclick="document.getElementById('modalCreatePlaylist').classList.add('hidden')" class="text-slate-400 hover:text-slate-600">✕</button>
        </div>
        <form method="post" class="space-y-3.5">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="save_playlist">
            <input type="hidden" name="playlist_id" value="0">

            <div>
                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Playlist-Name *</label>
                <input type="text" name="title" required placeholder="z. B. Vereinsfest-Hits, Chor-Proben, Hörbuch Kapitel 1–10…"
                       class="w-full px-3.5 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm">
            </div>

            <?php if (!empty($shareableUsers)): ?>
            <div>
                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Mit Cloud-Benutzer teilen (optional)</label>
                <select name="shared_user_id" class="w-full px-3.5 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm">
                    <option value="0">— Nur für mich privat —</option>
                    <?php foreach ($shareableUsers as $su): ?>
                        <option value="<?= (int)$su['id'] ?>">👤 <?= e((string)$su['username']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div>
                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Beschreibung (optional)</label>
                <textarea name="description" rows="2" placeholder="Notizen zur Playlist…"
                          class="w-full px-3.5 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm"></textarea>
            </div>

            <div class="p-3.5 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 space-y-2.5">
                <label class="flex items-center gap-2.5 text-xs sm:text-sm font-semibold text-slate-800 dark:text-slate-100 cursor-pointer">
                    <input type="checkbox" name="enable_public_link" value="1" class="rounded text-rose-600">
                    🔗 Öffentlichen Gäste-Link &amp; QR-Code aktivieren
                </label>
                <div>
                    <input type="password" name="playlist_password" placeholder="Optionales Passwort für Gäste-Link…"
                           class="w-full px-3 py-1.5 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-xs">
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-2">
                <button type="button" onclick="document.getElementById('modalCreatePlaylist').classList.add('hidden')"
                        class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-xs font-semibold">
                    Abbrechen
                </button>
                <button type="submit" class="px-4 py-2 rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-xs font-semibold cursor-pointer">
                    Playlist anlegen
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($activePlaylist && !$isGuestMode && canAccessMusicPlaylist($activePlaylist, $userId, '')): ?>
<!-- MODAL 3: VORHANDENE CLOUD-TITEL ZUR PLAYLIST HINZUFÜGEN -->
<div id="modalAddExistingTracks" class="hidden fixed inset-0 z-50 bg-black/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="mc-card w-full max-w-2xl p-6 space-y-4 max-h-[90vh] flex flex-col">
        <div class="flex items-center justify-between">
            <h3 class="text-lg font-bold text-slate-900 dark:text-white">➕ Cloud-Audiodateien zur Playlist hinzufügen</h3>
            <button type="button" onclick="document.getElementById('modalAddExistingTracks').classList.add('hidden')" class="text-slate-400 hover:text-slate-600">✕</button>
        </div>
        <form method="post" class="flex flex-col flex-1 overflow-hidden space-y-4">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="add_to_playlist">
            <input type="hidden" name="playlist_id" value="<?= (int)$activePlaylist['id'] ?>">

            <div class="overflow-y-auto flex-1 divide-y divide-slate-200 dark:divide-slate-800 pr-1">
                <?php foreach ($allAudioFiles as $af):
                    $afId = (int)$af['id'];
                ?>
                    <label class="flex items-center justify-between gap-3 py-2.5 px-2 hover:bg-slate-50 dark:hover:bg-slate-800/50 rounded-lg cursor-pointer text-xs">
                        <div class="flex items-center gap-2.5 min-w-0">
                            <input type="checkbox" name="file_ids[]" value="<?= $afId ?>" class="rounded text-rose-600">
                            <div class="min-w-0">
                                <p class="font-bold text-slate-800 dark:text-slate-100 truncate"><?= e((string)$af['display_title']) ?></p>
                                <p class="text-[11px] text-slate-400 truncate"><?= e((string)$af['display_artist']) ?> · 📁 <?= e((string)$af['folder_label']) ?></p>
                            </div>
                        </div>
                        <span class="text-[11px] font-mono text-slate-400 shrink-0"><?= e(formatBytes((int)$af['file_size'])) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>

            <div class="flex justify-end gap-2 pt-2 border-t border-slate-200 dark:border-slate-800">
                <button type="button" onclick="document.getElementById('modalAddExistingTracks').classList.add('hidden')"
                        class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-xs font-semibold">
                    Abbrechen
                </button>
                <button type="submit" class="px-4 py-2 rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-xs font-semibold cursor-pointer">
                    Ausgewählte Titel hinzufügen
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($activePlaylist && !$isGuestMode && (int)$activePlaylist['user_id'] === $userId): ?>
<!-- MODAL 4: PLAYLIST BEARBEITEN / TEILEN / LÖSCHEN -->
<div id="modalEditPlaylist" class="hidden fixed inset-0 z-50 bg-black/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="mc-card w-full max-w-lg p-6 space-y-4 max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between">
            <h3 class="text-lg font-bold text-slate-900 dark:text-white">⚙️ Playlist bearbeiten &amp; teilen</h3>
            <button type="button" onclick="document.getElementById('modalEditPlaylist').classList.add('hidden')" class="text-slate-400 hover:text-slate-600">✕</button>
        </div>
        <form method="post" class="space-y-3.5">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="save_playlist">
            <input type="hidden" name="playlist_id" value="<?= (int)$activePlaylist['id'] ?>">

            <div>
                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Playlist-Name *</label>
                <input type="text" name="title" required value="<?= e((string)$activePlaylist['title']) ?>"
                       class="w-full px-3.5 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm">
            </div>

            <?php if (!empty($shareableUsers)): ?>
            <div>
                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Mit Cloud-Benutzer teilen</label>
                <select name="shared_user_id" class="w-full px-3.5 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm">
                    <option value="0">— Keine Einzelperson —</option>
                    <?php foreach ($shareableUsers as $su): ?>
                        <option value="<?= (int)$su['id'] ?>" <?= (int)($activePlaylist['shared_user_id'] ?? 0) === (int)$su['id'] ? 'selected' : '' ?>>
                            👤 <?= e((string)$su['username']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div>
                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Beschreibung</label>
                <textarea name="description" rows="2"
                          class="w-full px-3.5 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm"><?= e((string)($activePlaylist['description'] ?? '')) ?></textarea>
            </div>

            <div class="p-3.5 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 space-y-2.5">
                <label class="flex items-center gap-2.5 text-xs sm:text-sm font-semibold text-slate-800 dark:text-slate-100 cursor-pointer">
                    <input type="checkbox" name="enable_public_link" value="1" <?= !empty($activePlaylist['share_token']) ? 'checked' : '' ?> class="rounded text-rose-600">
                    🔗 Öffentlichen Gäste-Link &amp; QR-Code aktivieren
                </label>
                <div>
                    <input type="password" name="playlist_password" placeholder="<?= !empty($activePlaylist['password_hash']) ? 'Neues Passwort (leer lassen zum Behalten)…' : 'Optionales Passwort setzen…' ?>"
                           class="w-full px-3 py-1.5 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-xs">
                </div>
                <?php if (!empty($activePlaylist['password_hash'])): ?>
                    <label class="flex items-center gap-2 text-xs text-red-600 dark:text-red-400 cursor-pointer">
                        <input type="checkbox" name="clear_password" value="1">
                        Passwortschutz entfernen
                    </label>
                <?php endif; ?>
            </div>

            <div class="flex items-center justify-between pt-2">
                <button type="button"
                        onclick="if(confirm('Playlist wirklich löschen? (Alle Audiodateien bleiben in deiner Cloud erhalten)')) document.getElementById('deletePlaylistForm').submit();"
                        class="px-3.5 py-2 rounded-xl bg-red-50 dark:bg-red-950/60 text-red-600 dark:text-red-300 text-xs font-semibold cursor-pointer">
                    🗑️ Playlist löschen
                </button>
                <div class="flex gap-2">
                    <button type="button" onclick="document.getElementById('modalEditPlaylist').classList.add('hidden')"
                            class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-xs font-semibold">
                        Abbrechen
                    </button>
                    <button type="submit" class="px-4 py-2 rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-xs font-semibold cursor-pointer">
                        💾 Speichern
                    </button>
                </div>
            </div>
        </form>
        <form id="deletePlaylistForm" method="post" class="hidden">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="delete_playlist">
            <input type="hidden" name="playlist_id" value="<?= (int)$activePlaylist['id'] ?>">
        </form>
    </div>
</div>
<?php endif; ?>

<script>
const MUSIC_CSRF_TOKEN = <?= json_encode(getCsrfToken()) ?>;
const MUSIC_IS_GUEST   = <?= $isGuestMode ? 'true' : 'false' ?>;
const MUSIC_AUTOPLAY_ID = <?= (int)$autoPlayFileId ?>;

const MUSIC_TRACKS = <?= json_encode(array_map(static function (array $t) use ($playlistToken): array {
    $fid = (int)$t['id'];
    $tok = $playlistToken !== '' ? '&playlist_token=' . urlencode($playlistToken) : '';
    $hasCov = (int)($t['has_cover'] ?? 0) === 1;
    return [
        'id'        => $fid,
        'title'     => (string)$t['display_title'],
        'artist'    => (string)$t['display_artist'],
        'album'     => (string)$t['display_album'],
        'folder'    => (string)$t['folder_label'],
        'duration'  => (int)($t['cached_duration_sec'] ?? 0),
        'lastPos'   => (int)($t['last_position_sec'] ?? 0),
        'streamUrl' => 'music.php?stream_file=' . $fid . $tok,
        'coverUrl'  => $hasCov ? ('music.php?cover_file=' . $fid . $tok) : '',
    ];
}, $displayedTracks), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;

const audioEl        = document.getElementById('cloudAudioElement');
const seekSlider     = document.getElementById('playerSeekSlider');
const visCanvas      = document.getElementById('audioVisualizerCanvas');
const visCtx         = visCanvas ? visCanvas.getContext('2d') : null;

let currentTrackIdx  = -1;
let isSeekingSlider  = false;
let shuffleEnabled   = false;
let repeatMode       = 'all'; // 'all' | 'one' | 'off'
let lastSavedSec     = 0;

// Web Audio API (Visualizer & 3-Band Equalizer)
let audioCtx         = null;
let analyserNode     = null;
let eqLowNode        = null;
let eqMidNode        = null;
let eqHighNode       = null;
let visAnimId        = null;

// Sleep-Timer
let sleepTimerEndTs  = 0;
let sleepIntervalId  = null;

function formatAudioSeconds(sec) {
    if (!isFinite(sec) || sec < 0) sec = 0;
    const total = Math.floor(sec);
    const hrs   = Math.floor(total / 3600);
    const mins  = Math.floor((total % 3600) / 60);
    const secs  = total % 60;
    if (hrs > 0) {
        return `${hrs}:${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
    }
    return `${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
}

function ensureWebAudioGraph() {
    if (audioCtx || !audioEl) return;
    try {
        const AudioContextClass = window.AudioContext || window.webkitAudioContext;
        if (!AudioContextClass) return;
        audioCtx     = new AudioContextClass();
        const source = audioCtx.createMediaElementSource(audioEl);

        eqLowNode = audioCtx.createBiquadFilter();
        eqLowNode.type = 'lowshelf';
        eqLowNode.frequency.value = 180;

        eqMidNode = audioCtx.createBiquadFilter();
        eqMidNode.type = 'peaking';
        eqMidNode.frequency.value = 1800;
        eqMidNode.Q.value = 1.0;

        eqHighNode = audioCtx.createBiquadFilter();
        eqHighNode.type = 'highshelf';
        eqHighNode.frequency.value = 4500;

        analyserNode = audioCtx.createAnalyser();
        analyserNode.fftSize = 64;

        source.connect(eqLowNode);
        eqLowNode.connect(eqMidNode);
        eqMidNode.connect(eqHighNode);
        eqHighNode.connect(analyserNode);
        analyserNode.connect(audioCtx.destination);

        startVisualizerLoop();
    } catch (e) {}
}

function applyEqualizerPreset(preset) {
    ensureWebAudioGraph();
    if (!eqLowNode || !eqMidNode || !eqHighNode) return;
    if (preset === 'bass') {
        eqLowNode.gain.value  = 7.5;
        eqMidNode.gain.value  = 0;
        eqHighNode.gain.value = 1.5;
    } else if (preset === 'voice') {
        eqLowNode.gain.value  = -4.5;
        eqMidNode.gain.value  = 6.0;
        eqHighNode.gain.value = 2.5;
    } else if (preset === 'pop') {
        eqLowNode.gain.value  = 4.0;
        eqMidNode.gain.value  = -1.5;
        eqHighNode.gain.value = 5.0;
    } else {
        eqLowNode.gain.value  = 0;
        eqMidNode.gain.value  = 0;
        eqHighNode.gain.value = 0;
    }
}

function startVisualizerLoop() {
    if (!visCtx || !analyserNode) return;
    const bufferLen = analyserNode.frequencyBinCount;
    const dataArr   = new Uint8Array(bufferLen);

    const draw = () => {
        visAnimId = requestAnimationFrame(draw);
        visCtx.clearRect(0, 0, visCanvas.width, visCanvas.height);

        if (audioEl.paused) {
            // Dezente Ruheliniendarstellung
            const bars = 24;
            const barW = (visCanvas.width / bars) - 3;
            visCtx.fillStyle = 'rgba(244, 63, 94, 0.28)';
            for (let i = 0; i < bars; i++) {
                visCtx.fillRect(i * (barW + 3), visCanvas.height - 4, barW, 3);
            }
            return;
        }

        analyserNode.getByteFrequencyData(dataArr);
        const bars = 24;
        const barW = (visCanvas.width / bars) - 3;
        for (let i = 0; i < bars; i++) {
            const val  = dataArr[i] || 0;
            const barH = Math.max(3, (val / 255) * (visCanvas.height - 4));
            const grad = visCtx.createLinearGradient(0, visCanvas.height, 0, 0);
            grad.addColorStop(0, '#e11d48');
            grad.addColorStop(1, '#fda4af');
            visCtx.fillStyle = grad;
            visCtx.fillRect(i * (barW + 3), visCanvas.height - barH, barW, barH);
        }
    };
    draw();
}

function highlightActiveTrackRow() {
    document.querySelectorAll('.music-track-row').forEach((row, idx) => {
        const titleEl = row.querySelector('.track-title-label');
        const badgeEl = row.querySelector('.track-play-badge');
        if (idx === currentTrackIdx) {
            row.classList.add('bg-rose-50/90', 'dark:bg-rose-950/30');
            if (titleEl) titleEl.className = 'track-title-label text-xs sm:text-sm font-extrabold text-rose-600 dark:text-rose-400 truncate';
            if (badgeEl) {
                badgeEl.classList.remove('opacity-0');
                badgeEl.textContent = audioEl.paused ? '⏸️' : '🔊';
            }
        } else {
            row.classList.remove('bg-rose-50/90', 'dark:bg-rose-950/30');
            if (titleEl) titleEl.className = 'track-title-label text-xs sm:text-sm font-bold text-slate-900 dark:text-white truncate';
            if (badgeEl) {
                badgeEl.classList.add('opacity-0');
                badgeEl.textContent = '▶️';
            }
        }
    });
}

function updateMediaSessionMetadata(track) {
    if (!('mediaSession' in navigator) || !track) return;
    try {
        const artwork = track.coverUrl ? [{ src: track.coverUrl, sizes: '512x512', type: 'image/jpeg' }] : [];
        navigator.mediaSession.metadata = new MediaMetadata({
            title: track.title,
            artist: track.artist,
            album: track.album,
            artwork: artwork
        });
        navigator.mediaSession.setActionHandler('play', () => toggleAudioPlay());
        navigator.mediaSession.setActionHandler('pause', () => toggleAudioPlay());
        navigator.mediaSession.setActionHandler('previoustrack', () => stepTrack(-1));
        navigator.mediaSession.setActionHandler('nexttrack', () => stepTrack(1));
        navigator.mediaSession.setActionHandler('seekbackward', () => seekRelative(-15));
        navigator.mediaSession.setActionHandler('seekforward', () => seekRelative(15));
    } catch (e) {}
}

function playTrackAtIndex(index, autoStart = true) {
    if (!MUSIC_TRACKS.length) return;
    currentTrackIdx = ((index % MUSIC_TRACKS.length) + MUSIC_TRACKS.length) % MUSIC_TRACKS.length;
    const track = MUSIC_TRACKS[currentTrackIdx];
    if (!track) return;

    ensureWebAudioGraph();
    if (audioCtx && audioCtx.state === 'suspended') {
        audioCtx.resume();
    }

    document.getElementById('playerTitle').textContent       = track.title;
    document.getElementById('playerArtist').textContent      = track.artist;
    document.getElementById('playerAlbumFolder').textContent = `💿 ${track.album} • 📁 ${track.folder}`;

    document.getElementById('miniPlayerTitle').textContent   = track.title;
    document.getElementById('miniPlayerArtist').textContent  = track.artist;
    document.getElementById('bottomMiniPlayer').classList.remove('hidden');

    const coverImg = document.getElementById('playerCoverImg');
    const vinylFb  = document.getElementById('playerVinylFallback');
    if (track.coverUrl) {
        coverImg.src = track.coverUrl;
        coverImg.classList.remove('hidden');
        vinylFb.classList.add('hidden');
    } else {
        coverImg.classList.add('hidden');
        vinylFb.classList.remove('hidden');
    }

    const resumeBtn = document.getElementById('btnResumePosition');
    if (track.lastPos >= 10) {
        document.getElementById('resumePosLabel').textContent = formatAudioSeconds(track.lastPos);
        resumeBtn.classList.remove('hidden');
    } else {
        resumeBtn.classList.add('hidden');
    }

    audioEl.src = track.streamUrl;
    const rateSel = document.getElementById('playbackRateSelect');
    if (rateSel) {
        audioEl.playbackRate = parseFloat(rateSel.value) || 1.0;
    }

    updateMediaSessionMetadata(track);
    highlightActiveTrackRow();

    if (autoStart) {
        audioEl.play().catch(() => {});
        saveTrackProgressAjax(track.id, 0, true);
    }
}

function resumeSavedTrackPosition() {
    if (currentTrackIdx < 0 || !MUSIC_TRACKS[currentTrackIdx]) return;
    const pos = MUSIC_TRACKS[currentTrackIdx].lastPos || 0;
    if (pos > 0 && audioEl) {
        audioEl.currentTime = pos;
        document.getElementById('btnResumePosition').classList.add('hidden');
        if (audioEl.paused) {
            audioEl.play().catch(() => {});
        }
    }
}

function toggleAudioPlay() {
    if (!MUSIC_TRACKS.length) return;
    if (currentTrackIdx < 0) {
        playTrackAtIndex(0, true);
        return;
    }
    ensureWebAudioGraph();
    if (audioCtx && audioCtx.state === 'suspended') {
        audioCtx.resume();
    }
    if (audioEl.paused) {
        audioEl.play().catch(() => {});
    } else {
        audioEl.pause();
    }
}

function stepTrack(delta) {
    if (!MUSIC_TRACKS.length) return;
    if (shuffleEnabled && MUSIC_TRACKS.length > 1) {
        let rnd = currentTrackIdx;
        while (rnd === currentTrackIdx) {
            rnd = Math.floor(Math.random() * MUSIC_TRACKS.length);
        }
        playTrackAtIndex(rnd, true);
    } else {
        playTrackAtIndex((currentTrackIdx < 0 ? 0 : currentTrackIdx) + delta, true);
    }
}

function seekRelative(deltaSec) {
    if (!audioEl || !audioEl.src) return;
    const dur = audioEl.duration || 0;
    audioEl.currentTime = Math.max(0, Math.min(dur > 0 ? dur : 999999, audioEl.currentTime + deltaSec));
}

function onSeekSliderInput(val) {
    isSeekingSlider = true;
    const dur = audioEl.duration || 0;
    if (dur > 0) {
        const previewSec = (parseInt(val, 10) / 1000) * dur;
        document.getElementById('playerTimeCurrent').textContent = formatAudioSeconds(previewSec);
    }
}

function onSeekSliderCommit(val) {
    const dur = audioEl.duration || 0;
    if (dur > 0) {
        audioEl.currentTime = (parseInt(val, 10) / 1000) * dur;
    }
    isSeekingSlider = false;
}

function toggleShuffle() {
    shuffleEnabled = !shuffleEnabled;
    const btn = document.getElementById('btnShuffle');
    if (btn) {
        btn.textContent = shuffleEnabled ? '🔀 An' : '🔀 Aus';
        btn.className = shuffleEnabled
            ? 'px-2.5 py-1.5 rounded-lg bg-rose-600 text-white font-bold transition cursor-pointer'
            : 'px-2.5 py-1.5 rounded-lg bg-white/10 hover:bg-white/20 font-semibold transition cursor-pointer';
    }
}

function cycleRepeatMode() {
    if (repeatMode === 'all') repeatMode = 'one';
    else if (repeatMode === 'one') repeatMode = 'off';
    else repeatMode = 'all';

    const btn = document.getElementById('btnRepeat');
    if (btn) {
        if (repeatMode === 'all') btn.textContent = '🔁 Alle';
        else if (repeatMode === 'one') btn.textContent = '🔂 1 Titel';
        else btn.textContent = '➡️ Aus';
    }
}

function setAudioVolume(val) {
    if (!audioEl) return;
    audioEl.muted  = false;
    audioEl.volume = Math.max(0, Math.min(1, parseInt(val, 10) / 100));
    document.getElementById('btnMute').textContent = audioEl.volume === 0 ? '🔇' : '🔊';
}

function toggleMute() {
    if (!audioEl) return;
    audioEl.muted = !audioEl.muted;
    document.getElementById('btnMute').textContent = audioEl.muted ? '🔇' : '🔊';
}

function setAudioPlaybackRate(rateStr) {
    if (!audioEl) return;
    audioEl.playbackRate = parseFloat(rateStr) || 1.0;
}

function setSleepTimer(minutesStr) {
    const mins = parseInt(minutesStr, 10) || 0;
    const badge = document.getElementById('sleepTimerBadge');
    if (sleepIntervalId) {
        clearInterval(sleepIntervalId);
        sleepIntervalId = null;
    }
    if (mins <= 0) {
        sleepTimerEndTs = 0;
        if (badge) badge.classList.add('hidden');
        return;
    }
    sleepTimerEndTs = Date.now() + mins * 60000;
    if (badge) badge.classList.remove('hidden');

    const updateTick = () => {
        const remSec = Math.max(0, Math.round((sleepTimerEndTs - Date.now()) / 1000));
        const lbl = document.getElementById('sleepTimerCountdown');
        if (lbl) lbl.textContent = formatAudioSeconds(remSec);
        if (remSec <= 0) {
            clearInterval(sleepIntervalId);
            sleepIntervalId = null;
            if (audioEl && !audioEl.paused) {
                audioEl.pause();
            }
            if (badge) badge.classList.add('hidden');
            document.getElementById('sleepTimerSelect').value = '0';
        }
    };
    updateTick();
    sleepIntervalId = setInterval(updateTick, 1000);
}

function saveTrackProgressAjax(fileId, posSec, incPlay = false) {
    if (MUSIC_IS_GUEST || !fileId) return;
    const fd = new FormData();
    fd.append('csrf_token', MUSIC_CSRF_TOKEN);
    fd.append('action', 'save_progress');
    fd.append('file_id', String(fileId));
    fd.append('position_sec', String(Math.floor(posSec)));
    if (incPlay) fd.append('inc_play', '1');
    fetch('music.php', { method: 'POST', body: fd }).catch(() => {});
}

async function toggleTrackFavorite(fileId, btnEl) {
    if (MUSIC_IS_GUEST) return;
    const fd = new FormData();
    fd.append('csrf_token', MUSIC_CSRF_TOKEN);
    fd.append('action', 'toggle_favorite');
    fd.append('file_id', String(fileId));
    try {
        const resp = await fetch('music.php', { method: 'POST', body: fd });
        const data = await resp.json();
        if (data && data.ok && btnEl) {
            btnEl.textContent = data.is_favorite ? '❤️' : '🤍';
        }
    } catch (e) {}
}

if (audioEl) {
    audioEl.addEventListener('play', () => {
        document.getElementById('btnPlayPauseMain').textContent = '⏸️';
        document.getElementById('btnPlayPauseMini').textContent = '⏸️';
        const disc = document.getElementById('playerVinylDisc');
        if (disc) disc.classList.add('animate-spin');
        highlightActiveTrackRow();
    });

    audioEl.addEventListener('pause', () => {
        document.getElementById('btnPlayPauseMain').textContent = '▶️';
        document.getElementById('btnPlayPauseMini').textContent = '▶️';
        const disc = document.getElementById('playerVinylDisc');
        if (disc) disc.classList.remove('animate-spin');
        highlightActiveTrackRow();
        if (currentTrackIdx >= 0 && MUSIC_TRACKS[currentTrackIdx]) {
            saveTrackProgressAjax(MUSIC_TRACKS[currentTrackIdx].id, audioEl.currentTime, false);
        }
    });

    audioEl.addEventListener('timeupdate', () => {
        const cur = audioEl.currentTime || 0;
        const dur = audioEl.duration || (currentTrackIdx >= 0 && MUSIC_TRACKS[currentTrackIdx] ? MUSIC_TRACKS[currentTrackIdx].duration : 0);
        if (!isSeekingSlider && dur > 0) {
            seekSlider.value = String(Math.min(1000, Math.round((cur / dur) * 1000)));
        }
        document.getElementById('playerTimeCurrent').textContent   = formatAudioSeconds(cur);
        document.getElementById('playerTimeTotal').textContent     = formatAudioSeconds(dur);
        document.getElementById('playerTimeRemaining').textContent = dur > cur ? ('-' + formatAudioSeconds(dur - cur)) : '-00:00';

        // Alle 10 Sekunden Hörbuch-/Wiedergabeposition im Hintergrund sichern
        if (currentTrackIdx >= 0 && Math.abs(cur - lastSavedSec) >= 10) {
            lastSavedSec = cur;
            MUSIC_TRACKS[currentTrackIdx].lastPos = Math.floor(cur);
            saveTrackProgressAjax(MUSIC_TRACKS[currentTrackIdx].id, cur, false);
        }
    });

    audioEl.addEventListener('ended', () => {
        if (currentTrackIdx >= 0 && MUSIC_TRACKS[currentTrackIdx]) {
            MUSIC_TRACKS[currentTrackIdx].lastPos = 0;
            saveTrackProgressAjax(MUSIC_TRACKS[currentTrackIdx].id, 0, false);
        }
        if (repeatMode === 'one') {
            audioEl.currentTime = 0;
            audioEl.play().catch(() => {});
        } else if (repeatMode === 'all' || currentTrackIdx < MUSIC_TRACKS.length - 1 || shuffleEnabled) {
            stepTrack(1);
        }
    });
}

// Tastatursteuerung (Leertaste = Play/Pause, Pfeil links/rechts = -15s/+15s)
document.addEventListener('keydown', (e) => {
    const tag = (e.target && e.target.tagName) ? e.target.tagName.toLowerCase() : '';
    if (tag === 'input' || tag === 'textarea' || tag === 'select') return;
    if (e.code === 'Space') {
        e.preventDefault();
        toggleAudioPlay();
    }
});

// Falls ?play=<fileId> übergeben wurde (z.B. aus Dateivorschau), direkt diesen Titel starten!
if (MUSIC_AUTOPLAY_ID > 0 && MUSIC_TRACKS.length > 0) {
    const targetIdx = MUSIC_TRACKS.findIndex(t => t.id === MUSIC_AUTOPLAY_ID);
    if (targetIdx >= 0) {
        playTrackAtIndex(targetIdx, true);
    } else {
        playTrackAtIndex(0, false);
    }
} else if (MUSIC_TRACKS.length > 0) {
    playTrackAtIndex(0, false);
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
