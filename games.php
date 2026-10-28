<?php
declare(strict_types=1);

/**
 * MiniCloud – Browserspiele-Zentrale, Cloud-Bestenlisten & Mehrspieler-Duelle (games.php)
 * 100 % lokal, werbefrei, Strato-, Intranet- & DSGVO-tauglich (HTML5 / Canvas / PHP / MySQL).
 *
 * Enthaltene 9 Spiele:
 * 1. 🔢 2048 (Zahlen-Schiebepuzzle mit Auto-Save)
 * 2. 🧠 Sudoku (3 Schwierigkeitsgrade, Notiz-Modus, Auto-Save & Timer)
 * 3. 💣 Minesweeper (3 Feldgrößen, Touch-Flaggenmodus & Bestzeit)
 * 4. 🔤 Wort des Tages (Cloud-Wordle: tägliches Wort für alle + Trainingsmodus)
 * 5. 🃏 Memory (4x4 & 6x4 Paar-Suche)
 * 6. 🧱 Block-Puzzle (Tetris-Klassiker mit Vorschau & Leveln)
 * 7. 🐍 Snake (Arcade-Klassiker mit 3 Tempostufen)
 * 8. 🏓 Brick Breaker (Breakout mit Level-Fortschritt & Maus/Touch)
 * 9. 🔴🟡 Vier Gewinnt & ❌⭕ Tic-Tac-Toe (gegen Computer-KI ODER live gegen andere Cloud-Benutzer!)
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$pdo         = getDB();
$appSettings = getAppSettings($pdo);

if (($appSettings['enable_games'] ?? '1') !== '1' && !isAdmin()) {
    setFlash('error', 'Das Spiele-Modul ist derzeit durch den Administrator deaktiviert.');
    header('Location: index.php');
    exit;
}

$userId   = (int)$_SESSION['user_id'];
$username = (string)($_SESSION['username'] ?? 'Benutzer');
$isAdmin  = isAdmin();

/**
 * Stellt sicher, dass die Tabellen für Highscores, Spielstände und Mehrspieler-Duelle existieren.
 */
function ensureGamesSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `game_scores` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` INT UNSIGNED NOT NULL,
                `game_key` VARCHAR(32) NOT NULL,
                `score` INT UNSIGNED NOT NULL DEFAULT 0,
                `duration_sec` INT UNSIGNED NOT NULL DEFAULT 0,
                `meta_label` VARCHAR(100) NOT NULL DEFAULT '',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_game_score` (`game_key`, `score` DESC),
                KEY `idx_user_game` (`user_id`, `game_key`),
                KEY `idx_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `game_saves` (
                `user_id` INT UNSIGNED NOT NULL,
                `game_key` VARCHAR(32) NOT NULL,
                `state_json` MEDIUMTEXT NOT NULL,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`user_id`, `game_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `game_matches` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `game_type` VARCHAR(24) NOT NULL DEFAULT 'connect4',
                `player1_id` INT UNSIGNED NOT NULL,
                `player2_id` INT UNSIGNED NOT NULL,
                `current_turn_id` INT UNSIGNED NOT NULL,
                `board_json` TEXT NOT NULL,
                `status` VARCHAR(20) NOT NULL DEFAULT 'active',
                `winner_id` INT UNSIGNED NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_players_status` (`player1_id`, `player2_id`, `status`),
                KEY `idx_updated` (`updated_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable) {
    }
}

ensureGamesSchema($pdo);

/**
 * Liefert den Katalog aller 9 Spiele inklusive Metadaten.
 *
 * @return array<string, array{key: string, title: string, icon: string, category: string, desc: string, color: string}>
 */
function getGamesCatalog(): array
{
    return [
        '2048' => [
            'key'      => '2048',
            'title'    => '2048 Puzzle',
            'icon'     => '🔢',
            'category' => 'Denkspiel',
            'desc'     => 'Verbinde gleiche Zahlenkacheln bis zur 2048 (und darüber hinaus!). Mit Auto-Save.',
            'color'    => 'from-amber-500 to-orange-600',
        ],
        'sudoku' => [
            'key'      => 'sudoku',
            'title'    => 'Cloud-Sudoku',
            'icon'     => '🧠',
            'category' => 'Logik',
            'desc'     => '9×9 Logik-Rätsel mit 3 Schwierigkeitsgraden, Bleistift-Notizen & Spielstand-Sync.',
            'color'    => 'from-indigo-500 to-blue-600',
        ],
        'minesweeper' => [
            'key'      => 'minesweeper',
            'title'    => 'Minesweeper',
            'icon'     => '💣',
            'category' => 'Klassiker',
            'desc'     => 'Spüre alle Minen fehlerfrei auf – mit Touch-Flaggen-Umschalter und Stoppuhr.',
            'color'    => 'from-slate-600 to-slate-800',
        ],
        'wordle' => [
            'key'      => 'wordle',
            'title'    => 'Wort des Tages',
            'icon'     => '🔤',
            'category' => 'Worträtsel',
            'desc'     => 'Errate das tägliche 5-Buchstaben-Wort (für alle Cloud-Nutzer gleich) in 6 Versuchen!',
            'color'    => 'from-emerald-500 to-teal-600',
        ],
        'memory' => [
            'key'      => 'memory',
            'title'    => 'Cloud-Memory',
            'icon'     => '🃏',
            'category' => 'Gedächtnis',
            'desc'     => 'Finde alle verdeckten Kartenpaare in möglichst wenigen Zügen und Bestzeit.',
            'color'    => 'from-pink-500 to-rose-600',
        ],
        'tetris' => [
            'key'      => 'tetris',
            'title'    => 'Block-Puzzle',
            'icon'     => '🧱',
            'category' => 'Arcade',
            'desc'     => 'Staple fallende Blöcke, räume bis zu 4 Reihen gleichzeitig ab und knacke den Highscore.',
            'color'    => 'from-purple-500 to-indigo-600',
        ],
        'snake' => [
            'key'      => 'snake',
            'title'    => 'Retro-Snake',
            'icon'     => '🐍',
            'category' => 'Arcade',
            'desc'     => 'Sammle Energiepunkte, werde länger und weiche den Wänden und dir selbst aus.',
            'color'    => 'from-green-500 to-emerald-700',
        ],
        'breakout' => [
            'key'      => 'breakout',
            'title'    => 'Brick Breaker',
            'icon'     => '🏓',
            'category' => 'Arcade',
            'desc'     => 'Halte den Ball mit dem Paddel im Spiel und räume alle farbigen Steinreihen ab.',
            'color'    => 'from-sky-500 to-blue-700',
        ],
        'connect4' => [
            'key'      => 'connect4',
            'title'    => 'Vier Gewinnt & Tic-Tac-Toe',
            'icon'     => '🔴',
            'category' => 'Duell & KI',
            'desc'     => 'Spiele gegen die Computer-KI oder fordere andere Cloud-Benutzer zum Live-Duell heraus!',
            'color'    => 'from-red-500 to-amber-500',
        ],
    ];
}

/**
 * Prüft auf einem 6x7 Vier-Gewinnt-Brett oder 3x3 Tic-Tac-Toe-Brett, ob Spieler 1 oder 2 gewonnen hat.
 */
function checkMatchWinner(string $gameType, array $board): int
{
    if ($gameType === 'tictactoe') {
        $lines = [
            [[0,0],[0,1],[0,2]], [[1,0],[1,1],[1,2]], [[2,0],[2,1],[2,2]],
            [[0,0],[1,0],[2,0]], [[0,1],[1,1],[2,1]], [[0,2],[1,2],[2,2]],
            [[0,0],[1,1],[2,2]], [[0,2],[1,1],[2,0]],
        ];
        foreach ($lines as $l) {
            $v = (int)($board[$l[0][0]][$l[0][1]] ?? 0);
            if ($v !== 0 && $v === (int)($board[$l[1][0]][$l[1][1]] ?? 0) && $v === (int)($board[$l[2][0]][$l[2][1]] ?? 0)) {
                return $v;
            }
        }
        return 0;
    }

    // Vier Gewinnt (6 Zeilen x 7 Spalten)
    $rows = 6;
    $cols = 7;
    $dirs = [[0,1], [1,0], [1,1], [1,-1]];
    for ($r = 0; $r < $rows; $r++) {
        for ($c = 0; $c < $cols; $c++) {
            $p = (int)($board[$r][$c] ?? 0);
            if ($p === 0) {
                continue;
            }
            foreach ($dirs as [$dr, $dc]) {
                $ok = true;
                for ($k = 1; $k < 4; $k++) {
                    $nr = $r + $dr * $k;
                    $nc = $c + $dc * $k;
                    if ($nr < 0 || $nr >= $rows || $nc < 0 || $nc >= $cols || (int)($board[$nr][$nc] ?? 0) !== $p) {
                        $ok = false;
                        break;
                    }
                }
                if ($ok) {
                    return $p;
                }
            }
        }
    }
    return 0;
}

/**
 * Lädt die Top-Highscores für ein bestimmtes Spiel (oder alle Spiele) und den gewählten Zeitraum.
 *
 * @return array<int, array<string, mixed>>
 */
function fetchLeaderboardRows(PDO $pdo, string $gameKey, string $period = 'all'): array
{
    $catalog = getGamesCatalog();
    if (!isset($catalog[$gameKey])) {
        $gameKey = '2048';
    }

    $dateFilter = match ($period) {
        'today' => 'AND s.created_at >= CURDATE()',
        'month' => 'AND s.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)',
        default => '',
    };

    try {
        // Pro Benutzer den besten Score im gewählten Zeitraum anzeigen
        $stmt = $pdo->prepare(
            "SELECT u.username,
                    MAX(s.score) AS best_score,
                    MIN(NULLIF(s.duration_sec, 0)) AS best_duration,
                    COUNT(s.id) AS plays_count,
                    MAX(s.created_at) AS last_played
             FROM `game_scores` s
             INNER JOIN `users` u ON u.id = s.user_id
             WHERE s.game_key = :gk {$dateFilter}
             GROUP BY s.user_id, u.username
             ORDER BY best_score DESC, best_duration ASC
             LIMIT 10"
        );
        $stmt->execute([':gk' => $gameKey]);
        return $stmt->fetchAll();
    } catch (Throwable) {
        return [];
    }
}

// =============================================================================
// AJAX-ENDPUNKTE FÜR HIGHSCORES, SPIELSTÄNDE & MEHRSPIELER-DUELLE
// =============================================================================
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['ajax']) && $_POST['ajax'] === '1') {
    header('Content-Type: application/json; charset=UTF-8');

    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!verifyCsrfToken($csrf)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Ungültiges Sicherheits-Token.']);
        exit;
    }

    $action  = (string)($_POST['action'] ?? '');
    $catalog = getGamesCatalog();

    // 1. Highscore speichern
    if ($action === 'save_score') {
        $gameKey  = (string)($_POST['game_key'] ?? '');
        $score    = max(0, min(9999999, (int)($_POST['score'] ?? 0)));
        $duration = max(0, min(86400, (int)($_POST['duration_sec'] ?? 0)));
        $metaLbl  = mb_substr(trim((string)($_POST['meta_label'] ?? '')), 0, 90);

        if (!isset($catalog[$gameKey]) || $score <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Ungültige Punktzahl.']);
            exit;
        }

        recordModuleUsage($pdo, 'games', true);

        $stmt = $pdo->prepare(
            "INSERT INTO `game_scores` (`user_id`, `game_key`, `score`, `duration_sec`, `meta_label`)
             VALUES (:uid, :gk, :sc, :dur, :meta)"
        );
        $stmt->execute([
            ':uid'  => $userId,
            ':gk'   => $gameKey,
            ':sc'   => $score,
            ':dur'  => $duration,
            ':meta' => $metaLbl,
        ]);

        // Bestwert des Benutzers abrufen
        $stmtBest = $pdo->prepare("SELECT MAX(`score`) FROM `game_scores` WHERE `user_id` = :uid AND `game_key` = :gk");
        $stmtBest->execute([':uid' => $userId, ':gk' => $gameKey]);
        $personalBest = (int)$stmtBest->fetchColumn();

        echo json_encode([
            'ok'            => true,
            'personal_best' => $personalBest,
            'is_new_record' => ($score >= $personalBest),
            'leaderboard'   => fetchLeaderboardRows($pdo, $gameKey, 'all'),
        ]);
        exit;
    }

    // 2. Bestenliste abrufen
    if ($action === 'get_leaderboard') {
        $gameKey = (string)($_POST['game_key'] ?? '2048');
        $period  = (string)($_POST['period'] ?? 'all');
        echo json_encode([
            'ok'          => true,
            'leaderboard' => fetchLeaderboardRows($pdo, $gameKey, $period),
        ]);
        exit;
    }

    // 3. Laufenden Spielstand speichern (z. B. 2048 oder Sudoku)
    if ($action === 'save_state') {
        $gameKey   = (string)($_POST['game_key'] ?? '');
        $stateJson = trim((string)($_POST['state_json'] ?? ''));

        if (!isset($catalog[$gameKey])) {
            echo json_encode(['ok' => false]);
            exit;
        }

        if ($stateJson === '' || $stateJson === 'null') {
            $stmtDel = $pdo->prepare("DELETE FROM `game_saves` WHERE `user_id` = :uid AND `game_key` = :gk");
            $stmtDel->execute([':uid' => $userId, ':gk' => $gameKey]);
            echo json_encode(['ok' => true]);
            exit;
        }

        $stmt = $pdo->prepare(
            "INSERT INTO `game_saves` (`user_id`, `game_key`, `state_json`)
             VALUES (:uid, :gk, :st)
             ON DUPLICATE KEY UPDATE `state_json` = VALUES(`state_json`)"
        );
        $stmt->execute([
            ':uid' => $userId,
            ':gk'  => $gameKey,
            ':st'  => $stateJson,
        ]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // 4. Neues Cloud-Mehrspieler-Duell (Vier Gewinnt oder Tic-Tac-Toe) starten
    if ($action === 'create_match') {
        $opponentId = (int)($_POST['opponent_id'] ?? 0);
        $gameType   = ($_POST['game_type'] ?? 'connect4') === 'tictactoe' ? 'tictactoe' : 'connect4';

        if ($opponentId <= 0 || $opponentId === $userId) {
            echo json_encode(['ok' => false, 'error' => 'Bitte wähle einen anderen Cloud-Benutzer als Mitspieler aus.']);
            exit;
        }

        $emptyBoard = $gameType === 'tictactoe'
            ? array_fill(0, 3, array_fill(0, 3, 0))
            : array_fill(0, 6, array_fill(0, 7, 0));

        $stmt = $pdo->prepare(
            "INSERT INTO `game_matches` (`game_type`, `player1_id`, `player2_id`, `current_turn_id`, `board_json`, `status`)
             VALUES (:gt, :p1, :p2, :turn, :bj, 'active')"
        );
        $stmt->execute([
            ':gt'   => $gameType,
            ':p1'   => $userId,
            ':p2'   => $opponentId,
            ':turn' => $userId,
            ':bj'   => json_encode($emptyBoard),
        ]);

        echo json_encode([
            'ok'       => true,
            'match_id' => (int)$pdo->lastInsertId(),
        ]);
        exit;
    }

    // 5. Zug in einem Cloud-Mehrspieler-Duell ausführen
    if ($action === 'play_match_move') {
        $matchId = (int)($_POST['match_id'] ?? 0);
        $col     = (int)($_POST['col'] ?? -1);
        $row     = (int)($_POST['row'] ?? -1);

        $stmt = $pdo->prepare("SELECT * FROM `game_matches` WHERE `id` = :id LIMIT 1");
        $stmt->execute([':id' => $matchId]);
        $match = $stmt->fetch();

        if (!$match || $match['status'] !== 'active') {
            echo json_encode(['ok' => false, 'error' => 'Diese Partie ist bereits beendet oder existiert nicht.']);
            exit;
        }

        $p1 = (int)$match['player1_id'];
        $p2 = (int)$match['player2_id'];
        if ($userId !== $p1 && $userId !== $p2) {
            echo json_encode(['ok' => false, 'error' => 'Keine Berechtigung für diese Partie.']);
            exit;
        }
        if ((int)$match['current_turn_id'] !== $userId) {
            echo json_encode(['ok' => false, 'error' => 'Dein Mitspieler ist gerade am Zug.']);
            exit;
        }

        $playerNum = ($userId === $p1) ? 1 : 2;
        $board     = json_decode((string)$match['board_json'], true);
        $gameType  = (string)$match['game_type'];

        if (!is_array($board)) {
            echo json_encode(['ok' => false, 'error' => 'Ungültiger Spielstand.']);
            exit;
        }

        if ($gameType === 'tictactoe') {
            if ($row < 0 || $row >= 3 || $col < 0 || $col >= 3 || (int)($board[$row][$col] ?? 0) !== 0) {
                echo json_encode(['ok' => false, 'error' => 'Ungültiges Feld.']);
                exit;
            }
            $board[$row][$col] = $playerNum;
        } else {
            // Vier Gewinnt: Stein fällt in Spalte $col nach unten
            if ($col < 0 || $col >= 7) {
                echo json_encode(['ok' => false, 'error' => 'Ungültige Spalte.']);
                exit;
            }
            $placedRow = -1;
            for ($r = 5; $r >= 0; $r--) {
                if ((int)($board[$r][$col] ?? 0) === 0) {
                    $board[$r][$col] = $playerNum;
                    $placedRow = $r;
                    break;
                }
            }
            if ($placedRow < 0) {
                echo json_encode(['ok' => false, 'error' => 'Diese Spalte ist bereits voll.']);
                exit;
            }
        }

        $winnerNum = checkMatchWinner($gameType, $board);
        $isFull    = true;
        foreach ($board as $rArr) {
            foreach ($rArr as $cell) {
                if ((int)$cell === 0) {
                    $isFull = false;
                    break 2;
                }
            }
        }

        $newStatus = 'active';
        $winnerId  = null;
        $nextTurn  = ($userId === $p1) ? $p2 : $p1;

        if ($winnerNum === 1) {
            $newStatus = 'p1_won';
            $winnerId  = $p1;
        } elseif ($winnerNum === 2) {
            $newStatus = 'p2_won';
            $winnerId  = $p2;
        } elseif ($isFull) {
            $newStatus = 'draw';
        }

        $stmtUp = $pdo->prepare(
            "UPDATE `game_matches`
             SET `board_json` = :bj, `current_turn_id` = :nt, `status` = :st, `winner_id` = :wid
             WHERE `id` = :id"
        );
        $stmtUp->execute([
            ':bj'  => json_encode($board),
            ':nt'  => $nextTurn,
            ':st'  => $newStatus,
            ':wid' => $winnerId,
            ':id'  => $matchId,
        ]);

        // Bei Sieg im Cloud-Duell automatisch Highscore-Punkte gutschreiben
        if ($winnerId !== null) {
            $winPoints = ($gameType === 'connect4') ? 600 : 250;
            $stmtSc = $pdo->prepare(
                "INSERT INTO `game_scores` (`user_id`, `game_key`, `score`, `duration_sec`, `meta_label`)
                 VALUES (:uid, 'connect4', :sc, 0, :lbl)"
            );
            $stmtSc->execute([
                ':uid' => $winnerId,
                ':sc'  => $winPoints,
                ':lbl' => ($gameType === 'connect4' ? 'Cloud-Duell Vier Gewinnt' : 'Cloud-Duell Tic-Tac-Toe'),
            ]);
        }

        echo json_encode(['ok' => true]);
        exit;
    }

    // 6. Aktuelle Cloud-Mehrspieler-Duelle des Benutzers abrufen
    if ($action === 'poll_matches') {
        $stmt = $pdo->prepare(
            "SELECT m.*, u1.username AS p1_name, u2.username AS p2_name
             FROM `game_matches` m
             INNER JOIN `users` u1 ON u1.id = m.player1_id
             INNER JOIN `users` u2 ON u2.id = m.player2_id
             WHERE m.player1_id = :u1 OR m.player2_id = :u2
             ORDER BY (m.status = 'active') DESC, m.updated_at DESC
             LIMIT 12"
        );
        $stmt->execute([':u1' => $userId, ':u2' => $userId]);
        $matches = [];
        foreach ($stmt->fetchAll() as $row) {
            $matches[] = [
                'id'              => (int)$row['id'],
                'game_type'       => (string)$row['game_type'],
                'player1_id'      => (int)$row['player1_id'],
                'player2_id'      => (int)$row['player2_id'],
                'p1_name'         => (string)$row['p1_name'],
                'p2_name'         => (string)$row['p2_name'],
                'current_turn_id' => (int)$row['current_turn_id'],
                'board'           => json_decode((string)$row['board_json'], true) ?: [],
                'status'          => (string)$row['status'],
                'winner_id'       => $row['winner_id'] !== null ? (int)$row['winner_id'] : null,
                'updated_at'      => date('d.m. H:i', strtotime((string)$row['updated_at']) ?: time()),
            ];
        }
        echo json_encode(['ok' => true, 'matches' => $matches]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unbekannte Aktion.']);
    exit;
}

// =============================================================================
// SEITENDATEN FÜR DIE SPIELE-ZENTRALE LADEN
// =============================================================================
$gamesCatalog = getGamesCatalog();

// Persönliche Bestwerte & Gesamtspiele des Benutzers
$myBestByGame = [];
$myTotalPlays = 0;
try {
    $stmtMy = $pdo->prepare(
        "SELECT `game_key`, MAX(`score`) AS `best_score`, COUNT(*) AS `plays`
         FROM `game_scores`
         WHERE `user_id` = :uid
         GROUP BY `game_key`"
    );
    $stmtMy->execute([':uid' => $userId]);
    foreach ($stmtMy->fetchAll() as $r) {
        $myBestByGame[(string)$r['game_key']] = (int)$r['best_score'];
        $myTotalPlays += (int)$r['plays'];
    }
} catch (Throwable) {
}

// Gespeicherte Spielstände (2048 & Sudoku) des Benutzers laden
$savedStates = [];
try {
    $stmtSv = $pdo->prepare("SELECT `game_key`, `state_json` FROM `game_saves` WHERE `user_id` = :uid");
    $stmtSv->execute([':uid' => $userId]);
    foreach ($stmtSv->fetchAll() as $r) {
        $savedStates[(string)$r['game_key']] = (string)$r['state_json'];
    }
} catch (Throwable) {
}

// Cloud-Benutzerliste für Mehrspieler-Duelle (Vier Gewinnt / Tic-Tac-Toe)
$otherUsers = [];
try {
    $stmtUsers = $pdo->prepare(
        "SELECT `id`, `username` FROM `users`
         WHERE `id` != :uid
         ORDER BY `username` ASC"
    );
    $stmtUsers->execute([':uid' => $userId]);
    $otherUsers = $stmtUsers->fetchAll();
} catch (Throwable) {
}

// Tägliches 5-Buchstaben-Wort (für alle Cloud-Nutzer am heutigen Tag identisch)
$wordList = [
    'APFEL', 'ADLER', 'ANKER', 'ENGEL', 'FEUER', 'FLUSS', 'GEIST', 'GLANZ', 'HAFEN', 'HERZOG',
    'INSEL', 'JUBEL', 'KABEL', 'KRAFT', 'KRONE', 'LAMPE', 'LEBEN', 'LICHT', 'LOGIK', 'MALER',
    'MUSIK', 'NACHT', 'NEBEL', 'OCEAN', 'ORGEL', 'PERLE', 'PILOT', 'PLANO', 'PUNKT', 'QUarz',
    'RADIO', 'RAUCH', 'REGEN', 'REISE', 'RITTER', 'ROMAN', 'SONNE', 'SPIEL', 'STERN', 'STURM',
    'TAFEL', 'TRAUM', 'TURBO', 'VOGEL', 'WOLKE', 'WUNDE', 'ZEBRA', 'ZUNGE', 'BLUME', 'BODEN',
    'BRIEF', 'DATEI', 'DECKE', 'DRAHT', 'EBENE', 'FAHRT', 'FARBE', 'FEDER', 'FELSE', 'FLAMM',
    'FORST', 'FRAGE', 'GLASF', 'GLEIS', 'GRUND', 'HONIG', 'HOTEL', 'IDEAL', 'KARTA', 'KERZE',
    'KLANG', 'KLEID', 'KUGEL', 'KUNST', 'LAUNE', 'LEDER', 'LINIE', 'LOEWE', 'MASKE', 'MEILE',
    'METALL', 'MITTE', 'MODUL', 'MONAT', 'MOTOR', 'NADEL', 'NATUR', 'NOTIZ', 'PALME', 'PAUSE',
    'PFADE', 'PFUND', 'PLATZ', 'PREIS', 'PRINZ', 'RADAR', 'RANGS', 'RAUMS', 'RECHT', 'REGAL',
    'RINGS', 'RUDER', 'RUHIG', 'SAITE', 'SALAT', 'SCHAF', 'SCHUH', 'SEELE', 'SEGEL', 'SEITE',
    'SICHT', 'SILBE', 'Sessel', 'SPURT', 'STADT', 'STAHL', 'STEIN', 'STIMM', 'STOFF', 'STRAND',
    'STROM', 'STUHL', 'SZENE', 'TANNE', 'TASSE', 'TEMPO', 'THEMA', 'TISCH', 'TITEL', 'TRANK',
    'TREUE', 'TROPF', 'UFERN', 'UMZUG', 'VATER', 'VIDEO', 'WAGEN', 'WALDE', 'WASSER', 'WELLE',
    'WERTE', 'WIESE', 'WINDE', 'WISSEN', 'WOCHE', 'WORTE', 'ZAHNE', 'ZELTE', 'ZIELE', 'ZITRO',
];
$words5 = array_values(array_unique(array_filter(
    array_map(static fn(string $w): string => strtoupper(trim($w)), $wordList),
    static fn(string $w): bool => strlen($w) === 5 && (bool)preg_match('/^[A-Z]+$/', $w)
)));
$dailySeed = crc32(date('Y-m-d') . '_minicloud_wordle');
$dailyWord = $words5[$dailySeed % count($words5)];

$initialLeaderboard = fetchLeaderboardRows($pdo, '2048', 'all');

$pageTitle = '🎮 Spiele & Pausenraum';
require_once __DIR__ . '/includes/header.php';
$csrfToken = getCsrfToken();
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-6">
    <!-- KOPFBEREICH -->
    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs flex flex-wrap items-center justify-between gap-4">
        <div class="flex items-center gap-3.5">
            <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-purple-600 to-indigo-600 text-white flex items-center justify-center text-2xl shadow-sm shrink-0">
                🎮
            </div>
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-lg sm:text-xl font-extrabold text-slate-900 dark:text-white">
                        MiniCloud Spiele-Zentrale &amp; Pausenraum
                    </h1>
                    <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-purple-100 dark:bg-purple-950/80 text-purple-800 dark:text-purple-300 border border-purple-200 dark:border-purple-800">
                        9 Spiele &middot; Cloud-Highscores &middot; Live-Duelle
                    </span>
                </div>
                <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-0.5">
                    100 % werbefrei &amp; lokal im Browser – inklusive Cloud-Bestenlisten, automatischer Spielstand-Speicherung und Duellen gegen andere Cloud-Mitglieder.
                </p>
            </div>
        </div>

        <div class="flex items-center gap-3 text-xs">
            <div class="px-3.5 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700">
                <span class="text-slate-500 dark:text-slate-400">Meine Runden:</span>
                <strong class="ml-1 text-slate-900 dark:text-white" id="myTotalPlaysBadge"><?= $myTotalPlays ?></strong>
            </div>
            <div class="px-3.5 py-2 rounded-xl bg-amber-50 dark:bg-amber-950/50 border border-amber-200 dark:border-amber-800 text-amber-800 dark:text-amber-300 font-semibold">
                🏆 Tageswort #<?= (int)date('z') + 1 ?> aktiv
            </div>
        </div>
    </div>

    <!-- SPIELAUSWAHL-LEISTE (9 SPIEL-KARTEN) -->
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-9 gap-2.5">
        <?php foreach ($gamesCatalog as $gKey => $gMeta): ?>
            <?php $myBest = $myBestByGame[$gKey] ?? 0; ?>
            <button type="button"
                    onclick="selectCloudGame('<?= e($gKey) ?>')"
                    id="gameTabBtn-<?= e($gKey) ?>"
                    class="game-tab-btn text-left p-3 rounded-2xl border transition-all cursor-pointer flex flex-col justify-between gap-1.5 <?= $gKey === '2048' ? 'bg-brand-600 text-white border-brand-600 shadow-md' : 'bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100 border-slate-200 dark:border-slate-800 hover:border-brand-400' ?>">
                <div class="flex items-center justify-between gap-1">
                    <span class="text-xl"><?= $gMeta['icon'] ?></span>
                    <span class="text-[10px] font-bold px-1.5 py-0.5 rounded-md bg-black/10 dark:bg-white/10 truncate">
                        <?= e($gMeta['category']) ?>
                    </span>
                </div>
                <div>
                    <p class="text-xs font-extrabold leading-tight truncate"><?= e($gMeta['title']) ?></p>
                    <p class="text-[10px] opacity-80 mt-0.5 truncate" id="myBestLabel-<?= e($gKey) ?>">
                        <?= $myBest > 0 ? '🏆 ' . number_format($myBest, 0, ',', '.') : 'Noch ohne Wertung' ?>
                    </p>
                </div>
            </button>
        <?php endforeach; ?>
    </div>

    <!-- HAUPTBEREICH: LINKS DAS AKTIVE SPIEL (8 SPALTEN) / RECHTS DIE CLOUD-BESTENLISTE (4 SPALTEN) -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">

        <!-- =============================================================== -->
        <!-- LINKE SPALTE: AKTIVE SPIELFLÄCHE                                -->
        <!-- =============================================================== -->
        <div class="lg:col-span-8 mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-4 sm:p-6 shadow-xs min-h-[540px]">

            <!-- 1. SPIEL: 2048 -->
            <div id="gamePanel-2048" class="game-panel space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3 pb-3 border-b border-slate-200 dark:border-slate-800">
                    <div>
                        <h2 class="text-base sm:text-lg font-extrabold text-slate-900 dark:text-white flex items-center gap-2">
                            <span>🔢 2048 Puzzle</span>
                            <span class="text-[11px] font-semibold px-2 py-0.5 rounded-full bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300">💾 Auto-Save aktiv</span>
                        </h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Nutze die Pfeiltasten (`← ↑ → ↓`), `W A S D` oder wische auf dem Smartphone.</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="px-3 py-1.5 rounded-xl bg-amber-100 dark:bg-amber-950/70 text-amber-900 dark:text-amber-200 text-xs font-bold">
                            Punkte: <span id="g2048Score" class="font-extrabold">0</span>
                        </div>
                        <button type="button" onclick="g2048NewGame()" class="px-3 py-1.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs font-bold cursor-pointer">
                            🔄 Neu starten
                        </button>
                    </div>
                </div>

                <div class="flex flex-col items-center justify-center py-2">
                    <div id="g2048Board"
                         class="w-full max-w-[340px] aspect-square p-3 rounded-2xl bg-slate-300 dark:bg-slate-800 grid grid-cols-4 gap-2.5 select-none touch-none shadow-inner">
                    </div>

                    <!-- Smartphone-/Maus-Steuertasten -->
                    <div class="grid grid-cols-3 gap-2 mt-4 w-44">
                        <div></div>
                        <button type="button" onclick="g2048Move('up')" class="py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 font-bold text-sm cursor-pointer">▲</button>
                        <div></div>
                        <button type="button" onclick="g2048Move('left')" class="py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 font-bold text-sm cursor-pointer">◀</button>
                        <button type="button" onclick="g2048Move('down')" class="py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 font-bold text-sm cursor-pointer">▼</button>
                        <button type="button" onclick="g2048Move('right')" class="py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 font-bold text-sm cursor-pointer">▶</button>
                    </div>
                </div>
            </div>

            <!-- 2. SPIEL: SUDOKU -->
            <div id="gamePanel-sudoku" class="game-panel hidden space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3 pb-3 border-b border-slate-200 dark:border-slate-800">
                    <div>
                        <h2 class="text-base sm:text-lg font-extrabold text-slate-900 dark:text-white flex items-center gap-2">
                            <span>🧠 Cloud-Sudoku</span>
                            <span class="text-[11px] font-semibold px-2 py-0.5 rounded-full bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300">💾 Spielstand-Sync</span>
                        </h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Fülle jede Zeile, Spalte und jeden 3×3-Block mit den Zahlen 1 bis 9.</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <select id="sudokuDiffSelect" onchange="sudokuNewGame(this.value)"
                                class="px-2.5 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs font-bold">
                            <option value="easy">🌱 Leicht</option>
                            <option value="medium" selected>⚖️ Mittel</option>
                            <option value="hard">🔥 Schwer</option>
                        </select>
                        <span class="px-2.5 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 text-xs font-mono font-bold" id="sudokuTimer">00:00</span>
                        <button type="button" onclick="sudokuToggleNoteMode()" id="sudokuNoteBtn"
                                class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 text-xs font-bold cursor-pointer">
                            ✏️ Notizen: Aus
                        </button>
                        <button type="button" onclick="sudokuGiveHint()"
                                class="px-3 py-1.5 rounded-xl bg-amber-100 dark:bg-amber-950/80 text-amber-800 dark:text-amber-300 text-xs font-bold cursor-pointer">
                            💡 Tipp
                        </button>
                        <button type="button" onclick="sudokuNewGame(document.getElementById('sudokuDiffSelect').value)"
                                class="px-3 py-1.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs font-bold cursor-pointer">
                            🔄 Neues Rätsel
                        </button>
                    </div>
                </div>

                <div class="flex flex-col items-center gap-4">
                    <div id="sudokuBoard" class="grid grid-cols-9 border-2 border-slate-800 dark:border-slate-400 rounded-xl overflow-hidden w-full max-w-[378px] aspect-square select-none"></div>

                    <!-- Ziffern-Leiste 1-9 + Löschen -->
                    <div class="flex flex-wrap justify-center gap-1.5">
                        <?php for ($n = 1; $n <= 9; $n++): ?>
                            <button type="button" onclick="sudokuInputNumber(<?= $n ?>)"
                                    class="w-9 h-10 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-600 hover:text-white font-extrabold text-sm transition-colors cursor-pointer">
                                <?= $n ?>
                            </button>
                        <?php endfor; ?>
                        <button type="button" onclick="sudokuInputNumber(0)"
                                class="px-3 h-10 rounded-xl bg-rose-100 dark:bg-rose-950/60 text-rose-700 dark:text-rose-300 font-bold text-xs cursor-pointer">
                            ⌫ Löschen
                        </button>
                    </div>
                </div>
            </div>

            <!-- 3. SPIEL: MINESWEEPER -->
            <div id="gamePanel-minesweeper" class="game-panel hidden space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3 pb-3 border-b border-slate-200 dark:border-slate-800">
                    <div>
                        <h2 class="text-base sm:text-lg font-extrabold text-slate-900 dark:text-white">💣 Minesweeper</h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Decke alle minenfreien Felder auf. Der erste Klick ist immer sicher!</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <select id="mineSizeSelect" onchange="mineNewGame(this.value)"
                                class="px-2.5 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs font-bold">
                            <option value="easy">9×9 (10 Minen)</option>
                            <option value="medium" selected>12×12 (22 Minen)</option>
                            <option value="hard">16×12 (34 Minen)</option>
                        </select>
                        <button type="button" id="mineFlagToggleBtn" onclick="mineToggleFlagMode()"
                                class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 text-xs font-bold cursor-pointer">
                            ⛏️ Modus: Aufdecken
                        </button>
                        <span class="px-2.5 py-1.5 rounded-xl bg-rose-100 dark:bg-rose-950/70 text-rose-800 dark:text-rose-300 text-xs font-bold">
                            💣 <span id="mineRemainingCount">22</span>
                        </span>
                        <span class="px-2.5 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 text-xs font-mono font-bold" id="mineTimer">00:00</span>
                        <button type="button" onclick="mineNewGame(document.getElementById('mineSizeSelect').value)"
                                class="px-3 py-1.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs font-bold cursor-pointer">
                            🔄 Neu
                        </button>
                    </div>
                </div>

                <div class="flex flex-col items-center overflow-x-auto py-2">
                    <div id="mineBoard" class="inline-grid gap-1 p-2.5 rounded-2xl bg-slate-200 dark:bg-slate-800 select-none"></div>
                    <p id="mineStatusMsg" class="text-xs font-bold mt-3 text-slate-600 dark:text-slate-300"></p>
                </div>
            </div>

            <!-- 4. SPIEL: WORT DES TAGES (CLOUD-WORDLE) -->
            <div id="gamePanel-wordle" class="game-panel hidden space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3 pb-3 border-b border-slate-200 dark:border-slate-800">
                    <div>
                        <h2 class="text-base sm:text-lg font-extrabold text-slate-900 dark:text-white flex items-center gap-2">
                            <span>🔤 Wort des Tages (Cloud-Wordle)</span>
                            <span id="wordleModeBadge" class="text-[11px] font-bold px-2 py-0.5 rounded-full bg-amber-100 dark:bg-amber-950 text-amber-800 dark:text-amber-300">
                                📅 Tageswort #<?= (int)date('z') + 1 ?>
                            </span>
                        </h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            🟩 Richtiger Platz &middot; 🟨 Im Wort enthalten &middot; ⬛ Nicht enthalten.
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" onclick="wordleStartDaily()" class="px-3 py-1.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-white text-xs font-bold cursor-pointer">
                            📅 Tageswort
                        </button>
                        <button type="button" onclick="wordleStartPractice()" class="px-3 py-1.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs font-bold cursor-pointer">
                            🎲 Zufallswort üben
                        </button>
                    </div>
                </div>

                <div class="flex flex-col items-center gap-4">
                    <div id="wordleGrid" class="grid grid-rows-6 gap-1.5 w-full max-w-[290px]"></div>
                    <p id="wordleStatusMsg" class="text-xs font-bold min-h-[20px] text-center"></p>
                    <div id="wordleKeyboard" class="w-full max-w-md space-y-1.5 select-none"></div>
                </div>
            </div>

            <!-- 5. SPIEL: MEMORY -->
            <div id="gamePanel-memory" class="game-panel hidden space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3 pb-3 border-b border-slate-200 dark:border-slate-800">
                    <div>
                        <h2 class="text-base sm:text-lg font-extrabold text-slate-900 dark:text-white">🃏 Cloud-Memory</h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Decke jeweils zwei Karten auf und finde alle Paare in möglichst wenigen Zügen.</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <select id="memorySizeSelect" onchange="memoryNewGame(this.value)"
                                class="px-2.5 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs font-bold">
                            <option value="16" selected>4×4 (8 Paare)</option>
                            <option value="24">6×4 (12 Paare)</option>
                        </select>
                        <span class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 text-xs font-bold">
                            Züge: <strong id="memoryMoves">0</strong>
                        </span>
                        <span class="px-2.5 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 text-xs font-mono font-bold" id="memoryTimer">00:00</span>
                        <button type="button" onclick="memoryNewGame(document.getElementById('memorySizeSelect').value)"
                                class="px-3 py-1.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs font-bold cursor-pointer">
                            🔄 Neu mischen
                        </button>
                    </div>
                </div>

                <div class="flex flex-col items-center py-2">
                    <div id="memoryBoard" class="grid grid-cols-4 gap-2.5 w-full max-w-[380px] select-none"></div>
                </div>
            </div>

            <!-- 6. SPIEL: BLOCK-PUZZLE (TETRIS-STIL) -->
            <div id="gamePanel-tetris" class="game-panel hidden space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3 pb-3 border-b border-slate-200 dark:border-slate-800">
                    <div>
                        <h2 class="text-base sm:text-lg font-extrabold text-slate-900 dark:text-white">🧱 Block-Puzzle (Tetris)</h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Pfeiltasten `←/→` Bewegen &middot; `↑` Drehen &middot; `↓` Schneller &middot; `Leertaste` Sofort-Drop.</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="px-3 py-1.5 rounded-xl bg-purple-100 dark:bg-purple-950/70 text-purple-900 dark:text-purple-200 text-xs font-bold">
                            Punkte: <strong id="tetrisScore">0</strong> &middot; Lvl <strong id="tetrisLevel">1</strong>
                        </span>
                        <button type="button" onclick="tetrisStartGame()"
                                class="px-3 py-1.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs font-bold cursor-pointer">
                            ▶️ Start / Neu
                        </button>
                    </div>
                </div>

                <div class="flex flex-col sm:flex-row items-center justify-center gap-5">
                    <canvas id="tetrisCanvas" width="240" height="440"
                            class="rounded-2xl border-2 border-slate-300 dark:border-slate-700 bg-slate-950 shadow-inner"></canvas>

                    <div class="flex flex-col items-center gap-3 w-44">
                        <div class="w-full p-3 rounded-2xl bg-slate-100 dark:bg-slate-800 text-center space-y-1.5">
                            <p class="text-[11px] font-bold uppercase text-slate-500">Nächster Stein</p>
                            <canvas id="tetrisNextCanvas" width="96" height="72" class="mx-auto rounded-xl bg-slate-900"></canvas>
                            <p class="text-xs text-slate-600 dark:text-slate-300">Reihen: <strong id="tetrisLines">0</strong></p>
                        </div>

                        <!-- Touch-Steuerung -->
                        <div class="grid grid-cols-3 gap-1.5 w-full">
                            <button type="button" onclick="tetrisAction('left')" class="py-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 font-bold text-sm cursor-pointer">◀</button>
                            <button type="button" onclick="tetrisAction('rotate')" class="py-2.5 rounded-xl bg-indigo-100 dark:bg-indigo-950 text-indigo-700 dark:text-indigo-300 font-bold text-xs cursor-pointer">↻ Drehen</button>
                            <button type="button" onclick="tetrisAction('right')" class="py-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 font-bold text-sm cursor-pointer">▶</button>
                            <button type="button" onclick="tetrisAction('down')" class="py-2 rounded-xl bg-slate-100 dark:bg-slate-800 font-bold text-xs cursor-pointer">▼ Runter</button>
                            <button type="button" onclick="tetrisAction('drop')" class="col-span-2 py-2 rounded-xl bg-amber-500 text-white font-bold text-xs cursor-pointer">⏬ Sofort-Drop</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 7. SPIEL: RETRO-SNAKE -->
            <div id="gamePanel-snake" class="game-panel hidden space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3 pb-3 border-b border-slate-200 dark:border-slate-800">
                    <div>
                        <h2 class="text-base sm:text-lg font-extrabold text-slate-900 dark:text-white">🐍 Retro-Snake</h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Steuere mit den Pfeiltasten (`← ↑ → ↓`), `W A S D` oder dem Touch-Steuerkreuz.</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <select id="snakeSpeedSelect" class="px-2.5 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs font-bold">
                            <option value="125">🐢 Normal</option>
                            <option value="95" selected>⚡ Schnell</option>
                            <option value="70">🚀 Turbo</option>
                        </select>
                        <span class="px-3 py-1.5 rounded-xl bg-emerald-100 dark:bg-emerald-950/70 text-emerald-900 dark:text-emerald-200 text-xs font-bold">
                            Punkte: <strong id="snakeScore">0</strong>
                        </span>
                        <button type="button" onclick="snakeStartGame()"
                                class="px-3 py-1.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs font-bold cursor-pointer">
                            ▶️ Start / Neu
                        </button>
                    </div>
                </div>

                <div class="flex flex-col items-center gap-3">
                    <canvas id="snakeCanvas" width="360" height="360"
                            class="w-full max-w-[360px] aspect-square rounded-2xl border-2 border-slate-300 dark:border-slate-700 bg-slate-950 shadow-inner"></canvas>

                    <div class="grid grid-cols-3 gap-2 w-44">
                        <div></div>
                        <button type="button" onclick="snakeSetDir(0,-1)" class="py-2 rounded-xl bg-slate-100 dark:bg-slate-800 font-bold text-sm cursor-pointer">▲</button>
                        <div></div>
                        <button type="button" onclick="snakeSetDir(-1,0)" class="py-2 rounded-xl bg-slate-100 dark:bg-slate-800 font-bold text-sm cursor-pointer">◀</button>
                        <button type="button" onclick="snakeSetDir(0,1)"  class="py-2 rounded-xl bg-slate-100 dark:bg-slate-800 font-bold text-sm cursor-pointer">▼</button>
                        <button type="button" onclick="snakeSetDir(1,0)"  class="py-2 rounded-xl bg-slate-100 dark:bg-slate-800 font-bold text-sm cursor-pointer">▶</button>
                    </div>
                </div>
            </div>

            <!-- 8. SPIEL: BRICK BREAKER (BREAKOUT) -->
            <div id="gamePanel-breakout" class="game-panel hidden space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3 pb-3 border-b border-slate-200 dark:border-slate-800">
                    <div>
                        <h2 class="text-base sm:text-lg font-extrabold text-slate-900 dark:text-white">🏓 Brick Breaker (Breakout)</h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Bewege den Schläger mit Maus, Finger oder Pfeiltasten `← / →`.</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="px-3 py-1.5 rounded-xl bg-sky-100 dark:bg-sky-950/70 text-sky-900 dark:text-sky-200 text-xs font-bold">
                            Punkte: <strong id="breakoutScore">0</strong> &middot; ❤️ <strong id="breakoutLives">3</strong>
                        </span>
                        <button type="button" onclick="breakoutStartGame()"
                                class="px-3 py-1.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs font-bold cursor-pointer">
                            ▶️ Start / Neu
                        </button>
                    </div>
                </div>

                <div class="flex flex-col items-center">
                    <canvas id="breakoutCanvas" width="480" height="360"
                            class="w-full max-w-[480px] rounded-2xl border-2 border-slate-300 dark:border-slate-700 bg-slate-950 shadow-inner touch-none"></canvas>
                </div>
            </div>

            <!-- 9. SPIEL: VIER GEWINNT & TIC-TAC-TOE (GEGEN KI ODER CLOUD-BENUTZER!) -->
            <div id="gamePanel-connect4" class="game-panel hidden space-y-5">
                <div class="flex flex-wrap items-center justify-between gap-3 pb-3 border-b border-slate-200 dark:border-slate-800">
                    <div>
                        <h2 class="text-base sm:text-lg font-extrabold text-slate-900 dark:text-white">
                            🔴🟡 Vier Gewinnt &amp; ❌⭕ Tic-Tac-Toe
                        </h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            Spiele entweder sofort gegen die lokale Computer-KI oder fordere einen anderen Cloud-Benutzer heraus!
                        </p>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <button type="button" id="c4SubTabAi" onclick="switchC4Mode('ai')"
                                class="px-3 py-1.5 rounded-xl bg-brand-600 text-white text-xs font-bold cursor-pointer">
                            🤖 Gegen Computer-KI
                        </button>
                        <button type="button" id="c4SubTabCloud" onclick="switchC4Mode('cloud')"
                                class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 text-xs font-bold cursor-pointer">
                            👥 Cloud-Duell gegen Benutzer
                        </button>
                    </div>
                </div>

                <!-- MODUS A: GEGEN LOKALE COMPUTER-KI -->
                <div id="c4ModeAiBox" class="space-y-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <select id="c4AiGameType" onchange="c4AiNewGame()"
                                    class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs font-bold">
                                <option value="connect4" selected>🔴🟡 Vier Gewinnt (7×6)</option>
                                <option value="tictactoe">❌⭕ Tic-Tac-Toe (3×3)</option>
                            </select>
                            <span id="c4AiStatusText" class="text-xs font-bold text-slate-700 dark:text-slate-200">Du bist am Zug (🔴)</span>
                        </div>
                        <button type="button" onclick="c4AiNewGame()"
                                class="px-3 py-1.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs font-bold cursor-pointer">
                            🔄 Neue Partie
                        </button>
                    </div>

                    <div class="flex justify-center py-2">
                        <div id="c4AiBoardContainer"></div>
                    </div>
                </div>

                <!-- MODUS B: CLOUD-DUELL GEGEN ANDERE BENUTZER -->
                <div id="c4ModeCloudBox" class="hidden space-y-4">
                    <?php if (empty($otherUsers)): ?>
                        <div class="p-4 rounded-xl bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800 text-xs text-amber-800 dark:text-amber-200">
                            Aktuell gibt es noch keine weiteren registrierten Benutzer in deiner Cloud. Bis dahin kannst du im Tab <strong>„🤖 Gegen Computer-KI“</strong> spielen!
                        </div>
                    <?php else: ?>
                        <form onsubmit="createCloudMatch(event)" class="p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 flex flex-wrap items-center gap-2.5">
                            <select id="cloudMatchType" class="px-3 py-2 rounded-xl bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs font-bold">
                                <option value="connect4">🔴🟡 Vier Gewinnt</option>
                                <option value="tictactoe">❌⭕ Tic-Tac-Toe</option>
                            </select>
                            <select id="cloudMatchOpponent" class="flex-1 min-w-[160px] px-3 py-2 rounded-xl bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs font-semibold">
                                <?php foreach ($otherUsers as $ou): ?>
                                    <option value="<?= (int)$ou['id'] ?>">👤 Herausfordern: <?= e((string)$ou['username']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="px-4 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs font-bold cursor-pointer">
                                ⚔️ Duell starten
                            </button>
                        </form>
                    <?php endif; ?>

                    <div class="grid grid-cols-1 md:grid-cols-12 gap-4 items-start">
                        <div class="md:col-span-5 space-y-2">
                            <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Meine Cloud-Partien</p>
                            <div id="cloudMatchesList" class="space-y-1.5 max-h-80 overflow-y-auto text-xs"></div>
                        </div>
                        <div class="md:col-span-7 flex flex-col items-center justify-center p-3 rounded-2xl bg-slate-50 dark:bg-slate-800/40 border border-slate-200 dark:border-slate-800 min-h-[260px]">
                            <p id="cloudActiveMatchTitle" class="text-xs font-bold text-slate-700 dark:text-slate-200 mb-3">
                                Wähle links eine Partie aus oder starte ein neues Duell.
                            </p>
                            <div id="cloudActiveMatchBoard"></div>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <!-- =============================================================== -->
        <!-- RECHTE SPALTE: CLOUD-BESTENLISTE (HIGHSCORES)                   -->
        <!-- =============================================================== -->
        <div class="lg:col-span-4 space-y-4">
            <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 shadow-xs space-y-4">
                <div class="flex items-center justify-between gap-2">
                    <div>
                        <h2 class="text-sm font-extrabold text-slate-900 dark:text-white flex items-center gap-1.5">
                            <span>🏆</span>
                            <span id="leaderboardGameTitle">Bestenliste: 2048 Puzzle</span>
                        </h2>
                        <p class="text-[11px] text-slate-500 dark:text-slate-400">Top 10 Spieler deiner Cloud</p>
                    </div>
                </div>

                <!-- Zeitraum-Filter -->
                <div class="grid grid-cols-3 gap-1.5 bg-slate-100 dark:bg-slate-800 p-1 rounded-xl text-xs font-bold">
                    <button type="button" onclick="loadCloudLeaderboard('today')" id="lbPeriod-today"
                            class="lb-period-btn py-1.5 rounded-lg text-slate-600 dark:text-slate-300 cursor-pointer">
                        Heute
                    </button>
                    <button type="button" onclick="loadCloudLeaderboard('month')" id="lbPeriod-month"
                            class="lb-period-btn py-1.5 rounded-lg text-slate-600 dark:text-slate-300 cursor-pointer">
                        30 Tage
                    </button>
                    <button type="button" onclick="loadCloudLeaderboard('all')" id="lbPeriod-all"
                            class="lb-period-btn py-1.5 rounded-lg bg-white dark:bg-slate-900 text-brand-600 dark:text-brand-400 shadow-2xs cursor-pointer">
                        Gesamt
                    </button>
                </div>

                <!-- Rangliste -->
                <div id="leaderboardListBox" class="space-y-2 text-xs"></div>
            </div>

            <!-- Kurzanleitung / Punkte-Info -->
            <div class="mc-card bg-indigo-50/70 dark:bg-indigo-950/20 rounded-2xl border border-indigo-200/80 dark:border-indigo-800/60 p-4 text-xs space-y-1.5">
                <p class="font-bold text-indigo-950 dark:text-indigo-200">💡 So funktioniert die Cloud-Wertung</p>
                <p class="text-indigo-900/80 dark:text-indigo-200/80 leading-relaxed">
                    • Deine Highscores werden automatisch am Ende einer Runde gespeichert.<br>
                    • Bei <strong>2048</strong> und <strong>Sudoku</strong> wird dein laufendes Spiel zusätzlich live auf dem Server gesichert – du kannst nahtlos am Smartphone weiterspielen!<br>
                    • Im <strong>Wort des Tages</strong> erhalten alle Cloud-Benutzer täglich exakt dasselbe Rätselwort.
                </p>
            </div>
        </div>
    </div>
</div>

<script>
const GAMES_CSRF        = <?= json_encode($csrfToken) ?>;
const CURRENT_USER_ID   = <?= (int)$userId ?>;
const CURRENT_USERNAME  = <?= json_encode($username) ?>;
const GAMES_CATALOG     = <?= json_encode($gamesCatalog) ?>;
const SAVED_GAME_STATES = <?= json_encode($savedStates) ?>;
const WORDLE_DAILY_WORD = <?= json_encode($dailyWord) ?>;
const WORDLE_POOL       = <?= json_encode($words5) ?>;

let activeGameKey    = '2048';
let activeLbPeriod   = 'all';
let myBestScores     = <?= json_encode($myBestByGame) ?>;
let totalPlaysCount  = <?= (int)$myTotalPlays ?>;

// =============================================================================
// ZENTRALE SPIEL-UMSCHALTUNG & HIGHSCORE-API
// =============================================================================
function selectCloudGame(gameKey) {
    if (!GAMES_CATALOG[gameKey]) return;
    activeGameKey = gameKey;

    document.querySelectorAll('.game-panel').forEach((el) => el.classList.add('hidden'));
    const targetPanel = document.getElementById('gamePanel-' + gameKey);
    if (targetPanel) targetPanel.classList.remove('hidden');

    document.querySelectorAll('.game-tab-btn').forEach((btn) => {
        btn.className = 'game-tab-btn text-left p-3 rounded-2xl border transition-all cursor-pointer flex flex-col justify-between gap-1.5 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100 border-slate-200 dark:border-slate-800 hover:border-brand-400';
    });
    const activeBtn = document.getElementById('gameTabBtn-' + gameKey);
    if (activeBtn) {
        activeBtn.className = 'game-tab-btn text-left p-3 rounded-2xl border transition-all cursor-pointer flex flex-col justify-between gap-1.5 bg-brand-600 text-white border-brand-600 shadow-md';
    }

    const titleEl = document.getElementById('leaderboardGameTitle');
    if (titleEl) {
        titleEl.textContent = 'Bestenliste: ' + GAMES_CATALOG[gameKey].title;
    }

    loadCloudLeaderboard(activeLbPeriod);
}

async function submitCloudScore(gameKey, score, durationSec = 0, metaLabel = '') {
    score = Math.round(Number(score) || 0);
    if (score <= 0) return;

    const fd = new FormData();
    fd.append('ajax', '1');
    fd.append('csrf_token', GAMES_CSRF);
    fd.append('action', 'save_score');
    fd.append('game_key', gameKey);
    fd.append('score', String(score));
    fd.append('duration_sec', String(Math.round(durationSec || 0)));
    fd.append('meta_label', metaLabel);

    try {
        const res = await fetch('games.php', { method: 'POST', body: fd });
        const d   = await res.json();
        if (d && d.ok) {
            totalPlaysCount++;
            const badge = document.getElementById('myTotalPlaysBadge');
            if (badge) badge.textContent = String(totalPlaysCount);

            if (d.personal_best) {
                myBestScores[gameKey] = d.personal_best;
                const lbl = document.getElementById('myBestLabel-' + gameKey);
                if (lbl) lbl.textContent = '🏆 ' + Number(d.personal_best).toLocaleString('de-DE');
            }
            if (activeGameKey === gameKey && Array.isArray(d.leaderboard)) {
                renderLeaderboardRows(d.leaderboard);
            }
        }
    } catch (e) {}
}

async function saveCloudGameState(gameKey, stateObj) {
    const fd = new FormData();
    fd.append('ajax', '1');
    fd.append('csrf_token', GAMES_CSRF);
    fd.append('action', 'save_state');
    fd.append('game_key', gameKey);
    fd.append('state_json', stateObj ? JSON.stringify(stateObj) : '');
    try {
        await fetch('games.php', { method: 'POST', body: fd });
    } catch (e) {}
}

async function loadCloudLeaderboard(period) {
    activeLbPeriod = period || 'all';
    ['today', 'month', 'all'].forEach((p) => {
        const b = document.getElementById('lbPeriod-' + p);
        if (!b) return;
        if (p === activeLbPeriod) {
            b.className = 'lb-period-btn py-1.5 rounded-lg bg-white dark:bg-slate-900 text-brand-600 dark:text-brand-400 shadow-2xs cursor-pointer';
        } else {
            b.className = 'lb-period-btn py-1.5 rounded-lg text-slate-600 dark:text-slate-300 cursor-pointer';
        }
    });

    const fd = new FormData();
    fd.append('ajax', '1');
    fd.append('csrf_token', GAMES_CSRF);
    fd.append('action', 'get_leaderboard');
    fd.append('game_key', activeGameKey);
    fd.append('period', activeLbPeriod);

    try {
        const r = await fetch('games.php', { method: 'POST', body: fd });
        const d = await r.json();
        if (d && d.ok) {
            renderLeaderboardRows(d.leaderboard || []);
        }
    } catch (e) {}
}

function renderLeaderboardRows(rows) {
    const box = document.getElementById('leaderboardListBox');
    if (!box) return;
    if (!Array.isArray(rows) || rows.length === 0) {
        box.innerHTML = '<p class="text-slate-400 italic py-4 text-center">Noch keine Einträge für dieses Spiel. Sichere dir jetzt Platz 1! 🥇</p>';
        return;
    }

    const medals = ['🥇', '🥈', '🥉'];
    box.innerHTML = rows.map((r, idx) => {
        const rankBadge = idx < 3 ? `<span class="text-base">${medals[idx]}</span>` : `<span class="w-5 text-center font-bold text-slate-400">${idx + 1}.</span>`;
        const isMe = String(r.username) === String(CURRENT_USERNAME);
        const durStr = r.best_duration ? ` · ⏱️ ${r.best_duration}s` : '';
        return `
            <div class="flex items-center justify-between gap-2 px-3 py-2 rounded-xl border ${isMe ? 'bg-brand-50/80 dark:bg-brand-950/40 border-brand-300 dark:border-brand-800' : 'bg-slate-50 dark:bg-slate-800/60 border-slate-200/70 dark:border-slate-700/70'}">
                <div class="flex items-center gap-2 min-w-0">
                    ${rankBadge}
                    <div class="min-w-0">
                        <p class="font-bold text-slate-900 dark:text-white truncate">${r.username}</p>
                        <p class="text-[10px] text-slate-400">${r.plays_count} ${r.plays_count == 1 ? 'Runde' : 'Runden'}${durStr}</p>
                    </div>
                </div>
                <span class="font-extrabold text-brand-600 dark:text-brand-400 shrink-0">${Number(r.best_score).toLocaleString('de-DE')} Pkt.</span>
            </div>
        `;
    }).join('');
}

// =============================================================================
// SPIEL 1: 2048 PUZZLE
// =============================================================================
let g2048Grid  = [];
let g2048Score = 0;

function g2048Init() {
    if (SAVED_GAME_STATES['2048']) {
        try {
            const st = JSON.parse(SAVED_GAME_STATES['2048']);
            if (Array.isArray(st.grid) && st.grid.length === 4) {
                g2048Grid  = st.grid;
                g2048Score = Number(st.score) || 0;
                g2048Render();
                return;
            }
        } catch (e) {}
    }
    g2048NewGame(false);
}

function g2048NewGame(clearRemote = true) {
    if (clearRemote && g2048Score > 40) {
        submitCloudScore('2048', g2048Score, 0, '2048');
    }
    g2048Grid  = Array.from({ length: 4 }, () => [0, 0, 0, 0]);
    g2048Score = 0;
    g2048Spawn();
    g2048Spawn();
    g2048Render();
    if (clearRemote) {
        saveCloudGameState('2048', { grid: g2048Grid, score: g2048Score });
    }
}

function g2048Spawn() {
    const empty = [];
    for (let r = 0; r < 4; r++) {
        for (let c = 0; c < 4; c++) {
            if (g2048Grid[r][c] === 0) empty.push([r, c]);
        }
    }
    if (!empty.length) return;
    const [r, c] = empty[Math.floor(Math.random() * empty.length)];
    g2048Grid[r][c] = Math.random() < 0.9 ? 2 : 4;
}

function g2048SlideLine(row) {
    const filtered = row.filter((v) => v !== 0);
    const out = [];
    let gained = 0;
    for (let i = 0; i < filtered.length; i++) {
        if (i + 1 < filtered.length && filtered[i] === filtered[i + 1]) {
            const merged = filtered[i] * 2;
            out.push(merged);
            gained += merged;
            i++;
        } else {
            out.push(filtered[i]);
        }
    }
    while (out.length < 4) out.push(0);
    return { line: out, gained };
}

function g2048Move(dir) {
    let moved = false;
    let gainedTotal = 0;

    for (let i = 0; i < 4; i++) {
        let line = [];
        for (let j = 0; j < 4; j++) {
            if (dir === 'left')  line.push(g2048Grid[i][j]);
            if (dir === 'right') line.push(g2048Grid[i][3 - j]);
            if (dir === 'up')    line.push(g2048Grid[j][i]);
            if (dir === 'down')  line.push(g2048Grid[3 - j][i]);
        }
        const { line: newLine, gained } = g2048SlideLine(line);
        gainedTotal += gained;
        for (let j = 0; j < 4; j++) {
            let r = i, c = j;
            if (dir === 'right') { r = i; c = 3 - j; }
            if (dir === 'up')    { r = j; c = i; }
            if (dir === 'down')  { r = 3 - j; c = i; }
            if (g2048Grid[r][c] !== newLine[j]) moved = true;
            g2048Grid[r][c] = newLine[j];
        }
    }

    if (!moved) return;
    g2048Score += gainedTotal;
    g2048Spawn();
    g2048Render();
    saveCloudGameState('2048', { grid: g2048Grid, score: g2048Score });

    if (g2048IsGameOver()) {
        submitCloudScore('2048', g2048Score, 0, '2048');
    }
}

function g2048IsGameOver() {
    for (let r = 0; r < 4; r++) {
        for (let c = 0; c < 4; c++) {
            if (g2048Grid[r][c] === 0) return false;
            if (r < 3 && g2048Grid[r][c] === g2048Grid[r + 1][c]) return false;
            if (c < 3 && g2048Grid[r][c] === g2048Grid[r][c + 1]) return false;
        }
    }
    return true;
}

function g2048Render() {
    const board = document.getElementById('g2048Board');
    const scEl  = document.getElementById('g2048Score');
    if (scEl) scEl.textContent = String(g2048Score);
    if (!board) return;

    const colors = {
        0:    'bg-slate-200/80 dark:bg-slate-900/60 text-transparent',
        2:    'bg-amber-50 text-slate-800',
        4:    'bg-amber-100 text-slate-800',
        8:    'bg-orange-300 text-white',
        16:   'bg-orange-400 text-white',
        32:   'bg-orange-500 text-white',
        64:   'bg-red-500 text-white',
        128:  'bg-yellow-400 text-slate-900',
        256:  'bg-yellow-500 text-white',
        512:  'bg-amber-500 text-white',
        1024: 'bg-indigo-600 text-white',
        2048: 'bg-emerald-600 text-white',
    };

    board.innerHTML = '';
    for (let r = 0; r < 4; r++) {
        for (let c = 0; c < 4; c++) {
            const v = g2048Grid[r][c];
            const cls = colors[v] || 'bg-purple-600 text-white';
            const d = document.createElement('div');
            d.className = `rounded-xl flex items-center justify-center font-extrabold ${v >= 1000 ? 'text-lg' : 'text-2xl'} shadow-2xs transition-all ${cls}`;
            d.textContent = v > 0 ? String(v) : '';
            board.appendChild(d);
        }
    }
}

// Touch-Swipe für 2048
(() => {
    let sx = 0, sy = 0;
    const b = document.getElementById('g2048Board');
    if (!b) return;
    b.addEventListener('touchstart', (e) => {
        sx = e.touches[0].clientX;
        sy = e.touches[0].clientY;
    }, { passive: true });
    b.addEventListener('touchend', (e) => {
        const dx = e.changedTouches[0].clientX - sx;
        const dy = e.changedTouches[0].clientY - sy;
        if (Math.max(Math.abs(dx), Math.abs(dy)) < 24) return;
        if (Math.abs(dx) > Math.abs(dy)) {
            g2048Move(dx > 0 ? 'right' : 'left');
        } else {
            g2048Move(dy > 0 ? 'down' : 'up');
        }
    }, { passive: true });
})();

// =============================================================================
// SPIEL 2: CLOUD-SUDOKU
// =============================================================================
const SUDOKU_BASE = [
    [5,3,4,6,7,8,9,1,2],
    [6,7,2,1,9,5,3,4,8],
    [1,9,8,3,4,2,5,6,7],
    [8,5,9,7,6,1,4,2,3],
    [4,2,6,8,5,3,7,9,1],
    [7,1,3,9,2,4,8,5,6],
    [9,6,1,5,3,7,2,8,4],
    [2,8,7,4,1,9,6,3,5],
    [3,4,5,2,8,6,1,7,9]
];
let sudokuSolution = [];
let sudokuPuzzle   = [];
let sudokuCurrent  = [];
let sudokuNotes    = [];
let sudokuSel      = [0, 0];
let sudokuNoteMode = false;
let sudokuSeconds  = 0;
let sudokuDiff     = 'medium';

function sudokuInit() {
    if (SAVED_GAME_STATES['sudoku']) {
        try {
            const st = JSON.parse(SAVED_GAME_STATES['sudoku']);
            if (Array.isArray(st.current) && st.current.length === 9) {
                sudokuSolution = st.solution;
                sudokuPuzzle   = st.puzzle;
                sudokuCurrent  = st.current;
                sudokuNotes    = st.notes || Array.from({ length: 9 }, () => Array.from({ length: 9 }, () => []));
                sudokuSeconds  = Number(st.seconds) || 0;
                sudokuDiff     = st.diff || 'medium';
                sudokuRender();
                return;
            }
        } catch (e) {}
    }
    sudokuNewGame('medium', false);
}

function sudokuNewGame(diff = 'medium', saveRemote = true) {
    sudokuDiff = diff;
    // Ziffern 1-9 zufällig permutieren
    const digits = [1,2,3,4,5,6,7,8,9].sort(() => Math.random() - 0.5);
    sudokuSolution = SUDOKU_BASE.map((row) => row.map((v) => digits[v - 1]));

    const holes = diff === 'easy' ? 32 : (diff === 'hard' ? 50 : 42);
    sudokuPuzzle = sudokuSolution.map((r) => [...r]);
    let removed = 0;
    while (removed < holes) {
        const r = Math.floor(Math.random() * 9);
        const c = Math.floor(Math.random() * 9);
        if (sudokuPuzzle[r][c] !== 0) {
            sudokuPuzzle[r][c] = 0;
            removed++;
        }
    }
    sudokuCurrent = sudokuPuzzle.map((r) => [...r]);
    sudokuNotes   = Array.from({ length: 9 }, () => Array.from({ length: 9 }, () => []));
    sudokuSeconds = 0;
    sudokuRender();
    if (saveRemote) sudokuPersistState();
}

function sudokuPersistState() {
    saveCloudGameState('sudoku', {
        solution: sudokuSolution,
        puzzle:   sudokuPuzzle,
        current:  sudokuCurrent,
        notes:    sudokuNotes,
        seconds:  sudokuSeconds,
        diff:     sudokuDiff
    });
}

function sudokuToggleNoteMode() {
    sudokuNoteMode = !sudokuNoteMode;
    const btn = document.getElementById('sudokuNoteBtn');
    if (btn) {
        btn.textContent = sudokuNoteMode ? '✏️ Notizen: An' : '✏️ Notizen: Aus';
        btn.className = sudokuNoteMode
            ? 'px-3 py-1.5 rounded-xl bg-brand-600 text-white text-xs font-bold cursor-pointer'
            : 'px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 text-xs font-bold cursor-pointer';
    }
}

function sudokuGiveHint() {
    const [r, c] = sudokuSel;
    if (sudokuPuzzle[r][c] !== 0) return;
    sudokuCurrent[r][c] = sudokuSolution[r][c];
    sudokuSeconds += 20; // kleine Zeitstrafe für Tipp
    sudokuRender();
    sudokuCheckComplete();
}

function sudokuInputNumber(num) {
    const [r, c] = sudokuSel;
    if (sudokuPuzzle[r][c] !== 0) return;

    if (num === 0) {
        sudokuCurrent[r][c] = 0;
        sudokuNotes[r][c]   = [];
    } else if (sudokuNoteMode) {
        sudokuCurrent[r][c] = 0;
        const idx = sudokuNotes[r][c].indexOf(num);
        if (idx >= 0) sudokuNotes[r][c].splice(idx, 1);
        else sudokuNotes[r][c].push(num);
    } else {
        sudokuCurrent[r][c] = num;
        sudokuNotes[r][c]   = [];
    }
    sudokuRender();
    sudokuPersistState();
    sudokuCheckComplete();
}

function sudokuCheckComplete() {
    for (let r = 0; r < 9; r++) {
        for (let c = 0; c < 9; c++) {
            if (sudokuCurrent[r][c] !== sudokuSolution[r][c]) return;
        }
    }
    const mult = sudokuDiff === 'hard' ? 1800 : (sudokuDiff === 'medium' ? 1200 : 800);
    const pts  = Math.max(200, mult - sudokuSeconds * 2);
    submitCloudScore('sudoku', pts, sudokuSeconds, 'Sudoku (' + sudokuDiff + ')');
    saveCloudGameState('sudoku', null);
    alert(`🎉 Herzlichen Glückwunsch! Sudoku gelöst in ${sudokuSeconds} Sekunden (${pts} Punkte)!`);
}

function sudokuRender() {
    const board = document.getElementById('sudokuBoard');
    if (!board) return;
    board.innerHTML = '';

    for (let r = 0; r < 9; r++) {
        for (let c = 0; c < 9; c++) {
            const fixed = sudokuPuzzle[r][c] !== 0;
            const val   = sudokuCurrent[r][c];
            const isSel = (sudokuSel[0] === r && sudokuSel[1] === c);
            const isWrong = (!fixed && val !== 0 && val !== sudokuSolution[r][c]);

            const borderR = (c === 2 || c === 5) ? 'border-r-2 border-r-slate-800 dark:border-r-slate-400' : 'border-r border-r-slate-300 dark:border-r-slate-700';
            const borderB = (r === 2 || r === 5) ? 'border-b-2 border-b-slate-800 dark:border-b-slate-400' : 'border-b border-b-slate-300 dark:border-b-slate-700';

            const cell = document.createElement('button');
            cell.type = 'button';
            cell.onclick = () => { sudokuSel = [r, c]; sudokuRender(); };
            cell.className = `flex items-center justify-center font-bold text-sm sm:text-base cursor-pointer ${borderR} ${borderB} ${
                isSel ? 'bg-brand-200 dark:bg-brand-900/80' : (fixed ? 'bg-slate-100 dark:bg-slate-800/80 text-slate-900 dark:text-white' : 'bg-white dark:bg-slate-900 text-brand-600 dark:text-brand-400')
            } ${isWrong ? 'text-rose-600 dark:text-rose-400 bg-rose-50 dark:bg-rose-950/50' : ''}`;

            if (val > 0) {
                cell.textContent = String(val);
            } else if (sudokuNotes[r][c]?.length) {
                cell.innerHTML = `<span class="text-[9px] leading-none text-slate-400">${sudokuNotes[r][c].sort().join('')}</span>`;
            }
            board.appendChild(cell);
        }
    }
}

// =============================================================================
// SPIEL 3: MINESWEEPER
// =============================================================================
let mineRows = 12, mineCols = 12, mineTotal = 22;
let mineGrid = [];
let mineStarted = false, mineOver = false, mineFlagMode = false, mineSeconds = 0;

function mineNewGame(diff = 'medium') {
    if (diff === 'easy') { mineRows = 9; mineCols = 9; mineTotal = 10; }
    else if (diff === 'hard') { mineRows = 12; mineCols = 16; mineTotal = 34; }
    else { mineRows = 12; mineCols = 12; mineTotal = 22; }

    mineGrid = Array.from({ length: mineRows }, () =>
        Array.from({ length: mineCols }, () => ({ mine: false, open: false, flag: false, count: 0 }))
    );
    mineStarted = false;
    mineOver    = false;
    mineSeconds = 0;
    const msg = document.getElementById('mineStatusMsg');
    if (msg) msg.textContent = '';
    mineRender();
}

function mineToggleFlagMode() {
    mineFlagMode = !mineFlagMode;
    const btn = document.getElementById('mineFlagToggleBtn');
    if (btn) {
        btn.textContent = mineFlagMode ? '🚩 Modus: Flagge' : '⛏️ Modus: Aufdecken';
        btn.className = mineFlagMode
            ? 'px-3 py-1.5 rounded-xl bg-amber-500 text-white text-xs font-bold cursor-pointer'
            : 'px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 text-xs font-bold cursor-pointer';
    }
}

function minePlaceBombs(safeR, safeC) {
    let placed = 0;
    while (placed < mineTotal) {
        const r = Math.floor(Math.random() * mineRows);
        const c = Math.floor(Math.random() * mineCols);
        if (Math.abs(r - safeR) <= 1 && Math.abs(c - safeC) <= 1) continue;
        if (mineGrid[r][c].mine) continue;
        mineGrid[r][c].mine = true;
        placed++;
    }
    for (let r = 0; r < mineRows; r++) {
        for (let c = 0; c < mineCols; c++) {
            let cnt = 0;
            for (let dr = -1; dr <= 1; dr++) {
                for (let dc = -1; dc <= 1; dc++) {
                    const nr = r + dr, nc = c + dc;
                    if (nr >= 0 && nr < mineRows && nc >= 0 && nc < mineCols && mineGrid[nr][nc].mine) cnt++;
                }
            }
            mineGrid[r][c].count = cnt;
        }
    }
}

function mineClick(r, c, forceFlag = false) {
    if (mineOver) return;
    const cell = mineGrid[r][c];

    if (forceFlag || mineFlagMode) {
        if (!cell.open) cell.flag = !cell.flag;
        mineRender();
        return;
    }
    if (cell.flag || cell.open) return;

    if (!mineStarted) {
        mineStarted = true;
        minePlaceBombs(r, c);
    }

    if (cell.mine) {
        cell.open = true;
        mineOver  = true;
        for (let rr = 0; rr < mineRows; rr++) {
            for (let cc = 0; cc < mineCols; cc++) {
                if (mineGrid[rr][cc].mine) mineGrid[rr][cc].open = true;
            }
        }
        const msg = document.getElementById('mineStatusMsg');
        if (msg) msg.textContent = '💥 Boom! Du bist auf eine Mine getreten.';
        mineRender();
        return;
    }

    mineFloodOpen(r, c);
    mineRender();
    mineCheckWin();
}

function mineFloodOpen(r, c) {
    if (r < 0 || r >= mineRows || c < 0 || c >= mineCols) return;
    const cell = mineGrid[r][c];
    if (cell.open || cell.flag || cell.mine) return;
    cell.open = true;
    if (cell.count === 0) {
        for (let dr = -1; dr <= 1; dr++) {
            for (let dc = -1; dc <= 1; dc++) {
                if (dr !== 0 || dc !== 0) mineFloodOpen(r + dr, c + dc);
            }
        }
    }
}

function mineCheckWin() {
    let unopenedSafe = 0;
    for (let r = 0; r < mineRows; r++) {
        for (let c = 0; c < mineCols; c++) {
            if (!mineGrid[r][c].mine && !mineGrid[r][c].open) unopenedSafe++;
        }
    }
    if (unopenedSafe === 0) {
        mineOver = true;
        const pts = Math.max(150, mineTotal * 65 - mineSeconds * 3);
        const msg = document.getElementById('mineStatusMsg');
        if (msg) msg.textContent = `🎉 Alle Minen gefunden! +${pts} Punkte!`;
        submitCloudScore('minesweeper', pts, mineSeconds, `${mineCols}x${mineRows}`);
    }
}

function mineRender() {
    const board = document.getElementById('mineBoard');
    const remEl = document.getElementById('mineRemainingCount');
    if (!board) return;

    board.style.gridTemplateColumns = `repeat(${mineCols}, minmax(26px, 30px))`;
    let flags = 0;
    board.innerHTML = '';

    for (let r = 0; r < mineRows; r++) {
        for (let c = 0; c < mineCols; c++) {
            const cell = mineGrid[r][c];
            if (cell.flag) flags++;
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.onclick = () => mineClick(r, c, false);
            btn.oncontextmenu = (e) => { e.preventDefault(); mineClick(r, c, true); };

            if (cell.open) {
                btn.className = `h-7 sm:h-8 rounded-md text-xs font-extrabold flex items-center justify-center ${cell.mine ? 'bg-rose-600 text-white' : 'bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100'}`;
                btn.textContent = cell.mine ? '💣' : (cell.count > 0 ? String(cell.count) : '');
            } else {
                btn.className = 'h-7 sm:h-8 rounded-md bg-slate-300 dark:bg-slate-700 hover:bg-slate-400 text-xs font-bold flex items-center justify-center cursor-pointer';
                btn.textContent = cell.flag ? '🚩' : '';
            }
            board.appendChild(btn);
        }
    }
    if (remEl) remEl.textContent = String(Math.max(0, mineTotal - flags));
}

// =============================================================================
// SPIEL 4: WORT DES TAGES (CLOUD-WORDLE)
// =============================================================================
let wordleTarget  = WORDLE_DAILY_WORD;
let wordleIsDaily = true;
let wordleGuesses = [];
let wordleCurrent = '';
let wordleOver    = false;

function wordleStartDaily() {
    wordleTarget  = WORDLE_DAILY_WORD;
    wordleIsDaily = true;
    wordleGuesses = [];
    wordleCurrent = '';
    wordleOver    = false;
    const b = document.getElementById('wordleModeBadge');
    if (b) b.textContent = '📅 Tageswort';
    const msg = document.getElementById('wordleStatusMsg');
    if (msg) msg.textContent = '';
    wordleRender();
}

function wordleStartPractice() {
    wordleTarget  = WORDLE_POOL[Math.floor(Math.random() * WORDLE_POOL.length)] || 'CLOUD';
    wordleIsDaily = false;
    wordleGuesses = [];
    wordleCurrent = '';
    wordleOver    = false;
    const b = document.getElementById('wordleModeBadge');
    if (b) b.textContent = '🎲 Trainingswort';
    const msg = document.getElementById('wordleStatusMsg');
    if (msg) msg.textContent = '';
    wordleRender();
}

function wordlePressKey(k) {
    if (wordleOver) return;
    if (k === 'BACK') {
        wordleCurrent = wordleCurrent.slice(0, -1);
    } else if (k === 'ENTER') {
        if (wordleCurrent.length !== 5) return;
        wordleGuesses.push(wordleCurrent);
        const attempt = wordleCurrent;
        wordleCurrent = '';

        if (attempt === wordleTarget) {
            wordleOver = true;
            const pts = (7 - wordleGuesses.length) * 150 + (wordleIsDaily ? 100 : 0);
            const msg = document.getElementById('wordleStatusMsg');
            if (msg) msg.textContent = `🎉 Stark! „${wordleTarget}“ im ${wordleGuesses.length}. Versuch erraten (+${pts} Punkte)!`;
            submitCloudScore('wordle', pts, 0, wordleIsDaily ? 'Tageswort' : 'Training');
        } else if (wordleGuesses.length >= 6) {
            wordleOver = true;
            const msg = document.getElementById('wordleStatusMsg');
            if (msg) msg.textContent = `Das gesuchte Wort war: ${wordleTarget}`;
        }
    } else if (/^[A-Z]$/.test(k) && wordleCurrent.length < 5) {
        wordleCurrent += k;
    }
    wordleRender();
}

function wordleRender() {
    const grid = document.getElementById('wordleGrid');
    const kb   = document.getElementById('wordleKeyboard');
    if (!grid || !kb) return;

    const keyStates = {};
    grid.innerHTML = '';

    for (let r = 0; r < 6; r++) {
        const rowEl = document.createElement('div');
        rowEl.className = 'grid grid-cols-5 gap-1.5';
        const word = r < wordleGuesses.length ? wordleGuesses[r] : (r === wordleGuesses.length ? wordleCurrent : '');
        const submitted = r < wordleGuesses.length;

        for (let c = 0; c < 5; c++) {
            const ch = word[c] || '';
            let cls = 'bg-slate-100 dark:bg-slate-800 border-2 border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white';
            if (submitted) {
                if (ch === wordleTarget[c]) {
                    cls = 'bg-emerald-600 border-emerald-600 text-white';
                    keyStates[ch] = 'correct';
                } else if (wordleTarget.includes(ch)) {
                    cls = 'bg-amber-500 border-amber-500 text-white';
                    if (keyStates[ch] !== 'correct') keyStates[ch] = 'present';
                } else {
                    cls = 'bg-slate-600 border-slate-600 text-white';
                    if (!keyStates[ch]) keyStates[ch] = 'absent';
                }
            }
            const cell = document.createElement('div');
            cell.className = `h-12 rounded-xl font-extrabold text-lg flex items-center justify-center ${cls}`;
            cell.textContent = ch;
            rowEl.appendChild(cell);
        }
        grid.appendChild(rowEl);
    }

    const rows = [
        ['Q','W','E','R','T','Z','U','I','O','P'],
        ['A','S','D','F','G','H','J','K','L'],
        ['ENTER','Y','X','C','V','B','N','M','BACK']
    ];
    kb.innerHTML = '';
    rows.forEach((rKeys) => {
        const line = document.createElement('div');
        line.className = 'flex justify-center gap-1';
        rKeys.forEach((k) => {
            const st = keyStates[k];
            let kCls = 'bg-slate-200 dark:bg-slate-800 text-slate-900 dark:text-white';
            if (st === 'correct') kCls = 'bg-emerald-600 text-white';
            else if (st === 'present') kCls = 'bg-amber-500 text-white';
            else if (st === 'absent') kCls = 'bg-slate-600/60 text-white/70';

            const btn = document.createElement('button');
            btn.type = 'button';
            btn.onclick = () => wordlePressKey(k);
            btn.className = `px-2.5 py-2.5 rounded-lg text-xs font-extrabold cursor-pointer ${kCls}`;
            btn.textContent = k === 'BACK' ? '⌫' : (k === 'ENTER' ? '✓' : k);
            line.appendChild(btn);
        });
        kb.appendChild(line);
    });
}

// =============================================================================
// SPIEL 5: CLOUD-MEMORY
// =============================================================================
const MEMORY_ICONS = ['🚀','🌈','🎸','🦊','🍕','💎','🏝️','⚡','🦉','🍉','🎯','🛸'];
let memoryCards = [];
let memoryOpen  = [];
let memoryMovesCount = 0;
let memorySeconds = 0;

function memoryNewGame(size = 16) {
    size = Number(size) || 16;
    const pairs = size / 2;
    const chosen = MEMORY_ICONS.slice(0, pairs);
    const deck = [...chosen, ...chosen].sort(() => Math.random() - 0.5);
    memoryCards = deck.map((icon, idx) => ({ idx, icon, matched: false, flipped: false }));
    memoryOpen = [];
    memoryMovesCount = 0;
    memorySeconds = 0;
    memoryRender();
}

function memoryFlip(idx) {
    if (memoryOpen.length >= 2) return;
    const card = memoryCards[idx];
    if (!card || card.flipped || card.matched) return;

    card.flipped = true;
    memoryOpen.push(card);
    memoryRender();

    if (memoryOpen.length === 2) {
        memoryMovesCount++;
        const [a, b] = memoryOpen;
        if (a.icon === b.icon) {
            a.matched = true;
            b.matched = true;
            memoryOpen = [];
            memoryRender();
            if (memoryCards.every((c) => c.matched)) {
                const pts = Math.max(120, memoryCards.length * 80 - memoryMovesCount * 15 - memorySeconds * 2);
                submitCloudScore('memory', pts, memorySeconds, `${memoryCards.length} Karten`);
            }
        } else {
            setTimeout(() => {
                a.flipped = false;
                b.flipped = false;
                memoryOpen = [];
                memoryRender();
            }, 650);
        }
    }
}

function memoryRender() {
    const board = document.getElementById('memoryBoard');
    const mvEl  = document.getElementById('memoryMoves');
    if (mvEl) mvEl.textContent = String(memoryMovesCount);
    if (!board) return;

    board.className = memoryCards.length > 16
        ? 'grid grid-cols-6 gap-2 w-full max-w-[440px] select-none'
        : 'grid grid-cols-4 gap-2.5 w-full max-w-[360px] select-none';

    board.innerHTML = '';
    memoryCards.forEach((c) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.onclick = () => memoryFlip(c.idx);
        const show = c.flipped || c.matched;
        btn.className = `aspect-square rounded-2xl text-2xl flex items-center justify-center font-bold transition-all cursor-pointer ${
            c.matched ? 'bg-emerald-500 text-white' : (c.flipped ? 'bg-brand-600 text-white' : 'bg-slate-200 dark:bg-slate-800 hover:bg-slate-300 text-transparent')
        }`;
        btn.textContent = show ? c.icon : '❓';
        board.appendChild(btn);
    });
}

// =============================================================================
// SPIEL 6: BLOCK-PUZZLE (TETRIS-KLASSIKER)
// =============================================================================
const TETRIS_SHAPES = [
    { color: '#06b6d4', m: [[1,1,1,1]] },
    { color: '#eab308', m: [[1,1],[1,1]] },
    { color: '#a855f7', m: [[0,1,0],[1,1,1]] },
    { color: '#22c55e', m: [[0,1,1],[1,1,0]] },
    { color: '#ef4444', m: [[1,1,0],[0,1,1]] },
    { color: '#3b82f6', m: [[1,0,0],[1,1,1]] },
    { color: '#f97316', m: [[0,0,1],[1,1,1]] },
];
let tetrisBoard = [];
let tetrisCur = null, tetrisNext = null;
let tetrisScoreVal = 0, tetrisLinesVal = 0, tetrisTimer = null, tetrisRunning = false;

function tetrisStartGame() {
    if (tetrisTimer) clearInterval(tetrisTimer);
    tetrisBoard = Array.from({ length: 20 }, () => Array(10).fill(''));
    tetrisScoreVal = 0;
    tetrisLinesVal = 0;
    tetrisRunning  = true;
    tetrisNext     = tetrisRandomPiece();
    tetrisSpawn();
    tetrisScheduleLoop();
    tetrisDraw();
}

function tetrisRandomPiece() {
    const p = TETRIS_SHAPES[Math.floor(Math.random() * TETRIS_SHAPES.length)];
    return { color: p.color, m: p.m.map((r) => [...r]), x: 3, y: 0 };
}

function tetrisSpawn() {
    tetrisCur  = tetrisNext || tetrisRandomPiece();
    tetrisCur.x = Math.floor((10 - tetrisCur.m[0].length) / 2);
    tetrisCur.y = 0;
    tetrisNext = tetrisRandomPiece();
    tetrisDrawNext();

    if (tetrisCollides(tetrisCur.x, tetrisCur.y, tetrisCur.m)) {
        tetrisRunning = false;
        if (tetrisTimer) clearInterval(tetrisTimer);
        submitCloudScore('tetris', tetrisScoreVal, 0, `Level ${Math.floor(tetrisLinesVal / 10) + 1}`);
    }
}

function tetrisCollides(nx, ny, mat) {
    for (let r = 0; r < mat.length; r++) {
        for (let c = 0; c < mat[r].length; c++) {
            if (!mat[r][c]) continue;
            const x = nx + c, y = ny + r;
            if (x < 0 || x >= 10 || y >= 20) return true;
            if (y >= 0 && tetrisBoard[y][x] !== '') return true;
        }
    }
    return false;
}

function tetrisAction(act) {
    if (!tetrisRunning || !tetrisCur) return;
    if (act === 'left' && !tetrisCollides(tetrisCur.x - 1, tetrisCur.y, tetrisCur.m)) tetrisCur.x--;
    else if (act === 'right' && !tetrisCollides(tetrisCur.x + 1, tetrisCur.y, tetrisCur.m)) tetrisCur.x++;
    else if (act === 'down') tetrisStepDown();
    else if (act === 'drop') {
        while (!tetrisCollides(tetrisCur.x, tetrisCur.y + 1, tetrisCur.m)) {
            tetrisCur.y++;
            tetrisScoreVal += 2;
        }
        tetrisStepDown();
    } else if (act === 'rotate') {
        const rot = tetrisCur.m[0].map((_, idx) => tetrisCur.m.map((row) => row[idx]).reverse());
        if (!tetrisCollides(tetrisCur.x, tetrisCur.y, rot)) tetrisCur.m = rot;
    }
    tetrisDraw();
}

function tetrisStepDown() {
    if (!tetrisRunning || !tetrisCur) return;
    if (!tetrisCollides(tetrisCur.x, tetrisCur.y + 1, tetrisCur.m)) {
        tetrisCur.y++;
    } else {
        for (let r = 0; r < tetrisCur.m.length; r++) {
            for (let c = 0; c < tetrisCur.m[r].length; c++) {
                if (tetrisCur.m[r][c] && tetrisCur.y + r >= 0) {
                    tetrisBoard[tetrisCur.y + r][tetrisCur.x + c] = tetrisCur.color;
                }
            }
        }
        let cleared = 0;
        for (let r = 19; r >= 0; r--) {
            if (tetrisBoard[r].every((v) => v !== '')) {
                tetrisBoard.splice(r, 1);
                tetrisBoard.unshift(Array(10).fill(''));
                cleared++;
                r++;
            }
        }
        if (cleared > 0) {
            tetrisLinesVal += cleared;
            tetrisScoreVal += [0, 100, 300, 500, 800][cleared] || 200;
            tetrisScheduleLoop();
        }
        tetrisSpawn();
    }
    tetrisDraw();
}

function tetrisScheduleLoop() {
    if (tetrisTimer) clearInterval(tetrisTimer);
    const lvl = Math.floor(tetrisLinesVal / 10) + 1;
    const speed = Math.max(140, 620 - (lvl - 1) * 55);
    tetrisTimer = setInterval(tetrisStepDown, speed);
}

function tetrisDraw() {
    const cv = document.getElementById('tetrisCanvas');
    if (!cv) return;
    const ctx = cv.getContext('2d');
    const sz  = 22;
    ctx.fillStyle = '#090d16';
    ctx.fillRect(0, 0, cv.width, cv.height);

    for (let r = 0; r < 20; r++) {
        for (let c = 0; c < 10; c++) {
            ctx.strokeStyle = 'rgba(148,163,184,0.08)';
            ctx.strokeRect(c * 24, r * 22, 24, 22);
            if (tetrisBoard[r][c]) {
                ctx.fillStyle = tetrisBoard[r][c];
                ctx.fillRect(c * 24 + 1, r * 22 + 1, 22, 20);
            }
        }
    }

    if (tetrisCur) {
        ctx.fillStyle = tetrisCur.color;
        for (let r = 0; r < tetrisCur.m.length; r++) {
            for (let c = 0; c < tetrisCur.m[r].length; c++) {
                if (tetrisCur.m[r][c]) {
                    ctx.fillRect((tetrisCur.x + c) * 24 + 1, (tetrisCur.y + r) * 22 + 1, sz, 20);
                }
            }
        }
    }

    document.getElementById('tetrisScore').textContent = String(tetrisScoreVal);
    document.getElementById('tetrisLines').textContent = String(tetrisLinesVal);
    document.getElementById('tetrisLevel').textContent = String(Math.floor(tetrisLinesVal / 10) + 1);
}

function tetrisDrawNext() {
    const cv = document.getElementById('tetrisNextCanvas');
    if (!cv || !tetrisNext) return;
    const ctx = cv.getContext('2d');
    ctx.fillStyle = '#0f172a';
    ctx.fillRect(0, 0, cv.width, cv.height);
    ctx.fillStyle = tetrisNext.color;
    for (let r = 0; r < tetrisNext.m.length; r++) {
        for (let c = 0; c < tetrisNext.m[r].length; c++) {
            if (tetrisNext.m[r][c]) {
                ctx.fillRect(18 + c * 18, 16 + r * 18, 16, 16);
            }
        }
    }
}

// =============================================================================
// SPIEL 7: RETRO-SNAKE
// =============================================================================
let snakeBody = [], snakeDir = [1, 0], snakeFood = [10, 10], snakeScoreVal = 0, snakeTimer = null, snakeRunning = false;

function snakeStartGame() {
    if (snakeTimer) clearInterval(snakeTimer);
    snakeBody     = [[8, 9], [7, 9], [6, 9]];
    snakeDir      = [1, 0];
    snakeScoreVal = 0;
    snakeRunning  = true;
    snakeSpawnFood();
    const speed = Number(document.getElementById('snakeSpeedSelect')?.value || 95);
    snakeTimer = setInterval(snakeTick, speed);
    snakeDraw();
}

function snakeSetDir(dx, dy) {
    if (dx === -snakeDir[0] && dy === -snakeDir[1]) return;
    snakeDir = [dx, dy];
}

function snakeSpawnFood() {
    while (true) {
        const fx = Math.floor(Math.random() * 18);
        const fy = Math.floor(Math.random() * 18);
        if (!snakeBody.some(([x, y]) => x === fx && y === fy)) {
            snakeFood = [fx, fy];
            break;
        }
    }
}

function snakeTick() {
    if (!snakeRunning) return;
    const head = [snakeBody[0][0] + snakeDir[0], snakeBody[0][1] + snakeDir[1]];

    if (head[0] < 0 || head[0] >= 18 || head[1] < 0 || head[1] >= 18 || snakeBody.some(([x, y]) => x === head[0] && y === head[1])) {
        snakeRunning = false;
        clearInterval(snakeTimer);
        submitCloudScore('snake', snakeScoreVal, 0, 'Snake');
        snakeDraw(true);
        return;
    }

    snakeBody.unshift(head);
    if (head[0] === snakeFood[0] && head[1] === snakeFood[1]) {
        snakeScoreVal += 25;
        snakeSpawnFood();
    } else {
        snakeBody.pop();
    }
    snakeDraw();
}

function snakeDraw(gameOver = false) {
    const cv = document.getElementById('snakeCanvas');
    if (!cv) return;
    const ctx = cv.getContext('2d');
    ctx.fillStyle = '#090d16';
    ctx.fillRect(0, 0, cv.width, cv.height);

    // Frucht
    ctx.fillStyle = '#ef4444';
    ctx.beginPath();
    ctx.arc(snakeFood[0] * 20 + 10, snakeFood[1] * 20 + 10, 8, 0, Math.PI * 2);
    ctx.fill();

    // Schlange
    snakeBody.forEach(([x, y], idx) => {
        ctx.fillStyle = idx === 0 ? '#22c55e' : '#16a34a';
        ctx.fillRect(x * 20 + 2, y * 20 + 2, 16, 16);
    });

    if (gameOver) {
        ctx.fillStyle = 'rgba(0,0,0,0.65)';
        ctx.fillRect(0, 0, cv.width, cv.height);
        ctx.fillStyle = '#ffffff';
        ctx.font = 'bold 20px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('Spiel vorbei! (' + snakeScoreVal + ' Pkt.)', cv.width / 2, cv.height / 2);
    }

    document.getElementById('snakeScore').textContent = String(snakeScoreVal);
}

// =============================================================================
// SPIEL 8: BRICK BREAKER (BREAKOUT)
// =============================================================================
let boPaddleX = 200, boBall = { x: 240, y: 300, vx: 3.5, vy: -3.5 }, boBricks = [], boScoreVal = 0, boLivesVal = 3, boAnim = null, boRunning = false;

function breakoutStartGame() {
    if (boAnim) cancelAnimationFrame(boAnim);
    boScoreVal = 0;
    boLivesVal = 3;
    boRunning  = true;
    boInitBricks();
    boResetBall();
    boLoop();
}

function boInitBricks() {
    const colors = ['#ef4444', '#f97316', '#eab308', '#22c55e', '#3b82f6'];
    boBricks = [];
    for (let r = 0; r < 5; r++) {
        for (let c = 0; c < 8; c++) {
            boBricks.push({ x: 16 + c * 56, y: 36 + r * 24, w: 50, h: 16, color: colors[r], alive: true });
        }
    }
}

function boResetBall() {
    boPaddleX = 195;
    boBall = { x: 240, y: 310, vx: (Math.random() > 0.5 ? 3.6 : -3.6), vy: -3.8 };
}

function boLoop() {
    if (!boRunning) return;
    const cv = document.getElementById('breakoutCanvas');
    if (!cv) return;
    const ctx = cv.getContext('2d');

    boBall.x += boBall.vx;
    boBall.y += boBall.vy;

    if (boBall.x < 8 || boBall.x > cv.width - 8) boBall.vx *= -1;
    if (boBall.y < 8) boBall.vy *= -1;

    // Paddel-Kollision
    if (boBall.y >= 332 && boBall.y <= 344 && boBall.x >= boPaddleX && boBall.x <= boPaddleX + 90) {
        const hitPos = (boBall.x - (boPaddleX + 45)) / 45;
        boBall.vx = hitPos * 4.8;
        boBall.vy = -Math.abs(boBall.vy);
    }

    // Stein-Kollision
    boBricks.forEach((b) => {
        if (!b.alive) return;
        if (boBall.x >= b.x && boBall.x <= b.x + b.w && boBall.y >= b.y && boBall.y <= b.y + b.h) {
            b.alive = false;
            boBall.vy *= -1;
            boScoreVal += 30;
        }
    });

    if (boBricks.every((b) => !b.alive)) {
        boInitBricks();
        boResetBall();
        boScoreVal += 250;
    }

    if (boBall.y > cv.height) {
        boLivesVal--;
        if (boLivesVal <= 0) {
            boRunning = false;
            submitCloudScore('breakout', boScoreVal, 0, 'Breakout');
        } else {
            boResetBall();
        }
    }

    ctx.fillStyle = '#090d16';
    ctx.fillRect(0, 0, cv.width, cv.height);

    boBricks.forEach((b) => {
        if (!b.alive) return;
        ctx.fillStyle = b.color;
        ctx.fillRect(b.x, b.y, b.w, b.h);
    });

    ctx.fillStyle = '#38bdf8';
    ctx.fillRect(boPaddleX, 336, 90, 10);

    ctx.fillStyle = '#ffffff';
    ctx.beginPath();
    ctx.arc(boBall.x, boBall.y, 7, 0, Math.PI * 2);
    ctx.fill();

    document.getElementById('breakoutScore').textContent = String(boScoreVal);
    document.getElementById('breakoutLives').textContent = String(boLivesVal);

    if (boRunning) {
        boAnim = requestAnimationFrame(boLoop);
    }
}

(() => {
    const cv = document.getElementById('breakoutCanvas');
    if (!cv) return;
    const movePaddle = (clientX) => {
        const rect = cv.getBoundingClientRect();
        const relX = ((clientX - rect.left) / rect.width) * cv.width;
        boPaddleX = Math.max(0, Math.min(cv.width - 90, relX - 45));
    };
    cv.addEventListener('mousemove', (e) => movePaddle(e.clientX));
    cv.addEventListener('touchmove', (e) => movePaddle(e.touches[0].clientX), { passive: true });
})();

// =============================================================================
// SPIEL 9: VIER GEWINNT & TIC-TAC-TOE (KI & CLOUD-MEHRSPIELER)
// =============================================================================
let c4Mode = 'ai';
let c4AiBoard = [];
let c4AiOver  = false;
let cloudMatchesCache = [];
let activeCloudMatchId = 0;

function switchC4Mode(mode) {
    c4Mode = mode;
    document.getElementById('c4ModeAiBox')?.classList.toggle('hidden', mode !== 'ai');
    document.getElementById('c4ModeCloudBox')?.classList.toggle('hidden', mode !== 'cloud');
    document.getElementById('c4SubTabAi').className = mode === 'ai'
        ? 'px-3 py-1.5 rounded-xl bg-brand-600 text-white text-xs font-bold cursor-pointer'
        : 'px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 text-xs font-bold cursor-pointer';
    document.getElementById('c4SubTabCloud').className = mode === 'cloud'
        ? 'px-3 py-1.5 rounded-xl bg-brand-600 text-white text-xs font-bold cursor-pointer'
        : 'px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 text-xs font-bold cursor-pointer';
    if (mode === 'cloud') pollCloudMatches();
}

function c4AiNewGame() {
    const gType = document.getElementById('c4AiGameType')?.value || 'connect4';
    c4AiBoard = gType === 'tictactoe'
        ? Array.from({ length: 3 }, () => [0, 0, 0])
        : Array.from({ length: 6 }, () => [0, 0, 0, 0, 0, 0, 0]);
    c4AiOver = false;
    document.getElementById('c4AiStatusText').textContent = 'Du bist am Zug!';
    c4AiRender();
}

function c4AiPlay(r, c) {
    if (c4AiOver) return;
    const gType = document.getElementById('c4AiGameType')?.value || 'connect4';

    if (gType === 'tictactoe') {
        if (c4AiBoard[r][c] !== 0) return;
        c4AiBoard[r][c] = 1;
    } else {
        let placed = -1;
        for (let rr = 5; rr >= 0; rr--) {
            if (c4AiBoard[rr][c] === 0) {
                c4AiBoard[rr][c] = 1;
                placed = rr;
                break;
            }
        }
        if (placed < 0) return;
    }

    c4AiRender();
    if (c4CheckLocalWinner(gType, c4AiBoard) === 1) {
        c4AiOver = true;
        document.getElementById('c4AiStatusText').textContent = '🎉 Du hast gegen die Computer-KI gewonnen! (+400 Pkt.)';
        submitCloudScore('connect4', 400, 0, 'Sieg gegen KI');
        return;
    }

    // Computer-Zug
    setTimeout(() => {
        if (c4AiOver) return;
        if (gType === 'tictactoe') {
            const empty = [];
            for (let rr = 0; rr < 3; rr++) for (let cc = 0; cc < 3; cc++) if (c4AiBoard[rr][cc] === 0) empty.push([rr, cc]);
            if (!empty.length) { c4AiOver = true; document.getElementById('c4AiStatusText').textContent = 'Unentschieden!'; return; }
            const [mr, mc] = empty[Math.floor(Math.random() * empty.length)];
            c4AiBoard[mr][mc] = 2;
        } else {
            const validCols = [0,1,2,3,4,5,6].filter((col) => c4AiBoard[0][col] === 0);
            if (!validCols.length) { c4AiOver = true; document.getElementById('c4AiStatusText').textContent = 'Unentschieden!'; return; }
            // Prüfe ob KI sofort gewinnen oder Spieler blocken kann
            let chosenCol = validCols[Math.floor(Math.random() * validCols.length)];
            for (const targetPlayer of [2, 1]) {
                for (const col of validCols) {
                    let rr = 5;
                    while (rr >= 0 && c4AiBoard[rr][col] !== 0) rr--;
                    c4AiBoard[rr][col] = targetPlayer;
                    const win = c4CheckLocalWinner('connect4', c4AiBoard) === targetPlayer;
                    c4AiBoard[rr][col] = 0;
                    if (win) { chosenCol = col; break; }
                }
            }
            for (let rr = 5; rr >= 0; rr--) {
                if (c4AiBoard[rr][chosenCol] === 0) { c4AiBoard[rr][chosenCol] = 2; break; }
            }
        }
        c4AiRender();
        if (c4CheckLocalWinner(gType, c4AiBoard) === 2) {
            c4AiOver = true;
            document.getElementById('c4AiStatusText').textContent = '🤖 Die Computer-KI hat diese Runde gewonnen.';
        }
    }, 260);
}

function c4CheckLocalWinner(gType, b) {
    if (gType === 'tictactoe') {
        const lines = [[[0,0],[0,1],[0,2]],[[1,0],[1,1],[1,2]],[[2,0],[2,1],[2,2]],[[0,0],[1,0],[2,0]],[[0,1],[1,1],[2,1]],[[0,2],[1,2],[2,2]],[[0,0],[1,1],[2,2]],[[0,2],[1,1],[2,0]]];
        for (const l of lines) {
            const v = b[l[0][0]][l[0][1]];
            if (v !== 0 && v === b[l[1][0]][l[1][1]] && v === b[l[2][0]][l[2][1]]) return v;
        }
        return 0;
    }
    const dirs = [[0,1],[1,0],[1,1],[1,-1]];
    for (let r = 0; r < 6; r++) {
        for (let c = 0; c < 7; c++) {
            const p = b[r][c];
            if (!p) continue;
            for (const [dr, dc] of dirs) {
                let ok = true;
                for (let k = 1; k < 4; k++) {
                    const nr = r + dr * k, nc = c + dc * k;
                    if (nr < 0 || nr >= 6 || nc < 0 || nc >= 7 || b[nr][nc] !== p) { ok = false; break; }
                }
                if (ok) return p;
            }
        }
    }
    return 0;
}

function c4AiRender() {
    const box = document.getElementById('c4AiBoardContainer');
    if (!box) return;
    const gType = document.getElementById('c4AiGameType')?.value || 'connect4';
    box.innerHTML = renderBoardHtml(gType, c4AiBoard, 'c4AiPlay');
}

function renderBoardHtml(gType, board, clickFnName) {
    if (gType === 'tictactoe') {
        let html = '<div class="grid grid-cols-3 gap-2.5 p-3 rounded-2xl bg-slate-200 dark:bg-slate-800">';
        for (let r = 0; r < 3; r++) {
            for (let c = 0; c < 3; c++) {
                const v = board[r]?.[c] || 0;
                const sym = v === 1 ? '❌' : (v === 2 ? '⭕' : '');
                html += `<button type="button" onclick="${clickFnName}(${r},${c})" class="w-20 h-20 rounded-xl bg-white dark:bg-slate-900 text-3xl flex items-center justify-center font-extrabold shadow-xs cursor-pointer">${sym}</button>`;
            }
        }
        return html + '</div>';
    }

    let html = '<div class="grid grid-cols-7 gap-2 p-3 rounded-2xl bg-blue-700 shadow-inner">';
    for (let r = 0; r < 6; r++) {
        for (let c = 0; c < 7; c++) {
            const v = board[r]?.[c] || 0;
            const colCls = v === 1 ? 'bg-red-500' : (v === 2 ? 'bg-amber-400' : 'bg-slate-900/80 hover:bg-slate-800');
            html += `<button type="button" onclick="${clickFnName}(${r},${c})" class="w-10 h-10 sm:w-12 sm:h-12 rounded-full ${colCls} shadow-inner cursor-pointer transition-transform hover:scale-105"></button>`;
        }
    }
    return html + '</div>';
}

// Cloud-Mehrspieler-Funktionen
async function createCloudMatch(ev) {
    ev.preventDefault();
    const gType = document.getElementById('cloudMatchType')?.value || 'connect4';
    const oppId = document.getElementById('cloudMatchOpponent')?.value || 0;
    const fd = new FormData();
    fd.append('ajax', '1');
    fd.append('csrf_token', GAMES_CSRF);
    fd.append('action', 'create_match');
    fd.append('game_type', gType);
    fd.append('opponent_id', String(oppId));

    const r = await fetch('games.php', { method: 'POST', body: fd });
    const d = await r.json();
    if (d && d.ok) {
        activeCloudMatchId = d.match_id;
        await pollCloudMatches();
    } else if (d?.error) {
        alert(d.error);
    }
}

async function pollCloudMatches() {
    const fd = new FormData();
    fd.append('ajax', '1');
    fd.append('csrf_token', GAMES_CSRF);
    fd.append('action', 'poll_matches');

    try {
        const r = await fetch('games.php', { method: 'POST', body: fd });
        const d = await r.json();
        if (d && d.ok && Array.isArray(d.matches)) {
            cloudMatchesCache = d.matches;
            if (!activeCloudMatchId && cloudMatchesCache.length > 0) {
                activeCloudMatchId = cloudMatchesCache[0].id;
            }
            renderCloudMatchesUI();
        }
    } catch (e) {}
}

function renderCloudMatchesUI() {
    const listEl  = document.getElementById('cloudMatchesList');
    const titleEl = document.getElementById('cloudActiveMatchTitle');
    const boardEl = document.getElementById('cloudActiveMatchBoard');
    if (!listEl || !titleEl || !boardEl) return;

    if (cloudMatchesCache.length === 0) {
        listEl.innerHTML = '<p class="text-slate-400 italic py-2">Noch keine Cloud-Duelle gestartet.</p>';
        boardEl.innerHTML = '';
        return;
    }

    listEl.innerHTML = cloudMatchesCache.map((m) => {
        const isActive = m.id === activeCloudMatchId;
        const myTurn = m.status === 'active' && m.current_turn_id === CURRENT_USER_ID;
        const badge = m.status === 'active'
            ? (myTurn ? '<span class="px-1.5 py-0.5 rounded bg-emerald-500 text-white text-[10px] font-bold">Du bist dran</span>' : '<span class="px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-700 text-[10px]">Wartet</span>')
            : '<span class="px-1.5 py-0.5 rounded bg-amber-100 dark:bg-amber-950 text-amber-700 text-[10px]">Beendet</span>';
        return `
            <button type="button" onclick="activeCloudMatchId=${m.id};renderCloudMatchesUI();"
                    class="w-full text-left p-2.5 rounded-xl border flex items-center justify-between gap-2 cursor-pointer ${isActive ? 'bg-brand-50 dark:bg-brand-950/40 border-brand-400' : 'bg-white dark:bg-slate-900 border-slate-200 dark:border-slate-700'}">
                <div class="min-w-0">
                    <p class="font-bold truncate">${m.game_type === 'tictactoe' ? '❌⭕' : '🔴🟡'} ${m.p1_name} vs. ${m.p2_name}</p>
                    <p class="text-[10px] text-slate-400">${m.updated_at}</p>
                </div>
                ${badge}
            </button>
        `;
    }).join('');

    const cur = cloudMatchesCache.find((m) => m.id === activeCloudMatchId) || cloudMatchesCache[0];
    if (!cur) return;

    let statusTxt = '';
    if (cur.status === 'active') {
        statusTxt = cur.current_turn_id === CURRENT_USER_ID ? '🟢 Du bist am Zug!' : '⏳ Dein Mitspieler ist am Zug…';
    } else if (cur.status === 'draw') {
        statusTxt = '🤝 Unentschieden!';
    } else {
        statusTxt = cur.winner_id === CURRENT_USER_ID ? '🏆 Du hast dieses Duell gewonnen!' : 'Partie beendet.';
    }

    titleEl.textContent = `${cur.p1_name} (🔴/❌) vs. ${cur.p2_name} (🟡/⭕) — ${statusTxt}`;
    boardEl.innerHTML   = renderBoardHtml(cur.game_type, cur.board, 'playCloudMatchMove');
}

async function playCloudMatchMove(r, c) {
    if (!activeCloudMatchId) return;
    const fd = new FormData();
    fd.append('ajax', '1');
    fd.append('csrf_token', GAMES_CSRF);
    fd.append('action', 'play_match_move');
    fd.append('match_id', String(activeCloudMatchId));
    fd.append('row', String(r));
    fd.append('col', String(c));

    const res = await fetch('games.php', { method: 'POST', body: fd });
    const d   = await res.json();
    if (d && d.ok) {
        await pollCloudMatches();
    } else if (d?.error) {
        alert(d.error);
    }
}

// =============================================================================
// GLOBALE TASTATUR-STEUERUNG & SEKUNDEN-TIMER
// =============================================================================
window.addEventListener('keydown', (e) => {
    if (['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName || '')) return;

    if (activeGameKey === '2048') {
        const map = { ArrowLeft: 'left', a: 'left', ArrowRight: 'right', d: 'right', ArrowUp: 'up', w: 'up', ArrowDown: 'down', s: 'down' };
        if (map[e.key]) { e.preventDefault(); g2048Move(map[e.key]); }
    } else if (activeGameKey === 'sudoku') {
        if (/^[1-9]$/.test(e.key)) sudokuInputNumber(Number(e.key));
        else if (e.key === 'Backspace' || e.key === 'Delete' || e.key === '0') sudokuInputNumber(0);
    } else if (activeGameKey === 'wordle') {
        if (e.key === 'Backspace') wordlePressKey('BACK');
        else if (e.key === 'Enter') wordlePressKey('ENTER');
        else if (/^[a-zA-Z]$/.test(e.key)) wordlePressKey(e.key.toUpperCase());
    } else if (activeGameKey === 'tetris' && tetrisRunning) {
        if (e.key === 'ArrowLeft')  { e.preventDefault(); tetrisAction('left'); }
        if (e.key === 'ArrowRight') { e.preventDefault(); tetrisAction('right'); }
        if (e.key === 'ArrowDown')  { e.preventDefault(); tetrisAction('down'); }
        if (e.key === 'ArrowUp')    { e.preventDefault(); tetrisAction('rotate'); }
        if (e.key === ' ')          { e.preventDefault(); tetrisAction('drop'); }
    } else if (activeGameKey === 'snake' && snakeRunning) {
        if (e.key === 'ArrowUp'    || e.key === 'w') { e.preventDefault(); snakeSetDir(0, -1); }
        if (e.key === 'ArrowDown'  || e.key === 's') { e.preventDefault(); snakeSetDir(0, 1); }
        if (e.key === 'ArrowLeft'  || e.key === 'a') { e.preventDefault(); snakeSetDir(-1, 0); }
        if (e.key === 'ArrowRight' || e.key === 'd') { e.preventDefault(); snakeSetDir(1, 0); }
    } else if (activeGameKey === 'breakout' && boRunning) {
        if (e.key === 'ArrowLeft')  boPaddleX = Math.max(0, boPaddleX - 24);
        if (e.key === 'ArrowRight') boPaddleX = Math.min(390, boPaddleX + 24);
    }
});

// 1-Sekunden-Intervall für Stoppuhren (Sudoku, Minesweeper, Memory) & Cloud-Duell-Polling
setInterval(() => {
    const fmt = (s) => String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0');
    if (activeGameKey === 'sudoku') {
        sudokuSeconds++;
        const el = document.getElementById('sudokuTimer');
        if (el) el.textContent = fmt(sudokuSeconds);
    } else if (activeGameKey === 'minesweeper' && mineStarted && !mineOver) {
        mineSeconds++;
        const el = document.getElementById('mineTimer');
        if (el) el.textContent = fmt(mineSeconds);
    } else if (activeGameKey === 'memory' && memoryMovesCount > 0 && !memoryCards.every((c) => c.matched)) {
        memorySeconds++;
        const el = document.getElementById('memoryTimer');
        if (el) el.textContent = fmt(memorySeconds);
    }
}, 1000);

setInterval(() => {
    if (activeGameKey === 'connect4' && c4Mode === 'cloud') {
        pollCloudMatches();
    }
}, 4000);

document.addEventListener('DOMContentLoaded', () => {
    renderLeaderboardRows(<?= json_encode($initialLeaderboard) ?>);
    g2048Init();
    sudokuInit();
    mineNewGame('medium');
    wordleStartDaily();
    memoryNewGame(16);
    tetrisDraw();
    snakeDraw();
    c4AiNewGame();
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
