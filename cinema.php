<?php
declare(strict_types=1);

/**
 * 🍿 MiniCloud Heimkino & Mediathek (cinema.php)
 *
 * Privates Streaming-Portal & Heimkino für alle Cloud-Videos (.mp4, .webm, .mov, .m4v, .mkv, .ogv):
 * - 🛋️ Kinosaal-Bühne mit Echtzeit-Ambilight (HTML5 Canvas Ambient Glow) & „🌑 Saallicht dimmen“
 * - ⏯️ „Weiterschauen“-Automatik (merkt sich sekundengenau die Abspielposition je Benutzer)
 * - 🖼️ 1-Klick Film-Poster-Schnappschuss direkt aus dem laufenden Video (plus automatische Poster-Vorschau)
 * - 🔊 WebAudio Kino-Sound-Booster (Original, 🍿 Kino-Bass, 🗣️ Dialog-Verstärker, 🌙 Nachtmodus)
 * - 💬 Untertitel-Unterstützung (.srt & .vtt aus der Cloud oder lokal, inkl. Live-SRT->WebVTT-Konverter)
 * - 🔖 Szenen-Sprungmarken (Kapitel / Lieblingsszenen mit Zeitstempel)
 * - 📺 Filmreihen / Serien-Playlists mit Autoplay der nächsten Folge & 🎟️ Gäste-Kino-Ticket per QR-Code
 * - 100 % STRATO-Shared-Hosting-tauglich (nutzt HTTP 206 Partial Content Range-Streaming).
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$pdo = getDB();
$appSettings = getAppSettings($pdo);

if (($appSettings['enable_cinema'] ?? '1') !== '1') {
    setFlash('error', 'Das Modul „Heimkino & Mediathek“ ist derzeit deaktiviert.');
    header('Location: index.php');
    exit;
}

/**
 * Stellt sicher, dass die Tabellen für das Heimkino existieren.
 */
function ensureCinemaSchema(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured || !empty($_SESSION['_mc_cinema_schema_ok'])) {
        $ensured = true;
        return;
    }
    $ensured = true;

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `cinema_media_meta` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `file_id` INT UNSIGNED NOT NULL,
            `custom_title` VARCHAR(190) NOT NULL DEFAULT '',
            `category` VARCHAR(50) NOT NULL DEFAULT 'allgemein',
            `release_year` SMALLINT UNSIGNED DEFAULT NULL,
            `description` TEXT DEFAULT NULL,
            `poster_data_uri` MEDIUMTEXT DEFAULT NULL,
            `subtitle_file_id` INT UNSIGNED DEFAULT NULL,
            `bookmarks_json` TEXT DEFAULT NULL,
            `duration_sec` INT UNSIGNED NOT NULL DEFAULT 0,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_cinema_file` (`file_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `cinema_user_state` (
            `user_id` INT UNSIGNED NOT NULL,
            `file_id` INT UNSIGNED NOT NULL,
            `last_position_sec` INT UNSIGNED NOT NULL DEFAULT 0,
            `duration_sec` INT UNSIGNED NOT NULL DEFAULT 0,
            `is_watchlist` TINYINT(1) NOT NULL DEFAULT 0,
            `is_favorite` TINYINT(1) NOT NULL DEFAULT 0,
            `play_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `last_watched_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`user_id`, `file_id`),
            KEY `idx_user_watched` (`user_id`, `last_watched_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `cinema_playlists` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT UNSIGNED NOT NULL,
            `title` VARCHAR(150) NOT NULL,
            `description` VARCHAR(255) NOT NULL DEFAULT '',
            `share_token` VARCHAR(64) DEFAULT NULL,
            `password_hash` VARCHAR(255) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_cinema_share_token` (`share_token`),
            KEY `idx_cinema_pl_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `cinema_playlist_items` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `playlist_id` INT UNSIGNED NOT NULL,
            `file_id` INT UNSIGNED NOT NULL,
            `sort_order` INT NOT NULL DEFAULT 0,
            UNIQUE KEY `uq_cinema_pl_item` (`playlist_id`, `file_id`),
            KEY `idx_cinema_pl_sort` (`playlist_id`, `sort_order`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['_mc_cinema_schema_ok'] = 1;
    }
}

ensureCinemaSchema($pdo);

$cinemaCategories = [
    'allgemein'  => ['label' => 'Allgemein / Clip',     'icon' => '🎞️', 'badge' => 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300'],
    'spielfilm'  => ['label' => 'Spielfilm & Kino',     'icon' => '🎬', 'badge' => 'bg-amber-100 dark:bg-amber-950/80 text-amber-800 dark:text-amber-300'],
    'serie'      => ['label' => 'Serie & Mehrteiler',   'icon' => '📺', 'badge' => 'bg-violet-100 dark:bg-violet-950/80 text-violet-700 dark:text-violet-300'],
    'urlaub'     => ['label' => 'Urlaub & Familie',     'icon' => '🏖️', 'badge' => 'bg-emerald-100 dark:bg-emerald-950/80 text-emerald-700 dark:text-emerald-300'],
    'sport'      => ['label' => 'Action, Surf & Sport', 'icon' => '🏄‍♂️', 'badge' => 'bg-cyan-100 dark:bg-cyan-950/80 text-cyan-700 dark:text-cyan-300'],
    'doku'       => ['label' => 'Doku & Wissen',        'icon' => '🌍', 'badge' => 'bg-blue-100 dark:bg-blue-950/80 text-blue-700 dark:text-blue-300'],
    'musikvideo' => ['label' => 'Konzert & Musikvideo', 'icon' => '🎵', 'badge' => 'bg-rose-100 dark:bg-rose-950/80 text-rose-700 dark:text-rose-300'],
];

/**
 * Formatiert Sekunden als "1 Std. 42 Min." oder "14:08".
 */
function formatCinemaDuration(int $seconds, bool $verbose = false): string
{
    if ($seconds <= 0) {
        return $verbose ? '–' : '00:00';
    }
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    if ($verbose) {
        if ($h > 0) {
            return sprintf('%d Std. %02d Min.', $h, $m);
        }
        return sprintf('%d Min. %02d Sek.', $m, $s);
    }
    if ($h > 0) {
        return sprintf('%d:%02d:%02d', $h, $m, $s);
    }
    return sprintf('%02d:%02d', $m, $s);
}

/**
 * Erzeugt einen sauberen Filmtitel aus einem Dateinamen, falls noch kein eigener Titel vergeben wurde.
 */
function deriveCleanMovieTitle(string $originalName): string
{
    $base = (string)pathinfo($originalName, PATHINFO_FILENAME);
    $base = str_replace(['_', '.'], ' ', $base);
    $base = trim((string)preg_replace('/\s+/', ' ', $base));
    return $base !== '' ? $base : $originalName;
}

// ============================================================================
// 1. ÖFFENTLICHES GÄSTE-KINO-TICKET (cinema.php?ticket=...)
// ============================================================================
$ticketToken = trim((string)($_GET['ticket'] ?? $_POST['ticket'] ?? ''));
if ($ticketToken !== '') {
    $stmtTicket = $pdo->prepare(
        "SELECT p.*, u.username AS owner_name
         FROM `cinema_playlists` p
         INNER JOIN `users` u ON u.id = p.user_id
         WHERE p.share_token = :tok
         LIMIT 1"
    );
    $stmtTicket->execute([':tok' => $ticketToken]);
    $ticketPlaylist = $stmtTicket->fetch();

    if (!$ticketPlaylist) {
        http_response_code(404);
        die('Dieses Kino-Ticket ist ungültig oder wurde vom Ersteller geschlossen.');
    }

    $ticketPlId = (int)$ticketPlaylist['id'];
    $hasPassword = !empty($ticketPlaylist['password_hash']);
    $sessionAuthKey = 'mc_cinema_ticket_ok_' . $ticketPlId;

    if ($hasPassword && empty($_SESSION[$sessionAuthKey])) {
        $pwError = '';
        $clientIp = getClientIp();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['ticket_password'])) {
            $csrf = (string)($_POST['csrf_token'] ?? '');
            if (!verifyCsrfToken($csrf)) {
                $pwError = 'Ungültiges Sicherheits-Token (CSRF). Bitte Seite neu laden.';
            } elseif (isLoginRateLimited($pdo, $clientIp)) {
                $pwError = 'Zu viele Fehlversuche. Bitte warte 5 Minuten.';
            } else {
                $inputPw = (string)$_POST['ticket_password'];
                if (password_verify($inputPw, (string)$ticketPlaylist['password_hash'])) {
                    $_SESSION[$sessionAuthKey] = true;
                } else {
                    recordLoginFailure($pdo, $clientIp, 'cinema_ticket_' . $ticketPlId);
                    $pwError = 'Das eingegebene Kino-Ticket-Passwort ist leider falsch.';
                }
            }
        }

        if (empty($_SESSION[$sessionAuthKey])) {
            $pageTitle = '🎟️ Kino-Ticket – ' . (string)$ticketPlaylist['title'];
            require_once __DIR__ . '/includes/header.php';
            ?>
            <div class="max-w-md mx-auto my-12 bg-slate-900 text-white rounded-3xl border border-amber-500/40 p-6 sm:p-8 shadow-2xl text-center space-y-4">
                <div class="w-14 h-14 mx-auto rounded-2xl bg-amber-500/20 border border-amber-500/40 flex items-center justify-center text-3xl">🎟️</div>
                <h1 class="text-xl font-extrabold">Passwortgeschütztes Kino-Ticket</h1>
                <p class="text-xs text-slate-300">
                    <strong><?= e((string)$ticketPlaylist['owner_name']) ?></strong> hat dich zum Filmabend <strong>„<?= e((string)$ticketPlaylist['title']) ?>“</strong> eingeladen.
                </p>
                <?php if ($pwError !== ''): ?>
                    <div class="p-3 rounded-xl bg-red-950/80 border border-red-700 text-xs text-red-200 font-semibold">
                        <?= e($pwError) ?>
                    </div>
                <?php endif; ?>
                <form method="post" class="space-y-3 text-left">
                    <?= csrfField() ?>
                    <input type="hidden" name="ticket" value="<?= e($ticketToken) ?>">
                    <div>
                        <label class="block text-xs font-bold text-slate-300 mb-1">Ticket-Passwort eingeben</label>
                        <input type="password" name="ticket_password" required autofocus
                               class="w-full px-3.5 py-2.5 rounded-xl bg-slate-800 border border-slate-700 text-white text-sm">
                    </div>
                    <button type="submit" class="w-full py-2.5 rounded-xl bg-amber-500 hover:bg-amber-400 text-slate-950 font-extrabold text-sm transition-colors cursor-pointer">
                        🍿 Kinosaal betreten
                    </button>
                </form>
            </div>
            <?php
            require_once __DIR__ . '/includes/footer.php';
            exit;
        }
    }

    // Dateien dieser Playlist laden
    $stmtItems = $pdo->prepare(
        "SELECT f.id, f.filename, f.original_name, f.file_size, f.mime_type, f.is_encrypted,
                m.custom_title, m.category, m.release_year, m.description, m.poster_data_uri, m.duration_sec
         FROM `cinema_playlist_items` pi
         INNER JOIN `files` f ON f.id = pi.file_id
         LEFT JOIN `cinema_media_meta` m ON m.file_id = f.id
         WHERE pi.playlist_id = :pid AND f.deleted_at IS NULL AND f.is_encrypted = 0
         ORDER BY pi.sort_order ASC, pi.id ASC"
    );
    $stmtItems->execute([':pid' => $ticketPlId]);
    $ticketMovies = $stmtItems->fetchAll();

    // Direktes HTTP-206-Streaming einer freigegebenen Filmdatei über das Ticket
    if (isset($_GET['stream_file'])) {
        $reqFileId = (int)$_GET['stream_file'];
        $allowedFile = null;
        foreach ($ticketMovies as $tm) {
            if ((int)$tm['id'] === $reqFileId) {
                $allowedFile = $tm;
                break;
            }
        }
        if (!$allowedFile) {
            http_response_code(403);
            exit('Zugriff nicht erlaubt.');
        }
        $filePath = UPLOAD_DIR . $allowedFile['filename'];
        if (!is_file($filePath)) {
            http_response_code(404);
            exit('Videodatei nicht gefunden.');
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $fileSize = (int)filesize($filePath);
        $mimeType = (string)($allowedFile['mime_type'] ?: 'video/mp4');
        $start = 0;
        $maxChunk = 4 * 1024 * 1024;
        $end = $fileSize > 0 ? ($fileSize - 1) : 0;
        $isPartial = false;

        if (isset($_SERVER['HTTP_RANGE']) && $fileSize > 0 && preg_match('/bytes=(\d*)-(\d*)/i', (string)$_SERVER['HTTP_RANGE'], $m)) {
            $rStart = $m[1] !== '' ? (int)$m[1] : null;
            $rEnd   = $m[2] !== '' ? (int)$m[2] : null;
            if ($rStart === null && $rEnd !== null) {
                $start = max(0, $fileSize - $rEnd);
            } elseif ($rStart !== null) {
                $start = $rStart;
                if ($rEnd !== null && $rEnd < $fileSize) {
                    $end = min($rEnd, $start + $maxChunk - 1);
                } else {
                    $end = min($fileSize - 1, $start + $maxChunk - 1);
                }
            }
            if ($start > $end || $start >= $fileSize) {
                http_response_code(416);
                header('Content-Range: bytes */' . $fileSize);
                exit;
            }
            $isPartial = true;
        }

        $length = ($fileSize > 0) ? ($end - $start + 1) : 0;
        if (ob_get_level() > 0) {
            @ob_end_clean();
        }
        if ($isPartial) {
            http_response_code(206);
            header(sprintf('Content-Range: bytes %d-%d/%d', $start, $end, $fileSize));
        } else {
            http_response_code(200);
        }
        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . $length);
        header('Accept-Ranges: bytes');
        header('Cache-Control: private, max-age=3600');

        $fp = fopen($filePath, 'rb');
        if ($fp !== false) {
            if ($start > 0) {
                fseek($fp, $start);
            }
            $rem = $length;
            while ($rem > 0 && !feof($fp)) {
                $buf = fread($fp, min(65536, $rem));
                if ($buf === false || $buf === '') {
                    break;
                }
                echo $buf;
                $rem -= strlen($buf);
                flush();
            }
            fclose($fp);
        }
        exit;
    }

    // Gäste-Kinosaal rendern
    $pageTitle = '🍿 Gäste-Kino: ' . (string)$ticketPlaylist['title'];
    require_once __DIR__ . '/includes/header.php';
    ?>
    <div class="max-w-5xl mx-auto space-y-6">
        <div class="rounded-3xl bg-gradient-to-r from-slate-950 via-red-950 to-slate-950 text-white p-6 sm:p-8 shadow-2xl border border-amber-500/30 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div class="space-y-1">
                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[11px] font-bold uppercase tracking-wider bg-amber-500/20 text-amber-300 border border-amber-400/30">
                    🎟️ Einladung zum Filmabend von <?= e((string)$ticketPlaylist['owner_name']) ?>
                </span>
                <h1 class="text-2xl sm:text-3xl font-extrabold">🍿 <?= e((string)$ticketPlaylist['title']) ?></h1>
                <?php if (!empty($ticketPlaylist['description'])): ?>
                    <p class="text-xs sm:text-sm text-slate-300"><?= e((string)$ticketPlaylist['description']) ?></p>
                <?php endif; ?>
            </div>
            <div class="text-right text-xs text-amber-300 font-semibold">
                <?= count($ticketMovies) ?> <?= count($ticketMovies) === 1 ? 'Film' : 'Filme / Folgen' ?>
            </div>
        </div>

        <?php if (empty($ticketMovies)): ?>
            <div class="p-8 rounded-2xl bg-slate-900 text-slate-300 text-center">
                In dieser Filmreihe befinden sich aktuell noch keine Videos.
            </div>
        <?php else: ?>
            <?php $firstMovie = $ticketMovies[0]; ?>
            <div class="rounded-3xl bg-slate-950 border border-slate-800 p-4 sm:p-6 shadow-2xl space-y-4">
                <div class="aspect-video w-full rounded-2xl overflow-hidden bg-black border border-slate-800 shadow-xl">
                    <video id="guestCinemaVideo" controls playsinline preload="metadata" class="w-full h-full object-contain"
                           src="cinema.php?ticket=<?= e(urlencode($ticketToken)) ?>&stream_file=<?= (int)$firstMovie['id'] ?>">
                    </video>
                </div>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 id="guestCinemaTitle" class="text-lg font-extrabold text-white">
                        <?= e($firstMovie['custom_title'] !== '' ? (string)$firstMovie['custom_title'] : deriveCleanMovieTitle((string)$firstMovie['original_name'])) ?>
                    </h2>
                    <span class="text-xs text-slate-400">Tipp: Mit Doppelklick aufs Video in den Vollbildmodus wechseln</span>
                </div>
                <?php if (count($ticketMovies) > 1): ?>
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3 pt-3 border-t border-slate-800">
                        <?php foreach ($ticketMovies as $idx => $tm): ?>
                            <?php $tTitle = $tm['custom_title'] !== '' ? (string)$tm['custom_title'] : deriveCleanMovieTitle((string)$tm['original_name']); ?>
                            <button type="button"
                                    onclick="document.getElementById('guestCinemaVideo').src='cinema.php?ticket=<?= e(urlencode($ticketToken)) ?>&stream_file=<?= (int)$tm['id'] ?>'; document.getElementById('guestCinemaVideo').play(); document.getElementById('guestCinemaTitle').textContent=this.getAttribute('data-title');"
                                    data-title="<?= e($tTitle) ?>"
                                    class="p-3 rounded-xl bg-slate-900 hover:bg-slate-800 border border-slate-800 text-left transition-colors cursor-pointer flex items-center gap-3">
                                <span class="w-7 h-7 rounded-lg bg-amber-500/20 text-amber-300 font-extrabold text-xs flex items-center justify-center shrink-0">
                                    <?= $idx + 1 ?>
                                </span>
                                <div class="min-w-0">
                                    <p class="text-xs font-bold text-white truncate"><?= e($tTitle) ?></p>
                                    <p class="text-[11px] text-slate-400"><?= e(formatBytes((int)$tm['file_size'])) ?></p>
                                </div>
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// ============================================================================
// 2. REGULÄRER BETRIEB FÜR ANGEMELDETE CLOUD-BENUTZER
// ============================================================================
requireLogin();
$userId = (int)$_SESSION['user_id'];

/**
 * Prüft, ob der aktuelle Benutzer Leserechte auf eine Videodatei hat.
 *
 * @return array<string, mixed>|null
 */
function getAccessibleCinemaFile(PDO $pdo, int $fileId, int $userId): ?array
{
    if ($fileId <= 0) {
        return null;
    }
    $stmt = $pdo->prepare(
        "SELECT f.*
         FROM `files` f
         WHERE f.id = :id AND f.deleted_at IS NULL
         LIMIT 1"
    );
    $stmt->execute([':id' => $fileId]);
    $f = $stmt->fetch();
    if (!$f) {
        return null;
    }
    if ((int)$f['user_id'] === $userId) {
        return $f;
    }
    if (!empty($f['group_folder_id'])) {
        $perm = getUserGroupFolderPermission($pdo, $userId, (int)$f['group_folder_id']);
        if ($perm !== null) {
            return $f;
        }
    }
    if (canUserAccessSharedFile($pdo, $f, $userId)) {
        return $f;
    }
    return null;
}

// ============================================================================
// 3. AJAX-ENDPUNKTE (Fortschritt speichern, Watchlist, Poster, Metadaten, Untertitel)
// ============================================================================
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json; charset=UTF-8');

    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!verifyCsrfToken($csrf)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Ungültiges CSRF-Token.']);
        exit;
    }

    // Session-Sperre sofort nach CSRF-Prüfung freigeben, damit paralleles Video-Streaming niemals blockiert wird
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $ajaxAction = (string)$_POST['ajax_action'];

    // A) Wiedergabeposition ("Weiterschauen") & Dauer speichern
    if ($ajaxAction === 'save_progress') {
        $fileId      = (int)($_POST['file_id'] ?? 0);
        $positionSec = max(0, (int)($_POST['position_sec'] ?? 0));
        $durationSec = max(0, (int)($_POST['duration_sec'] ?? 0));
        $incPlay     = isset($_POST['inc_play']) && $_POST['inc_play'] === '1';

        if (!getAccessibleCinemaFile($pdo, $fileId, $userId)) {
            echo json_encode(['ok' => false]);
            exit;
        }

        // Wenn fast am Ende (>= 96 %), Position wieder auf 0 setzen (als fertig geschaut)
        if ($durationSec > 30 && $positionSec >= (int)floor($durationSec * 0.96)) {
            $positionSec = 0;
        }

        $stmtUp = $pdo->prepare(
            "INSERT INTO `cinema_user_state` (`user_id`, `file_id`, `last_position_sec`, `duration_sec`, `play_count`, `last_watched_at`)
             VALUES (:uid, :fid, :pos, :dur, :pc, NOW())
             ON DUPLICATE KEY UPDATE
                `last_position_sec` = VALUES(`last_position_sec`),
                `duration_sec`      = IF(VALUES(`duration_sec`) > 0, VALUES(`duration_sec`), `duration_sec`),
                `play_count`        = `play_count` + :pc2,
                `last_watched_at`   = NOW()"
        );
        $stmtUp->execute([
            ':uid' => $userId,
            ':fid' => $fileId,
            ':pos' => $positionSec,
            ':dur' => $durationSec,
            ':pc'  => $incPlay ? 1 : 0,
            ':pc2' => $incPlay ? 1 : 0,
        ]);

        if ($durationSec > 0) {
            $pdo->prepare(
                "INSERT INTO `cinema_media_meta` (`file_id`, `duration_sec`)
                 VALUES (:fid, :dur)
                 ON DUPLICATE KEY UPDATE `duration_sec` = IF(`duration_sec` = 0, VALUES(`duration_sec`), `duration_sec`)"
            )->execute([':fid' => $fileId, ':dur' => $durationSec]);
        }

        echo json_encode(['ok' => true, 'position_sec' => $positionSec, 'duration_sec' => $durationSec]);
        exit;
    }

    // B) Watchlist ("Später ansehen") oder Favorit ("⭐") umschalten
    if ($ajaxAction === 'toggle_flag') {
        $fileId = (int)($_POST['file_id'] ?? 0);
        $field  = ($_POST['field'] ?? '') === 'favorite' ? 'is_favorite' : 'is_watchlist';

        if (!getAccessibleCinemaFile($pdo, $fileId, $userId)) {
            echo json_encode(['ok' => false]);
            exit;
        }

        $stmtCur = $pdo->prepare("SELECT `$field` FROM `cinema_user_state` WHERE `user_id` = :uid AND `file_id` = :fid");
        $stmtCur->execute([':uid' => $userId, ':fid' => $fileId]);
        $curVal = (int)($stmtCur->fetchColumn() ?: 0);
        $newVal = $curVal === 1 ? 0 : 1;

        $pdo->prepare(
            "INSERT INTO `cinema_user_state` (`user_id`, `file_id`, `$field`)
             VALUES (:uid, :fid, :val)
             ON DUPLICATE KEY UPDATE `$field` = VALUES(`$field`)"
        )->execute([
            ':uid' => $userId,
            ':fid' => $fileId,
            ':val' => $newVal,
        ]);

        echo json_encode(['ok' => true, 'field' => $field, 'value' => $newVal]);
        exit;
    }

    // C) Film-Poster (Data-URI JPEG aus dem Video-Canvas) speichern
    if ($ajaxAction === 'save_poster') {
        $fileId     = (int)($_POST['file_id'] ?? 0);
        $posterData = trim((string)($_POST['poster_data_uri'] ?? ''));
        $duration   = max(0, (int)($_POST['duration_sec'] ?? 0));

        if (!getAccessibleCinemaFile($pdo, $fileId, $userId)) {
            echo json_encode(['ok' => false, 'error' => 'Kein Zugriff auf diese Videodatei.']);
            exit;
        }
        if (!str_starts_with($posterData, 'data:image/jpeg;base64,') && !str_starts_with($posterData, 'data:image/webp;base64,') && !str_starts_with($posterData, 'data:image/png;base64,')) {
            echo json_encode(['ok' => false, 'error' => 'Ungültiges Bildformat für Poster.']);
            exit;
        }
        if (strlen($posterData) > 650000) {
            echo json_encode(['ok' => false, 'error' => 'Poster-Vorschaubild zu groß.']);
            exit;
        }

        $pdo->prepare(
            "INSERT INTO `cinema_media_meta` (`file_id`, `poster_data_uri`, `duration_sec`)
             VALUES (:fid, :poster, :dur)
             ON DUPLICATE KEY UPDATE
                `poster_data_uri` = VALUES(`poster_data_uri`),
                `duration_sec`    = IF(VALUES(`duration_sec`) > 0, VALUES(`duration_sec`), `duration_sec`)"
        )->execute([
            ':fid'    => $fileId,
            ':poster' => $posterData,
            ':dur'    => $duration,
        ]);

        echo json_encode(['ok' => true]);
        exit;
    }

    // D) Szenen-Sprungmarken (Bookmarks) speichern
    if ($ajaxAction === 'save_bookmarks') {
        $fileId        = (int)($_POST['file_id'] ?? 0);
        $bookmarksJson = trim((string)($_POST['bookmarks_json'] ?? '[]'));

        if (!getAccessibleCinemaFile($pdo, $fileId, $userId)) {
            echo json_encode(['ok' => false]);
            exit;
        }

        $decoded = json_decode($bookmarksJson, true);
        $cleanBookmarks = [];
        if (is_array($decoded)) {
            foreach (array_slice($decoded, 0, 50) as $bm) {
                if (is_array($bm) && isset($bm['time'])) {
                    $cleanBookmarks[] = [
                        'time'  => max(0, (int)$bm['time']),
                        'label' => mb_substr(trim((string)($bm['label'] ?? 'Szene')), 0, 80) ?: 'Szene',
                    ];
                }
            }
            usort($cleanBookmarks, static fn(array $a, array $b): int => $a['time'] <=> $b['time']);
        }

        $finalJson = (string)json_encode($cleanBookmarks, JSON_UNESCAPED_UNICODE);
        $pdo->prepare(
            "INSERT INTO `cinema_media_meta` (`file_id`, `bookmarks_json`)
             VALUES (:fid, :bm)
             ON DUPLICATE KEY UPDATE `bookmarks_json` = VALUES(`bookmarks_json`)"
        )->execute([
            ':fid' => $fileId,
            ':bm'  => $finalJson,
        ]);

        echo json_encode(['ok' => true, 'bookmarks' => $cleanBookmarks]);
        exit;
    }

    // E) Cloud-Untertiteldatei (.srt oder .vtt) als WebVTT laden
    if ($ajaxAction === 'load_subtitle') {
        $subFileId = (int)($_POST['subtitle_file_id'] ?? 0);
        $subFile   = getAccessibleCinemaFile($pdo, $subFileId, $userId);
        if (!$subFile || (int)$subFile['is_encrypted'] === 1) {
            echo json_encode(['ok' => false, 'error' => 'Untertiteldatei nicht gefunden.']);
            exit;
        }
        $subPath = UPLOAD_DIR . $subFile['filename'];
        if (!is_file($subPath) || filesize($subPath) > 2 * 1024 * 1024) {
            echo json_encode(['ok' => false, 'error' => 'Untertiteldatei nicht lesbar.']);
            exit;
        }
        $rawText = (string)file_get_contents($subPath);
        $rawText = str_replace(["\r\n", "\r"], "\n", $rawText);
        // Falls es eine .srt Datei ist: Zeitstempel 00:01:20,500 -> 00:01:20.500 in WebVTT umwandeln
        if (!str_starts_with(ltrim($rawText), 'WEBVTT')) {
            $vtt = "WEBVTT\n\n" . preg_replace('/(\d{2}:\d{2}:\d{2}),(\d{3})/', '$1.$2', $rawText);
        } else {
            $vtt = $rawText;
        }
        echo json_encode(['ok' => true, 'vtt' => $vtt]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unbekannte Aktion.']);
    exit;
}

// ============================================================================
// 4. FORMULAR-AKTIONEN (Film-Metadaten pflegen, Serien-Playlists & Kino-Tickets)
// ============================================================================
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action'])) {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!verifyCsrfToken($csrf)) {
        setFlash('error', 'Ungültiges Sicherheits-Token (CSRF).');
        header('Location: cinema.php');
        exit;
    }

    $action = (string)$_POST['action'];

    // 1) Film-Details (Titel, Kategorie/Genre, Erscheinungsjahr, Beschreibung, Untertitel-Verknüpfung) speichern
    if ($action === 'save_movie_meta') {
        $fileId      = (int)($_POST['file_id'] ?? 0);
        $customTitle = mb_substr(trim((string)($_POST['custom_title'] ?? '')), 0, 190);
        $category    = trim((string)($_POST['category'] ?? 'allgemein'));
        if (!isset($cinemaCategories[$category])) {
            $category = 'allgemein';
        }
        $releaseYear = !empty($_POST['release_year']) ? max(1900, min(2100, (int)$_POST['release_year'])) : null;
        $description = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 2000);
        $subFileId   = !empty($_POST['subtitle_file_id']) ? (int)$_POST['subtitle_file_id'] : null;

        if (getAccessibleCinemaFile($pdo, $fileId, $userId)) {
            $stmtMeta = $pdo->prepare(
                "INSERT INTO `cinema_media_meta` (`file_id`, `custom_title`, `category`, `release_year`, `description`, `subtitle_file_id`)
                 VALUES (:fid, :title, :cat, :ry, :descr, :sub)
                 ON DUPLICATE KEY UPDATE
                    `custom_title`     = VALUES(`custom_title`),
                    `category`         = VALUES(`category`),
                    `release_year`     = VALUES(`release_year`),
                    `description`      = VALUES(`description`),
                    `subtitle_file_id` = VALUES(`subtitle_file_id`)"
            );
            $stmtMeta->execute([
                ':fid'   => $fileId,
                ':title' => $customTitle,
                ':cat'   => $category,
                ':ry'    => $releaseYear,
                ':descr' => $description !== '' ? $description : null,
                ':sub'   => $subFileId,
            ]);
            setFlash('success', '🎬 Film-Informationen wurden im Heimkino aktualisiert.');
        }
        header('Location: cinema.php?watch=' . $fileId);
        exit;
    }

    // 2) Neue Filmreihe / Serien-Playlist (inkl. optionalem Gäste-Kino-Ticket) anlegen
    if ($action === 'create_playlist') {
        $title       = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 150);
        $description = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 255);
        $makeTicket  = isset($_POST['enable_guest_ticket']) && $_POST['enable_guest_ticket'] === '1';
        $ticketPass  = trim((string)($_POST['ticket_password'] ?? ''));
        $selectedIds = isset($_POST['movie_ids']) && is_array($_POST['movie_ids']) ? array_map('intval', $_POST['movie_ids']) : [];

        if ($title === '') {
            setFlash('error', 'Bitte gib einen Namen für die Filmreihe oder den Kinoabend ein.');
            header('Location: cinema.php');
            exit;
        }

        $shareToken = $makeTicket ? bin2hex(random_bytes(16)) : null;
        $pwHash     = ($makeTicket && $ticketPass !== '') ? password_hash($ticketPass, PASSWORD_DEFAULT) : null;

        $stmtPl = $pdo->prepare(
            "INSERT INTO `cinema_playlists` (`user_id`, `title`, `description`, `share_token`, `password_hash`)
             VALUES (:uid, :t, :d, :tok, :pw)"
        );
        $stmtPl->execute([
            ':uid' => $userId,
            ':t'   => $title,
            ':d'   => $description,
            ':tok' => $shareToken,
            ':pw'  => $pwHash,
        ]);
        $newPlId = (int)$pdo->lastInsertId();

        $sort = 1;
        $stmtItem = $pdo->prepare("INSERT IGNORE INTO `cinema_playlist_items` (`playlist_id`, `file_id`, `sort_order`) VALUES (:pid, :fid, :so)");
        foreach ($selectedIds as $fid) {
            if ($fid > 0 && getAccessibleCinemaFile($pdo, $fid, $userId)) {
                $stmtItem->execute([':pid' => $newPlId, ':fid' => $fid, ':so' => $sort++]);
            }
        }

        setFlash('success', sprintf('🍿 Filmreihe „%s“ wurde angelegt.', $title));
        header('Location: cinema.php?playlist=' . $newPlId);
        exit;
    }

    // 3) Filmreihe löschen
    if ($action === 'delete_playlist') {
        $plId = (int)($_POST['playlist_id'] ?? 0);
        $stmtDel = $pdo->prepare("DELETE FROM `cinema_playlists` WHERE `id` = :id AND `user_id` = :uid");
        $stmtDel->execute([':id' => $plId, ':uid' => $userId]);
        if ($stmtDel->rowCount() > 0) {
            $pdo->prepare("DELETE FROM `cinema_playlist_items` WHERE `playlist_id` = :pid")->execute([':pid' => $plId]);
            setFlash('success', 'Filmreihe wurde gelöscht.');
        }
        header('Location: cinema.php');
        exit;
    }
}

// ============================================================================
// 5. ALLE VERFÜGBAREN VIDEOS & UNTERTITEL AUS DER CLOUD LADEN
// ============================================================================
$stmtVideos = $pdo->prepare(
    "SELECT f.id, f.user_id, f.group_folder_id, f.filename, f.original_name, f.folder_path,
            f.file_size, f.mime_type, f.upload_date,
            u.username AS owner_name,
            gf.name AS group_folder_name,
            m.custom_title, m.category, m.release_year, m.description, m.poster_data_uri,
            m.subtitle_file_id, m.bookmarks_json, m.duration_sec AS meta_duration_sec,
            s.last_position_sec, s.duration_sec AS user_duration_sec, s.is_watchlist, s.is_favorite,
            s.play_count, s.last_watched_at
     FROM `files` f
     INNER JOIN `users` u ON u.id = f.user_id
     LEFT JOIN `group_folders` gf ON gf.id = f.group_folder_id
     LEFT JOIN `cinema_media_meta` m ON m.file_id = f.id
     LEFT JOIN `cinema_user_state` s ON s.file_id = f.id AND s.user_id = :uid_state
     WHERE f.deleted_at IS NULL
       AND f.is_encrypted = 0
       AND (
           f.mime_type LIKE 'video/%'
           OR LOWER(SUBSTRING_INDEX(f.original_name, '.', -1)) IN ('mp4', 'webm', 'mov', 'm4v', 'ogv', 'mkv')
       )
       AND (
           f.user_id = :uid1
           OR (f.group_folder_id IS NOT NULL AND EXISTS (
               SELECT 1 FROM `group_folder_permissions` gfp
               LEFT JOIN `group_members` gm ON gm.group_id = gfp.group_id
               WHERE gfp.group_folder_id = f.group_folder_id
                 AND (gfp.user_id = :uid2 OR gm.user_id = :uid3)
           ))
           OR EXISTS (
               SELECT 1 FROM `user_shares` us
               WHERE us.shared_with_user_id = :uid4
                 AND (
                     us.file_id = f.id
                     OR (us.owner_id = f.user_id AND us.folder_path IS NOT NULL AND (
                         f.folder_path = us.folder_path OR f.folder_path LIKE CONCAT(us.folder_path, '/%')
                     ))
                 )
           )
       )
     ORDER BY f.upload_date DESC, f.id DESC"
);
$stmtVideos->execute([
    ':uid_state' => $userId,
    ':uid1'      => $userId,
    ':uid2'      => $userId,
    ':uid3'      => $userId,
    ':uid4'      => $userId,
]);
$rawVideos = $stmtVideos->fetchAll();

// Untertiteldateien (.srt, .vtt) des Benutzers laden
$stmtSubs = $pdo->prepare(
    "SELECT `id`, `original_name`, `folder_path`
     FROM `files`
     WHERE `user_id` = :uid
       AND `deleted_at` IS NULL
       AND `is_encrypted` = 0
       AND LOWER(SUBSTRING_INDEX(`original_name`, '.', -1)) IN ('srt', 'vtt')
     ORDER BY `original_name` ASC"
);
$stmtSubs->execute([':uid' => $userId]);
$subtitleFiles = $stmtSubs->fetchAll();

// Videos aufbereiten
$movies = [];
$continueWatching = [];
$watchlistCount = 0;
$favoritesCount = 0;
$totalWatchSeconds = 0;

foreach ($rawVideos as $rv) {
    $fid = (int)$rv['id'];
    $dur = max((int)($rv['meta_duration_sec'] ?? 0), (int)($rv['user_duration_sec'] ?? 0));
    $pos = (int)($rv['last_position_sec'] ?? 0);
    $cat = (string)($rv['category'] ?: 'allgemein');
    if (!isset($cinemaCategories[$cat])) {
        $cat = 'allgemein';
    }

    // Automatische Kategorie-Erkennung anhand Ordner/Dateiname, falls noch 'allgemein'
    if ($cat === 'allgemein') {
        $hay = mb_strtolower($rv['original_name'] . ' ' . ($rv['folder_path'] ?? ''), 'UTF-8');
        if (str_contains($hay, 'surf') || str_contains($hay, 'kite') || str_contains($hay, 'wing') || str_contains($hay, 'gopro') || str_contains($hay, 'sport')) {
            $cat = 'sport';
        } elseif (str_contains($hay, 'urlaub') || str_contains($hay, 'reise') || str_contains($hay, 'familie') || str_contains($hay, 'sommer')) {
            $cat = 'urlaub';
        } elseif (preg_match('/s\d{1,2}e\d{1,2}|folge|staffel|episode/i', $hay)) {
            $cat = 'serie';
        }
    }

    $displayTitle = trim((string)($rv['custom_title'] ?? '')) !== ''
        ? trim((string)$rv['custom_title'])
        : deriveCleanMovieTitle((string)$rv['original_name']);

    $progressPct = ($dur > 0 && $pos > 0) ? min(99, max(2, (int)round(($pos / $dur) * 100))) : 0;
    $bookmarks = [];
    if (!empty($rv['bookmarks_json'])) {
        $decBm = json_decode((string)$rv['bookmarks_json'], true);
        if (is_array($decBm)) {
            $bookmarks = $decBm;
        }
    }

    $movieItem = [
        'id'                => $fid,
        'original_name'     => (string)$rv['original_name'],
        'display_title'     => $displayTitle,
        'folder_path'       => (string)($rv['folder_path'] ?? ''),
        'group_folder_name' => (string)($rv['group_folder_name'] ?? ''),
        'owner_name'        => (string)$rv['owner_name'],
        'file_size'         => (int)$rv['file_size'],
        'mime_type'         => (string)$rv['mime_type'],
        'upload_date'       => (string)$rv['upload_date'],
        'category'          => $cat,
        'release_year'      => !empty($rv['release_year']) ? (int)$rv['release_year'] : null,
        'description'       => (string)($rv['description'] ?? ''),
        'poster_data_uri'   => (string)($rv['poster_data_uri'] ?? ''),
        'subtitle_file_id'  => !empty($rv['subtitle_file_id']) ? (int)$rv['subtitle_file_id'] : 0,
        'bookmarks'         => $bookmarks,
        'duration_sec'      => $dur,
        'last_position_sec' => $pos,
        'progress_pct'      => $progressPct,
        'is_watchlist'      => (int)($rv['is_watchlist'] ?? 0) === 1,
        'is_favorite'       => (int)($rv['is_favorite'] ?? 0) === 1,
        'play_count'        => (int)($rv['play_count'] ?? 0),
        'last_watched_at'   => (string)($rv['last_watched_at'] ?? ''),
    ];

    if ($movieItem['is_watchlist']) {
        $watchlistCount++;
    }
    if ($movieItem['is_favorite']) {
        $favoritesCount++;
    }
    if ($pos > 10) {
        $continueWatching[] = $movieItem;
    }

    $movies[$fid] = $movieItem;
}

// "Weiterschauen" nach zuletzt geschaut sortieren
usort($continueWatching, static fn(array $a, array $b): int => strcmp($b['last_watched_at'], $a['last_watched_at']));

// Filmreihen / Serien-Playlists des Benutzers laden
$stmtPlaylists = $pdo->prepare(
    "SELECT p.*,
            (SELECT COUNT(*) FROM `cinema_playlist_items` pi WHERE pi.playlist_id = p.id) AS item_count
     FROM `cinema_playlists` p
     WHERE p.user_id = :uid
     ORDER BY p.created_at DESC"
);
$stmtPlaylists->execute([':uid' => $userId]);
$playlists = $stmtPlaylists->fetchAll();

// Aktive Playlist oder aktiven Film für die Kinobühne bestimmen
$activePlaylistId = isset($_GET['playlist']) ? (int)$_GET['playlist'] : 0;
$activePlaylist   = null;
$playlistMovieIds = [];

if ($activePlaylistId > 0) {
    foreach ($playlists as $pl) {
        if ((int)$pl['id'] === $activePlaylistId) {
            $activePlaylist = $pl;
            break;
        }
    }
    if ($activePlaylist !== null) {
        $stmtPlItems = $pdo->prepare("SELECT `file_id` FROM `cinema_playlist_items` WHERE `playlist_id` = :pid ORDER BY `sort_order` ASC, `id` ASC");
        $stmtPlItems->execute([':pid' => $activePlaylistId]);
        foreach ($stmtPlItems->fetchAll(PDO::FETCH_COLUMN) as $fid) {
            $fidInt = (int)$fid;
            if (isset($movies[$fidInt])) {
                $playlistMovieIds[] = $fidInt;
            }
        }
    }
}

$requestedWatchId = isset($_GET['watch']) ? (int)$_GET['watch'] : 0;
$activeMovie = null;

if ($requestedWatchId > 0 && isset($movies[$requestedWatchId])) {
    $activeMovie = $movies[$requestedWatchId];
} elseif (!empty($playlistMovieIds) && isset($movies[$playlistMovieIds[0]])) {
    $activeMovie = $movies[$playlistMovieIds[0]];
} elseif (!empty($continueWatching)) {
    $activeMovie = $continueWatching[0];
} elseif (!empty($movies)) {
    $activeMovie = reset($movies);
}

$pageTitle = '🍿 Heimkino & Mediathek';
require_once __DIR__ . '/includes/header.php';
?>

<style>
/* 🌑 Kinosaal Lights-Out (Saallicht dimmen) */
body.cinema-lights-out {
    background-color: #020617 !important;
    color: #f8fafc !important;
    transition: background-color 0.5s ease;
}
body.cinema-lights-out header,
body.cinema-lights-out footer,
body.cinema-lights-out .cinema-hide-on-dim {
    opacity: 0.08;
    transition: opacity 0.4s ease;
}
body.cinema-lights-out header:hover,
body.cinema-lights-out .cinema-hide-on-dim:hover {
    opacity: 0.95;
}
#cinemaAmbilightCanvas {
    filter: blur(55px) saturate(1.85);
    opacity: 0.72;
    transition: opacity 0.4s ease;
    pointer-events: none;
}
</style>

<div class="space-y-6 pb-10">
    <!-- =================================================================== -->
    <!-- KOPFBEREICH / KINO-FOYER BANNER                                     -->
    <!-- =================================================================== -->
    <div class="cinema-hide-on-dim rounded-3xl bg-gradient-to-r from-slate-950 via-red-950 to-slate-950 text-white p-5 sm:p-7 shadow-xl border border-red-900/50 relative overflow-hidden">
        <div class="absolute -right-10 -top-10 w-56 h-56 rounded-full bg-amber-500/10 blur-3xl pointer-events-none"></div>
        <div class="relative z-10 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            <div class="space-y-1.5">
                <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-amber-500/20 text-amber-300 border border-amber-400/30 text-xs font-bold tracking-wide">
                    <span>🍿 MINICLOUD HEIMKINO</span>
                    <span>·</span>
                    <span>🌈 Echtzeit-Ambilight</span>
                    <span>·</span>
                    <span>⏯️ Weiterschauen</span>
                </div>
                <h1 class="text-2xl sm:text-3xl font-extrabold tracking-tight flex items-center gap-2.5">
                    <span>🍿 Heimkino &amp; Film-Mediathek</span>
                </h1>
                <p class="text-xs sm:text-sm text-slate-300 max-w-2xl">
                    Genieße deine Urlaubs-, Actioncam- und Spielfilme im dunklen Kinosaal mit dynamischem Ambilight-Schein, Kino-Sound-Booster, Untertiteln und sekundengenauer „Weiterschauen“-Automatik.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2.5">
                <button type="button" onclick="toggleCinemaLightsOut()" id="btnLightsOut"
                        class="inline-flex items-center gap-2 px-4 py-2.5 rounded-2xl bg-slate-900 hover:bg-slate-800 text-amber-300 border border-amber-500/40 text-xs font-extrabold transition-all cursor-pointer shadow-sm">
                    <span id="lightsOutIcon">🌑</span>
                    <span id="lightsOutLabel">Saallicht dimmen</span>
                </button>
                <button type="button" onclick="document.getElementById('modalCreatePlaylist').classList.remove('hidden')"
                        class="inline-flex items-center gap-1.5 px-3.5 py-2.5 rounded-2xl bg-white/10 hover:bg-white/20 text-white border border-white/15 text-xs font-bold transition-colors cursor-pointer">
                    <span>🎟️</span>
                    <span>Filmreihe / Kino-Ticket</span>
                </button>
                <a href="video_studio.php<?= $activeMovie ? '?file_id=' . (int)$activeMovie['id'] : '' ?>"
                   class="inline-flex items-center gap-1.5 px-3.5 py-2.5 rounded-2xl bg-violet-600/80 hover:bg-violet-600 text-white text-xs font-bold transition-colors shadow-sm">
                    <span>🎬</span>
                    <span>Im Video-Studio schneiden</span>
                </a>
            </div>
        </div>

        <!-- Schnell-Kennzahlen -->
        <div class="relative z-10 grid grid-cols-2 sm:grid-cols-4 gap-3 mt-5 pt-4 border-t border-white/10 text-xs">
            <div class="bg-white/5 rounded-2xl p-3 border border-white/10">
                <p class="text-[11px] text-slate-400">🎞️ Filme &amp; Videos in Mediathek</p>
                <p class="text-xl font-extrabold text-white mt-0.5"><?= count($movies) ?></p>
            </div>
            <div class="bg-white/5 rounded-2xl p-3 border border-white/10">
                <p class="text-[11px] text-slate-400">⏯️ Angefangen („Weiterschauen“)</p>
                <p class="text-xl font-extrabold text-amber-300 mt-0.5"><?= count($continueWatching) ?></p>
            </div>
            <div class="bg-white/5 rounded-2xl p-3 border border-white/10">
                <p class="text-[11px] text-slate-400">🎟️ Auf meiner Watchlist</p>
                <p class="text-xl font-extrabold text-emerald-300 mt-0.5" id="statWatchlistCount"><?= $watchlistCount ?></p>
            </div>
            <div class="bg-white/5 rounded-2xl p-3 border border-white/10">
                <p class="text-[11px] text-slate-400">📺 Filmreihen &amp; Kino-Tickets</p>
                <p class="text-xl font-extrabold text-violet-300 mt-0.5"><?= count($playlists) ?></p>
            </div>
        </div>
    </div>

    <?php if ($activeMovie === null): ?>
        <!-- Leerer Zustand falls noch keine Videos in der Cloud liegen -->
        <div class="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200 dark:border-slate-800 p-10 text-center space-y-4 shadow-xs">
            <div class="w-16 h-16 mx-auto rounded-2xl bg-red-50 dark:bg-red-950/60 text-red-600 dark:text-red-300 flex items-center justify-center text-3xl">🍿</div>
            <h2 class="text-lg font-extrabold text-slate-900 dark:text-white">Noch keine Filme in deiner Cloud</h2>
            <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 max-w-md mx-auto">
                Lade einfach eine Videodatei (<code>.mp4</code>, <code>.webm</code>, <code>.mov</code>, <code>.mkv</code>) im Datei-Manager hoch oder nimm im Video-Studio einen Clip auf – sie erscheint sofort automatisch hier im Heimkino!
            </p>
            <div class="flex justify-center gap-3 pt-2">
                <a href="index.php" class="px-4 py-2.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs font-bold">📁 Zum Datei-Manager</a>
                <a href="video_studio.php" class="px-4 py-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 text-xs font-bold">🎬 Video-Studio öffnen</a>
            </div>
        </div>
    <?php else: ?>

        <!-- =============================================================== -->
        <!-- 1. HAUPT-KINOSAAL (HERO STAGE MIT ECHTZEIT-AMBILIGHT)           -->
        <!-- =============================================================== -->
        <div id="cinemaStageSection" class="rounded-3xl bg-slate-950 text-white border border-slate-800/90 p-4 sm:p-7 shadow-2xl relative overflow-hidden">
            <!-- Echtzeit-Ambilight-Hintergrund-Canvas -->
            <canvas id="cinemaAmbilightCanvas" width="64" height="36"
                    class="absolute inset-4 w-[calc(100%-2rem)] h-[calc(100%-2rem)] rounded-3xl z-0"></canvas>

            <div class="relative z-10 space-y-4">
                <!-- Obere Kinosaal-Leiste -->
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-2.5 min-w-0">
                        <span class="px-2.5 py-1 rounded-lg bg-red-600 text-white text-[11px] font-extrabold uppercase tracking-wider shrink-0">
                            ▶️ Jetzt auf Leinwand
                        </span>
                        <h2 id="stageMovieTitle" class="text-base sm:text-xl font-extrabold text-white truncate">
                            <?= e($activeMovie['display_title']) ?>
                        </h2>
                        <?php if ($activeMovie['release_year']): ?>
                            <span class="text-xs text-slate-400 font-semibold">(<?= (int)$activeMovie['release_year'] ?>)</span>
                        <?php endif; ?>
                    </div>

                    <!-- Kino-Steuerknöpfe oben rechts -->
                    <div class="flex flex-wrap items-center gap-1.5 text-xs">
                        <button type="button" id="btnToggleAmbilight" onclick="toggleAmbilight()"
                                class="px-2.5 py-1.5 rounded-xl bg-amber-500/20 text-amber-300 border border-amber-400/30 font-bold hover:bg-amber-500/30 transition-colors cursor-pointer"
                                title="Dynamische Hintergrundbeleuchtung (Ambilight) ein-/ausschalten">
                            🌈 Ambilight: AN
                        </button>

                        <!-- Sound-Booster Auswahl -->
                        <select id="cinemaSoundMode" onchange="applyCinemaSoundPreset(this.value)"
                                class="px-2.5 py-1.5 rounded-xl bg-slate-900 border border-slate-700 text-slate-200 text-xs font-semibold cursor-pointer"
                                title="WebAudio Kino-Klangprofil wählen">
                            <option value="original">🎧 Klang: Original</option>
                            <option value="cinema">🍿 Klang: Kino-Bass (Subwoofer)</option>
                            <option value="dialogue">🗣️ Klang: Dialog-Verstärker</option>
                            <option value="night">🌙 Klang: Nacht-Kompressor</option>
                        </select>

                        <!-- Geschwindigkeit -->
                        <select id="cinemaSpeedSelect" onchange="setCinemaSpeed(this.value)"
                                class="px-2.5 py-1.5 rounded-xl bg-slate-900 border border-slate-700 text-slate-200 text-xs font-semibold cursor-pointer"
                                title="Abspielgeschwindigkeit">
                            <option value="0.75">0.75×</option>
                            <option value="1" selected>1.0× Normal</option>
                            <option value="1.25">1.25×</option>
                            <option value="1.5">1.5×</option>
                            <option value="2">2.0×</option>
                        </select>

                        <button type="button" onclick="captureMoviePosterFrame(true)"
                                class="px-2.5 py-1.5 rounded-xl bg-slate-900 hover:bg-slate-800 border border-slate-700 text-slate-200 font-semibold cursor-pointer"
                                title="Aktuelles Videobild als Film-Poster für die Mediathek speichern">
                            📸 Als Poster setzen
                        </button>
                    </div>
                </div>

                <!-- 16:9 Kinoleinwand -->
                <div class="relative aspect-video w-full max-w-5xl mx-auto rounded-2xl overflow-hidden bg-black border border-slate-800/90 shadow-2xl">
                    <video id="cinemaVideoPlayer"
                           controls
                           playsinline
                           preload="metadata"
                           crossorigin="anonymous"
                           class="w-full h-full object-contain bg-black"
                           src="download.php?id=<?= (int)$activeMovie['id'] ?>&inline=1">
                        <track id="cinemaSubtitleTrack" kind="subtitles" srclang="de" label="Deutsch / Untertitel" default>
                    </video>

                    <!-- „Weiterschauen ab XX:XX“-Hinweis-Overlay beim Start -->
                    <?php if ($activeMovie['last_position_sec'] > 10): ?>
                        <div id="resumeBannerOverlay"
                             class="absolute bottom-16 left-4 right-4 sm:right-auto bg-slate-950/90 backdrop-blur-md border border-amber-500/50 rounded-2xl px-4 py-3 shadow-2xl flex items-center gap-3 z-20">
                            <div class="text-xs">
                                <p class="font-extrabold text-amber-300">⏯️ Zuletzt pausiert bei <?= e(formatCinemaDuration($activeMovie['last_position_sec'])) ?></p>
                                <p class="text-[11px] text-slate-300">Möchtest du direkt dort weiterschauen?</p>
                            </div>
                            <button type="button" onclick="resumeFromSavedPosition(<?= (int)$activeMovie['last_position_sec'] ?>)"
                                    class="px-3 py-1.5 rounded-xl bg-amber-500 hover:bg-amber-400 text-slate-950 font-extrabold text-xs cursor-pointer">
                                ▶️ Fortsetzen
                            </button>
                            <button type="button" onclick="document.getElementById('resumeBannerOverlay').remove()"
                                    class="px-2 py-1 text-slate-400 hover:text-white text-xs cursor-pointer">✕</button>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Untere Aktions- & Szenen-Leiste unter der Leinwand -->
                <div class="max-w-5xl mx-auto flex flex-wrap items-center justify-between gap-3 pt-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="button" onclick="skipCinemaSeconds(-10)"
                                class="px-3 py-1.5 rounded-xl bg-slate-900 hover:bg-slate-800 border border-slate-800 text-xs font-bold text-slate-200 cursor-pointer">
                            ⏪ -10s
                        </button>
                        <button type="button" onclick="skipCinemaSeconds(10)"
                                class="px-3 py-1.5 rounded-xl bg-slate-900 hover:bg-slate-800 border border-slate-800 text-xs font-bold text-slate-200 cursor-pointer">
                            +10s ⏩
                        </button>
                        <button type="button" onclick="toggleCinemaPiP()"
                                class="px-3 py-1.5 rounded-xl bg-slate-900 hover:bg-slate-800 border border-slate-800 text-xs font-semibold text-slate-300 cursor-pointer"
                                title="Bild-im-Bild-Modus (Mini-Fenster beim Weiterarbeiten)">
                            🖼️ Bild-im-Bild
                        </button>

                        <!-- Watchlist & Favorit für aktuellen Film -->
                        <button type="button" id="btnStageWatchlist" onclick="toggleMovieFlag(currentCinemaFileId, 'watchlist')"
                                class="px-3 py-1.5 rounded-xl border text-xs font-bold transition-colors cursor-pointer <?= $activeMovie['is_watchlist'] ? 'bg-emerald-950/90 border-emerald-600 text-emerald-300' : 'bg-slate-900 border-slate-800 text-slate-300 hover:bg-slate-800' ?>">
                            🎟️ <?= $activeMovie['is_watchlist'] ? 'Auf Watchlist ✓' : '+ Watchlist' ?>
                        </button>
                        <button type="button" id="btnStageFavorite" onclick="toggleMovieFlag(currentCinemaFileId, 'favorite')"
                                class="px-3 py-1.5 rounded-xl border text-xs font-bold transition-colors cursor-pointer <?= $activeMovie['is_favorite'] ? 'bg-amber-950/90 border-amber-500 text-amber-300' : 'bg-slate-900 border-slate-800 text-slate-300 hover:bg-slate-800' ?>">
                            <?= $activeMovie['is_favorite'] ? '⭐ Favorit' : '☆ Favorit' ?>
                        </button>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <!-- Lokale .srt/.vtt Untertitel-Datei reinladen -->
                        <label class="px-3 py-1.5 rounded-xl bg-slate-900 hover:bg-slate-800 border border-slate-800 text-xs font-semibold text-slate-300 cursor-pointer">
                            💬 Untertitel (.srt/.vtt)
                            <input type="file" accept=".srt,.vtt" class="hidden" onchange="loadLocalSubtitleFile(this)">
                        </label>

                        <!-- Szene merken -->
                        <button type="button" onclick="addCurrentSceneBookmark()"
                                class="px-3 py-1.5 rounded-xl bg-slate-900 hover:bg-slate-800 border border-slate-800 text-xs font-semibold text-amber-300 cursor-pointer">
                            🔖 + Szene merken
                        </button>

                        <!-- Film-Infos bearbeiten -->
                        <button type="button" onclick="document.getElementById('modalEditMovieMeta').classList.remove('hidden')"
                                class="px-3 py-1.5 rounded-xl bg-slate-900 hover:bg-slate-800 border border-slate-800 text-xs font-semibold text-slate-300 cursor-pointer">
                            ✏️ Film-Infos
                        </button>
                    </div>
                </div>

                <!-- Szenen-Sprungmarken (Bookmarks) des aktuellen Films -->
                <div id="stageBookmarksBar" class="max-w-5xl mx-auto flex flex-wrap items-center gap-1.5 pt-1 <?= empty($activeMovie['bookmarks']) ? 'hidden' : '' ?>">
                    <span class="text-[11px] font-bold text-slate-400 mr-1">🔖 Szenen:</span>
                    <div id="stageBookmarksList" class="flex flex-wrap items-center gap-1.5"></div>
                </div>

                <?php if ($activeMovie['description'] !== ''): ?>
                    <p id="stageMovieDescription" class="max-w-5xl mx-auto text-xs text-slate-300 bg-slate-900/70 border border-slate-800/80 rounded-2xl p-3.5">
                        <?= nl2br(e($activeMovie['description'])) ?>
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <!-- =============================================================== -->
        <!-- 2. AKTIVE FILMREIHE / SERIEN-PLAYLIST (FALLS AUSGEWÄHLT)        -->
        <!-- =============================================================== -->
        <?php if ($activePlaylist !== null): ?>
            <div class="cinema-hide-on-dim rounded-2xl bg-violet-950/90 text-white border border-violet-700/60 p-4 sm:p-5 shadow-md space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <span class="text-[11px] font-bold uppercase tracking-wider text-violet-300">📺 Aktive Filmreihe / Serien-Staffel (Autoplay aktiv)</span>
                        <h3 class="text-base font-extrabold"><?= e((string)$activePlaylist['title']) ?></h3>
                    </div>
                    <div class="flex items-center gap-2">
                        <?php if (!empty($activePlaylist['share_token'])): ?>
                            <?php $ticketUrl = getBaseUrl() . '/cinema.php?ticket=' . urlencode((string)$activePlaylist['share_token']); ?>
                            <button type="button" onclick="showTicketQrModal('<?= e($ticketUrl) ?>', '<?= e((string)$activePlaylist['title']) ?>')"
                                    class="px-3 py-1.5 rounded-xl bg-amber-500 hover:bg-amber-400 text-slate-950 text-xs font-extrabold cursor-pointer">
                                🎟️ Gäste-Kino-Ticket &amp; QR-Code
                            </button>
                        <?php endif; ?>
                        <a href="cinema.php" class="px-3 py-1.5 rounded-xl bg-white/10 hover:bg-white/20 text-xs font-semibold">Alle Filme anzeigen</a>
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-2.5">
                    <?php foreach ($playlistMovieIds as $idx => $pmId): ?>
                        <?php $pm = $movies[$pmId]; ?>
                        <a href="cinema.php?playlist=<?= (int)$activePlaylist['id'] ?>&watch=<?= (int)$pm['id'] ?>"
                           class="p-2.5 rounded-xl border transition-all flex items-center gap-2.5 <?= (int)$pm['id'] === (int)$activeMovie['id'] ? 'bg-amber-500/20 border-amber-400 text-white' : 'bg-slate-900/70 border-violet-800/60 text-slate-200 hover:bg-slate-900' ?>">
                            <span class="w-6 h-6 rounded-lg bg-violet-800 text-white font-bold text-xs flex items-center justify-center shrink-0">
                                <?= $idx + 1 ?>
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="text-xs font-bold truncate"><?= e($pm['display_title']) ?></p>
                                <p class="text-[10px] text-violet-300"><?= e(formatCinemaDuration($pm['duration_sec'])) ?></p>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- =============================================================== -->
        <!-- 3. „WEITERSCHAUEN“-LEISTE (NETFLIX-STYLE RESUME)                -->
        <!-- =============================================================== -->
        <?php if (!empty($continueWatching)): ?>
            <section class="cinema-hide-on-dim space-y-3">
                <div class="flex items-center justify-between">
                    <h2 class="text-base sm:text-lg font-extrabold text-slate-900 dark:text-white flex items-center gap-2">
                        <span>⏯️ Weiterschauen</span>
                        <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">(an letzter Stelle fortsetzen)</span>
                    </h2>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <?php foreach (array_slice($continueWatching, 0, 4) as $cw): ?>
                        <a href="cinema.php?watch=<?= (int)$cw['id'] ?>"
                           class="group rounded-2xl bg-slate-900 text-white border border-slate-800 overflow-hidden shadow-md hover:border-amber-500/70 transition-all flex flex-col">
                            <div class="relative aspect-video bg-slate-950 overflow-hidden">
                                <?php if ($cw['poster_data_uri'] !== ''): ?>
                                    <img src="<?= e($cw['poster_data_uri']) ?>" alt="" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300">
                                <?php else: ?>
                                    <div class="w-full h-full flex flex-col items-center justify-center bg-gradient-to-br from-slate-900 via-red-950/60 to-slate-950 text-3xl">
                                        <?= $cinemaCategories[$cw['category']]['icon'] ?? '🎬' ?>
                                    </div>
                                <?php endif; ?>
                                <div class="absolute inset-0 bg-gradient-to-t from-black/85 via-black/20 to-transparent flex items-end p-3">
                                    <span class="px-2.5 py-1 rounded-lg bg-amber-500 text-slate-950 font-extrabold text-[11px] shadow-sm">
                                        ▶️ Weiter ab <?= e(formatCinemaDuration($cw['last_position_sec'])) ?>
                                    </span>
                                </div>
                                <!-- Roter/Goldener Fortschrittsbalken unten am Bild -->
                                <div class="absolute bottom-0 left-0 right-0 h-1.5 bg-slate-800">
                                    <div class="h-full bg-amber-500" style="width: <?= (int)$cw['progress_pct'] ?>%"></div>
                                </div>
                            </div>
                            <div class="p-3 space-y-0.5">
                                <p class="text-xs font-extrabold truncate"><?= e($cw['display_title']) ?></p>
                                <p class="text-[11px] text-slate-400">
                                    Noch <?= e(formatCinemaDuration(max(0, $cw['duration_sec'] - $cw['last_position_sec']))) ?> übrig
                                </p>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <!-- =============================================================== -->
        <!-- 4. FILTER-, GENRE- & SUCHLEISTE DER MEDIATHEK                   -->
        <!-- =============================================================== -->
        <section class="cinema-hide-on-dim space-y-4">
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-3.5 sm:p-4 shadow-xs flex flex-col md:flex-row md:items-center justify-between gap-3">
                <div class="flex items-center gap-1.5 overflow-x-auto pb-1 md:pb-0">
                    <button type="button" onclick="filterCinemaCatalog('all')" data-cinema-filter="all"
                            class="cinema-filter-btn px-3 py-1.5 rounded-xl text-xs font-extrabold bg-red-600 text-white cursor-pointer shrink-0">
                        🍿 Alle (<?= count($movies) ?>)
                    </button>
                    <button type="button" onclick="filterCinemaCatalog('watchlist')" data-cinema-filter="watchlist"
                            class="cinema-filter-btn px-3 py-1.5 rounded-xl text-xs font-semibold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-200 cursor-pointer shrink-0">
                        🎟️ Watchlist
                    </button>
                    <button type="button" onclick="filterCinemaCatalog('favorite')" data-cinema-filter="favorite"
                            class="cinema-filter-btn px-3 py-1.5 rounded-xl text-xs font-semibold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-200 cursor-pointer shrink-0">
                        ⭐ Favoriten
                    </button>
                    <?php foreach ($cinemaCategories as $cKey => $cMeta): ?>
                        <button type="button" onclick="filterCinemaCatalog('<?= e($cKey) ?>')" data-cinema-filter="<?= e($cKey) ?>"
                                class="cinema-filter-btn px-3 py-1.5 rounded-xl text-xs font-semibold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-200 cursor-pointer shrink-0">
                            <?= $cMeta['icon'] ?> <?= e($cMeta['label']) ?>
                        </button>
                    <?php endforeach; ?>
                </div>

                <div class="relative w-full md:w-64 shrink-0">
                    <input type="search" id="cinemaSearchInput" oninput="applyCinemaSearchAndFilter()"
                           placeholder="Film, Ordner oder Jahr suchen…"
                           class="w-full pl-8 pr-3 py-1.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-xs text-slate-900 dark:text-white">
                    <span class="absolute left-2.5 top-2 text-xs text-slate-400">🔍</span>
                </div>
            </div>

            <!-- =========================================================== -->
            <!-- 5. FILM-POSTER-WAND (MEDIATHEK GRID)                        -->
            <!-- =========================================================== -->
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4" id="cinemaMovieGrid">
                <?php foreach ($movies as $m): ?>
                    <?php
                    $catMeta = $cinemaCategories[$m['category']] ?? $cinemaCategories['allgemein'];
                    $isCurrent = ((int)$m['id'] === (int)$activeMovie['id']);
                    ?>
                    <div class="cinema-movie-card group rounded-2xl bg-white dark:bg-slate-900 border <?= $isCurrent ? 'border-amber-500 ring-2 ring-amber-500/30' : 'border-slate-200 dark:border-slate-800' ?> overflow-hidden shadow-xs hover:shadow-lg transition-all flex flex-col justify-between"
                         data-movie-id="<?= (int)$m['id'] ?>"
                         data-category="<?= e($m['category']) ?>"
                         data-watchlist="<?= $m['is_watchlist'] ? '1' : '0' ?>"
                         data-favorite="<?= $m['is_favorite'] ? '1' : '0' ?>"
                         data-search="<?= e(mb_strtolower($m['display_title'] . ' ' . $m['original_name'] . ' ' . $m['folder_path'] . ' ' . ($m['release_year'] ?? ''), 'UTF-8')) ?>">

                        <div>
                            <!-- Poster / 16:9 Thumbnail -->
                            <a href="cinema.php?watch=<?= (int)$m['id'] ?>" class="block relative aspect-video bg-slate-950 overflow-hidden">
                                <?php if ($m['poster_data_uri'] !== ''): ?>
                                    <img src="<?= e($m['poster_data_uri']) ?>" alt="<?= e($m['display_title']) ?>"
                                         id="cardPosterImg_<?= (int)$m['id'] ?>"
                                         class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300">
                                <?php else: ?>
                                    <div id="cardPosterPlaceholder_<?= (int)$m['id'] ?>"
                                         class="w-full h-full flex flex-col items-center justify-center bg-gradient-to-br from-slate-900 via-slate-800 to-slate-950 text-white p-4 text-center">
                                        <span class="text-4xl mb-1"><?= $catMeta['icon'] ?></span>
                                        <span class="text-[11px] font-bold text-slate-300 line-clamp-2"><?= e($m['display_title']) ?></span>
                                    </div>
                                <?php endif; ?>

                                <!-- Play-Hover-Overlay -->
                                <div class="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center">
                                    <span class="w-12 h-12 rounded-full bg-amber-500 text-slate-950 flex items-center justify-center text-lg font-extrabold shadow-xl">▶</span>
                                </div>

                                <!-- Dauer-Badge rechts unten -->
                                <span class="absolute bottom-2 right-2 px-2 py-0.5 rounded-md bg-black/80 text-white text-[10px] font-mono font-bold">
                                    <?= $m['duration_sec'] > 0 ? e(formatCinemaDuration($m['duration_sec'])) : e(formatBytes($m['file_size'])) ?>
                                </span>

                                <?php if ($m['progress_pct'] > 0): ?>
                                    <div class="absolute bottom-0 left-0 right-0 h-1 bg-slate-800">
                                        <div class="h-full bg-amber-500" style="width: <?= (int)$m['progress_pct'] ?>%"></div>
                                    </div>
                                <?php endif; ?>
                            </a>

                            <!-- Titel & Metadaten -->
                            <div class="p-3.5 space-y-1.5">
                                <div class="flex items-start justify-between gap-2">
                                    <a href="cinema.php?watch=<?= (int)$m['id'] ?>" class="text-sm font-extrabold text-slate-900 dark:text-white hover:text-red-600 dark:hover:text-amber-400 line-clamp-1">
                                        <?= e($m['display_title']) ?>
                                    </a>
                                    <?php if ($m['release_year']): ?>
                                        <span class="text-[11px] font-bold text-slate-400 shrink-0"><?= (int)$m['release_year'] ?></span>
                                    <?php endif; ?>
                                </div>

                                <div class="flex flex-wrap items-center gap-1.5 text-[11px]">
                                    <span class="px-2 py-0.5 rounded-md font-semibold <?= $catMeta['badge'] ?>">
                                        <?= $catMeta['icon'] ?> <?= e($catMeta['label']) ?>
                                    </span>
                                    <?php if ($m['folder_path'] !== ''): ?>
                                        <span class="text-slate-400 truncate max-w-[140px]" title="<?= e($m['folder_path']) ?>">
                                            📁 <?= e($m['folder_path']) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Fußzeile der Karte -->
                        <div class="px-3.5 py-2.5 bg-slate-50/70 dark:bg-slate-800/40 border-t border-slate-100 dark:border-slate-800/80 flex items-center justify-between text-xs">
                            <span class="text-[11px] text-slate-400">
                                <?= e(formatBytes($m['file_size'])) ?>
                                <?php if ($m['play_count'] > 0): ?>
                                    · <?= (int)$m['play_count'] ?>× gesehen
                                <?php endif; ?>
                            </span>
                            <div class="flex items-center gap-1">
                                <button type="button" onclick="toggleMovieFlag(<?= (int)$m['id'] ?>, 'watchlist', this)"
                                        class="p-1 rounded-lg hover:bg-slate-200 dark:hover:bg-slate-700 cursor-pointer"
                                        title="Auf die Watchlist setzen">
                                    <?= $m['is_watchlist'] ? '🎟️' : '➕' ?>
                                </button>
                                <button type="button" onclick="toggleMovieFlag(<?= (int)$m['id'] ?>, 'favorite', this)"
                                        class="p-1 rounded-lg hover:bg-slate-200 dark:hover:bg-slate-700 cursor-pointer"
                                        title="Als Favorit markieren">
                                    <?= $m['is_favorite'] ? '⭐' : '☆' ?>
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- =============================================================== -->
        <!-- 6. FILMREIHEN, SERIEN-STAFFELN & GÄSTE-KINO-TICKETS             -->
        <!-- =============================================================== -->
        <?php if (!empty($playlists)): ?>
            <section class="cinema-hide-on-dim bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 shadow-xs space-y-3">
                <div class="flex items-center justify-between">
                    <h2 class="text-base font-extrabold text-slate-900 dark:text-white">📺 Meine Filmreihen &amp; Gäste-Kino-Tickets</h2>
                    <button type="button" onclick="document.getElementById('modalCreatePlaylist').classList.remove('hidden')"
                            class="text-xs font-bold text-brand-600 dark:text-brand-400 hover:underline cursor-pointer">
                        + Neue Filmreihe erstellen
                    </button>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                    <?php foreach ($playlists as $pl): ?>
                        <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/80 dark:border-slate-700/80 flex flex-col justify-between gap-3">
                            <div>
                                <div class="flex items-center justify-between gap-2">
                                    <a href="cinema.php?playlist=<?= (int)$pl['id'] ?>" class="text-sm font-extrabold text-slate-900 dark:text-white hover:underline">
                                        🍿 <?= e((string)$pl['title']) ?>
                                    </a>
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-violet-100 dark:bg-violet-950 text-violet-700 dark:text-violet-300">
                                        <?= (int)$pl['item_count'] ?> Filme
                                    </span>
                                </div>
                                <?php if (!empty($pl['description'])): ?>
                                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1"><?= e((string)$pl['description']) ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="flex items-center justify-between gap-2 pt-2 border-t border-slate-200/70 dark:border-slate-700/70">
                                <div class="flex items-center gap-2">
                                    <a href="cinema.php?playlist=<?= (int)$pl['id'] ?>"
                                       class="px-2.5 py-1 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-xs font-bold">
                                        ▶️ Starten
                                    </a>
                                    <?php if (!empty($pl['share_token'])): ?>
                                        <?php $tUrl = getBaseUrl() . '/cinema.php?ticket=' . urlencode((string)$pl['share_token']); ?>
                                        <button type="button" onclick="showTicketQrModal('<?= e($tUrl) ?>', '<?= e((string)$pl['title']) ?>')"
                                                class="px-2.5 py-1 rounded-lg bg-amber-100 dark:bg-amber-950/80 text-amber-800 dark:text-amber-300 text-xs font-bold cursor-pointer">
                                            🎟️ Ticket-Link
                                        </button>
                                    <?php endif; ?>
                                </div>
                                <form method="post" onsubmit="return confirm('Filmreihe wirklich löschen? (Die Videodateien selbst bleiben selbstverständlich erhalten)');">
                                    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                    <input type="hidden" name="action" value="delete_playlist">
                                    <input type="hidden" name="playlist_id" value="<?= (int)$pl['id'] ?>">
                                    <button type="submit" class="text-xs text-rose-600 hover:underline cursor-pointer">Löschen</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <!-- =============================================================== -->
        <!-- MODAL 1: FILM-INFORMATIONEN & UNTERTITEL BEARBEITEN             -->
        <!-- =============================================================== -->
        <div id="modalEditMovieMeta" class="hidden fixed inset-0 z-50 bg-black/75 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200 dark:border-slate-700 w-full max-w-lg p-6 shadow-2xl space-y-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-base font-extrabold text-slate-900 dark:text-white">🎬 Film-Informationen bearbeiten</h3>
                    <button type="button" onclick="document.getElementById('modalEditMovieMeta').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 text-lg cursor-pointer">✕</button>
                </div>
                <form method="post" class="space-y-3.5">
                    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                    <input type="hidden" name="action" value="save_movie_meta">
                    <input type="hidden" name="file_id" value="<?= (int)$activeMovie['id'] ?>">

                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Anzeigetitel im Heimkino</label>
                        <input type="text" name="custom_title" value="<?= e($activeMovie['display_title']) ?>"
                               class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm text-slate-900 dark:text-white">
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Genre / Kategorie</label>
                            <select name="category" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm text-slate-900 dark:text-white">
                                <?php foreach ($cinemaCategories as $cKey => $cMeta): ?>
                                    <option value="<?= e($cKey) ?>" <?= $activeMovie['category'] === $cKey ? 'selected' : '' ?>>
                                        <?= $cMeta['icon'] ?> <?= e($cMeta['label']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Erscheinungs-/Aufnahmejahr</label>
                            <input type="number" name="release_year" min="1900" max="2100" value="<?= e((string)($activeMovie['release_year'] ?? date('Y'))) ?>"
                                   class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm text-slate-900 dark:text-white">
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Cloud-Untertiteldatei (<code>.srt</code> / <code>.vtt</code>) verknüpfen</label>
                        <select name="subtitle_file_id" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs text-slate-900 dark:text-white">
                            <option value="0">– Keine feste Untertiteldatei –</option>
                            <?php foreach ($subtitleFiles as $sf): ?>
                                <option value="<?= (int)$sf['id'] ?>" <?= (int)$activeMovie['subtitle_file_id'] === (int)$sf['id'] ? 'selected' : '' ?>>
                                    💬 <?= e((string)$sf['original_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Filmbeschreibung / Notizen</label>
                        <textarea name="description" rows="3" placeholder="Handlung, Drehorte, Besetzung oder eigene Notizen…"
                                  class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs text-slate-900 dark:text-white"><?= e($activeMovie['description']) ?></textarea>
                    </div>

                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" onclick="document.getElementById('modalEditMovieMeta').classList.add('hidden')"
                                class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-xs font-semibold text-slate-700 dark:text-slate-300 cursor-pointer">Abbrechen</button>
                        <button type="submit" class="px-5 py-2 rounded-xl bg-red-600 hover:bg-red-700 text-white text-xs font-extrabold cursor-pointer">
                            💾 Speichern
                        </button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <!-- =================================================================== -->
    <!-- MODAL 2: FILMREIHE / GÄSTE-KINO-TICKET ERSTELLEN                    -->
    <!-- =================================================================== -->
    <div id="modalCreatePlaylist" class="hidden fixed inset-0 z-50 bg-black/75 backdrop-blur-xs flex items-center justify-center p-4">
        <div class="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200 dark:border-slate-700 w-full max-w-lg p-6 shadow-2xl space-y-4 max-h-[90vh] overflow-y-auto">
            <div class="flex items-center justify-between">
                <h3 class="text-base font-extrabold text-slate-900 dark:text-white">🎟️ Neue Filmreihe / Gäste-Kino-Ticket</h3>
                <button type="button" onclick="document.getElementById('modalCreatePlaylist').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 text-lg cursor-pointer">✕</button>
            </div>
            <form method="post" class="space-y-3.5">
                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                <input type="hidden" name="action" value="create_playlist">

                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Titel der Filmreihe / des Kinoabends *</label>
                    <input type="text" name="title" required placeholder="z. B. Surf-Highlights 2026 oder Familien-Filmabend"
                           class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm text-slate-900 dark:text-white">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Kurzbeschreibung (optional)</label>
                    <input type="text" name="description" placeholder="z. B. Unsere besten Clips aus Dänemark & Fehmarn"
                           class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs text-slate-900 dark:text-white">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Filme / Folgen auswählen</label>
                    <div class="max-h-44 overflow-y-auto rounded-xl border border-slate-200 dark:border-slate-700 p-2 space-y-1 bg-slate-50 dark:bg-slate-800/60">
                        <?php foreach ($movies as $m): ?>
                            <label class="flex items-center gap-2 p-1.5 rounded-lg hover:bg-white dark:hover:bg-slate-800 text-xs cursor-pointer">
                                <input type="checkbox" name="movie_ids[]" value="<?= (int)$m['id'] ?>"
                                       <?= ($activeMovie && (int)$m['id'] === (int)$activeMovie['id']) ? 'checked' : '' ?>
                                       class="rounded border-slate-300 text-red-600">
                                <span class="truncate font-semibold text-slate-800 dark:text-slate-200"><?= e($m['display_title']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="p-3.5 rounded-2xl bg-amber-50/80 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800/60 space-y-2.5">
                    <label class="flex items-center gap-2 text-xs font-bold text-amber-900 dark:text-amber-300 cursor-pointer">
                        <input type="checkbox" name="enable_guest_ticket" value="1" checked class="rounded border-amber-400 text-amber-600">
                        <span>🎟️ Öffentliches Gäste-Kino-Ticket (Link &amp; QR-Code) erzeugen</span>
                    </label>
                    <div>
                        <label class="block text-[11px] font-semibold text-amber-800 dark:text-amber-300 mb-1">Optionales Ticket-Passwort</label>
                        <input type="text" name="ticket_password" placeholder="Leer lassen für direkten Zugang per Link"
                               class="w-full px-3 py-1.5 rounded-xl border border-amber-300 dark:border-amber-800 bg-white dark:bg-slate-900 text-xs text-slate-900 dark:text-white">
                    </div>
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" onclick="document.getElementById('modalCreatePlaylist').classList.add('hidden')"
                            class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-xs font-semibold text-slate-700 dark:text-slate-300 cursor-pointer">Abbrechen</button>
                    <button type="submit" class="px-5 py-2 rounded-xl bg-red-600 hover:bg-red-700 text-white text-xs font-extrabold cursor-pointer">
                        🍿 Filmreihe anlegen
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- =================================================================== -->
    <!-- MODAL 3: GÄSTE-KINO-TICKET LINK & LOKALER QR-CODE                   -->
    <!-- =================================================================== -->
    <div id="modalTicketQr" class="hidden fixed inset-0 z-50 bg-black/75 backdrop-blur-xs flex items-center justify-center p-4">
        <div class="bg-slate-900 text-white rounded-3xl border border-amber-500/40 w-full max-w-md p-6 shadow-2xl text-center space-y-4">
            <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-amber-500/20 text-amber-300 text-xs font-bold">
                🎟️ Gäste-Kino-Ticket
            </div>
            <h3 id="modalTicketTitle" class="text-lg font-extrabold">Filmabend</h3>
            <p class="text-xs text-slate-300">Teile diesen Link mit Freunden oder Familie – sie können den Film direkt im Browser streamen.</p>
            <div class="flex items-center gap-2">
                <input type="text" id="modalTicketUrlInput" readonly
                       class="w-full px-3 py-2 rounded-xl bg-slate-800 border border-slate-700 text-xs text-amber-300 font-mono">
                <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('modalTicketUrlInput').value); this.textContent='Kopiert ✓';"
                        class="px-3 py-2 rounded-xl bg-amber-500 text-slate-950 font-extrabold text-xs shrink-0 cursor-pointer">
                    Kopieren
                </button>
            </div>
            <div class="pt-2">
                <button type="button" onclick="document.getElementById('modalTicketQr').classList.add('hidden')"
                        class="px-5 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-xs font-bold cursor-pointer">
                    Schließen
                </button>
            </div>
        </div>
    </div>
</div>

<?php if ($activeMovie !== null): ?>
<script>
const cinemaCsrfToken = <?= json_encode(getCsrfToken()) ?>;
let currentCinemaFileId = <?= (int)$activeMovie['id'] ?>;
let currentBookmarks = <?= json_encode($activeMovie['bookmarks'], JSON_UNESCAPED_UNICODE) ?>;
const linkedSubtitleFileId = <?= (int)$activeMovie['subtitle_file_id'] ?>;
const hasSavedPoster = <?= $activeMovie['poster_data_uri'] !== '' ? 'true' : 'false' ?>;
const playlistNextIds = <?= json_encode($playlistMovieIds) ?>;
const activePlaylistId = <?= (int)$activePlaylistId ?>;

const videoEl = document.getElementById('cinemaVideoPlayer');
const ambilightCanvas = document.getElementById('cinemaAmbilightCanvas');
const ambilightCtx = ambilightCanvas ? ambilightCanvas.getContext('2d') : null;
let ambilightEnabled = true;

// 1. Echtzeit-Ambilight (farbenfroher Schein hinter der Kinoleinwand)
function updateAmbilightGlow() {
    if (ambilightEnabled && videoEl && !videoEl.paused && !videoEl.ended && ambilightCtx) {
        try {
            ambilightCtx.drawImage(videoEl, 0, 0, ambilightCanvas.width, ambilightCanvas.height);
        } catch (e) {}
    }
    requestAnimationFrame(updateAmbilightGlow);
}
requestAnimationFrame(updateAmbilightGlow);

function toggleAmbilight() {
    ambilightEnabled = !ambilightEnabled;
    if (ambilightCanvas) {
        ambilightCanvas.style.opacity = ambilightEnabled ? '0.72' : '0';
    }
    const btn = document.getElementById('btnToggleAmbilight');
    if (btn) {
        btn.textContent = ambilightEnabled ? '🌈 Ambilight: AN' : '🌈 Ambilight: AUS';
    }
}

// 2. Saallicht dimmen (Lights-Out-Modus)
function toggleCinemaLightsOut() {
    const isDimmed = document.body.classList.toggle('cinema-lights-out');
    const lbl = document.getElementById('lightsOutLabel');
    const ico = document.getElementById('lightsOutIcon');
    if (lbl) lbl.textContent = isDimmed ? 'Saallicht einschalten' : 'Saallicht dimmen';
    if (ico) ico.textContent = isDimmed ? '💡' : '🌑';
}

// 3. WebAudio Kino-Sound-Booster (Subwoofer, Dialog-Verstärker, Nacht-Kompressor)
let cinemaAudioCtx = null;
let cinemaBassFilter = null;
let cinemaVoiceFilter = null;
let cinemaCompressor = null;

function ensureCinemaAudioGraph() {
    if (cinemaAudioCtx || !videoEl) return;
    try {
        const AudioCtx = window.AudioContext || window.webkitAudioContext;
        cinemaAudioCtx = new AudioCtx();
        const source = cinemaAudioCtx.createMediaElementSource(videoEl);

        cinemaBassFilter = cinemaAudioCtx.createBiquadFilter();
        cinemaBassFilter.type = 'lowshelf';
        cinemaBassFilter.frequency.value = 110;
        cinemaBassFilter.gain.value = 0;

        cinemaVoiceFilter = cinemaAudioCtx.createBiquadFilter();
        cinemaVoiceFilter.type = 'peaking';
        cinemaVoiceFilter.frequency.value = 2400;
        cinemaVoiceFilter.Q.value = 1.0;
        cinemaVoiceFilter.gain.value = 0;

        cinemaCompressor = cinemaAudioCtx.createDynamicsCompressor();
        cinemaCompressor.threshold.value = -24;
        cinemaCompressor.ratio.value = 1;

        source.connect(cinemaBassFilter);
        cinemaBassFilter.connect(cinemaVoiceFilter);
        cinemaVoiceFilter.connect(cinemaCompressor);
        cinemaCompressor.connect(cinemaAudioCtx.destination);
    } catch (e) {}
}

function applyCinemaSoundPreset(preset) {
    ensureCinemaAudioGraph();
    if (!cinemaAudioCtx) return;
    if (cinemaAudioCtx.state === 'suspended') {
        cinemaAudioCtx.resume();
    }
    if (preset === 'cinema') {
        cinemaBassFilter.gain.value = 7.5;
        cinemaVoiceFilter.gain.value = 2.0;
        cinemaCompressor.ratio.value = 2.5;
    } else if (preset === 'dialogue') {
        cinemaBassFilter.gain.value = -3.0;
        cinemaVoiceFilter.gain.value = 7.0;
        cinemaCompressor.ratio.value = 3.5;
    } else if (preset === 'night') {
        cinemaBassFilter.gain.value = -5.0;
        cinemaVoiceFilter.gain.value = 4.5;
        cinemaCompressor.threshold.value = -34;
        cinemaCompressor.ratio.value = 8.0;
    } else {
        cinemaBassFilter.gain.value = 0;
        cinemaVoiceFilter.gain.value = 0;
        cinemaCompressor.ratio.value = 1.0;
    }
}

function setCinemaSpeed(val) {
    if (videoEl) {
        videoEl.playbackRate = parseFloat(val) || 1.0;
    }
}

function skipCinemaSeconds(delta) {
    if (!videoEl) return;
    videoEl.currentTime = Math.max(0, Math.min(videoEl.duration || 999999, videoEl.currentTime + delta));
}

function toggleCinemaPiP() {
    if (!videoEl) return;
    if (document.pictureInPictureElement) {
        document.exitPictureInPicture().catch(() => {});
    } else if (videoEl.requestPictureInPicture) {
        videoEl.requestPictureInPicture().catch(() => {});
    }
}

function resumeFromSavedPosition(sec) {
    const banner = document.getElementById('resumeBannerOverlay');
    if (banner) banner.remove();
    if (videoEl) {
        videoEl.currentTime = sec;
        videoEl.play().catch(() => {});
    }
}

// 4. Automatische „Weiterschauen“-Speicherung & automatisches Film-Poster
let lastSavedTimestamp = 0;
let playIncremented = false;
let autoPosterCaptured = hasSavedPoster;

function sendProgressUpdate(force = false, incPlay = false) {
    if (!videoEl || !videoEl.duration || isNaN(videoEl.duration)) return;
    const now = Date.now();
    if (!force && (now - lastSavedTimestamp) < 9000) return;
    lastSavedTimestamp = now;

    const fd = new FormData();
    fd.append('csrf_token', cinemaCsrfToken);
    fd.append('ajax_action', 'save_progress');
    fd.append('file_id', String(currentCinemaFileId));
    fd.append('position_sec', String(Math.floor(videoEl.currentTime)));
    fd.append('duration_sec', String(Math.floor(videoEl.duration)));
    if (incPlay) {
        fd.append('inc_play', '1');
    }
    fetch('cinema.php', { method: 'POST', body: fd }).catch(() => {});
}

if (videoEl) {
    videoEl.addEventListener('play', () => {
        if (!playIncremented) {
            playIncremented = true;
            sendProgressUpdate(true, true);
        }
    });
    videoEl.addEventListener('timeupdate', () => {
        sendProgressUpdate(false, false);
        if (!autoPosterCaptured && videoEl.currentTime > 1.5 && videoEl.videoWidth > 0) {
            autoPosterCaptured = true;
            captureMoviePosterFrame(false);
        }
    });
    videoEl.addEventListener('pause', () => sendProgressUpdate(true, false));
    videoEl.addEventListener('ended', () => {
        sendProgressUpdate(true, false);
        // Falls Teil einer Filmreihe: automatisch nächste Folge starten
        if (playlistNextIds.length > 1) {
            const idx = playlistNextIds.indexOf(currentCinemaFileId);
            if (idx >= 0 && idx + 1 < playlistNextIds.length) {
                const nextId = playlistNextIds[idx + 1];
                window.location.href = 'cinema.php?playlist=' + activePlaylistId + '&watch=' + nextId;
            }
        }
    });
}

// 5. Film-Poster aus dem aktuellen Videobild aufnehmen
function captureMoviePosterFrame(showNotice = true) {
    if (!videoEl || videoEl.videoWidth <= 0) return;
    try {
        const c = document.createElement('canvas');
        c.width = 480;
        c.height = 270;
        const ctx = c.getContext('2d');
        ctx.drawImage(videoEl, 0, 0, c.width, c.height);
        const dataUri = c.toDataURL('image/jpeg', 0.82);

        const fd = new FormData();
        fd.append('csrf_token', cinemaCsrfToken);
        fd.append('ajax_action', 'save_poster');
        fd.append('file_id', String(currentCinemaFileId));
        fd.append('poster_data_uri', dataUri);
        fd.append('duration_sec', String(Math.floor(videoEl.duration || 0)));

        fetch('cinema.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if (res.ok) {
                    const imgEl = document.getElementById('cardPosterImg_' + currentCinemaFileId);
                    if (imgEl) {
                        imgEl.src = dataUri;
                    }
                    if (showNotice) {
                        alert('📸 Das aktuelle Filmbild wurde als Poster in der Mediathek gespeichert!');
                    }
                }
            }).catch(() => {});
    } catch (e) {}
}

// 6. Szenen-Sprungmarken (Bookmarks)
function formatSecShort(sec) {
    const h = Math.floor(sec / 3600);
    const m = Math.floor((sec % 3600) / 60);
    const s = Math.floor(sec % 60);
    if (h > 0) return `${h}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
    return `${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
}

function renderStageBookmarks() {
    const bar = document.getElementById('stageBookmarksBar');
    const list = document.getElementById('stageBookmarksList');
    if (!bar || !list) return;
    list.innerHTML = '';
    if (!currentBookmarks || currentBookmarks.length === 0) {
        bar.classList.add('hidden');
        return;
    }
    bar.classList.remove('hidden');
    currentBookmarks.forEach((bm, idx) => {
        const pill = document.createElement('div');
        pill.className = 'inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-slate-900 border border-slate-700 text-xs text-amber-300';
        const jumpBtn = document.createElement('button');
        jumpBtn.type = 'button';
        jumpBtn.className = 'font-semibold hover:underline cursor-pointer';
        jumpBtn.textContent = `${formatSecShort(bm.time)} · ${bm.label}`;
        jumpBtn.onclick = () => {
            if (videoEl) {
                videoEl.currentTime = bm.time;
                videoEl.play().catch(() => {});
            }
        };
        const delBtn = document.createElement('button');
        delBtn.type = 'button';
        delBtn.className = 'text-slate-500 hover:text-rose-400 ml-1 cursor-pointer';
        delBtn.textContent = '×';
        delBtn.onclick = () => {
            currentBookmarks.splice(idx, 1);
            persistBookmarks();
        };
        pill.appendChild(jumpBtn);
        pill.appendChild(delBtn);
        list.appendChild(pill);
    });
}
renderStageBookmarks();

function addCurrentSceneBookmark() {
    if (!videoEl) return;
    const t = Math.floor(videoEl.currentTime || 0);
    const label = prompt('Name für diese Szene / Sprungmarke (' + formatSecShort(t) + '):', 'Highlight-Szene');
    if (!label) return;
    currentBookmarks.push({ time: t, label: label.trim() });
    currentBookmarks.sort((a, b) => a.time - b.time);
    persistBookmarks();
}

function persistBookmarks() {
    renderStageBookmarks();
    const fd = new FormData();
    fd.append('csrf_token', cinemaCsrfToken);
    fd.append('ajax_action', 'save_bookmarks');
    fd.append('file_id', String(currentCinemaFileId));
    fd.append('bookmarks_json', JSON.stringify(currentBookmarks));
    fetch('cinema.php', { method: 'POST', body: fd }).catch(() => {});
}

// 7. Untertitel (.srt / .vtt) laden
function applyWebVttString(vttText) {
    const trackEl = document.getElementById('cinemaSubtitleTrack');
    if (!trackEl || !videoEl) return;
    const blob = new Blob([vttText], { type: 'text/vtt' });
    trackEl.src = URL.createObjectURL(blob);
    if (videoEl.textTracks && videoEl.textTracks[0]) {
        videoEl.textTracks[0].mode = 'showing';
    }
}

function loadLocalSubtitleFile(input) {
    const file = input.files && input.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = () => {
        let txt = String(reader.result || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n');
        if (!txt.trimStart().startsWith('WEBVTT')) {
            txt = 'WEBVTT\n\n' + txt.replace(/(\d{2}:\d{2}:\d{2}),(\d{3})/g, '$1.$2');
        }
        applyWebVttString(txt);
    };
    reader.readAsText(file, 'UTF-8');
}

if (linkedSubtitleFileId > 0) {
    const fd = new FormData();
    fd.append('csrf_token', cinemaCsrfToken);
    fd.append('ajax_action', 'load_subtitle');
    fd.append('subtitle_file_id', String(linkedSubtitleFileId));
    fetch('cinema.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.ok && res.vtt) {
                applyWebVttString(res.vtt);
            }
        }).catch(() => {});
}

// 8. Watchlist & Favorit umschalten
function toggleMovieFlag(fileId, field, cardBtn = null) {
    const fd = new FormData();
    fd.append('csrf_token', cinemaCsrfToken);
    fd.append('ajax_action', 'toggle_flag');
    fd.append('file_id', String(fileId));
    fd.append('field', field);

    fetch('cinema.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (!res.ok) return;
            const isOn = res.value === 1;
            const card = document.querySelector(`.cinema-movie-card[data-movie-id="${fileId}"]`);
            if (card) {
                card.setAttribute(field === 'favorite' ? 'data-favorite' : 'data-watchlist', isOn ? '1' : '0');
            }
            if (cardBtn) {
                cardBtn.textContent = field === 'favorite' ? (isOn ? '⭐' : '☆') : (isOn ? '🎟️' : '➕');
            }
            if (fileId === currentCinemaFileId) {
                if (field === 'watchlist') {
                    const sb = document.getElementById('btnStageWatchlist');
                    if (sb) sb.textContent = isOn ? '🎟️ Auf Watchlist ✓' : '🎟️ + Watchlist';
                } else {
                    const fb = document.getElementById('btnStageFavorite');
                    if (fb) fb.textContent = isOn ? '⭐ Favorit' : '☆ Favorit';
                }
            }
        }).catch(() => {});
}

// 9. Mediathek-Filter & Suche
let activeCinemaFilter = 'all';
function filterCinemaCatalog(filterKey) {
    activeCinemaFilter = filterKey;
    document.querySelectorAll('.cinema-filter-btn').forEach(btn => {
        const isSel = btn.getAttribute('data-cinema-filter') === filterKey;
        btn.className = isSel
            ? 'cinema-filter-btn px-3 py-1.5 rounded-xl text-xs font-extrabold bg-red-600 text-white cursor-pointer shrink-0'
            : 'cinema-filter-btn px-3 py-1.5 rounded-xl text-xs font-semibold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-200 cursor-pointer shrink-0';
    });
    applyCinemaSearchAndFilter();
}

function applyCinemaSearchAndFilter() {
    const q = (document.getElementById('cinemaSearchInput')?.value || '').trim().toLowerCase();
    document.querySelectorAll('.cinema-movie-card').forEach(card => {
        const cat = card.getAttribute('data-category');
        const wl  = card.getAttribute('data-watchlist') === '1';
        const fav = card.getAttribute('data-favorite') === '1';
        const hay = card.getAttribute('data-search') || '';

        let matchCat = true;
        if (activeCinemaFilter === 'watchlist') matchCat = wl;
        else if (activeCinemaFilter === 'favorite') matchCat = fav;
        else if (activeCinemaFilter !== 'all') matchCat = (cat === activeCinemaFilter);

        const matchQ = q === '' || hay.includes(q);
        card.style.display = (matchCat && matchQ) ? '' : 'none';
    });
}

function showTicketQrModal(url, title) {
    document.getElementById('modalTicketTitle').textContent = '🍿 ' + title;
    document.getElementById('modalTicketUrlInput').value = url;
    document.getElementById('modalTicketQr').classList.remove('hidden');
}

// 10. Kino-Tastaturkürzel (Leertaste, Pfeiltasten, F = Vollbild, L = Saallicht dimmen)
document.addEventListener('keydown', function(e) {
    const tag = (e.target && e.target.tagName) ? e.target.tagName.toLowerCase() : '';
    if (tag === 'input' || tag === 'textarea' || tag === 'select') return;
    if (!videoEl) return;

    if (e.key === 'l' || e.key === 'L') {
        e.preventDefault();
        toggleCinemaLightsOut();
    } else if (e.key === 'f' || e.key === 'F') {
        e.preventDefault();
        if (!document.fullscreenElement) {
            videoEl.requestFullscreen?.();
        } else {
            document.exitFullscreen?.();
        }
    } else if (e.key === 'ArrowRight') {
        skipCinemaSeconds(10);
    } else if (e.key === 'ArrowLeft') {
        skipCinemaSeconds(-10);
    }
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
