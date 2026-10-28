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
        `cached_track_no` INT UNSIGNED NOT NULL DEFAULT 0,
        `cached_disc_no` INT UNSIGNED NOT NULL DEFAULT 0,
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

try {
    $colsMtm = $pdo->query("SHOW COLUMNS FROM `music_track_meta`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('cached_track_no', $colsMtm, true)) {
        $pdo->exec("ALTER TABLE `music_track_meta` ADD COLUMN `cached_track_no` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `cached_year`");
        $pdo->exec("ALTER TABLE `music_track_meta` ADD COLUMN `cached_disc_no` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `cached_track_no`");
    }
} catch (Throwable) {}

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
        'track_no'   => 0,
        'disc_no'    => 0,
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
                } elseif ($frameId === 'TRCK' && $res['track_no'] === 0) {
                    $tr = decodeId3TextFrame($payload);
                    if (preg_match('/^(\d{1,4})/', trim($tr), $m)) {
                        $res['track_no'] = (int)$m[1];
                    }
                } elseif ($frameId === 'TPOS' && $res['disc_no'] === 0) {
                    $ds = decodeId3TextFrame($payload);
                    if (preg_match('/^(\d{1,3})/', trim($ds), $m)) {
                        $res['disc_no'] = (int)$m[1];
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
                // ID3v1.1 Track-Nummer (Byte 125 == 0, Byte 126 = Track)
                if ($res['track_no'] === 0 && ord($v1[125]) === 0 && ord($v1[126]) > 0) {
                    $res['track_no'] = ord($v1[126]);
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

/**
 * Erkennt Kapitel-, Track- und CD/Teil-Nummerierungen intelligent aus Dateiname und Ordnerpfad
 * (z. B. "01 - Kapitel 1.mp3", "Kapitel 12.mp3", "CD2_Track05.mp3", "Teil 3 - 08.mp3").
 *
 * @return array{track_no:int,disc_no:int}
 */
function extractTrackAndDiscFromFilename(string $originalName, string $folderPath = ''): array
{
    $base = pathinfo($originalName, PATHINFO_FILENAME);
    $discNo  = 0;
    $trackNo = 0;

    // 1. CD / Disc / Teil im Ordnerpfad oder Dateinamen suchen (z. B. "CD 1", "CD2", "Disc 02", "Teil 3")
    $combined = $folderPath . ' / ' . $base;
    if (preg_match('/\b(?:cd|disc|disk|teil|part|buch|band)[\s._-]*0*(\d{1,3})\b/i', $combined, $mDisc)) {
        $discNo = (int)$mDisc[1];
    }

    // 2. Kombinationsmuster wie "1-05 Titel" oder "CD1_05" am Anfang des Dateinamens
    if (preg_match('/^\s*0*(\d{1,2})[-_.]0*(\d{1,4})\b/', $base, $mCombo)) {
        if ($discNo === 0) {
            $discNo = (int)$mCombo[1];
        }
        $trackNo = (int)$mCombo[2];
        return ['track_no' => $trackNo, 'disc_no' => $discNo];
    }

    // 3. Explizites "Kapitel X", "Chapter X", "Track X", "Folge X", "Episode X" im Dateinamen
    if (preg_match('/\b(?:kapitel|kap|chapter|chap|track|trk|folge|episode|ep|nr|no)[\s._#-]*0*(\d{1,4})\b/i', $base, $mKap)) {
        $trackNo = (int)$mKap[1];
        return ['track_no' => $trackNo, 'disc_no' => $discNo];
    }

    // 4. Führende Nummerierung am Dateianfang (z. B. "01 - ...", "007_...", "12. ...")
    if (preg_match('/^\s*0*(\d{1,4})(?:\s*[-_.)\]]|\s+)/', $base, $mLead)) {
        $cand = (int)$mLead[1];
        // Jahreszahlen wie "2024 - ..." nicht fälschlich als Kapitel werten, außer < 1900
        if ($cand > 0 && ($cand < 1900 || $cand > 2099)) {
            $trackNo = $cand;
            return ['track_no' => $trackNo, 'disc_no' => $discNo];
        }
    }

    // 5. Zahl ganz am Ende des Dateinamens (z. B. "Hörbuch Name 04")
    if (preg_match('/(?:[\s._-#]+|^)0*(\d{1,4})\s*$/', $base, $mTail)) {
        $cand = (int)$mTail[1];
        if ($cand > 0 && ($cand < 1900 || $cand > 2099)) {
            $trackNo = $cand;
        }
    }

    return ['track_no' => $trackNo, 'disc_no' => $discNo];
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

    $physicalPath = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . basename((string)$fileRow['filename']);
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

    $physicalPath = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . basename((string)$fileRow['filename']);
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

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

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

    // Passwortgeschützte Gast-Playlist entsperren (inkl. Brute-Force-Ratenbegrenzung)
    if ($action === 'unlock_playlist') {
        $plId = (int)($_POST['playlist_id'] ?? 0);
        $pass = (string)($_POST['playlist_password'] ?? '');
        $clientIp = getClientIp();

        if (isLoginBlocked($pdo, $clientIp)) {
            setFlash('error', 'Zu viele Fehlversuche. Bitte warte 5 Minuten.');
            header('Location: music.php?playlist_token=' . urlencode($playlistToken));
            exit;
        }

        $stmtP = $pdo->prepare('SELECT id, password_hash, share_token FROM music_playlists WHERE id = :id LIMIT 1');
        $stmtP->execute([':id' => $plId]);
        $plRow = $stmtP->fetch();

        if ($plRow && !empty($plRow['password_hash']) && password_verify($pass, (string)$plRow['password_hash'])) {
            recordLoginAttempt($pdo, $clientIp, true);
            $_SESSION['unlocked_music_playlists'][(int)$plRow['id']] = true;
            setFlash('success', 'Playlist erfolgreich entsperrt.');
        } else {
            recordLoginAttempt($pdo, $clientIp, false);
            setFlash('error', 'Das eingegebene Passwort ist leider falsch.');
        }
        header('Location: music.php?playlist_token=' . urlencode((string)($plRow['share_token'] ?? $playlistToken)));
        exit;
    }

    requireLogin();

    // AJAX: Favorit umschalten (❤️) – inkl. IDOR-Berechtigungsprüfung
    if ($action === 'toggle_favorite') {
        header('Content-Type: application/json; charset=UTF-8');
        $fid = (int)($_POST['file_id'] ?? 0);
        $stmtF = $pdo->prepare('SELECT * FROM files WHERE id = :id LIMIT 1');
        $stmtF->execute([':id' => $fid]);
        $fRow = $stmtF->fetch();
        if (!$fRow || !canPlayAudioFile($pdo, $fRow, $userId, '')) {
            echo json_encode(['ok' => false, 'error' => 'Keine Berechtigung']);
            exit;
        }

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

    // AJAX: Hörbuch-/Wiedergabeposition & Play-Counter speichern – inkl. IDOR-Berechtigungsprüfung
    if ($action === 'save_progress') {
        header('Content-Type: application/json; charset=UTF-8');
        $fid     = (int)($_POST['file_id'] ?? 0);
        $posSec  = max(0, min(864000, (int)($_POST['position_sec'] ?? 0)));
        $incPlay = !empty($_POST['inc_play']) ? 1 : 0;

        if ($fid > 0) {
            $stmtF = $pdo->prepare('SELECT * FROM files WHERE id = :id LIMIT 1');
            $stmtF->execute([':id' => $fid]);
            $fRow = $stmtF->fetch();
            if ($fRow && canPlayAudioFile($pdo, $fRow, $userId, '')) {
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
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    // Direkt-Upload von Audiodateien in den Ordner "Musik" (oder "Musik/<Unterordner>")
    if ($action === 'upload_audio') {
        $targetPlaylistId = (int)($_POST['playlist_id'] ?? 0);
        $subFolderRaw     = trim((string)($_POST['target_subfolder'] ?? ''));
        $subFolderClean   = preg_replace('/[^a-zA-Z0-9äöüÄÖÜß _\-\/]/u', '', $subFolderRaw);
        $subFolderClean   = normalizeFolderPath((string)$subFolderClean);
        $safeFolder       = $subFolderClean !== '' ? ('Musik/' . $subFolderClean) : 'Musik';

        ensureUploadDirReady(UPLOAD_DIR);
        registerFolderHierarchy($pdo, $userId, $safeFolder);

        $stats = getUserStorageStats($pdo, $userId);
        $usedBytes  = (int)$stats['used'];
        $quotaBytes = (int)$stats['quota'];

        $uploadedCount = 0;
        if (!empty($_FILES['audio_files']['name']) && is_array($_FILES['audio_files']['name'])) {
            $fileCount   = count($_FILES['audio_files']['name']);
            $allowedExts = ['mp3', 'm4a', 'wav', 'ogg', 'flac', 'aac', 'opus', 'webm'];

            for ($i = 0; $i < $fileCount; $i++) {
                if (($_FILES['audio_files']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    continue;
                }
                $tmpPath  = (string)$_FILES['audio_files']['tmp_name'][$i];
                $origName = sanitizeFileName(basename((string)$_FILES['audio_files']['name'][$i]));
                $fileSize = (int)$_FILES['audio_files']['size'][$i];
                $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

                if (!in_array($ext, $allowedExts, true) || $fileSize <= 0 || $fileSize > MAX_FILE_SIZE) {
                    continue;
                }
                if (function_exists('finfo_open')) {
                    $finfo    = finfo_open(FILEINFO_MIME_TYPE);
                    $realMime = $finfo ? (string)finfo_file($finfo, $tmpPath) : '';
                    if ($finfo) {
                        finfo_close($finfo);
                    }
                    if ($realMime !== '' && !str_starts_with($realMime, 'audio/') && !in_array($realMime, ['video/webm', 'video/mp4', 'video/ogg', 'application/ogg', 'application/octet-stream'], true)) {
                        continue;
                    }
                }
                if (($usedBytes + $fileSize) > $quotaBytes) {
                    setFlash('warning', 'Dein Cloud-Speicherkontingent ist ausgeschöpft.');
                    break;
                }

                $storedName = generateStoredFileName($ext);
                $destPath   = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . $storedName;

                if (move_uploaded_file($tmpPath, $destPath)) {
                    @chmod($destPath, 0640);
                    $usedBytes += $fileSize;
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
                    $mimeType = $mimeMap[$ext] ?? 'audio/mpeg';

                    $stmtIns = $pdo->prepare(
                        'INSERT INTO files (user_id, folder_path, filename, original_name, file_size, mime_type, is_encrypted)
                         VALUES (:uid, :fp, :fn, :on, :fs, :mt, 0)'
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
            setFlash('success', $uploadedCount . ' Audiodatei(en) erfolgreich in deinen Ordner „' . $safeFolder . '/“ hochgeladen!');
        } else {
            setFlash('warning', 'Bitte wähle gültige Audiodateien (.mp3, .m4a, .wav, .ogg, .flac, .aac, .opus) aus.');
        }
        $redirectQuery = $targetPlaylistId > 0
            ? '?playlist=' . $targetPlaylistId
            : ($subFolderClean !== '' ? '?folder=' . urlencode($safeFolder) . '&audiobook=1&sort=chapter' : '');
        header('Location: music.php' . $redirectQuery);
        exit;
    }

    // Playlist erstellen oder bearbeiten
    if ($action === 'save_playlist') {
        $playlistId       = (int)($_POST['playlist_id'] ?? 0);
        $title            = trim((string)($_POST['title'] ?? ''));
        $description      = trim((string)($_POST['description'] ?? ''));
        $candSharedUser   = (int)($_POST['shared_user_id'] ?? 0);
        $visiblePeerIds   = array_map(static fn(array $u): int => (int)$u['id'], getVisibleUsersForUser($pdo, $userId));
        $sharedUserId     = ($candSharedUser > 0 && in_array($candSharedUser, $visiblePeerIds, true)) ? $candSharedUser : null;
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
                $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
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
            $passwordHash = $newPassword !== '' ? password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]) : null;

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
$audiobookMode    = (isset($_GET['audiobook']) && $_GET['audiobook'] === '1') || $viewFilter === 'resume';
$defaultSort      = ($audiobookMode || $folderFilter !== '') ? 'chapter' : 'chapter';
$sortMode         = isset($_GET['sort']) && in_array($_GET['sort'], ['chapter', 'title_asc', 'artist_album', 'recent'], true)
    ? $_GET['sort']
    : $defaultSort;

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
                mtm.is_favorite, mtm.last_position_sec, mtm.play_count, mtm.updated_at AS meta_updated_at,
                mtm.cached_title, mtm.cached_artist, mtm.cached_album, mtm.cached_year,
                mtm.cached_track_no, mtm.cached_disc_no,
                mtm.cached_duration_sec, mtm.has_cover, mtm.meta_parsed
         FROM files f
         INNER JOIN users u ON u.id = f.user_id
         LEFT JOIN music_track_meta mtm ON mtm.file_id = f.id AND mtm.user_id = :uid_meta
         WHERE f.deleted_at IS NULL
           AND f.is_encrypted = 0
           AND f.group_folder_id IS NULL
           AND (
               LOWER(f.original_name) REGEXP '\\\\.(mp3|m4a|wav|ogg|flac|aac|opus|webm)$'
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
            (file_id, user_id, cached_title, cached_artist, cached_album, cached_year, cached_track_no, cached_disc_no, cached_duration_sec, has_cover, meta_parsed)
         VALUES (:fid, :uid, :t, :ar, :al, :yr, :trk, :dsc, :dur, :cov, 1)
         ON DUPLICATE KEY UPDATE
            cached_title = :t2, cached_artist = :ar2, cached_album = :al2,
            cached_year = :yr2, cached_track_no = :trk2, cached_disc_no = :dsc2,
            cached_duration_sec = :dur2, has_cover = :cov2, meta_parsed = 1'
    );

    foreach ($rawAudio as $row) {
        $fid = (int)$row['id'];
        $fnNums = extractTrackAndDiscFromFilename((string)$row['original_name'], (string)($row['folder_path'] ?? ''));

        if ((int)($row['meta_parsed'] ?? 0) !== 1) {
            $phys = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . basename((string)$row['filename']);
            $id3  = parseLocalAudioMetadata($phys, false);
            $trkNo = $id3['track_no'] > 0 ? $id3['track_no'] : $fnNums['track_no'];
            $dscNo = $id3['disc_no'] > 0 ? $id3['disc_no'] : $fnNums['disc_no'];

            $row['cached_title']        = $id3['title'];
            $row['cached_artist']       = $id3['artist'];
            $row['cached_album']        = $id3['album'];
            $row['cached_year']         = $id3['year'];
            $row['cached_track_no']     = $trkNo;
            $row['cached_disc_no']      = $dscNo;
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
                    ':trk'  => $trkNo,
                    ':dsc'  => $dscNo,
                    ':dur'  => $id3['duration'],
                    ':cov'  => $id3['has_cover'],
                    ':t2'   => $id3['title'] ?: null,
                    ':ar2'  => $id3['artist'] ?: null,
                    ':al2'  => $id3['album'] ?: null,
                    ':yr2'  => $id3['year'] ?: null,
                    ':trk2' => $trkNo,
                    ':dsc2' => $dscNo,
                    ':dur2' => $id3['duration'],
                    ':cov2' => $id3['has_cover'],
                ]);
            } catch (Throwable) {}
        } else {
            if ((int)($row['cached_track_no'] ?? 0) === 0 && $fnNums['track_no'] > 0) {
                $row['cached_track_no'] = $fnNums['track_no'];
            }
            if ((int)($row['cached_disc_no'] ?? 0) === 0 && $fnNums['disc_no'] > 0) {
                $row['cached_disc_no'] = $fnNums['disc_no'];
            }
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
            $phys = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . basename((string)$row['filename']);
            $id3  = parseLocalAudioMetadata($phys, false);
            $fnNums = extractTrackAndDiscFromFilename((string)$row['original_name'], (string)($row['folder_path'] ?? ''));
            $fallbackBase = pathinfo((string)$row['original_name'], PATHINFO_FILENAME);
            $row['display_title']       = $id3['title'] ?: $fallbackBase;
            $row['display_artist']      = $id3['artist'] ?: (string)$row['owner_name'];
            $row['display_album']       = $id3['album'] ?: (string)$activePlaylist['title'];
            $row['cached_track_no']     = $id3['track_no'] > 0 ? $id3['track_no'] : $fnNums['track_no'];
            $row['cached_disc_no']      = $id3['disc_no'] > 0 ? $id3['disc_no'] : $fnNums['disc_no'];
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

// ============================================================================
// INTELLIGENTE KAPITEL- & TRACK-SORTIERUNG (inkl. Natural Sort für Hörbücher)
// ============================================================================
if (!empty($displayedTracks)) {
    // Im Hörbuch-Modus immer nach Kapitel-/Tracknummer sortieren, sofern nicht explizit anders gewählt
    $effectiveSort = ($audiobookMode && !isset($_GET['sort'])) ? 'chapter' : $sortMode;

    if ($effectiveSort === 'chapter') {
        usort($displayedTracks, static function (array $a, array $b): int {
            // 1. Gleicher Ordner zuerst natürlich gruppieren
            $cmpFolder = strnatcasecmp((string)($a['folder_label'] ?? ''), (string)($b['folder_label'] ?? ''));
            if ($cmpFolder !== 0) {
                return $cmpFolder;
            }
            // 2. CD- / Teil-Nummer (1, 2, 3...)
            $discA = (int)($a['cached_disc_no'] ?? 0);
            $discB = (int)($b['cached_disc_no'] ?? 0);
            if ($discA !== $discB) {
                if ($discA === 0) return 1;
                if ($discB === 0) return -1;
                return $discA <=> $discB;
            }
            // 3. Kapitel- / Track-Nummer aus ID3 oder Dateiname (1, 2, ..., 10, 11)
            $trkA = (int)($a['cached_track_no'] ?? 0);
            $trkB = (int)($b['cached_track_no'] ?? 0);
            if ($trkA !== $trkB) {
                if ($trkA === 0) return 1;
                if ($trkB === 0) return -1;
                return $trkA <=> $trkB;
            }
            // 4. Natürlicher Vergleich des Original-Dateinamens ("Kapitel 2.mp3" vor "Kapitel 10.mp3")
            $cmpOrig = strnatcasecmp((string)($a['original_name'] ?? ''), (string)($b['original_name'] ?? ''));
            if ($cmpOrig !== 0) {
                return $cmpOrig;
            }
            return strnatcasecmp((string)($a['display_title'] ?? ''), (string)($b['display_title'] ?? ''));
        });
    } elseif ($effectiveSort === 'title_asc') {
        usort($displayedTracks, static function (array $a, array $b): int {
            $cmp = strnatcasecmp((string)($a['display_title'] ?? ''), (string)($b['display_title'] ?? ''));
            return $cmp !== 0 ? $cmp : strnatcasecmp((string)($a['original_name'] ?? ''), (string)($b['original_name'] ?? ''));
        });
    } elseif ($effectiveSort === 'artist_album') {
        usort($displayedTracks, static function (array $a, array $b): int {
            $cmpAr = strnatcasecmp((string)($a['display_artist'] ?? ''), (string)($b['display_artist'] ?? ''));
            if ($cmpAr !== 0) return $cmpAr;
            $cmpAl = strnatcasecmp((string)($a['display_album'] ?? ''), (string)($b['display_album'] ?? ''));
            if ($cmpAl !== 0) return $cmpAl;
            $discA = (int)($a['cached_disc_no'] ?? 0);
            $discB = (int)($b['cached_disc_no'] ?? 0);
            if ($discA !== $discB) return $discA <=> $discB;
            $trkA = (int)($a['cached_track_no'] ?? 0);
            $trkB = (int)($b['cached_track_no'] ?? 0);
            if ($trkA !== $trkB) return $trkA <=> $trkB;
            return strnatcasecmp((string)($a['original_name'] ?? ''), (string)($b['original_name'] ?? ''));
        });
    } elseif ($effectiveSort === 'recent') {
        usort($displayedTracks, static function (array $a, array $b): int {
            $cmp = strcmp((string)($b['upload_date'] ?? ''), (string)($a['upload_date'] ?? ''));
            return $cmp !== 0 ? $cmp : ((int)$b['id'] <=> (int)$a['id']);
        });
    }
}

// Zuletzt gehörten Track / Kapitel innerhalb der aktuellen Liste ermitteln (für "📖 Hörbuch nahtlos weiterhören")
$resumeChapterCandidate = null;
foreach ($displayedTracks as $idx => $t) {
    $pos = (int)($t['last_position_sec'] ?? 0);
    if ($pos >= 5) {
        $upd = (string)($t['meta_updated_at'] ?? '');
        if ($resumeChapterCandidate === null || strcmp($upd, $resumeChapterCandidate['updated_at']) > 0) {
            $resumeChapterCandidate = [
                'index'      => $idx,
                'chapter_no' => $idx + 1,
                'total'      => count($displayedTracks),
                'title'      => (string)$t['display_title'],
                'folder'     => (string)$t['folder_label'],
                'pos_sec'    => $pos,
                'updated_at' => $upd,
            ];
        }
    }
}

$shareableUsers = $userId > 0 ? getVisibleUsersForUser($pdo, $userId) : [];
$countFavorites = count(array_filter($allAudioFiles, static fn($t) => (int)($t['is_favorite'] ?? 0) === 1));
$countResume    = count(array_filter($allAudioFiles, static fn($t) => (int)($t['last_position_sec'] ?? 0) >= 10));

$pageTitle = $activePlaylist ? ('Playlist: ' . $activePlaylist['title']) : 'Musik- & Hörbuch-Studio';
require_once __DIR__ . '/includes/header.php';
?>

<main class="max-w-7xl mx-auto px-3 sm:px-6 py-3 sm:py-5 space-y-3 sm:space-y-5 pb-24">
    <!-- Kompakte Aktionsleiste (ohne sperrige Überschrift) -->
    <?php if (!$isGuestMode): ?>
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex items-center gap-2">
                <?php if ($activePlaylist): ?>
                    <a href="music.php"
                       class="px-3 py-1.5 rounded-xl text-xs font-semibold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-200 transition">
                        ← Alle Titel
                    </a>
                    <span class="text-sm font-bold text-slate-800 dark:text-slate-100 truncate max-w-[180px] sm:max-w-xs">
                        💿 <?= e((string)$activePlaylist['title']) ?>
                    </span>
                <?php else: ?>
                    <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-rose-100 dark:bg-rose-950/80 text-rose-700 dark:text-rose-300 border border-rose-200 dark:border-rose-800">
                        🎵 <?= count($displayedTracks) ?> Titel
                    </span>
                <?php endif; ?>
            </div>

            <div class="flex items-center gap-1.5 sm:gap-2">
                <button type="button"
                        onclick="document.getElementById('modalUploadAudio').classList.remove('hidden')"
                        class="px-3 py-1.5 rounded-xl text-xs font-semibold bg-emerald-600 hover:bg-emerald-700 text-white shadow-2xs transition cursor-pointer">
                    📤 <span class="hidden xs:inline">Musik / Hörbuch </span>Hochladen
                </button>

                <button type="button"
                        onclick="document.getElementById('modalCreatePlaylist').classList.remove('hidden')"
                        class="px-3 py-1.5 rounded-xl text-xs font-semibold bg-rose-600 hover:bg-rose-700 text-white shadow-2xs transition cursor-pointer">
                    ➕ Playlist
                </button>
            </div>
        </div>
    <?php endif; ?>

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
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-4 sm:gap-6 items-start">

            <!-- LINKE SPALTE (4 Spalten): KOMPAKTER STUDIO-PLAYER, VISUALIZER, KLANG-BOOSTER & SLEEP-TIMER -->
            <div class="lg:col-span-4 space-y-3 sm:space-y-4 lg:sticky lg:top-20">
                <div class="mc-card p-3.5 sm:p-5 bg-gradient-to-br from-slate-900 via-slate-900 to-rose-950 text-white rounded-2xl border border-slate-800 shadow-xl space-y-3">
                    <!-- Mobil: Cover links + Infos rechts | Desktop (lg): Cover zentriert -->
                    <div class="flex flex-row lg:flex-col items-center gap-3 lg:text-center">
                        <div id="playerCoverWrap" class="relative w-16 h-16 sm:w-20 sm:h-20 lg:w-40 lg:h-40 shrink-0 rounded-xl lg:rounded-2xl overflow-hidden shadow-xl border border-white/15 bg-gradient-to-br from-rose-600 via-indigo-700 to-slate-900 flex items-center justify-center">
                            <img id="playerCoverImg" src="" alt="Cover" class="hidden w-full h-full object-cover">
                            <div id="playerVinylFallback" class="flex flex-col items-center justify-center">
                                <div id="playerVinylDisc" class="w-12 h-12 sm:w-14 sm:h-14 lg:w-28 lg:h-28 rounded-full bg-[radial-gradient(circle,#f43f5e_0%,#be123c_22%,#0f172a_24%,#1e293b_48%,#0f172a_52%,#1e293b_75%,#020617_100%)] border-2 lg:border-4 border-white/20 flex items-center justify-center shadow-inner transition-transform duration-700">
                                    <div class="w-4 h-4 lg:w-7 lg:h-7 rounded-full bg-slate-950 border border-rose-400 flex items-center justify-center text-[9px] lg:text-xs">🎵</div>
                                </div>
                            </div>
                        </div>

                        <div class="min-w-0 flex-1 w-full">
                            <h2 id="playerTitle" class="text-sm sm:text-base lg:text-lg font-extrabold tracking-tight text-white line-clamp-1">
                                Kein Titel ausgewählt
                            </h2>
                            <p id="playerArtist" class="text-xs text-rose-300 font-medium line-clamp-1">
                                Wähle einen Titel oder tippe auf „▶️ Alle abspielen“
                            </p>
                            <p id="playerAlbumFolder" class="text-[11px] text-slate-400 line-clamp-1"></p>

                            <!-- Live-Frequenz-Visualizer (Web Audio API) -->
                            <canvas id="audioVisualizerCanvas" width="280" height="36" class="w-full h-7 lg:h-10 mt-1.5 lg:mt-2.5 rounded-lg bg-black/25 px-2"></canvas>

                            <!-- Hörbuch-Positions-Banner (falls gespeicherte Stelle vorhanden) -->
                            <button type="button" id="btnResumePosition" onclick="resumeSavedTrackPosition()"
                                    class="hidden mt-1.5 px-2.5 py-0.5 rounded-full bg-amber-500/20 hover:bg-amber-500/30 border border-amber-400/40 text-amber-200 text-[11px] font-bold transition cursor-pointer">
                                🔖 Weiterhören ab <span id="resumePosLabel">00:00</span>
                            </button>
                        </div>
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
                                class="w-9 h-9 sm:w-10 sm:h-10 rounded-full bg-white/10 hover:bg-white/20 flex items-center justify-center text-xs sm:text-sm font-bold transition cursor-pointer">
                            ⏮️
                        </button>
                        <button type="button" onclick="seekRelative(-15)" title="15 Sekunden zurückspulen (ideal für Hörbücher)"
                                class="px-2.5 h-8 sm:h-9 rounded-xl bg-white/10 hover:bg-white/20 text-xs font-bold transition cursor-pointer">
                            -15s
                        </button>
                        <button type="button" id="btnPlayPauseMain" onclick="toggleAudioPlay()" title="Abspielen / Pause (Leertaste)"
                                class="w-12 h-12 sm:w-14 sm:h-14 rounded-full bg-rose-600 hover:bg-rose-500 shadow-lg shadow-rose-600/40 flex items-center justify-center text-lg sm:text-xl font-bold transition cursor-pointer">
                            ▶️
                        </button>
                        <button type="button" onclick="seekRelative(15)" title="15 Sekunden vorspulen"
                                class="px-2.5 h-8 sm:h-9 rounded-xl bg-white/10 hover:bg-white/20 text-xs font-bold transition cursor-pointer">
                            +15s
                        </button>
                        <button type="button" onclick="stepTrack(1)" title="Nächster Titel"
                                class="w-9 h-9 sm:w-10 sm:h-10 rounded-full bg-white/10 hover:bg-white/20 flex items-center justify-center text-xs sm:text-sm font-bold transition cursor-pointer">
                            ⏭️
                        </button>
                    </div>

                    <!-- Zusatz-Steuerung: Hörbuch-Modus, Shuffle, Repeat, Lautstärke -->
                    <div class="flex flex-wrap items-center justify-between gap-1.5 pt-2 border-t border-white/10 text-xs">
                        <div class="flex flex-wrap items-center gap-1">
                            <button type="button" id="btnAudiobookMode" onclick="toggleAudiobookMode()"
                                    class="px-2 py-1 rounded-lg <?= $audiobookMode ? 'bg-amber-500 text-slate-950 font-bold shadow-xs' : 'bg-white/10 hover:bg-white/20 text-white font-semibold' ?> transition cursor-pointer"
                                    title="Hörbuch-Modus: Spielt Kapitel strikt der Reihe nach (1 → 99), deaktiviert Zufallswiedergabe und aktiviert klare Sprachwiedergabe">
                                📖 Hörbuch <?= $audiobookMode ? 'An' : 'Aus' ?>
                            </button>
                            <button type="button" id="btnShuffle" onclick="toggleShuffle()"
                                    class="px-2 py-1 rounded-lg bg-white/10 hover:bg-white/20 font-semibold transition cursor-pointer"
                                    title="Zufallswiedergabe (Shuffle)">
                                🔀 Aus
                            </button>
                            <button type="button" id="btnRepeat" onclick="cycleRepeatMode()"
                                    class="px-2 py-1 rounded-lg bg-white/10 hover:bg-white/20 font-semibold transition cursor-pointer"
                                    title="Wiederholungs-Modus umschalten">
                                🔁 Alle
                            </button>
                        </div>

                        <div class="hidden sm:flex items-center gap-1.5">
                            <button type="button" id="btnMute" onclick="toggleMute()" class="text-sm cursor-pointer" title="Stummschalten">🔊</button>
                            <input type="range" id="playerVolumeSlider" min="0" max="100" value="90"
                                   oninput="setAudioVolume(this.value)"
                                   class="w-16 accent-rose-500 cursor-pointer">
                        </div>
                    </div>

                    <!-- Kompakte 3er-Leiste für Klang-Profil (Equalizer), Tempo & Sleep-Timer -->
                    <div class="grid grid-cols-3 gap-1.5 pt-2 border-t border-white/10 text-[11px]">
                        <div class="flex flex-col gap-0.5">
                            <label for="eqPresetSelect" class="text-slate-400 font-semibold truncate">🎛️ Klang</label>
                            <select id="eqPresetSelect" onchange="applyEqualizerPreset(this.value)"
                                    class="w-full px-1.5 py-1 rounded-lg bg-slate-800 border border-slate-700 text-white text-[11px] font-semibold">
                                <option value="flat">Neutral</option>
                                <option value="bass">Bass+</option>
                                <option value="voice">Stimme</option>
                                <option value="pop">Pop</option>
                            </select>
                        </div>

                        <div class="flex flex-col gap-0.5">
                            <label for="playbackRateSelect" class="text-slate-400 font-semibold truncate">⏩ Tempo</label>
                            <select id="playbackRateSelect" onchange="setAudioPlaybackRate(this.value)"
                                    class="w-full px-1.5 py-1 rounded-lg bg-slate-800 border border-slate-700 text-white text-[11px] font-semibold">
                                <option value="0.75">0.75×</option>
                                <option value="1" selected>1.0×</option>
                                <option value="1.25">1.25×</option>
                                <option value="1.5">1.5×</option>
                                <option value="2">2.0×</option>
                            </select>
                        </div>

                        <div class="flex flex-col gap-0.5">
                            <label for="sleepTimerSelect" class="text-slate-400 font-semibold truncate">🌙 Timer</label>
                            <select id="sleepTimerSelect" onchange="setSleepTimer(this.value)"
                                    class="w-full px-1.5 py-1 rounded-lg bg-slate-800 border border-slate-700 text-white text-[11px] font-semibold">
                                <option value="0">Aus</option>
                                <option value="15">15 Min.</option>
                                <option value="30">30 Min.</option>
                                <option value="45">45 Min.</option>
                                <option value="60">60 Min.</option>
                            </select>
                        </div>
                    </div>
                    <div id="sleepTimerBadge" class="hidden text-center py-1 rounded-lg bg-indigo-500/20 border border-indigo-400/30 text-indigo-200 font-mono text-[11px]">
                        🌙 Stoppt in <span id="sleepTimerCountdown">00:00</span>
                    </div>
                </div>

                <!-- PLAYLISTS-ÜBERSICHT (Links unter dem Player; auf Mobil nur eingeblendet, wenn Playlists vorhanden sind) -->
                <?php if (!$isGuestMode): ?>
                <div class="mc-card p-3 sm:p-4 space-y-2 <?= empty($playlists) ? 'hidden lg:block' : '' ?>">
                    <div class="flex items-center justify-between">
                        <h3 class="text-xs sm:text-sm font-bold text-slate-900 dark:text-white flex items-center gap-1.5">
                            <span>📋</span> Playlists (<?= count($playlists) ?>)
                        </h3>
                        <button type="button"
                                onclick="document.getElementById('modalCreatePlaylist').classList.remove('hidden')"
                                class="text-xs font-bold text-rose-600 dark:text-rose-400 hover:underline cursor-pointer">
                            + Neu
                        </button>
                    </div>

                    <?php if (empty($playlists)): ?>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            Noch keine Playlist angelegt.
                        </p>
                    <?php else: ?>
                        <div class="flex lg:flex-col gap-1.5 overflow-x-auto lg:max-h-56 lg:overflow-y-auto pb-1 lg:pb-0">
                            <?php foreach ($playlists as $pl):
                                $pid = (int)$pl['id'];
                                $isAct = ($activePlaylistId === $pid);
                            ?>
                                <a href="music.php?playlist=<?= $pid ?>"
                                   class="flex items-center justify-between gap-2 px-2.5 py-1.5 rounded-xl text-xs font-semibold shrink-0 transition <?= $isAct ? 'bg-rose-600 text-white shadow-xs' : 'bg-slate-50 dark:bg-slate-800/70 hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-800 dark:text-slate-100' ?>">
                                    <div class="min-w-0 truncate max-w-[160px] lg:max-w-none">
                                        <span>💿 <?= e((string)$pl['title']) ?></span>
                                        <?php if (!empty($pl['share_token'])): ?>
                                            <span title="Öffentlicher Link aktiv">🔗</span>
                                        <?php endif; ?>
                                    </div>
                                    <span class="px-1.5 py-0.5 rounded-full text-[10px] <?= $isAct ? 'bg-white/20 text-white' : 'bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300' ?>">
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
                                <a href="music.php?filter=all<?= $folderFilter !== '' ? '&amp;folder=' . urlencode($folderFilter) : '' ?>&amp;sort=<?= urlencode($sortMode) ?>"
                                   class="px-3 py-1.5 rounded-xl text-xs font-bold transition <?= ($viewFilter === 'all' && !$audiobookMode) ? 'bg-rose-600 text-white shadow-2xs' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-200' ?>">
                                    🎵 Alle Titel (<?= count($allAudioFiles) ?>)
                                </a>
                                <a href="music.php?filter=all<?= $folderFilter !== '' ? '&amp;folder=' . urlencode($folderFilter) : '' ?>&amp;audiobook=<?= $audiobookMode ? '0' : '1' ?>&amp;sort=chapter"
                                   class="px-3 py-1.5 rounded-xl text-xs font-bold transition <?= $audiobookMode ? 'bg-amber-500 text-slate-950 shadow-2xs' : 'bg-amber-100/80 dark:bg-amber-950/50 text-amber-800 dark:text-amber-300 hover:bg-amber-200/80' ?>"
                                   title="Erkennt Kapitel- &amp; Dateinamen-Nummerierungen (01, 02... / Kapitel 1, 2...) und spielt sie garantiert der Reihe nach ab">
                                    📖 Hörbuch-Modus <?= $audiobookMode ? '✓' : '' ?>
                                </a>
                                <a href="music.php?filter=favorites&amp;sort=<?= urlencode($sortMode) ?>"
                                   class="px-3 py-1.5 rounded-xl text-xs font-bold transition <?= $viewFilter === 'favorites' ? 'bg-rose-600 text-white shadow-2xs' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-200' ?>">
                                    ❤️ Favoriten (<?= $countFavorites ?>)
                                </a>
                                <a href="music.php?filter=resume&amp;audiobook=1&amp;sort=chapter"
                                   class="px-3 py-1.5 rounded-xl text-xs font-bold transition <?= $viewFilter === 'resume' ? 'bg-rose-600 text-white shadow-2xs' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-200' ?>">
                                    🔖 Weiterhören (<?= $countResume ?>)
                                </a>
                            </div>

                            <?php if (!empty($displayedTracks)): ?>
                                <button type="button" onclick="playTrackAtIndex(0)"
                                        class="px-3.5 py-1.5 rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold shadow-2xs cursor-pointer">
                                    ▶️ <?= $audiobookMode ? 'Ab Kapitel 1 starten' : 'Alle abspielen' ?>
                                </button>
                            <?php endif; ?>
                        </div>

                        <form method="get" action="music.php" class="flex flex-wrap items-center gap-2 pt-2 border-t border-slate-200/70 dark:border-slate-800">
                            <input type="hidden" name="filter" value="<?= e($viewFilter) ?>">
                            <?php if ($audiobookMode): ?>
                                <input type="hidden" name="audiobook" value="1">
                            <?php endif; ?>
                            <div class="relative flex-1 min-w-[180px]">
                                <input type="text" name="q" value="<?= e($searchQuery) ?>"
                                       placeholder="🔍 Titel, Kapitel, Interpret oder Dateiname suchen…"
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
                            <select name="sort" onchange="this.form.submit()" title="Sortierreihenfolge der Audiodateien"
                                    class="px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/80 text-xs sm:text-sm font-semibold text-slate-800 dark:text-slate-100">
                                <option value="chapter" <?= $sortMode === 'chapter' ? 'selected' : '' ?>>🔢 Kapitel / Nummerierung (1 → 99)</option>
                                <option value="title_asc" <?= $sortMode === 'title_asc' ? 'selected' : '' ?>>🔤 Titel / Dateiname (A → Z)</option>
                                <option value="artist_album" <?= $sortMode === 'artist_album' ? 'selected' : '' ?>>💿 Interpret &amp; Album</option>
                                <option value="recent" <?= $sortMode === 'recent' ? 'selected' : '' ?>>🕒 Zuletzt hochgeladen</option>
                            </select>
                            <button type="submit" class="px-3.5 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs sm:text-sm font-semibold cursor-pointer">
                                Anwenden
                            </button>
                            <?php if ($searchQuery !== '' || $folderFilter !== ''): ?>
                                <a href="music.php?filter=<?= e($viewFilter) ?>" class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-xs font-semibold text-slate-600 dark:text-slate-300">
                                    ✕ Reset
                                </a>
                            <?php endif; ?>
                        </form>
                    </div>
                <?php endif; ?>

                <!-- 📖 HÖRBUCH-WEITERHÖREN-BANNER (zeigt das zuletzt gehörte Kapitel inkl. Sekunde) -->
                <?php if ($resumeChapterCandidate !== null): ?>
                    <div class="mc-card p-3.5 sm:p-4 bg-amber-50/90 dark:bg-amber-950/35 border border-amber-200 dark:border-amber-800/80 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                        <div class="flex items-center gap-3 min-w-0">
                            <span class="w-10 h-10 rounded-xl bg-amber-500/20 text-amber-700 dark:text-amber-300 flex items-center justify-center text-xl shrink-0">📖</span>
                            <div class="min-w-0">
                                <p class="text-xs font-bold uppercase tracking-wider text-amber-700 dark:text-amber-300">
                                    Hörbuch-Gedächtnis &bull; Datei <?= (int)$resumeChapterCandidate['chapter_no'] ?> von <?= (int)$resumeChapterCandidate['total'] ?>
                                </p>
                                <p class="text-xs sm:text-sm font-extrabold text-slate-900 dark:text-white truncate">
                                    <?= e((string)$resumeChapterCandidate['title']) ?>
                                    <span class="font-normal text-slate-500 dark:text-slate-400">
                                        (bei <?= sprintf('%02d:%02d', intdiv((int)$resumeChapterCandidate['pos_sec'], 60), (int)$resumeChapterCandidate['pos_sec'] % 60) ?> Min.)
                                    </span>
                                </p>
                            </div>
                        </div>
                        <button type="button"
                                onclick="playTrackAtIndexAndResume(<?= (int)$resumeChapterCandidate['index'] ?>, <?= (int)$resumeChapterCandidate['pos_sec'] ?>)"
                                class="px-4 py-2 rounded-xl bg-amber-600 hover:bg-amber-700 text-white text-xs font-bold shadow-xs shrink-0 cursor-pointer">
                            ▶️ Nahtlos weiterhören
                        </button>
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
                                $trkNo   = (int)($t['cached_track_no'] ?? 0);
                                $dscNo   = (int)($t['cached_disc_no'] ?? 0);
                                $hasCov  = (int)($t['has_cover'] ?? 0) === 1;
                                $tokParam = $playlistToken !== '' ? '&album_token=' . urlencode($playlistToken) : '';
                                $coverUrl = $hasCov ? ('music.php?cover_file=' . $fid . ($playlistToken !== '' ? '&playlist_token=' . urlencode($playlistToken) : '')) : '';
                                $durFormatted = $durSec > 0 ? sprintf('%02d:%02d', intdiv($durSec, 60), $durSec % 60) : formatBytes((int)$t['file_size']);
                                $chapterBadge = '';
                                if ($trkNo > 0) {
                                    $chapterBadge = ($dscNo > 0 ? 'CD' . $dscNo . ' · ' : '') . '#' . str_pad((string)$trkNo, 2, '0', STR_PAD_LEFT);
                                } elseif ($audiobookMode || $sortMode === 'chapter') {
                                    $chapterBadge = '#' . str_pad((string)($idx + 1), 2, '0', STR_PAD_LEFT);
                                }
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
                                                <span class="text-lg"><?= $audiobookMode ? '📖' : '🎵' ?></span>
                                            <?php endif; ?>
                                            <span class="track-play-badge absolute inset-0 bg-black/55 text-white opacity-0 group-hover:opacity-100 flex items-center justify-center text-xs font-bold transition">
                                                ▶️
                                            </span>
                                        </div>

                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-center gap-2">
                                                <?php if ($chapterBadge !== ''): ?>
                                                    <span class="px-1.5 py-0.5 rounded-md text-[10px] font-mono font-bold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 border border-slate-200 dark:border-slate-700 shrink-0"
                                                          title="Erkannte Kapitel- / Track-Folge">
                                                        <?= e($chapterBadge) ?>
                                                    </span>
                                                <?php endif; ?>
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
                <input type="file" name="audio_files[]" accept=".mp3,.m4a,.wav,.ogg,.flac,.aac,.opus,.webm,audio/*" multiple required
                       class="w-full text-xs sm:text-sm text-slate-700 dark:text-slate-200 file:mr-3 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-rose-600 file:text-white hover:file:bg-rose-700">
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                    Optionaler Unterordner unter <code>Musik/</code> (ideal für Hörbücher oder Alben)
                </label>
                <input type="text" name="target_subfolder" placeholder="z. B. Hörbücher/Der kleine Prinz (leer lassen für Musik/)"
                       class="w-full px-3.5 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs sm:text-sm">
                <p class="text-[11px] text-slate-500 mt-1">
                    Die Dateien werden automatisch gespeichert und auf ID3-Tags, Kapitelnummern &amp; Cover gescannt.
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
let audiobookModeActive = <?= $audiobookMode ? 'true' : 'false' ?>;

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
        'trackNo'   => (int)($t['cached_track_no'] ?? 0),
        'discNo'    => (int)($t['cached_disc_no'] ?? 0),
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

    const chapterPrefix = audiobookModeActive
        ? `📖 Kapitel ${currentTrackIdx + 1}/${MUSIC_TRACKS.length} • `
        : (track.trackNo > 0 ? `#${String(track.trackNo).padStart(2, '0')} • ` : '');

    document.getElementById('playerTitle').textContent       = track.title;
    document.getElementById('playerArtist').textContent      = track.artist;
    document.getElementById('playerAlbumFolder').textContent = `${chapterPrefix}💿 ${track.album} • 📁 ${track.folder}`;

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

function playTrackAtIndexAndResume(index, posSec) {
    playTrackAtIndex(index, true);
    if (posSec > 0 && audioEl) {
        const applySeek = () => {
            audioEl.currentTime = posSec;
            const rBtn = document.getElementById('btnResumePosition');
            if (rBtn) rBtn.classList.add('hidden');
            audioEl.removeEventListener('loadedmetadata', applySeek);
        };
        if (audioEl.readyState >= 1) {
            applySeek();
        } else {
            audioEl.addEventListener('loadedmetadata', applySeek);
        }
    }
}

function toggleAudiobookMode() {
    audiobookModeActive = !audiobookModeActive;
    const btn = document.getElementById('btnAudiobookMode');
    if (btn) {
        btn.textContent = audiobookModeActive ? '📖 Hörbuch An' : '📖 Hörbuch Aus';
        btn.className = audiobookModeActive
            ? 'px-2.5 py-1.5 rounded-lg bg-amber-500 text-slate-950 font-bold shadow-xs transition cursor-pointer'
            : 'px-2.5 py-1.5 rounded-lg bg-white/10 hover:bg-white/20 text-white font-semibold transition cursor-pointer';
    }
    if (audiobookModeActive) {
        // Im Hörbuch-Modus Shuffle ausschalten, damit Kapitel immer strikt der Reihe nach abgespielt werden
        if (shuffleEnabled) {
            toggleShuffle();
        }
        // Klang-Profil "Stimme / Hörbuch" vorwählen
        const eqSel = document.getElementById('eqPresetSelect');
        if (eqSel) {
            eqSel.value = 'voice';
            applyEqualizerPreset('voice');
        }
    }
    if (currentTrackIdx >= 0 && MUSIC_TRACKS[currentTrackIdx]) {
        const track = MUSIC_TRACKS[currentTrackIdx];
        const chapterPrefix = audiobookModeActive
            ? `📖 Kapitel ${currentTrackIdx + 1}/${MUSIC_TRACKS.length} • `
            : (track.trackNo > 0 ? `#${String(track.trackNo).padStart(2, '0')} • ` : '');
        document.getElementById('playerAlbumFolder').textContent = `${chapterPrefix}💿 ${track.album} • 📁 ${track.folder}`;
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
    if (audiobookModeActive && !shuffleEnabled) {
        if (!confirm('Der Hörbuch-Modus ist aktiv (Kapitel 1 → 99). Möchtest du trotzdem die Zufallswiedergabe einschalten?')) {
            return;
        }
    }
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

// Falls Hörbuch-Modus aktiv ist, Klangprofil "Stimme / Hörbuch" vorwählen
if (audiobookModeActive) {
    const eqSel = document.getElementById('eqPresetSelect');
    if (eqSel) eqSel.value = 'voice';
}

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
