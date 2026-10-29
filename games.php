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
    if ($done || (!empty($_SESSION['_mc_games_schema_ok']) && $_SESSION['_mc_games_schema_ok'] === 1)) {
        $done = true;
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
                `board_json` MEDIUMTEXT NOT NULL,
                `status` VARCHAR(20) NOT NULL DEFAULT 'active',
                `winner_id` INT UNSIGNED NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_players_status` (`player1_id`, `player2_id`, `status`),
                KEY `idx_updated` (`updated_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        try {
            $pdo->exec("ALTER TABLE `game_matches` MODIFY `board_json` MEDIUMTEXT NOT NULL");
        } catch (Throwable) {
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['_mc_games_schema_ok'] = 1;
        }
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
        'gummigeruch' => [
            'key'      => 'gummigeruch',
            'title'    => 'Gummigeruch Kart',
            'icon'     => '🏎️',
            'category' => 'Rennspiel',
            'desc'     => 'Kult-Kart-Rennen mit Drift-Physik, Bremsspuren, 6 Strecken, Ghost-Duellen & Live-Herausforderungen!',
            'color'    => 'from-rose-600 to-amber-500',
        ],
        'pacman' => [
            'key'      => 'pacman',
            'title'    => 'Cloud-PacMan',
            'icon'     => '🟡',
            'category' => 'Arcade',
            'desc'     => 'Der Neon-Labyrinth-Klassiker mit 4 schlauen Geistern (Blinky, Pinky, Inky, Clyde), Power-Pillen & Bonusfrüchten!',
            'color'    => 'from-amber-400 to-yellow-600',
        ],
        'blitz' => [
            'key'      => 'blitz',
            'title'    => 'VC-20 Blitz',
            'icon'     => '✈️',
            'category' => 'Retro 1981',
            'desc'     => 'Der Commodore VC-20 Kult-Klassiker: Trage im Sinkflug alle Wolkenkratzer per Bombe ab, um sicher zu landen!',
            'color'    => 'from-cyan-500 to-blue-700',
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
        'wow' => [
            'key'      => 'wow',
            'title'    => 'World of MiniCloud',
            'icon'     => '⚔️',
            'category' => 'MMO-RPG',
            'desc'     => 'Fantasy-Rollenspiel im WoW-Stil: 3 Klassen, Quests, 4 Zonen, Raid-Bosse, Epischer Loot & Live-Mitspieler!',
            'color'    => 'from-amber-600 to-red-700',
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
        $gameKey = 'gummigeruch';
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

        if (!isset($catalog[$gameKey]) || strlen($stateJson) > 262144) {
            echo json_encode(['ok' => false]);
            exit;
        }

        if ($stateJson === '' || $stateJson === 'null') {
            $stmtDel = $pdo->prepare("DELETE FROM `game_saves` WHERE `user_id` = :uid AND `game_key` = :gk");
            $stmtDel->execute([':uid' => $userId, ':gk' => $gameKey]);
            echo json_encode(['ok' => true]);
            exit;
        }

        if (json_decode($stateJson, true) === null) {
            echo json_encode(['ok' => false]);
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

        $stmtOpp = $pdo->prepare('SELECT id FROM users WHERE id = :id AND allow_user_sharing = 1 AND is_locked = 0 LIMIT 1');
        $stmtOpp->execute([':id' => $opponentId]);
        if (!$stmtOpp->fetchColumn()) {
            echo json_encode(['ok' => false, 'error' => 'Dieser Benutzer ist für Einladungen nicht freigegeben oder gesperrt.']);
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

    // 6. Aktuelle Cloud-Mehrspieler-Duelle (Vier Gewinnt / Tic-Tac-Toe) des Benutzers abrufen
    if ($action === 'poll_matches') {
        $stmt = $pdo->prepare(
            "SELECT m.*, u1.username AS p1_name, u2.username AS p2_name
             FROM `game_matches` m
             INNER JOIN `users` u1 ON u1.id = m.player1_id
             INNER JOIN `users` u2 ON u2.id = m.player2_id
             WHERE (m.player1_id = :u1 OR m.player2_id = :u2)
               AND m.game_type IN ('connect4', 'tictactoe')
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

    // 7. Live-Helden anderer Cloud-Benutzer in "World of MiniCloud" abrufen
    if ($action === 'wow_sync') {
        session_write_close();
        $peers = [];
        try {
            $stmtPeers = $pdo->prepare(
                "SELECT s.user_id, u.username, s.state_json, s.updated_at
                 FROM `game_saves` s
                 INNER JOIN `users` u ON u.id = s.user_id
                 WHERE s.game_key = 'wow'
                   AND s.user_id != :uid
                   AND s.updated_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
                 ORDER BY s.updated_at DESC
                 LIMIT 12"
            );
            $stmtPeers->execute([':uid' => $userId]);
            foreach ($stmtPeers->fetchAll() as $pRow) {
                $st = json_decode((string)$pRow['state_json'], true);
                if (!is_array($st)) {
                    continue;
                }
                $peers[] = [
                    'username'   => (string)$pRow['username'],
                    'hero_class' => (string)($st['heroClass'] ?? 'paladin'),
                    'level'      => max(1, (int)($st['level'] ?? 1)),
                    'zone'       => (string)($st['zone'] ?? 'elwynn'),
                    'x'          => (float)($st['x'] ?? 320),
                    'y'          => (float)($st['y'] ?? 220),
                    'hp'         => max(1, (int)($st['hp'] ?? 100)),
                    'max_hp'     => max(1, (int)($st['maxHp'] ?? 100)),
                    'gear_score' => max(0, (int)($st['gearScore'] ?? 10)),
                ];
            }
        } catch (Throwable) {
        }
        echo json_encode(['ok' => true, 'peers' => $peers], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 8. Gummigeruch Kart: 3-Runden-Rennen speichern, Ghost sichern & Herausforderungen auswerten
    if ($action === 'gummi_save_run') {
        session_write_close();
        $allowedTracks = ['circuit', 'coast', 'night', 'alps', 'desert', 'volcano'];
        $trackId    = in_array($_POST['track_id'] ?? '', $allowedTracks, true) ? (string)$_POST['track_id'] : 'circuit';
        $timeMs     = max(4000, min(600000, (int)($_POST['time_ms'] ?? 0)));
        $bestLapMs  = max(1200, min(300000, (int)($_POST['best_lap_ms'] ?? 0)));
        $ghostRaw   = (string)($_POST['ghost_json'] ?? '[]');
        $opponentId = (int)($_POST['opponent_id'] ?? 0);
        $matchId    = (int)($_POST['match_id'] ?? 0);

        $ghostFrames = json_decode($ghostRaw, true);
        if (!is_array($ghostFrames)) {
            $ghostFrames = [];
        }
        $ghostFrames = array_slice($ghostFrames, 0, 900);

        // Punkteberechnung: Je schneller die 3 Runden (+ Schwierigkeitsbonus für Profi-Strecken), desto höher der Score!
        $trackBonuses = [
            'circuit' => 0,
            'coast'   => 2000,
            'night'   => 3500,
            'alps'    => 9000,
            'desert'  => 11000,
            'volcano' => 15000,
        ];
        $basePool    = 120000 + ($trackBonuses[$trackId] ?? 0);
        $score       = max(500, $basePool - (int)round($timeMs * 1.25));
        $durationSec = max(1, (int)round($timeMs / 1000));
        $trackNames  = [
            'circuit' => 'GummiCircuit',
            'coast'   => 'Küsten-GP',
            'night'   => 'Nacht-Ring',
            'alps'    => 'Alpen-Serpentinen',
            'desert'  => 'Wüsten-Schikane',
            'volcano' => 'Vulkan-Höllenring',
        ];
        $tName       = $trackNames[$trackId] ?? 'GummiCircuit';
        $fmtTime     = sprintf('%02d:%05.2f', (int)floor($timeMs / 60000), ($timeMs % 60000) / 1000);

        recordModuleUsage($pdo, 'games', true);

        $stmtSc = $pdo->prepare(
            "INSERT INTO `game_scores` (`user_id`, `game_key`, `score`, `duration_sec`, `meta_label`)
             VALUES (:uid, 'gummigeruch', :sc, :dur, :meta)"
        );
        $stmtSc->execute([
            ':uid'  => $userId,
            ':sc'   => $score,
            ':dur'  => $durationSec,
            ':meta' => $tName . ' (' . $fmtTime . ')',
        ]);

        // Eigenen besten Ghost für diese Strecke in game_saves aktualisieren, falls schneller
        $saveKey = 'gummi_' . $trackId;
        $stmtOld = $pdo->prepare("SELECT `state_json` FROM `game_saves` WHERE `user_id` = :uid AND `game_key` = :gk LIMIT 1");
        $stmtOld->execute([':uid' => $userId, ':gk' => $saveKey]);
        $oldJson = $stmtOld->fetchColumn();
        $oldData = is_string($oldJson) ? json_decode($oldJson, true) : null;
        $oldBest = is_array($oldData) && isset($oldData['time_ms']) ? (int)$oldData['time_ms'] : 99999999;

        if ($timeMs < $oldBest && !empty($ghostFrames)) {
            $newGhostPayload = json_encode([
                'track_id'    => $trackId,
                'time_ms'     => $timeMs,
                'best_lap_ms' => $bestLapMs,
                'username'    => $username,
                'frames'      => $ghostFrames,
            ], JSON_UNESCAPED_UNICODE);
            $stmtUpG = $pdo->prepare(
                "INSERT INTO `game_saves` (`user_id`, `game_key`, `state_json`)
                 VALUES (:uid, :gk, :st)
                 ON DUPLICATE KEY UPDATE `state_json` = VALUES(`state_json`)"
            );
            $stmtUpG->execute([':uid' => $userId, ':gk' => $saveKey, ':st' => $newGhostPayload]);
        }

        $challengeMsg = '';

        // Fall A: Benutzer hat eine bestehende Ghost-Herausforderung angenommen und zu Ende gefahren
        if ($matchId > 0) {
            $stmtM = $pdo->prepare("SELECT * FROM `game_matches` WHERE `id` = :id LIMIT 1");
            $stmtM->execute([':id' => $matchId]);
            $mRow = $stmtM->fetch();
            if ($mRow && $mRow['status'] === 'active' && (int)$mRow['player2_id'] === $userId) {
                $bData    = json_decode((string)$mRow['board_json'], true) ?: [];
                $p1TimeMs = (int)($bData['p1_time_ms'] ?? 999999);
                $p1Id     = (int)$mRow['player1_id'];

                $winnerId  = null;
                $newStatus = 'finished';
                if ($timeMs < $p1TimeMs) {
                    $winnerId = $userId;
                    $challengeMsg = '🏆 Du hast die Ghost-Herausforderung gewonnen (' . $fmtTime . ')! +800 Bonus-Punkte!';
                } elseif ($timeMs > $p1TimeMs) {
                    $winnerId = $p1Id;
                    $challengeMsg = '🏁 Herausforderungs-Fahrt beendet (' . $fmtTime . ') – dein Gegner war einen Hauch schneller!';
                } else {
                    $newStatus = 'draw';
                    $challengeMsg = '🤝 Auf die Millisekunde exakt gleich schnell!';
                }

                $bData['p2_time_ms'] = $timeMs;
                $bData['p2_ghost']   = array_slice($ghostFrames, 0, 450);

                $stmtUpM = $pdo->prepare(
                    "UPDATE `game_matches`
                     SET `board_json` = :bj, `status` = :st, `winner_id` = :wid
                     WHERE `id` = :id"
                );
                $stmtUpM->execute([
                    ':bj'  => json_encode($bData, JSON_UNESCAPED_UNICODE),
                    ':st'  => $newStatus,
                    ':wid' => $winnerId,
                    ':id'  => $matchId,
                ]);

                if ($winnerId !== null) {
                    $stmtBonus = $pdo->prepare(
                        "INSERT INTO `game_scores` (`user_id`, `game_key`, `score`, `duration_sec`, `meta_label`)
                         VALUES (:uid, 'gummigeruch', :sc, :dur, :lbl)"
                    );
                    $stmtBonus->execute([
                        ':uid' => $winnerId,
                        ':sc'  => $score + 800,
                        ':dur' => $durationSec,
                        ':lbl' => 'Ghost-Duell Sieg (' . $tName . ')',
                    ]);
                }
            }
        }
        // Fall B: Benutzer hat einen anderen Cloud-Benutzer mit dieser Fahrt neu herausgefordert!
        elseif ($opponentId > 0 && $opponentId !== $userId) {
            $bData = [
                'track_id'    => $trackId,
                'track_name'  => $tName,
                'p1_time_ms'  => $timeMs,
                'p1_best_lap' => $bestLapMs,
                'p1_ghost'    => array_slice($ghostFrames, 0, 600),
            ];
            $stmtInsM = $pdo->prepare(
                "INSERT INTO `game_matches` (`game_type`, `player1_id`, `player2_id`, `current_turn_id`, `board_json`, `status`)
                 VALUES (:gt, :p1, :p2, :turn, :bj, 'active')"
            );
            $stmtInsM->execute([
                ':gt'   => 'gummi_' . $trackId,
                ':p1'   => $userId,
                ':p2'   => $opponentId,
                ':turn' => $opponentId,
                ':bj'   => json_encode($bData, JSON_UNESCAPED_UNICODE),
            ]);
            $challengeMsg = '👻 Herausforderung mit deinem Ghost-Kart (' . $fmtTime . ') wurde gesendet!';
        }

        $stmtBest = $pdo->prepare("SELECT MAX(`score`) FROM `game_scores` WHERE `user_id` = :uid AND `game_key` = 'gummigeruch'");
        $stmtBest->execute([':uid' => $userId]);
        $personalBest = (int)$stmtBest->fetchColumn();

        echo json_encode([
            'ok'            => true,
            'score'         => $score,
            'personal_best' => $personalBest,
            'challenge_msg' => $challengeMsg,
            'leaderboard'   => fetchLeaderboardRows($pdo, 'gummigeruch', 'all'),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 9. Gummigeruch Kart: Streckenrekord-Ghost, offene Ghost-Duelle & Live-Mitfahrer abrufen
    if ($action === 'gummi_poll_state') {
        session_write_close();
        $allowedTracks = ['circuit', 'coast', 'night', 'alps', 'desert', 'volcano'];
        $trackId = in_array($_POST['track_id'] ?? '', $allowedTracks, true) ? (string)$_POST['track_id'] : 'circuit';
        $saveKey = 'gummi_' . $trackId;

        // Optional: Eigene Live-Kart-Position aktualisieren, falls gerade im Rennen
        if (isset($_POST['live_x'], $_POST['live_y'])) {
            $livePayload = json_encode([
                'username' => $username,
                'track_id' => $trackId,
                'x'        => (float)$_POST['live_x'],
                'y'        => (float)$_POST['live_y'],
                'angle'    => (float)($_POST['live_angle'] ?? 0),
                'lap'      => (int)($_POST['live_lap'] ?? 1),
            ], JSON_UNESCAPED_UNICODE);
            try {
                $stmtLv = $pdo->prepare(
                    "INSERT INTO `game_saves` (`user_id`, `game_key`, `state_json`)
                     VALUES (:uid, 'gummi_live', :st)
                     ON DUPLICATE KEY UPDATE `state_json` = VALUES(`state_json`)"
                );
                $stmtLv->execute([':uid' => $userId, ':st' => $livePayload]);
            } catch (Throwable) {
            }
        }

        // 1. Schnellsten Cloud-Champion-Ghost für diese Strecke ermitteln
        $recordGhost = null;
        try {
            $stmtG = $pdo->prepare(
                "SELECT s.user_id, u.username, s.state_json
                 FROM `game_saves` s
                 INNER JOIN `users` u ON u.id = s.user_id
                 WHERE s.game_key = :gk"
            );
            $stmtG->execute([':gk' => $saveKey]);
            $bestMs = 99999999;
            foreach ($stmtG->fetchAll() as $gRow) {
                $gd = json_decode((string)$gRow['state_json'], true);
                if (!is_array($gd) || empty($gd['frames'])) {
                    continue;
                }
                $ms = (int)($gd['time_ms'] ?? 99999999);
                if ($ms < $bestMs) {
                    $bestMs = $ms;
                    $recordGhost = [
                        'username' => (string)$gRow['username'],
                        'time_ms'  => $ms,
                        'frames'   => $gd['frames'],
                    ];
                }
            }
        } catch (Throwable) {
        }

        // 2. Offene & beendete Ghost-Herausforderungen des Benutzers laden
        $challenges = [];
        try {
            $stmtCh = $pdo->prepare(
                "SELECT m.*, u1.username AS p1_name, u2.username AS p2_name
                 FROM `game_matches` m
                 INNER JOIN `users` u1 ON u1.id = m.player1_id
                 INNER JOIN `users` u2 ON u2.id = m.player2_id
                 WHERE (m.player1_id = :u1 OR m.player2_id = :u2)
                   AND m.game_type LIKE 'gummi_%'
                 ORDER BY (m.status = 'active') DESC, m.updated_at DESC
                 LIMIT 10"
            );
            $stmtCh->execute([':u1' => $userId, ':u2' => $userId]);
            foreach ($stmtCh->fetchAll() as $cRow) {
                $bData = json_decode((string)$cRow['board_json'], true) ?: [];
                $challenges[] = [
                    'id'              => (int)$cRow['id'],
                    'track_id'        => (string)($bData['track_id'] ?? 'circuit'),
                    'track_name'      => (string)($bData['track_name'] ?? 'GummiCircuit'),
                    'player1_id'      => (int)$cRow['player1_id'],
                    'player2_id'      => (int)$cRow['player2_id'],
                    'p1_name'         => (string)$cRow['p1_name'],
                    'p2_name'         => (string)$cRow['p2_name'],
                    'p1_time_ms'      => (int)($bData['p1_time_ms'] ?? 0),
                    'p2_time_ms'      => isset($bData['p2_time_ms']) ? (int)$bData['p2_time_ms'] : null,
                    'p1_ghost'        => $bData['p1_ghost'] ?? [],
                    'status'          => (string)$cRow['status'],
                    'winner_id'       => $cRow['winner_id'] !== null ? (int)$cRow['winner_id'] : null,
                    'updated_at'      => date('d.m. H:i', strtotime((string)$cRow['updated_at']) ?: time()),
                ];
            }
        } catch (Throwable) {
        }

        // 3. Gerade live auf derselben Strecke fahrende Mitspieler laden
        $livePeers = [];
        try {
            $stmtLp = $pdo->prepare(
                "SELECT u.username, s.state_json
                 FROM `game_saves` s
                 INNER JOIN `users` u ON u.id = s.user_id
                 WHERE s.game_key = 'gummi_live'
                   AND s.user_id != :uid
                   AND s.updated_at >= DATE_SUB(NOW(), INTERVAL 15 SECOND)"
            );
            $stmtLp->execute([':uid' => $userId]);
            foreach ($stmtLp->fetchAll() as $lpRow) {
                $ld = json_decode((string)$lpRow['state_json'], true);
                if (is_array($ld) && ($ld['track_id'] ?? '') === $trackId) {
                    $livePeers[] = [
                        'username' => (string)$lpRow['username'],
                        'x'        => (float)($ld['x'] ?? 0),
                        'y'        => (float)($ld['y'] ?? 0),
                        'angle'    => (float)($ld['angle'] ?? 0),
                        'lap'      => (int)($ld['lap'] ?? 1),
                    ];
                }
            }
        } catch (Throwable) {
        }

        echo json_encode([
            'ok'           => true,
            'record_ghost' => $recordGhost,
            'challenges'   => $challenges,
            'live_peers'   => $livePeers,
        ], JSON_UNESCAPED_UNICODE);
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

$initialLeaderboard = fetchLeaderboardRows($pdo, 'gummigeruch', 'all');

$pageTitle = '🎮 Spiele & Pausenraum';
require_once __DIR__ . '/includes/header.php';
$csrfToken = getCsrfToken();
?>

<div class="space-y-6">
    <!-- KOPFBEREICH -->
    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs flex flex-wrap items-center justify-between gap-4">
        <div class="flex items-center gap-3.5">
            <div class="w-11 h-11 rounded-2xl bg-brand-50 dark:bg-brand-950/70 border border-brand-200/70 dark:border-brand-800/70 text-brand-600 dark:text-brand-400 flex items-center justify-center text-2xl shadow-xs shrink-0">
                🎮
            </div>
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-xl sm:text-2xl font-bold text-slate-900 dark:text-white">
                        MiniCloud Spiele-Zentrale &amp; Pausenraum
                    </h1>
                    <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-brand-50 dark:bg-brand-950/80 text-brand-700 dark:text-brand-300 border border-brand-200 dark:border-brand-800">
                        11 Spiele &middot; Cloud-Highscores &middot; Ghost- &amp; Live-Duelle
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
            <div class="px-3.5 py-2 rounded-xl bg-rose-50 dark:bg-rose-950/50 border border-rose-200 dark:border-rose-800 text-rose-800 dark:text-rose-300 font-semibold">
                🏎️ Gummigeruch Kart-Cup aktiv
            </div>
        </div>
    </div>

    <!-- SPIELAUSWAHL-LEISTE (11 SPIEL-KARTEN) -->
    <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-11 gap-2">
        <?php foreach ($gamesCatalog as $gKey => $gMeta): ?>
            <?php
            $gKeyStr = (string)$gKey;
            $myBest  = $myBestByGame[$gKeyStr] ?? 0;
            ?>
            <button type="button"
                    onclick="selectCloudGame('<?= e($gKeyStr) ?>')"
                    id="gameTabBtn-<?= e($gKeyStr) ?>"
                    class="game-tab-btn text-left p-3 rounded-2xl border transition-all cursor-pointer flex flex-col justify-between gap-1.5 <?= $gKeyStr === 'gummigeruch' ? 'bg-brand-600 text-white border-brand-600 shadow-md' : 'bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100 border-slate-200 dark:border-slate-800 hover:border-brand-400' ?>">
                <div class="flex items-center justify-between gap-1">
                    <span class="text-xl"><?= $gMeta['icon'] ?></span>
                    <span class="text-[10px] font-bold px-1.5 py-0.5 rounded-md bg-black/10 dark:bg-white/10 truncate">
                        <?= e($gMeta['category']) ?>
                    </span>
                </div>
                <div>
                    <p class="text-xs font-extrabold leading-tight truncate"><?= e($gMeta['title']) ?></p>
                    <p class="text-[10px] opacity-80 mt-0.5 truncate" id="myBestLabel-<?= e($gKeyStr) ?>">
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

            <!-- 1. SPIEL: GUMMIGERUCH KART-CUP & GHOST-DUELLE -->
            <div id="gamePanel-gummigeruch" class="game-panel space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3 pb-3 border-b border-slate-200 dark:border-slate-800">
                    <div>
                        <h2 class="text-base sm:text-lg font-extrabold text-slate-900 dark:text-white flex items-center gap-2">
                            <span>🏎️ Gummigeruch Kart-Cup</span>
                            <span class="text-[11px] font-semibold px-2 py-0.5 rounded-full bg-rose-100 dark:bg-rose-950 text-rose-700 dark:text-rose-300 border border-rose-300/70 dark:border-rose-800">
                                👻 Ghost-Duelle &amp; Live-Rekordjagd
                            </span>
                        </h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            Steuerung: `↑`/`W` Gas · `↓`/`S` Bremse · `← →`/`A D` Lenken · `Leertaste` Drift-Handbremse · `E` Nitro-Boost (1× pro Runde).
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <select id="gummiTrackSelect" onchange="gummiChangeTrack(this.value)"
                                class="px-2.5 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs font-bold text-slate-900 dark:text-white cursor-pointer">
                            <optgroup label="🟢 Klassik-Strecken">
                                <option value="circuit">🏁 GummiCircuit Klassik (Breit · 3 Runden)</option>
                                <option value="coast">🌴 Küsten-Grand-Prix (Mittel · 3 Runden)</option>
                                <option value="night">🌙 Flutlicht Nacht-Ring (Mittel · 3 Runden)</option>
                            </optgroup>
                            <optgroup label="🔥 Profi-Strecken (Anspruchsvoll)">
                                <option value="alps">⛰️ Alpen-Serpentinen (Eng · 3 Haarnadelkehren)</option>
                                <option value="desert">🏜️ Wüsten-Schikane (Rutschig · Doppel-S-Canyon)</option>
                                <option value="volcano">🌋 Vulkan-Höllenring (Extrem schmal · Profi-Kurs)</option>
                            </optgroup>
                        </select>

                        <label class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 text-xs font-semibold cursor-pointer select-none">
                            <input type="checkbox" id="gummiAiToggle" checked onchange="gummiResetRace()" class="rounded border-slate-300 text-brand-600">
                            <span>🤖 3 KI-Karts</span>
                        </label>

                        <button type="button" onclick="gummiStartCountdown()"
                                class="px-3.5 py-1.5 rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-xs font-extrabold shadow-2xs cursor-pointer">
                            🚦 Rennen neu starten
                        </button>
                    </div>
                </div>

                <!-- Telemetrie- & Rundenzeiten-Zeile -->
                <div class="grid grid-cols-2 sm:grid-cols-5 gap-2 text-xs">
                    <div class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 flex items-center justify-between">
                        <span class="text-slate-500 dark:text-slate-400">Runde:</span>
                        <strong id="gummiLapBadge" class="text-slate-900 dark:text-white font-extrabold">1 / 3</strong>
                    </div>
                    <div class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 flex items-center justify-between">
                        <span class="text-slate-500 dark:text-slate-400">Zeit:</span>
                        <strong id="gummiTimeBadge" class="font-mono text-brand-600 dark:text-brand-400 font-extrabold">00:00.00</strong>
                    </div>
                    <div class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 flex items-center justify-between">
                        <span class="text-slate-500 dark:text-slate-400">Beste Runde:</span>
                        <strong id="gummiBestLapBadge" class="font-mono text-emerald-600 dark:text-emerald-400 font-extrabold">--:--.--</strong>
                    </div>
                    <div class="px-3 py-2 rounded-xl bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800/60 flex items-center justify-between">
                        <span class="text-amber-800 dark:text-amber-300">🔥 Nitro [E]:</span>
                        <strong id="gummiBoostBadge" class="text-amber-700 dark:text-amber-300 font-extrabold">Bereit!</strong>
                    </div>
                    <div class="col-span-2 sm:col-span-1 px-3 py-2 rounded-xl bg-slate-900 text-white flex items-center justify-between">
                        <span class="text-slate-400">Tacho:</span>
                        <strong id="gummiSpeedBadge" class="font-mono text-amber-400 font-extrabold">0 km/h</strong>
                    </div>
                </div>

                <!-- RENNSTRECKEN-CANVAS -->
                <div class="relative rounded-2xl overflow-hidden border-2 border-slate-700 bg-emerald-950 shadow-lg select-none">
                    <canvas id="gummiCanvas" width="760" height="450" class="w-full h-auto block touch-none"></canvas>
                </div>

                <!-- Touch-Lenkrad & Pedale für Smartphone / Tablet -->
                <div class="flex sm:hidden items-center justify-between gap-2 pt-1 select-none">
                    <div class="flex items-center gap-2">
                        <button type="button" onpointerdown="gummiTouch('left',true)" onpointerup="gummiTouch('left',false)" onpointerleave="gummiTouch('left',false)"
                                class="px-5 py-3 rounded-xl bg-slate-200 dark:bg-slate-800 font-extrabold text-base">◀</button>
                        <button type="button" onpointerdown="gummiTouch('right',true)" onpointerup="gummiTouch('right',false)" onpointerleave="gummiTouch('right',false)"
                                class="px-5 py-3 rounded-xl bg-slate-200 dark:bg-slate-800 font-extrabold text-base">▶</button>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" onclick="gummiTriggerBoost()"
                                class="px-3.5 py-3 rounded-xl bg-amber-500 text-white font-extrabold text-xs">🔥 Nitro</button>
                        <button type="button" onpointerdown="gummiTouch('brake',true)" onpointerup="gummiTouch('brake',false)" onpointerleave="gummiTouch('brake',false)"
                                class="px-4 py-3 rounded-xl bg-rose-600 text-white font-extrabold text-xs">Bremse</button>
                        <button type="button" onpointerdown="gummiTouch('gas',true)" onpointerup="gummiTouch('gas',false)" onpointerleave="gummiTouch('gas',false)"
                                class="px-5 py-3 rounded-xl bg-emerald-600 text-white font-extrabold text-xs">GAS ▲</button>
                    </div>
                </div>

                <!-- HERAUSFORDERUNGS-ZENTRALE: GHOST-DUELLE & STRECKENREKORD-JAGD -->
                <div class="grid grid-cols-1 md:grid-cols-12 gap-4 pt-1">
                    <!-- Links: Mitspieler herausfordern + Champion-Ghost Info -->
                    <div class="md:col-span-6 p-3.5 rounded-2xl bg-rose-50/70 dark:bg-rose-950/20 border border-rose-200/80 dark:border-rose-800/60 space-y-2.5 text-xs">
                        <div class="flex items-center justify-between">
                            <h3 class="font-extrabold uppercase tracking-wider text-rose-900 dark:text-rose-300 flex items-center gap-1.5">
                                <span>👻</span>
                                <span>Cloud-Benutzer zum Ghost-Duell fordern</span>
                            </h3>
                        </div>

                        <?php if (empty($otherUsers)): ?>
                            <p class="text-slate-500 dark:text-slate-400">
                                Sobald weitere Benutzer in der Cloud angelegt sind, kannst du sie hier zum direkten Ghost-Rennen herausfordern!
                            </p>
                        <?php else: ?>
                            <div class="flex flex-wrap items-center gap-2">
                                <select id="gummiChallengeOpponent" class="flex-1 min-w-[150px] px-3 py-2 rounded-xl bg-white dark:bg-slate-900 border border-rose-300 dark:border-rose-700 font-semibold">
                                    <option value="0">🏁 Freies Rennen (nur Rekordjagd)</option>
                                    <?php foreach ($otherUsers as $ou): ?>
                                        <option value="<?= (int)$ou['id'] ?>">👤 Herausfordern: <?= e((string)$ou['username']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" onclick="gummiStartChallengeRun()"
                                        class="px-3.5 py-2 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-bold cursor-pointer">
                                    🏎️ Vorlegen!
                                </button>
                            </div>
                        <?php endif; ?>

                        <div id="gummiRecordGhostBox" class="p-2.5 rounded-xl bg-white/90 dark:bg-slate-900/90 border border-slate-200 dark:border-slate-800 text-[11px] text-slate-600 dark:text-slate-300">
                            👻 <strong>Streckenrekord-Ghost:</strong> Wird geladen…
                        </div>
                    </div>

                    <!-- Rechts: Offene & beendete Gummigeruch-Duelle -->
                    <div class="md:col-span-6 p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200 dark:border-slate-700/80 space-y-2 text-xs">
                        <div class="flex items-center justify-between">
                            <h3 class="font-extrabold uppercase tracking-wider text-slate-700 dark:text-slate-300">
                                🏆 Meine Ghost-Herausforderungen
                            </h3>
                            <span id="gummiActiveMatchBadge" class="text-[11px] font-bold text-rose-600 dark:text-rose-400"></span>
                        </div>
                        <div id="gummiChallengesList" class="space-y-1.5 max-h-40 overflow-y-auto">
                            <p class="text-slate-400 italic py-2">Noch keine offenen Kart-Herausforderungen.</p>
                        </div>
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

            <!-- 10. SPIEL: WORLD OF MINICLOUD (FANTASY ACTION-RPG / MMORPG-LITE) -->
            <div id="gamePanel-wow" class="game-panel hidden space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3 pb-3 border-b border-slate-200 dark:border-slate-800">
                    <div>
                        <h2 class="text-base sm:text-lg font-extrabold text-slate-900 dark:text-white flex items-center gap-2">
                            <span>⚔️ World of MiniCloud</span>
                            <span class="text-[11px] font-semibold px-2 py-0.5 rounded-full bg-amber-100 dark:bg-amber-950 text-amber-800 dark:text-amber-300 border border-amber-300/70 dark:border-amber-800">
                                💾 Charakter-Sync &amp; Live-Mitspieler
                            </span>
                        </h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            Bewegung: `W A S D` / Pfeiltasten · Zauberleiste: `1`–`5` · Heiltrank: `Q` · Ziel wechseln: `Tab` oder Klick auf Gegner.
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <!-- Klassen-Auswahl -->
                        <select id="wowClassSelect" onchange="wowChangeClass(this.value)"
                                class="px-2.5 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs font-bold text-slate-900 dark:text-white cursor-pointer">
                            <option value="paladin">🛡️ Paladin / Krieger</option>
                            <option value="mage">🔥 Erzmagier</option>
                            <option value="ranger">🏹 Waldläufer / Jäger</option>
                        </select>

                        <!-- Zonen- / Dungeon-Flugmeister -->
                        <select id="wowZoneSelect" onchange="wowChangeZone(this.value)"
                                class="px-2.5 py-1.5 rounded-xl bg-amber-50 dark:bg-amber-950/60 border border-amber-300 dark:border-amber-800 text-xs font-bold text-amber-900 dark:text-amber-200 cursor-pointer">
                            <option value="elwynn">🌲 Goldhain &amp; Elwynn (Stufe 1–5)</option>
                            <option value="duskwood">🌙 Dämmerwald (Stufe 5–10)</option>
                            <option value="moltencore">🔥 Geschmolzener Kern [Raid] (Stufe 10+)</option>
                            <option value="icecrown">❄️ Eiskronenzitadelle [Raid] (Stufe 16+)</option>
                        </select>

                        <button type="button" onclick="wowTeleportTown()"
                                title="Ruhestein: Zurück zum Heilbrunnen im Lager"
                                class="px-2.5 py-1.5 rounded-xl bg-sky-600 hover:bg-sky-700 text-white text-xs font-bold cursor-pointer">
                            💎 Ruhestein
                        </button>
                    </div>
                </div>

                <!-- Helden-Statuszeile (Stufe, Kampfwert, Gold, Tränke) -->
                <div class="grid grid-cols-2 sm:grid-cols-5 gap-2 text-xs">
                    <div class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 flex items-center justify-between">
                        <span class="text-slate-500 dark:text-slate-400">Stufe:</span>
                        <strong id="wowHeroLevelBadge" class="text-amber-600 dark:text-amber-400 font-extrabold">Lv. 1</strong>
                    </div>
                    <div class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 flex items-center justify-between">
                        <span class="text-slate-500 dark:text-slate-400">GearScore:</span>
                        <strong id="wowGearScoreBadge" class="text-purple-600 dark:text-purple-400 font-extrabold">25</strong>
                    </div>
                    <div class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 flex items-center justify-between">
                        <span class="text-slate-500 dark:text-slate-400">Schaden / Krit:</span>
                        <strong id="wowDamageBadge" class="text-rose-600 dark:text-rose-400 font-extrabold">18 (12%)</strong>
                    </div>
                    <div class="px-3 py-2 rounded-xl bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800/60 flex items-center justify-between">
                        <span class="text-amber-800 dark:text-amber-300">🪙 Gold:</span>
                        <strong id="wowGoldBadge" class="text-amber-700 dark:text-amber-300 font-extrabold">50</strong>
                    </div>
                    <div class="col-span-2 sm:col-span-1 px-3 py-2 rounded-xl bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800/60 flex items-center justify-between">
                        <span class="text-emerald-800 dark:text-emerald-300">🧪 Tränke:</span>
                        <strong id="wowPotionsBadge" class="text-emerald-700 dark:text-emerald-300 font-extrabold">3</strong>
                    </div>
                </div>

                <!-- SPIELWELT-CANVAS (MIT WOW-UNIT-FRAMES, QUEST-KOMPASS & BOSS-MECHANIKEN) -->
                <div class="relative rounded-2xl overflow-hidden border-2 border-amber-500/50 dark:border-amber-600/50 bg-slate-950 shadow-lg select-none">
                    <canvas id="wowCanvas" width="760" height="440" class="w-full h-auto block cursor-crosshair touch-none"></canvas>
                </div>

                <!-- KLASSISCHE WOW-AKTIONSLEISTE (TASTEN 1-5 + HEILTRANK Q + ZIELWECHSEL TAB) -->
                <div class="p-2.5 rounded-2xl bg-slate-900 border border-amber-500/40 shadow-inner flex flex-wrap items-center justify-between gap-2">
                    <div id="wowActionBarSlots" class="flex flex-wrap items-center gap-1.5 sm:gap-2">
                        <!-- Wird dynamisch je nach Klasse gerendert -->
                    </div>

                    <div class="flex items-center gap-1.5">
                        <button type="button" onclick="wowUsePotion()"
                                id="wowPotionBtn"
                                class="relative px-3 py-2 rounded-xl bg-rose-950/90 hover:bg-rose-900 border border-rose-500/70 text-rose-100 text-xs font-bold flex items-center gap-1.5 cursor-pointer"
                                title="Heiltrank trinken (Taste Q) – Stellt 45 % LP &amp; Mana wieder her">
                            <span class="text-base">🧪</span>
                            <div class="text-left leading-tight">
                                <div class="text-[10px] text-rose-300">[Q] Trank</div>
                                <div id="wowPotionBtnCount" class="text-xs font-extrabold">3×</div>
                            </div>
                        </button>
                        <button type="button" onclick="wowCycleTarget()"
                                class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 border border-slate-600 text-slate-200 text-xs font-bold cursor-pointer"
                                title="Nächstes Monster ins Visier nehmen (Taste Tab)">
                            🎯 [Tab] Ziel
                        </button>
                    </div>
                </div>

                <!-- Mobile Touch-Steuerung (nur auf kleinen Bildschirmen sichtbar) -->
                <div class="flex sm:hidden items-center justify-center gap-2 pt-1">
                    <button type="button" onpointerdown="wowTouchMove(-1,0)" onpointerup="wowTouchMove(0,0)" class="px-4 py-2.5 rounded-xl bg-slate-200 dark:bg-slate-800 font-bold text-sm">◀</button>
                    <button type="button" onpointerdown="wowTouchMove(0,-1)" onpointerup="wowTouchMove(0,0)" class="px-4 py-2.5 rounded-xl bg-slate-200 dark:bg-slate-800 font-bold text-sm">▲</button>
                    <button type="button" onpointerdown="wowTouchMove(0,1)" onpointerup="wowTouchMove(0,0)" class="px-4 py-2.5 rounded-xl bg-slate-200 dark:bg-slate-800 font-bold text-sm">▼</button>
                    <button type="button" onpointerdown="wowTouchMove(1,0)" onpointerup="wowTouchMove(0,0)" class="px-4 py-2.5 rounded-xl bg-slate-200 dark:bg-slate-800 font-bold text-sm">▶</button>
                </div>

                <!-- UNTERER BEREICH: QUEST-LOGBUCH (LINKS) & AUSRÜSTUNG / BEUTE-RUCKSACK / SCHMIED (RECHTS) -->
                <div class="grid grid-cols-1 md:grid-cols-12 gap-4 pt-1">
                    <!-- Quest-Log & Schmied-Händler -->
                    <div class="md:col-span-5 space-y-3">
                        <div class="p-3.5 rounded-2xl bg-amber-50/70 dark:bg-amber-950/25 border border-amber-200/80 dark:border-amber-800/60 space-y-2">
                            <div class="flex items-center justify-between">
                                <h3 class="text-xs font-extrabold uppercase tracking-wider text-amber-900 dark:text-amber-300 flex items-center gap-1.5">
                                    <span>📜</span>
                                    <span>Aktive Quests &amp; Kopfgelder</span>
                                </h3>
                                <span id="wowCompletedQuestsBadge" class="text-[11px] font-bold text-amber-700 dark:text-amber-400">0 erledigt</span>
                            </div>
                            <div id="wowQuestListBox" class="space-y-2 text-xs"></div>
                        </div>

                        <!-- Dorf-Schmied & Alchemist -->
                        <div class="p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200 dark:border-slate-700/80 space-y-2">
                            <h3 class="text-xs font-extrabold uppercase tracking-wider text-slate-700 dark:text-slate-300">
                                ⚒️ Dorf-Schmied &amp; Alchemist
                            </h3>
                            <div class="grid grid-cols-2 gap-2 text-xs">
                                <button type="button" onclick="wowBuyPotion()"
                                        class="p-2 rounded-xl bg-white dark:bg-slate-900 hover:border-emerald-500 border border-slate-200 dark:border-slate-700 text-left cursor-pointer transition-colors">
                                    <div class="font-bold text-slate-900 dark:text-white">🧪 Heiltrank kaufen</div>
                                    <div class="text-[11px] text-amber-600 dark:text-amber-400 font-semibold">Kosten: 25 🪙 Gold</div>
                                </button>
                                <button type="button" onclick="wowGambleMysteryItem()"
                                        class="p-2 rounded-xl bg-white dark:bg-slate-900 hover:border-purple-500 border border-slate-200 dark:border-slate-700 text-left cursor-pointer transition-colors">
                                    <div class="font-bold text-slate-900 dark:text-white">🎁 Mysteriöse Truhe</div>
                                    <div class="text-[11px] text-purple-600 dark:text-purple-400 font-semibold">Kosten: 80 🪙 (Zufalls-Item!)</div>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Angelegte Ausrüstung & Beute-Rucksack -->
                    <div class="md:col-span-7 p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200 dark:border-slate-700/80 space-y-3">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h3 class="text-xs font-extrabold uppercase tracking-wider text-slate-700 dark:text-slate-300">
                                🛡️ Angelegte Helden-Ausrüstung (4 Slots)
                            </h3>
                            <button type="button" onclick="wowSellAllBackpack()"
                                    class="px-2.5 py-1 rounded-lg bg-amber-100 dark:bg-amber-950/70 hover:bg-amber-200 text-amber-900 dark:text-amber-200 text-[11px] font-bold cursor-pointer">
                                🪙 Rucksack-Beute verkaufen
                            </button>
                        </div>

                        <!-- 4 Ausrüstungs-Slots -->
                        <div id="wowEquippedGrid" class="grid grid-cols-2 sm:grid-cols-4 gap-2 text-xs"></div>

                        <!-- Beute-Rucksack -->
                        <div>
                            <div class="flex items-center justify-between mb-1.5">
                                <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400">
                                    🎒 Beute-Rucksack (Klick zum Anlegen)
                                </span>
                                <span id="wowBagCountLabel" class="text-[11px] text-slate-400">0 / 12 Plätze</span>
                            </div>
                            <div id="wowBackpackGrid" class="grid grid-cols-1 sm:grid-cols-2 gap-1.5 max-h-44 overflow-y-auto text-xs"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 10. SPIEL: CLOUD-PACMAN (NEON-ARCADE) -->
            <div id="gamePanel-pacman" class="game-panel hidden space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3 pb-3 border-b border-slate-200 dark:border-slate-800">
                    <div>
                        <h2 class="text-base sm:text-lg font-extrabold text-slate-900 dark:text-white flex items-center gap-2">
                            <span>🟡 Cloud-PacMan</span>
                            <span class="text-[11px] font-semibold px-2 py-0.5 rounded-full bg-amber-100 dark:bg-amber-950 text-amber-800 dark:text-amber-300 border border-amber-300/70 dark:border-amber-800">
                                👻 4 Geister-KIs &middot; Power-Pillen &middot; Warp-Tunnel
                            </span>
                        </h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            Steuerung mit <strong>Pfeiltasten</strong> oder <strong>W A S D</strong> (Richtung kann schon vor der nächsten Kreuzung vorab gedrückt werden!).
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="button" id="pacmanPauseBtn" onclick="pacmanTogglePause()"
                                class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 text-xs font-bold cursor-pointer">
                            ⏸️ Pause
                        </button>
                        <button type="button" onclick="pacmanStartGame()"
                                class="px-3.5 py-1.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 text-xs font-extrabold shadow-2xs cursor-pointer">
                            ▶️ Neues Spiel starten
                        </button>
                    </div>
                </div>

                <!-- Status- & Punktezeile -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 text-xs">
                    <div class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 flex items-center justify-between">
                        <span class="text-slate-500 dark:text-slate-400">Punkte:</span>
                        <strong id="pacmanScore" class="text-amber-600 dark:text-amber-400 font-extrabold text-sm">0</strong>
                    </div>
                    <div class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 flex items-center justify-between">
                        <span class="text-slate-500 dark:text-slate-400">Level:</span>
                        <strong id="pacmanLevel" class="text-slate-900 dark:text-white font-extrabold">1</strong>
                    </div>
                    <div class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 flex items-center justify-between">
                        <span class="text-slate-500 dark:text-slate-400">Leben:</span>
                        <strong id="pacmanLives" class="tracking-widest">❤️❤️❤️</strong>
                    </div>
                    <div class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 flex items-center justify-between">
                        <span class="text-slate-500 dark:text-slate-400">Status:</span>
                        <strong id="pacmanStatus" class="text-emerald-600 dark:text-emerald-400 font-extrabold truncate">Bereit</strong>
                    </div>
                </div>

                <!-- Geister-Legende -->
                <div class="flex flex-wrap items-center justify-center gap-3 text-[11px] text-slate-500 dark:text-slate-400">
                    <span class="inline-flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-full bg-red-500 inline-block"></span> <strong>Blinky</strong> (Verfolger)</span>
                    <span class="inline-flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-full bg-pink-400 inline-block"></span> <strong>Pinky</strong> (Hinterhalt)</span>
                    <span class="inline-flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-full bg-sky-400 inline-block"></span> <strong>Inky</strong> (Flankierer)</span>
                    <span class="inline-flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-full bg-orange-400 inline-block"></span> <strong>Clyde</strong> (Streuner)</span>
                    <span>&middot; ⚡ Power-Pille = <strong>+200 / +400 / +800 / +1600 Pkt.</strong></span>
                </div>

                <!-- Arcade-Spielfeld (Canvas) -->
                <div class="flex flex-col items-center gap-3">
                    <canvas id="pacmanCanvas" width="456" height="504"
                            class="rounded-2xl bg-slate-950 border-2 border-blue-900/80 shadow-xl max-w-full h-auto"></canvas>

                    <!-- Touch- / Mobil-Steuerkreuz -->
                    <div class="grid grid-cols-3 gap-2 w-44 sm:w-48 select-none">
                        <div></div>
                        <button type="button" onclick="pacmanQueueDir(0, -1)"
                                class="py-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 active:bg-amber-500 active:text-slate-950 font-extrabold text-sm shadow-2xs cursor-pointer">
                            ⬆️
                        </button>
                        <div></div>
                        <button type="button" onclick="pacmanQueueDir(-1, 0)"
                                class="py-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 active:bg-amber-500 active:text-slate-950 font-extrabold text-sm shadow-2xs cursor-pointer">
                            ⬅️
                        </button>
                        <button type="button" onclick="pacmanQueueDir(0, 1)"
                                class="py-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 active:bg-amber-500 active:text-slate-950 font-extrabold text-sm shadow-2xs cursor-pointer">
                            ⬇️
                        </button>
                        <button type="button" onclick="pacmanQueueDir(1, 0)"
                                class="py-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 active:bg-amber-500 active:text-slate-950 font-extrabold text-sm shadow-2xs cursor-pointer">
                            ➡️
                        </button>
                    </div>
                </div>
            </div>

            <!-- 11. SPIEL: COMMODORE VC-20 BLITZ (1981 RETRO-KLASSIKER) -->
            <div id="gamePanel-blitz" class="game-panel hidden space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3 pb-3 border-b border-slate-200 dark:border-slate-800">
                    <div>
                        <h2 class="text-base sm:text-lg font-extrabold text-slate-900 dark:text-white flex items-center gap-2">
                            <span>✈️ Commodore VC-20 „Blitz“ (1981)</span>
                            <span class="text-[11px] font-semibold px-2 py-0.5 rounded-full bg-cyan-100 dark:bg-cyan-950 text-cyan-800 dark:text-cyan-300 border border-cyan-300/70 dark:border-cyan-800">
                                🏙️ 8-Bit Ein-Tasten-Kultklassiker
                            </span>
                        </h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            Dein Flugzeug sinkt mit jedem Überflug eine Zeile tiefer! Wirf mit <strong>Leertaste</strong> (oder Klick auf das Spielfeld) Bomben ab, um alle Hochhäuser rechtzeitig vor der Landung einzuebnen – es kann immer nur <strong>1 Bombe gleichzeitig</strong> fallen!
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="button" id="blitzThemeBtn" onclick="blitzToggleTheme()"
                                class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 text-xs font-bold cursor-pointer">
                            🖥️ Optik: VC-20 Klassik
                        </button>
                        <button type="button" onclick="blitzStartGame()"
                                class="px-3.5 py-1.5 rounded-xl bg-cyan-500 hover:bg-cyan-600 text-slate-950 text-xs font-extrabold shadow-2xs cursor-pointer">
                            ✈️ Neuer Anflug (Start)
                        </button>
                    </div>
                </div>

                <!-- Telemetrie- & Stadt-Status -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 text-xs">
                    <div class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 flex items-center justify-between">
                        <span class="text-slate-500 dark:text-slate-400">Punkte:</span>
                        <strong id="blitzScore" class="text-cyan-600 dark:text-cyan-400 font-extrabold text-sm">0</strong>
                    </div>
                    <div class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 flex items-center justify-between">
                        <span class="text-slate-500 dark:text-slate-400">Stadt (City):</span>
                        <strong id="blitzCity" class="text-slate-900 dark:text-white font-extrabold">1</strong>
                    </div>
                    <div class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 flex items-center justify-between">
                        <span class="text-slate-500 dark:text-slate-400">Übrige Etagen:</span>
                        <strong id="blitzBlocksLeft" class="text-amber-600 dark:text-amber-400 font-extrabold">0</strong>
                    </div>
                    <div class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 flex items-center justify-between">
                        <span class="text-slate-500 dark:text-slate-400">Bomben-Schacht:</span>
                        <strong id="blitzBombStatus" class="text-emerald-600 dark:text-emerald-400 font-extrabold truncate">🟢 Bereit</strong>
                    </div>
                </div>

                <!-- VC-20 Bildschirm (Canvas) + Großer Abwurf-Button -->
                <div class="flex flex-col items-center gap-3">
                    <canvas id="blitzCanvas" width="528" height="432" onclick="blitzDropBomb()"
                            class="rounded-2xl bg-cyan-400 p-2 sm:p-3 border-4 border-cyan-500 shadow-xl max-w-full h-auto cursor-pointer select-none"
                            title="Klicken oder Leertaste drücken, um eine Bombe abzuwerfen!"></canvas>

                    <button type="button" onclick="blitzDropBomb()"
                            class="w-full max-w-md py-3 px-6 rounded-2xl bg-gradient-to-r from-rose-600 to-amber-500 hover:from-rose-500 hover:to-amber-400 active:scale-[0.99] text-white font-extrabold text-sm shadow-md cursor-pointer select-none flex items-center justify-center gap-2">
                        <span>💣 BOMBE ABWERFEN</span>
                        <span class="text-xs px-2 py-0.5 rounded-md bg-black/25 font-mono">Leertaste / Klick</span>
                    </button>
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
                            <span id="leaderboardGameTitle">Bestenliste: Gummigeruch Kart</span>
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
                    • Bei <strong>🏎️ Gummigeruch Kart</strong> wird deine exakte Fahrlinie als <strong>👻 Ghost-Kart</strong> gesichert – du kannst andere Cloud-Benutzer direkt herausfordern oder gegen den Streckenrekord-Ghost antreten!<br>
                    • Bei <strong>Sudoku</strong> und <strong>World of MiniCloud</strong> wird dein Fortschritt zusätzlich live auf dem Server gesichert.
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

let activeGameKey    = 'gummigeruch';
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

    if (gameKey === 'gummigeruch') {
        if (!gummiInitialized) {
            gummiInit();
        }
        gummiPollState();
    } else if (gameKey === 'pacman') {
        if (!pacmanInitialized) {
            pacmanInit();
        }
        pacmanDraw();
    } else if (gameKey === 'blitz') {
        if (!blitzInitialized) {
            blitzInit();
        }
        blitzDraw();
    } else if (gameKey === 'wow') {
        if (!wowInitialized) {
            wowInit();
        }
        wowDrawWorld(performance.now());
        wowSyncPeers();
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
// SPIEL 4: CLOUD-MEMORY
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
            if (tetrisBoard[r] && tetrisBoard[r][c]) {
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
// SPIEL 10: WORLD OF MINICLOUD (FANTASY ACTION-RPG / MMORPG-LITE)
// =============================================================================
const WOW_CLASSES = {
    paladin: {
        name: 'Paladin',
        icon: '🛡️',
        color: '#f59e0b',
        baseHp: 180,
        baseMp: 100,
        baseDmg: 16,
        range: 64,
        spells: [
            { key: '1', name: 'Kreuzfahrerstoß', icon: '⚔️', cd: 0.7, mp: 0, desc: 'Kräftiger Nahkampfhieb (+10 Mana)' },
            { key: '2', name: 'Göttlicher Sturm', icon: '🌪️', cd: 4.0, mp: 20, desc: 'Trifft alle Feinde im Umkreis (Flächenschaden)' },
            { key: '3', name: 'Richturteil', icon: '⚡', cd: 5.5, mp: 22, desc: 'Schleudert einen heiligen Hammer auf das Ziel & betäubt es kurz' },
            { key: '4', name: 'Heiliges Licht', icon: '✨', cd: 7.5, mp: 28, desc: 'Heilt sofort 40 % deiner maximalen Lebenspunkte' },
            { key: '5', name: 'Zornige Vergeltung', icon: '🪽', cd: 14.0, mp: 30, desc: '6 Sek. +55 % Schaden, +25 % Krit & Schadensschild' },
        ]
    },
    mage: {
        name: 'Erzmagier',
        icon: '🔥',
        color: '#38bdf8',
        baseHp: 130,
        baseMp: 160,
        baseDmg: 21,
        range: 220,
        spells: [
            { key: '1', name: 'Feuerball', icon: '🔥', cd: 0.75, mp: 0, desc: 'Schleudert einen Feuerball auf das Ziel (+12 Mana)' },
            { key: '2', name: 'Frostnova', icon: '❄️', cd: 5.5, mp: 24, desc: 'Friert alle Feinde um dich herum ein & verlangsamt sie stark' },
            { key: '3', name: 'Flammenstoß', icon: '☄️', cd: 5.0, mp: 28, desc: 'Mächtige Feuersäule am Ort deines Ziels (Flächenschaden)' },
            { key: '4', name: 'Blinzeln & Eisbarriere', icon: '🌀', cd: 7.0, mp: 25, desc: 'Teleportiert dich ein Stück & heilt 32 % LP' },
            { key: '5', name: 'Pyroschlag', icon: '🌋', cd: 12.0, mp: 35, desc: 'Gigantischer garantierter Krit-Feuerball + Arkane Macht' },
        ]
    },
    ranger: {
        name: 'Waldläufer',
        icon: '🏹',
        color: '#22c55e',
        baseHp: 150,
        baseMp: 120,
        baseDmg: 19,
        range: 210,
        spells: [
            { key: '1', name: 'Gezielter Schuss', icon: '🏹', cd: 0.65, mp: 0, desc: 'Präziser Pfeilschuss aus der Distanz (+10 Fokus)' },
            { key: '2', name: 'Mehrfachschuss', icon: '🌧️', cd: 4.0, mp: 22, desc: 'Feuert eine Salve auf bis zu 5 Gegner gleichzeitig' },
            { key: '3', name: 'Schlangenbiss', icon: '🐍', cd: 5.0, mp: 20, desc: 'Vergiftet & verlangsamt das Ziel mit hohem Bonusschaden' },
            { key: '4', name: 'Naturheilung', icon: '🌿', cd: 7.5, mp: 25, desc: 'Stellt sofort 38 % LP wieder her & erhöht dein Lauftempo' },
            { key: '5', name: 'Schnellfeuer', icon: '🦅', cd: 13.5, mp: 30, desc: '6 Sek. +60 % Angriffskraft & doppelte Krit-Chance' },
        ]
    }
};

const WOW_ZONES = {
    elwynn: {
        id: 'elwynn',
        name: 'Goldhain & Wald von Elwynn',
        minLv: 1,
        bgTop: '#14331d',
        bgBot: '#1d4527',
        campColor: '#2e5938',
        npcName: 'Marschall Dughan',
        npcIcon: '💂',
        questTitle: 'Kopfgeld: Die Gnoll-Plage von Elwynn',
        questTargetKills: 6,
        questRewardXp: 140,
        questRewardGold: 65,
        mobs: [
            { name: 'Waldwolf', icon: '🐺', hp: 55, dmg: 7, xp: 24, gold: 8, lv: 1, speed: 1.05 },
            { name: 'Kobold-Gräber', icon: '🕯️', hp: 70, dmg: 9, xp: 30, gold: 11, lv: 2, speed: 0.95 },
            { name: 'Defias-Bandit', icon: '🗡️', hp: 90, dmg: 11, xp: 38, gold: 14, lv: 3, speed: 1.1 },
        ],
        boss: {
            name: 'Hogger der Gnoll-Häuptling',
            quote: 'Grrr! Keine Kerze für euch!',
            icon: '🐗',
            hp: 360,
            dmg: 22,
            xp: 165,
            gold: 60,
            lv: 5
        }
    },
    duskwood: {
        id: 'duskwood',
        name: 'Dämmerwald & Rabenflucht',
        minLv: 5,
        bgTop: '#17162b',
        bgBot: '#231f3d',
        campColor: '#2d284d',
        npcName: 'Kommandant Althea',
        npcIcon: '🧙‍♀️',
        questTitle: 'Säubert die Katakomben von Rabenflucht',
        questTargetKills: 7,
        questRewardXp: 320,
        questRewardGold: 130,
        mobs: [
            { name: 'Skelettkrieger', icon: '💀', hp: 160, dmg: 18, xp: 65, gold: 20, lv: 6, speed: 1.05 },
            { name: 'Nachtweber-Spinne', icon: '🕷️', hp: 195, dmg: 22, xp: 78, gold: 24, lv: 7, speed: 1.2 },
            { name: 'Seuchen-Ghul', icon: '🧟', hp: 230, dmg: 25, xp: 92, gold: 28, lv: 8, speed: 1.0 },
        ],
        boss: {
            name: 'Stitches die Monstrosität',
            quote: 'Stitches will spielen!!',
            icon: '👹',
            hp: 920,
            dmg: 45,
            xp: 390,
            gold: 135,
            lv: 10
        }
    },
    moltencore: {
        id: 'moltencore',
        name: 'Geschmolzener Kern [Raid]',
        minLv: 10,
        bgTop: '#2e0d08',
        bgBot: '#47150b',
        campColor: '#5c2012',
        npcName: 'Herzog Hydraxis',
        npcIcon: '🌊',
        questTitle: 'Bezwingt die Diener des Feuerfürsten',
        questTargetKills: 8,
        questRewardXp: 700,
        questRewardGold: 260,
        mobs: [
            { name: 'Feuerelementar', icon: '🔥', hp: 380, dmg: 38, xp: 160, gold: 45, lv: 11, speed: 1.1 },
            { name: 'Uralter Kernhund', icon: '🐕‍🦺', hp: 460, dmg: 44, xp: 195, gold: 55, lv: 13, speed: 1.2 },
            { name: 'Flammenwaker-Wache', icon: '🐍', hp: 540, dmg: 50, xp: 230, gold: 65, lv: 14, speed: 1.05 },
        ],
        boss: {
            name: 'Ragnaros der Feuerfürst',
            quote: 'ZU FRÜH! Ihr habt mich zu früh geweckt! SPÜRT DIE FLAMMEN VON SULFURON!',
            icon: '🌋',
            hp: 2400,
            dmg: 85,
            xp: 950,
            gold: 320,
            lv: 16
        }
    },
    icecrown: {
        id: 'icecrown',
        name: 'Eiskronenzitadelle [Raid]',
        minLv: 16,
        bgTop: '#0b1e33',
        bgBot: '#122f4d',
        campColor: '#1b4269',
        npcName: 'Hochlord Tirion',
        npcIcon: '⚜️',
        questTitle: 'Sturm auf den Frostthron',
        questTargetKills: 8,
        questRewardXp: 1400,
        questRewardGold: 480,
        mobs: [
            { name: 'Todesritter der Geißel', icon: '🗡️', hp: 820, dmg: 70, xp: 360, gold: 95, lv: 17, speed: 1.12 },
            { name: 'Frostwyrm-Brut', icon: '🐉', hp: 980, dmg: 82, xp: 430, gold: 115, lv: 19, speed: 1.15 },
            { name: 'Val\'kyr-Schattenwächter', icon: '🪽', hp: 1150, dmg: 92, xp: 510, gold: 135, lv: 20, speed: 1.2 },
        ],
        boss: {
            name: 'Der Lichkönig (Arthas)',
            quote: 'Frostgram hungert… Eure Seelen gehören der Geißel!',
            icon: '👑',
            hp: 5200,
            dmg: 145,
            xp: 2200,
            gold: 680,
            lv: 25
        }
    }
};

const WOW_RARITIES = [
    { key: 'common',    name: 'Gewöhnlich',   color: '#cbd5e1', badgeCls: 'text-slate-600 dark:text-slate-300 border-slate-300 dark:border-slate-700', mult: 1.0 },
    { key: 'uncommon',  name: 'Ungewöhnlich', color: '#22c55e', badgeCls: 'text-emerald-600 dark:text-emerald-400 border-emerald-400/60', mult: 1.3 },
    { key: 'rare',      name: 'Selten',       color: '#3b82f6', badgeCls: 'text-blue-600 dark:text-blue-400 border-blue-400/70', mult: 1.65 },
    { key: 'epic',      name: 'Episch',       color: '#a855f7', badgeCls: 'text-purple-600 dark:text-purple-400 border-purple-400/80', mult: 2.15 },
    { key: 'legendary', name: 'Legendär',     color: '#f97316', badgeCls: 'text-orange-500 dark:text-orange-400 border-orange-500', mult: 2.85 },
];

const WOW_SLOTS = {
    weapon:  { label: 'Waffe',      icon: '⚔️', names: ['Klinge des Kreuzfahrers', 'Zauberstab von Dalaran', 'Langbogen der Waldläufer', 'Aschenbringer', 'Sulfuras, Hand von Ragnaros', 'Frostgram-Runenklinge'] },
    armor:   { label: 'Brustpanzer', icon: '🛡️', names: ['Plattenrüstung der Allianz', 'Roben des Erzmagiers', 'Drachenschuppen-Harnisch', 'T2-Rüstung des Richturteils', 'Eiskronen-Prunkharnisch'] },
    ring:    { label: 'Ring',       icon: '💍', names: ['Siegelring von Sturmwind', 'Band des Kirin Tor', 'Feuerring des Kerns', 'Aschering des unendlichen Mutes'] },
    trinket: { label: 'Amulett',    icon: '📿', names: ['Talisman des Lichts', 'Auge von Sulfuras', 'Drachenzahn-Talisman', 'Willensstärke des Todesbringers'] },
};

let wowHero = {
    heroClass: 'paladin',
    level: 1,
    xp: 0,
    nextXp: 100,
    x: 110,
    y: 220,
    hp: 180,
    maxHp: 180,
    mp: 100,
    maxMp: 100,
    gold: 60,
    potions: 3,
    zone: 'elwynn',
    questKills: 0,
    questsCompleted: 0,
    bossKills: 0,
    buffUntil: 0,
    speedUntil: 0,
    equipped: {
        weapon:  { id: 'w0', slot: 'weapon',  name: 'Knappenschwert von Sturmwind', rarity: 'common', dmg: 5, hp: 10, crit: 2, gs: 12, price: 10 },
        armor:   { id: 'a0', slot: 'armor',   name: 'Wams der Bürgerwehr',          rarity: 'common', dmg: 2, hp: 25, crit: 1, gs: 12, price: 10 },
        ring:    null,
        trinket: null,
    },
    backpack: []
};

let wowKeysDown     = {};
let wowTouchDir     = { dx: 0, dy: 0 };
let wowCooldowns    = [0, 0, 0, 0, 0];
let wowMobs         = [];
let wowTargetId     = null;
let wowProjectiles  = [];
let wowFloatTexts   = [];
let wowVisualRings  = [];
let wowGroundHazards= [];
let wowScenery      = [];
let wowPeerHeroes   = [];
let wowMoveTarget   = null;
let wowAutoChaseMob = false;
let wowPendingSpell = null;
let wowLastFrameTs  = 0;
let wowLastAutoAtk  = 0;
let wowInitialized  = false;

function wowCalcStats() {
    const cls = WOW_CLASSES[wowHero.heroClass] || WOW_CLASSES.paladin;
    let bonusDmg  = 0;
    let bonusHp   = 0;
    let bonusCrit = 8;
    let gearScore = 0;

    for (const slotKey of ['weapon', 'armor', 'ring', 'trinket']) {
        const it = wowHero.equipped[slotKey];
        if (it) {
            bonusDmg  += Number(it.dmg) || 0;
            bonusHp   += Number(it.hp) || 0;
            bonusCrit += Number(it.crit) || 0;
            gearScore += Number(it.gs) || 0;
        }
    }

    const lv = wowHero.level;
    const maxHp = Math.round(cls.baseHp + (lv - 1) * 34 + bonusHp);
    const maxMp = Math.round(cls.baseMp + (lv - 1) * 14);
    let dmg     = Math.round(cls.baseDmg + (lv - 1) * 5.5 + bonusDmg);
    let critPct = Math.min(65, Math.round(bonusCrit + lv * 0.6));

    if (performance.now() < wowHero.buffUntil) {
        dmg     = Math.round(dmg * 1.55);
        critPct = Math.min(85, critPct + 25);
    }

    wowHero.maxHp     = maxHp;
    wowHero.maxMp     = maxMp;
    wowHero.hp        = Math.min(wowHero.hp, maxHp);
    wowHero.mp        = Math.min(wowHero.mp, maxMp);
    wowHero.gearScore = gearScore;
    wowHero.totalDmg  = dmg;
    wowHero.critPct   = critPct;
    wowHero.nextXp    = Math.round(100 * Math.pow(1.28, lv - 1));
}

function wowGetPowerScore() {
    wowCalcStats();
    return Math.round(wowHero.level * 150 + (wowHero.gearScore || 0) * 4 + (wowHero.questsCompleted || 0) * 80 + (wowHero.bossKills || 0) * 120);
}

function wowInit() {
    if (SAVED_GAME_STATES['wow']) {
        try {
            const saved = JSON.parse(SAVED_GAME_STATES['wow']);
            if (saved && typeof saved === 'object' && saved.level) {
                wowHero = Object.assign(wowHero, saved);
                if (!WOW_CLASSES[wowHero.heroClass]) wowHero.heroClass = 'paladin';
                if (!WOW_ZONES[wowHero.zone]) wowHero.zone = 'elwynn';
                if (!Array.isArray(wowHero.backpack)) wowHero.backpack = [];
            }
        } catch (e) {}
    }

    const clsSel = document.getElementById('wowClassSelect');
    if (clsSel) clsSel.value = wowHero.heroClass;
    const znSel = document.getElementById('wowZoneSelect');
    if (znSel) znSel.value = wowHero.zone;

    wowCalcStats();
    wowHero.hp = wowHero.maxHp;
    wowHero.mp = wowHero.maxMp;
    wowBuildZone(wowHero.zone, false);
    wowRenderActionBar();
    wowRenderUI();

    const cv = document.getElementById('wowCanvas');
    if (cv && !wowInitialized) {
        wowInitialized = true;
        cv.addEventListener('pointerdown', (ev) => {
            const rect = cv.getBoundingClientRect();
            const scaleX = cv.width / rect.width;
            const scaleY = cv.height / rect.height;
            const cx = (ev.clientX - rect.left) * scaleX;
            const cy = (ev.clientY - rect.top) * scaleY;

            // Klick auf Questgeber im Lager?
            if (Math.hypot(cx - 88, cy - 125) < 34) {
                wowAddFloatText(88, 95, '📜 „Besiegt die Feinde im Osten!“', '#fbbf24', true);
                return;
            }

            // Klick auf ein Monster -> ins Visier nehmen & automatisch hinlaufen/angreifen!
            let bestMob = null;
            let bestDist = 48;
            for (const m of wowMobs) {
                if (m.dead) continue;
                const d = Math.hypot(cx - m.x, cy - m.y);
                if (d < bestDist) {
                    bestDist = d;
                    bestMob = m;
                }
            }
            if (bestMob) {
                wowTargetId = bestMob.id;
                wowAutoChaseMob = true;
                wowMoveTarget = null;
                wowVisualRings.push({ x: bestMob.x, y: bestMob.y, r: 8, maxR: 28, color: '#ef4444', alpha: 0.9 });
            } else {
                // Klick auf freien Boden -> Held läuft automatisch dorthin (Click-to-Move)!
                wowMoveTarget = { x: Math.min(735, Math.max(25, cx)), y: Math.min(415, Math.max(30, cy)) };
                wowAutoChaseMob = false;
                wowVisualRings.push({ x: wowMoveTarget.x, y: wowMoveTarget.y, r: 4, maxR: 20, color: '#4ade80', alpha: 0.85 });
            }
        });
        wowDrawWorld(performance.now());
        requestAnimationFrame(wowGameLoop);
    }
}

function wowSaveHeroProgress(submitHighscore = false) {
    wowCalcStats();
    const stateToSave = {
        heroClass: wowHero.heroClass,
        level: wowHero.level,
        xp: wowHero.xp,
        x: Math.round(wowHero.x),
        y: Math.round(wowHero.y),
        hp: Math.round(wowHero.hp),
        maxHp: wowHero.maxHp,
        gold: wowHero.gold,
        potions: wowHero.potions,
        zone: wowHero.zone,
        questKills: wowHero.questKills,
        questsCompleted: wowHero.questsCompleted,
        bossKills: wowHero.bossKills,
        gearScore: wowHero.gearScore,
        equipped: wowHero.equipped,
        backpack: wowHero.backpack.slice(0, 12),
    };
    saveCloudGameState('wow', stateToSave);

    if (submitHighscore) {
        const clsName = (WOW_CLASSES[wowHero.heroClass] || WOW_CLASSES.paladin).name;
        const pScore  = wowGetPowerScore();
        submitCloudScore('wow', pScore, 0, `Lv.${wowHero.level} ${clsName} (GS ${wowHero.gearScore})`);
    }
}

async function wowSyncPeers() {
    if (activeGameKey !== 'wow') return;
    wowSaveHeroProgress(false);
    const fd = new FormData();
    fd.append('ajax', '1');
    fd.append('csrf_token', GAMES_CSRF);
    fd.append('action', 'wow_sync');
    try {
        const r = await fetch('games.php', { method: 'POST', body: fd });
        const d = await r.json();
        if (d && d.ok && Array.isArray(d.peers)) {
            wowPeerHeroes = d.peers;
        }
    } catch (e) {}
}

function wowChangeClass(newClass) {
    if (!WOW_CLASSES[newClass]) return;
    wowHero.heroClass = newClass;
    wowCalcStats();
    wowHero.hp = wowHero.maxHp;
    wowHero.mp = wowHero.maxMp;
    wowRenderActionBar();
    wowRenderUI();
    wowAddFloatText(wowHero.x, wowHero.y - 24, `${WOW_CLASSES[newClass].icon} Klasse: ${WOW_CLASSES[newClass].name}`, '#fbbf24', true);
    wowSaveHeroProgress(false);
}

function wowChangeZone(newZone) {
    if (!WOW_ZONES[newZone]) return;
    wowHero.zone = newZone;
    wowHero.x = 105;
    wowHero.y = 220;
    wowBuildZone(newZone, true);
    wowRenderUI();
    wowSaveHeroProgress(false);
}

function wowTeleportTown() {
    wowHero.x = 92;
    wowHero.y = 220;
    wowHero.hp = wowHero.maxHp;
    wowHero.mp = wowHero.maxMp;
    wowVisualRings.push({ x: wowHero.x, y: wowHero.y, r: 8, maxR: 58, color: '#38bdf8', alpha: 1 });
    wowAddFloatText(wowHero.x, wowHero.y - 25, '💎 Ruhestein: Vollständig geheilt!', '#38bdf8', true);
    wowRenderUI();
}

function wowBuildZone(zoneKey, announce = true) {
    const z = WOW_ZONES[zoneKey] || WOW_ZONES.elwynn;
    wowMobs = [];
    wowProjectiles = [];
    wowGroundHazards = [];
    wowScenery = [];

    // Dekorative Bäume / Felsen / Lava-Krater erzeugen
    const propIcon = zoneKey === 'elwynn' ? '🌲' : (zoneKey === 'duskwood' ? '🪦' : (zoneKey === 'moltencore' ? '🌋' : '🧊'));
    for (let i = 0; i < 14; i++) {
        wowScenery.push({
            x: 190 + (i * 43) % 540,
            y: 35 + ((i * 89) % 370),
            icon: propIcon
        });
    }

    // 7 normale Zonen-Gegner + 1 Zonen-/Raid-Boss
    for (let i = 0; i < 7; i++) {
        wowSpawnMob(i, false);
    }
    wowSpawnMob(99, true);

    wowTargetId = wowMobs[0]?.id || null;
    if (announce) {
        wowAddFloatText(380, 70, `🗺️ ${z.name}`, '#fbbf24', true);
    }
}

function wowSpawnMob(slotIdx, isBoss = false) {
    const z = WOW_ZONES[wowHero.zone] || WOW_ZONES.elwynn;
    if (isBoss) {
        const b = z.boss;
        wowMobs.push({
            id: 'boss_' + Date.now() + '_' + Math.random().toString(36).slice(2, 6),
            slotIdx: 99,
            isBoss: true,
            name: b.name,
            quote: b.quote,
            icon: b.icon,
            lv: b.lv,
            hp: b.hp,
            maxHp: b.hp,
            dmg: b.dmg,
            xp: b.xp,
            gold: b.gold,
            speed: 0.92,
            x: 665,
            y: 220,
            homeX: 665,
            homeY: 220,
            lastAtk: 0,
            lastHazard: performance.now() + 3000,
            slowUntil: 0,
            stunUntil: 0,
            enraged: false,
            dead: false,
            respawnAt: 0
        });
        return;
    }

    const tpl = z.mobs[slotIdx % z.mobs.length];
    const col = slotIdx % 3;
    const row = Math.floor(slotIdx / 3);
    const sx  = 255 + col * 125 + (Math.random() * 40 - 20);
    const sy  = 75 + row * 135 + (Math.random() * 40 - 20);

    wowMobs.push({
        id: 'mob_' + slotIdx + '_' + Date.now() + '_' + Math.random().toString(36).slice(2, 5),
        slotIdx,
        isBoss: false,
        name: tpl.name,
        icon: tpl.icon,
        lv: tpl.lv,
        hp: tpl.hp,
        maxHp: tpl.hp,
        dmg: tpl.dmg,
        xp: tpl.xp,
        gold: tpl.gold,
        speed: tpl.speed,
        x: Math.min(585, Math.max(220, sx)),
        y: Math.min(390, Math.max(55, sy)),
        homeX: sx,
        homeY: sy,
        lastAtk: 0,
        slowUntil: 0,
        stunUntil: 0,
        dead: false,
        respawnAt: 0
    });
}

function wowRenderActionBar() {
    const box = document.getElementById('wowActionBarSlots');
    if (!box) return;
    const cls = WOW_CLASSES[wowHero.heroClass] || WOW_CLASSES.paladin;
    box.innerHTML = cls.spells.map((sp, idx) => `
        <button type="button"
                onclick="wowCastSpell(${idx})"
                id="wowSpellBtn-${idx}"
                title="${sp.name} (${sp.mp > 0 ? sp.mp + ' Mana · ' : ''}${sp.cd}s Abklingzeit): ${sp.desc}"
                class="relative px-2.5 sm:px-3 py-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 border border-amber-400/50 text-white text-xs flex items-center gap-1.5 cursor-pointer transition-transform active:scale-95">
            <span class="text-base sm:text-lg">${sp.icon}</span>
            <div class="text-left leading-tight">
                <div class="text-[10px] text-amber-300 font-bold">[${sp.key}] ${sp.name}</div>
                <div id="wowSpellCd-${idx}" class="text-[10px] text-slate-300">${sp.mp > 0 ? sp.mp + ' MP' : 'Sofort'}</div>
            </div>
        </button>
    `).join('');
}

function wowCycleTarget() {
    const alive = wowMobs.filter((m) => !m.dead);
    if (!alive.length) {
        wowTargetId = null;
        return;
    }
    alive.sort((a, b) => Math.hypot(a.x - wowHero.x, a.y - wowHero.y) - Math.hypot(b.x - wowHero.x, b.y - wowHero.y));
    const curIdx = alive.findIndex((m) => m.id === wowTargetId);
    const next   = alive[(curIdx + 1) % alive.length];
    wowTargetId  = next.id;
}

function wowGetActiveTarget() {
    let t = wowMobs.find((m) => !m.dead && m.id === wowTargetId);
    if (t) return t;
    const alive = wowMobs.filter((m) => !m.dead);
    if (!alive.length) return null;
    alive.sort((a, b) => Math.hypot(a.x - wowHero.x, a.y - wowHero.y) - Math.hypot(b.x - wowHero.x, b.y - wowHero.y));
    wowTargetId = alive[0].id;
    return alive[0];
}

function wowCastSpell(spellIdx) {
    const now = performance.now();
    const cls = WOW_CLASSES[wowHero.heroClass] || WOW_CLASSES.paladin;
    const sp  = cls.spells[spellIdx];
    if (!sp) return;

    if (now < wowCooldowns[spellIdx]) {
        return;
    }
    if (wowHero.mp < sp.mp) {
        wowAddFloatText(wowHero.x, wowHero.y - 20, '💧 Nicht genug Mana!', '#60a5fa', false);
        return;
    }

    wowCalcStats();
    const target = wowGetActiveTarget();

    // Zauber 4: Heilung / Blinzeln (braucht kein Gegner-Ziel)
    if (spellIdx === 3) {
        wowHero.mp -= sp.mp;
        wowCooldowns[spellIdx] = now + sp.cd * 1000;
        const healAmt = Math.round(wowHero.maxHp * 0.38);
        wowHero.hp = Math.min(wowHero.maxHp, wowHero.hp + healAmt);
        if (wowHero.heroClass === 'mage') {
            wowHero.x = Math.min(720, Math.max(40, wowHero.x + 75));
        } else if (wowHero.heroClass === 'ranger') {
            wowHero.speedUntil = now + 4500;
        }
        wowVisualRings.push({ x: wowHero.x, y: wowHero.y, r: 10, maxR: 52, color: '#22c55e', alpha: 1 });
        wowAddFloatText(wowHero.x, wowHero.y - 25, `+${healAmt} LP (${sp.name})`, '#4ade80', true);
        wowRenderUI();
        return;
    }

    // Zauber 5: Ultimativer Klassen-Buff / Pyroschlag
    if (spellIdx === 4) {
        wowHero.mp -= sp.mp;
        wowCooldowns[spellIdx] = now + sp.cd * 1000;
        wowHero.buffUntil = now + 6500;
        wowVisualRings.push({ x: wowHero.x, y: wowHero.y, r: 12, maxR: 72, color: '#f59e0b', alpha: 1 });
        wowAddFloatText(wowHero.x, wowHero.y - 28, `${sp.icon} ${sp.name} AKTIV!`, '#f59e0b', true);
        if (wowHero.heroClass === 'mage' && target) {
            wowSpawnProjectile(wowHero.x, wowHero.y, target, wowHero.totalDmg * 2.5, '#f97316', '🌋', true);
        }
        wowRenderUI();
        return;
    }

    // Zauber 2: AoE (Göttlicher Sturm / Frostnova / Mehrfachschuss)
    if (spellIdx === 1) {
        wowHero.mp -= sp.mp;
        wowCooldowns[spellIdx] = now + sp.cd * 1000;
        const aoeRadius = wowHero.heroClass === 'ranger' ? 230 : 115;
        const ringColor = wowHero.heroClass === 'mage' ? '#38bdf8' : (wowHero.heroClass === 'paladin' ? '#fbbf24' : '#22c55e');
        wowVisualRings.push({ x: wowHero.x, y: wowHero.y, r: 14, maxR: aoeRadius, color: ringColor, alpha: 1 });

        let hits = 0;
        for (const m of wowMobs) {
            if (m.dead) continue;
            if (Math.hypot(m.x - wowHero.x, m.y - wowHero.y) <= aoeRadius) {
                if (wowHero.heroClass === 'mage') {
                    m.slowUntil = now + 4500;
                }
                wowDealDamageToMob(m, wowHero.totalDmg * 1.35, sp.name);
                hits++;
                if (wowHero.heroClass === 'ranger' && hits >= 5) break;
            }
        }
        if (hits === 0) {
            wowAddFloatText(wowHero.x, wowHero.y - 20, `${sp.icon} ${sp.name}`, ringColor, false);
        }
        return;
    }

    if (!target) {
        wowAddFloatText(wowHero.x, wowHero.y - 20, 'Kein Ziel in Sicht!', '#94a3b8', false);
        return;
    }

    const dist = Math.hypot(target.x - wowHero.x, target.y - wowHero.y);
    const maxRange = spellIdx === 2 ? 250 : cls.range;
    if (dist > maxRange + 25) {
        wowAutoChaseMob = true;
        wowPendingSpell = spellIdx;
        wowMoveTarget   = null;
        wowAddFloatText(wowHero.x, wowHero.y - 20, '🏃 Laufe zum Ziel…', '#fbbf24', false);
        return;
    }

    wowHero.mp = Math.min(wowHero.maxMp, wowHero.mp - sp.mp + (spellIdx === 0 ? 10 : 0));
    wowCooldowns[spellIdx] = now + sp.cd * 1000;

    if (spellIdx === 0) {
        // Basisangriff (Kreuzfahrerstoß / Feuerball / Gezielter Schuss)
        if (wowHero.heroClass === 'paladin') {
            wowVisualRings.push({ x: target.x, y: target.y, r: 6, maxR: 32, color: '#fbbf24', alpha: 1 });
            wowDealDamageToMob(target, wowHero.totalDmg, sp.name);
        } else {
            const pIcon = wowHero.heroClass === 'mage' ? '🔥' : '🏹';
            const pCol  = wowHero.heroClass === 'mage' ? '#fb923c' : '#4ade80';
            wowSpawnProjectile(wowHero.x, wowHero.y, target, wowHero.totalDmg, pCol, pIcon, false);
        }
    } else if (spellIdx === 2) {
        // Zauber 3: Richturteil / Flammenstoß / Schlangenbiss
        if (wowHero.heroClass === 'paladin') {
            target.stunUntil = now + 2200;
            wowVisualRings.push({ x: target.x, y: target.y, r: 8, maxR: 48, color: '#facc15', alpha: 1 });
            wowDealDamageToMob(target, wowHero.totalDmg * 1.65, '⚡ Richturteil');
        } else if (wowHero.heroClass === 'mage') {
            wowVisualRings.push({ x: target.x, y: target.y, r: 10, maxR: 85, color: '#ef4444', alpha: 1 });
            for (const m of wowMobs) {
                if (!m.dead && Math.hypot(m.x - target.x, m.y - target.y) <= 85) {
                    wowDealDamageToMob(m, wowHero.totalDmg * 1.6, '☄️ Flammenstoß');
                }
            }
        } else {
            target.slowUntil = now + 5000;
            wowSpawnProjectile(wowHero.x, wowHero.y, target, wowHero.totalDmg * 1.7, '#22c55e', '🐍', false);
        }
    }
}

function wowSpawnProjectile(sx, sy, targetMob, dmg, color, icon, forceCrit) {
    wowProjectiles.push({
        x: sx,
        y: sy,
        targetId: targetMob.id,
        tx: targetMob.x,
        ty: targetMob.y,
        dmg,
        color,
        icon,
        forceCrit: !!forceCrit
    });
}

function wowDealDamageToMob(mob, rawDmg, abilityLabel = '', forceCrit = false) {
    if (!mob || mob.dead) return;
    const isCrit = forceCrit || (Math.random() * 100 < (wowHero.critPct || 12));
    const finalDmg = Math.max(1, Math.round(rawDmg * (isCrit ? 1.95 : (0.9 + Math.random() * 0.2))));
    mob.hp -= finalDmg;

    if (mob.isBoss && !mob.enraged && mob.hp > 0 && mob.hp < mob.maxHp * 0.45) {
        mob.enraged = true;
        wowAddFloatText(mob.x, mob.y - 38, `💢 ${mob.quote}`, '#ef4444', true);
    }

    const txt = isCrit ? `💥 ${finalDmg}!` : `-${finalDmg}`;
    const col = isCrit ? '#f97316' : '#f8fafc';
    wowAddFloatText(mob.x + (Math.random() * 24 - 12), mob.y - 18, txt, col, isCrit);

    if (mob.hp <= 0) {
        mob.hp   = 0;
        mob.dead = true;
        mob.respawnAt = performance.now() + (mob.isBoss ? 14000 : 5500);
        wowOnMobKilled(mob);
    }
}

function wowOnMobKilled(mob) {
    wowHero.xp   += mob.xp;
    wowHero.gold += mob.gold;
    wowHero.questKills++;

    if (mob.isBoss) {
        wowHero.bossKills = (wowHero.bossKills || 0) + 1;
        wowHero.questKills += 2;
        wowAddFloatText(mob.x, mob.y - 34, `🏆 BOSS BESIEGT! +${mob.xp} EP · +${mob.gold} Gold`, '#fbbf24', true);
    } else {
        wowAddFloatText(mob.x, mob.y - 26, `+${mob.xp} EP  +${mob.gold}🪙`, '#c084fc', false);
    }

    // Loot-Drop-Chance (Bosse droppen immer, normale Mobs zu 45 %)
    if (mob.isBoss || Math.random() < 0.45) {
        const drop = wowGenerateLootItem(mob.lv, mob.isBoss);
        wowAddLootToBag(drop, mob.x, mob.y);
    }

    // Quest-Abschluss prüfen
    const z = WOW_ZONES[wowHero.zone] || WOW_ZONES.elwynn;
    if (wowHero.questKills >= z.questTargetKills) {
        wowHero.questKills = 0;
        wowHero.questsCompleted = (wowHero.questsCompleted || 0) + 1;
        wowHero.xp   += z.questRewardXp;
        wowHero.gold += z.questRewardGold;
        wowHero.potions = Math.min(15, wowHero.potions + 1);
        wowAddFloatText(wowHero.x, wowHero.y - 40, `📜 QUEST ERLEDIGT! +${z.questRewardXp} EP & +${z.questRewardGold}🪙`, '#fde047', true);
    }

    // Level-Up prüfen
    let leveledUp = false;
    while (wowHero.xp >= wowHero.nextXp) {
        wowHero.xp -= wowHero.nextXp;
        wowHero.level++;
        leveledUp = true;
        wowCalcStats();
        wowHero.hp = wowHero.maxHp;
        wowHero.mp = wowHero.maxMp;
        wowVisualRings.push({ x: wowHero.x, y: wowHero.y, r: 10, maxR: 85, color: '#fde047', alpha: 1 });
        wowAddFloatText(wowHero.x, wowHero.y - 45, `🌟 LEVEL UP! Stufe ${wowHero.level} erreicht!`, '#fde047', true);
    }

    wowRenderUI();
    wowSaveHeroProgress(leveledUp || mob.isBoss);
}

function wowGenerateLootItem(itemLv, isBossDrop = false) {
    const slotKeys = ['weapon', 'armor', 'ring', 'trinket'];
    const slotKey  = slotKeys[Math.floor(Math.random() * slotKeys.length)];
    const slotMeta = WOW_SLOTS[slotKey];

    let rIdx = 0;
    const roll = Math.random() + (isBossDrop ? 0.38 : 0);
    if (roll > 1.22)      rIdx = 4; // Legendär
    else if (roll > 0.96) rIdx = 3; // Episch
    else if (roll > 0.68) rIdx = 2; // Selten
    else if (roll > 0.35) rIdx = 1; // Ungewöhnlich

    const rar  = WOW_RARITIES[rIdx];
    const base = Math.max(1, itemLv) * rar.mult;
    const dmg  = Math.max(2, Math.round(base * (slotKey === 'weapon' ? 3.4 : 1.6)));
    const hp   = Math.max(8, Math.round(base * (slotKey === 'armor' ? 14 : 7)));
    const crit = Math.max(1, Math.round(rar.mult * 2.2));
    const gs   = Math.round(dmg * 2.2 + hp * 0.4 + crit * 3);
    const name = slotMeta.names[Math.floor(Math.random() * slotMeta.names.length)];

    return {
        id: 'it_' + Date.now() + '_' + Math.random().toString(36).slice(2, 6),
        slot: slotKey,
        name,
        rarity: rar.key,
        dmg,
        hp,
        crit,
        gs,
        price: Math.max(12, Math.round(gs * 0.9))
    };
}

function wowAddLootToBag(item, fxX, fxY) {
    const rar = WOW_RARITIES.find((r) => r.key === item.rarity) || WOW_RARITIES[0];
    // Wenn der Slot noch leer ist -> sofort automatisch anlegen!
    if (!wowHero.equipped[item.slot]) {
        wowHero.equipped[item.slot] = item;
        wowCalcStats();
        wowAddFloatText(fxX, fxY - 12, `🎁 Angelegt: [${item.name}]`, rar.color, true);
        return;
    }
    if (wowHero.backpack.length < 12) {
        wowHero.backpack.unshift(item);
        wowAddFloatText(fxX, fxY - 12, `🎒 Beute: [${item.name}]`, rar.color, item.rarity !== 'common');
    } else {
        wowHero.gold += item.price;
        wowAddFloatText(fxX, fxY - 12, `🪙 Rucksack voll (+${item.price} Gold)`, '#fbbf24', false);
    }
}

function wowEquipBagItem(idx) {
    const item = wowHero.backpack[idx];
    if (!item) return;
    const prev = wowHero.equipped[item.slot] || null;
    wowHero.equipped[item.slot] = item;
    wowHero.backpack.splice(idx, 1);
    if (prev) {
        wowHero.backpack.push(prev);
    }
    wowCalcStats();
    wowRenderUI();
    wowSaveHeroProgress(true);
}

function wowSellAllBackpack() {
    if (!wowHero.backpack.length) return;
    let sum = 0;
    for (const it of wowHero.backpack) {
        sum += Number(it.price) || 10;
    }
    wowHero.backpack = [];
    wowHero.gold += sum;
    wowAddFloatText(wowHero.x, wowHero.y - 24, `🪙 Beute für +${sum} Gold verkauft!`, '#fbbf24', true);
    wowRenderUI();
    wowSaveHeroProgress(false);
}

function wowBuyPotion() {
    if (wowHero.gold < 25) {
        wowAddFloatText(wowHero.x, wowHero.y - 20, 'Nicht genug Gold (25 🪙 benötigt)!', '#f87171', false);
        return;
    }
    wowHero.gold -= 25;
    wowHero.potions++;
    wowAddFloatText(wowHero.x, wowHero.y - 20, '🧪 +1 Heiltrank gekauft!', '#4ade80', false);
    wowRenderUI();
    wowSaveHeroProgress(false);
}

function wowGambleMysteryItem() {
    if (wowHero.gold < 80) {
        wowAddFloatText(wowHero.x, wowHero.y - 20, 'Nicht genug Gold (80 🪙 benötigt)!', '#f87171', false);
        return;
    }
    wowHero.gold -= 80;
    const item = wowGenerateLootItem(wowHero.level + 1, true);
    wowAddLootToBag(item, wowHero.x, wowHero.y - 10);
    wowRenderUI();
    wowSaveHeroProgress(true);
}

function wowUsePotion() {
    if (wowHero.potions <= 0) {
        wowAddFloatText(wowHero.x, wowHero.y - 20, '🧪 Keine Heiltränke mehr!', '#f87171', false);
        return;
    }
    if (wowHero.hp >= wowHero.maxHp && wowHero.mp >= wowHero.maxMp) {
        wowAddFloatText(wowHero.x, wowHero.y - 20, 'LP & Mana sind bereits voll!', '#4ade80', false);
        return;
    }
    wowHero.potions--;
    const healHp = Math.round(wowHero.maxHp * 0.48);
    const healMp = Math.round(wowHero.maxMp * 0.45);
    wowHero.hp = Math.min(wowHero.maxHp, wowHero.hp + healHp);
    wowHero.mp = Math.min(wowHero.maxMp, wowHero.mp + healMp);
    wowVisualRings.push({ x: wowHero.x, y: wowHero.y, r: 8, maxR: 46, color: '#4ade80', alpha: 1 });
    wowAddFloatText(wowHero.x, wowHero.y - 24, `🧪 +${healHp} LP / +${healMp} MP`, '#4ade80', true);
    wowRenderUI();
}

function wowTouchMove(dx, dy) {
    wowTouchDir = { dx, dy };
}

function wowAddFloatText(x, y, text, color = '#ffffff', big = false) {
    wowFloatTexts.push({ x, y, text, color, big, alpha: 1.0, vy: big ? -0.95 : -0.7 });
}

function wowRenderUI() {
    wowCalcStats();
    const lvEl  = document.getElementById('wowHeroLevelBadge');
    const gsEl  = document.getElementById('wowGearScoreBadge');
    const dmgEl = document.getElementById('wowDamageBadge');
    const gldEl = document.getElementById('wowGoldBadge');
    const potEl = document.getElementById('wowPotionsBadge');
    const potBtn= document.getElementById('wowPotionBtnCount');

    if (lvEl)  lvEl.textContent  = `Lv. ${wowHero.level}`;
    if (gsEl)  gsEl.textContent  = String(wowHero.gearScore || 0);
    if (dmgEl) dmgEl.textContent = `${wowHero.totalDmg} (${wowHero.critPct}%)`;
    if (gldEl) gldEl.textContent = String(wowHero.gold);
    if (potEl) potEl.textContent = String(wowHero.potions);
    if (potBtn) potBtn.textContent = `${wowHero.potions}×`;

    // Quest-Box
    const z = WOW_ZONES[wowHero.zone] || WOW_ZONES.elwynn;
    const qBox = document.getElementById('wowQuestListBox');
    const qDone= document.getElementById('wowCompletedQuestsBadge');
    if (qDone) qDone.textContent = `${wowHero.questsCompleted || 0} erledigt`;
    if (qBox) {
        const pct = Math.min(100, Math.round((wowHero.questKills / z.questTargetKills) * 100));
        qBox.innerHTML = `
            <div class="p-2.5 rounded-xl bg-white dark:bg-slate-900 border border-amber-200 dark:border-amber-800/70 space-y-1.5">
                <div class="flex items-center justify-between font-bold text-slate-900 dark:text-white">
                    <span>${z.npcIcon} ${z.questTitle}</span>
                    <span class="text-amber-600 dark:text-amber-400">${wowHero.questKills} / ${z.questTargetKills}</span>
                </div>
                <div class="w-full h-2 rounded-full bg-slate-200 dark:bg-slate-800 overflow-hidden">
                    <div class="h-full bg-amber-500 transition-all" style="width:${pct}%"></div>
                </div>
                <div class="text-[11px] text-slate-500 dark:text-slate-400">
                    Belohnung: <strong>+${z.questRewardXp} EP</strong>, <strong>+${z.questRewardGold} 🪙 Gold</strong> &amp; <strong>+1 🧪 Heiltrank</strong>
                </div>
            </div>
        `;
    }

    // 4 Ausrüstungs-Slots
    const eqGrid = document.getElementById('wowEquippedGrid');
    if (eqGrid) {
        eqGrid.innerHTML = Object.entries(WOW_SLOTS).map(([slotKey, meta]) => {
            const it = wowHero.equipped[slotKey];
            if (!it) {
                return `
                    <div class="p-2 rounded-xl border border-dashed border-slate-300 dark:border-slate-700 text-slate-400 flex flex-col justify-between min-h-[68px]">
                        <div class="text-[10px] font-bold">${meta.icon} ${meta.label}</div>
                        <div class="text-[11px] italic">Leer</div>
                    </div>
                `;
            }
            const rar = WOW_RARITIES.find((r) => r.key === it.rarity) || WOW_RARITIES[0];
            return `
                <div class="p-2 rounded-xl bg-white dark:bg-slate-900 border ${rar.badgeCls} flex flex-col justify-between min-h-[68px]">
                    <div class="flex items-center justify-between text-[10px] font-bold">
                        <span>${meta.icon} ${meta.label}</span>
                        <span>GS ${it.gs}</span>
                    </div>
                    <div class="font-bold text-[11px] leading-tight truncate my-0.5" title="${it.name}">${it.name}</div>
                    <div class="text-[10px] text-slate-500 dark:text-slate-400">+${it.dmg} DMG · +${it.hp} LP</div>
                </div>
            `;
        }).join('');
    }

    // Rucksack
    const bagGrid = document.getElementById('wowBackpackGrid');
    const bagLbl  = document.getElementById('wowBagCountLabel');
    if (bagLbl) bagLbl.textContent = `${wowHero.backpack.length} / 12 Plätze`;
    if (bagGrid) {
        if (wowHero.backpack.length === 0) {
            bagGrid.innerHTML = '<div class="col-span-2 text-slate-400 italic py-2 text-center">Noch keine Beute im Rucksack – besiege Gegner oder öffne eine Truhe!</div>';
        } else {
            bagGrid.innerHTML = wowHero.backpack.map((it, idx) => {
                const rar  = WOW_RARITIES.find((r) => r.key === it.rarity) || WOW_RARITIES[0];
                const meta = WOW_SLOTS[it.slot] || WOW_SLOTS.weapon;
                const curEq = wowHero.equipped[it.slot];
                const diffGs = it.gs - (curEq ? curEq.gs : 0);
                const diffBadge = diffGs > 0
                    ? `<span class="text-emerald-600 dark:text-emerald-400 font-bold">(+${diffGs} GS ▲)</span>`
                    : `<span class="text-slate-400">(${diffGs} GS)</span>`;
                return `
                    <button type="button" onclick="wowEquipBagItem(${idx})"
                            class="p-2 rounded-xl bg-white dark:bg-slate-900 hover:bg-slate-100 dark:hover:bg-slate-800 border ${rar.badgeCls} text-left flex items-center justify-between gap-2 cursor-pointer transition-colors">
                        <div class="min-w-0">
                            <div class="font-bold text-[11px] truncate">${meta.icon} ${it.name}</div>
                            <div class="text-[10px] text-slate-500 dark:text-slate-400">+${it.dmg} DMG · +${it.hp} LP ${diffBadge}</div>
                        </div>
                        <span class="px-2 py-0.5 rounded bg-brand-600 text-white text-[10px] font-bold shrink-0">Anlegen</span>
                    </button>
                `;
            }).join('');
        }
    }
}

function wowGameLoop(now) {
    requestAnimationFrame(wowGameLoop);
    if (activeGameKey !== 'wow') {
        wowLastFrameTs = now;
        return;
    }

    const dt = Math.min(0.05, (now - (wowLastFrameTs || now)) / 1000);
    wowLastFrameTs = now;

    // 1. Helden-Bewegung (W A S D / Pfeiltasten / Touch / Click-to-Move / Auto-Ansturm)
    const cls = WOW_CLASSES[wowHero.heroClass] || WOW_CLASSES.paladin;
    const target = wowMobs.find((m) => !m.dead && m.id === wowTargetId);

    let mx = wowTouchDir.dx;
    let my = wowTouchDir.dy;
    if (wowKeysDown['ArrowLeft']  || wowKeysDown['a'] || wowKeysDown['A']) mx -= 1;
    if (wowKeysDown['ArrowRight'] || wowKeysDown['d'] || wowKeysDown['D']) mx += 1;
    if (wowKeysDown['ArrowUp']    || wowKeysDown['w'] || wowKeysDown['W']) my -= 1;
    if (wowKeysDown['ArrowDown']  || wowKeysDown['s'] || wowKeysDown['S']) my += 1;

    if (mx !== 0 || my !== 0) {
        wowMoveTarget   = null;
        wowAutoChaseMob = false;
        wowPendingSpell = null;
        const len = Math.hypot(mx, my) || 1;
        const spd = (now < wowHero.speedUntil ? 195 : 145) * dt;
        wowHero.x = Math.min(735, Math.max(25, wowHero.x + (mx / len) * spd));
        wowHero.y = Math.min(415, Math.max(30, wowHero.y + (my / len) * spd));
    } else if (wowAutoChaseMob && target) {
        const distToT = Math.hypot(target.x - wowHero.x, target.y - wowHero.y);
        const stopDist = Math.max(42, cls.range - 12);
        if (distToT > stopDist) {
            const spd = (now < wowHero.speedUntil ? 195 : 145) * dt;
            wowHero.x = Math.min(735, Math.max(25, wowHero.x + ((target.x - wowHero.x) / distToT) * spd));
            wowHero.y = Math.min(415, Math.max(30, wowHero.y + ((target.y - wowHero.y) / distToT) * spd));
        } else {
            wowAutoChaseMob = false;
            if (wowPendingSpell !== null) {
                const spToCast = wowPendingSpell;
                wowPendingSpell = null;
                wowCastSpell(spToCast);
            }
        }
    } else if (wowMoveTarget) {
        const distToM = Math.hypot(wowMoveTarget.x - wowHero.x, wowMoveTarget.y - wowHero.y);
        if (distToM > 6) {
            const spd = (now < wowHero.speedUntil ? 195 : 145) * dt;
            wowHero.x = Math.min(735, Math.max(25, wowHero.x + ((wowMoveTarget.x - wowHero.x) / distToM) * spd));
            wowHero.y = Math.min(415, Math.max(30, wowHero.y + ((wowMoveTarget.y - wowHero.y) / distToM) * spd));
        } else {
            wowMoveTarget = null;
        }
    }

    // 2. Heilbrunnen im Lager (links x < 165) regeneriert LP & Mana rasch
    const inSafeCamp = wowHero.x < 165;
    if (inSafeCamp) {
        wowHero.hp = Math.min(wowHero.maxHp, wowHero.hp + wowHero.maxHp * 0.14 * dt);
        wowHero.mp = Math.min(wowHero.maxMp, wowHero.mp + wowHero.maxMp * 0.22 * dt);
    } else {
        wowHero.mp = Math.min(wowHero.maxMp, wowHero.mp + wowHero.maxMp * 0.04 * dt);
    }

    // 3. Auto-Attacke auf das anvisierte Ziel, wenn in Reichweite
    if (target && !inSafeCamp && (now - wowLastAutoAtk) > 1350) {
        const dist = Math.hypot(target.x - wowHero.x, target.y - wowHero.y);
        if (dist <= cls.range) {
            wowLastAutoAtk = now;
            if (wowHero.heroClass === 'paladin') {
                wowDealDamageToMob(target, wowHero.totalDmg * 0.75, 'Auto');
            } else {
                const pIcon = wowHero.heroClass === 'mage' ? '✨' : '🏹';
                wowSpawnProjectile(wowHero.x, wowHero.y, target, wowHero.totalDmg * 0.75, cls.color, pIcon, false);
            }
        }
    }

    // 4. Monster-KI, Boss-Bodenzonen (AoE) & Respawns
    for (let i = 0; i < wowMobs.length; i++) {
        const m = wowMobs[i];
        if (m.dead) {
            if (now >= m.respawnAt) {
                const slot = m.slotIdx;
                const wasBoss = m.isBoss;
                wowMobs.splice(i, 1);
                wowSpawnMob(slot, wasBoss);
            }
            continue;
        }

        if (now < m.stunUntil) continue;

        const distToHero = Math.hypot(wowHero.x - m.x, wowHero.y - m.y);
        const aggroRange = m.isBoss ? 225 : 145;

        if (!inSafeCamp && distToHero < aggroRange) {
            const slowMult = now < m.slowUntil ? 0.42 : 1.0;
            const moveSpd  = m.speed * 68 * slowMult * dt;
            if (distToHero > 28) {
                m.x += ((wowHero.x - m.x) / distToHero) * moveSpd;
                m.y += ((wowHero.y - m.y) / distToHero) * moveSpd;
            } else if (now - m.lastAtk > 1400) {
                m.lastAtk = now;
                let incoming = Math.round(m.dmg * (0.88 + Math.random() * 0.24));
                if (now < wowHero.buffUntil && wowHero.heroClass === 'paladin') {
                    incoming = Math.round(incoming * 0.5);
                }
                wowHero.hp -= incoming;
                wowAddFloatText(wowHero.x, wowHero.y - 18, `-${incoming} LP`, '#ef4444', false);
                if (wowHero.hp <= 0) {
                    // Wiederbelebung beim Geistheiler im Lager
                    wowHero.hp = wowHero.maxHp;
                    wowHero.mp = wowHero.maxMp;
                    wowHero.x  = 88;
                    wowHero.y  = 220;
                    wowAddFloatText(88, 185, '👼 Vom Geistheiler im Lager wiederbelebt!', '#38bdf8', true);
                }
            }

            // Boss-Spezialfähigkeit: Telegraphierte Feuer-/Frost-Bodenzone!
            if (m.isBoss && now > m.lastHazard) {
                m.lastHazard = now + 4800;
                wowGroundHazards.push({
                    x: wowHero.x,
                    y: wowHero.y,
                    r: 52,
                    detonateAt: now + 1750,
                    dmg: Math.round(m.dmg * 1.45),
                    color: wowHero.zone === 'icecrown' ? '#38bdf8' : '#ef4444'
                });
                wowAddFloatText(wowHero.x, wowHero.y - 32, '⚠️ BOSS-FLÄCHE! Weiche aus!', '#fde047', true);
            }
        } else {
            // Zurück zum Heimatpunkt patrouillieren
            const dh = Math.hypot(m.homeX - m.x, m.homeY - m.y);
            if (dh > 6) {
                m.x += ((m.homeX - m.x) / dh) * 40 * dt;
                m.y += ((m.homeY - m.y) / dh) * 40 * dt;
            }
        }
    }

    // 5. Boss-Bodenzonen (AoE) auswerten
    for (let i = wowGroundHazards.length - 1; i >= 0; i--) {
        const hz = wowGroundHazards[i];
        if (now >= hz.detonateAt) {
            wowVisualRings.push({ x: hz.x, y: hz.y, r: 8, maxR: hz.r, color: hz.color, alpha: 1 });
            if (Math.hypot(wowHero.x - hz.x, wowHero.y - hz.y) <= hz.r) {
                wowHero.hp = Math.max(1, wowHero.hp - hz.dmg);
                wowAddFloatText(wowHero.x, wowHero.y - 24, `💥 -${hz.dmg} LP (Boss-Fläche!)`, '#ef4444', true);
            }
            wowGroundHazards.splice(i, 1);
        }
    }

    // 6. Projektile bewegen
    for (let i = wowProjectiles.length - 1; i >= 0; i--) {
        const p = wowProjectiles[i];
        const t = wowMobs.find((m) => !m.dead && m.id === p.targetId);
        if (t) {
            p.tx = t.x;
            p.ty = t.y;
        }
        const dist = Math.hypot(p.tx - p.x, p.ty - p.y);
        if (dist < 18) {
            if (t && !t.dead) {
                wowDealDamageToMob(t, p.dmg, '', p.forceCrit);
            }
            wowProjectiles.splice(i, 1);
        } else {
            p.x += ((p.tx - p.x) / dist) * 380 * dt;
            p.y += ((p.ty - p.y) / dist) * 380 * dt;
        }
    }

    // 7. Cooldown-Anzeige auf den Aktionsleisten-Buttons aktualisieren
    cls.spells.forEach((sp, idx) => {
        const cdEl  = document.getElementById('wowSpellCd-' + idx);
        const btnEl = document.getElementById('wowSpellBtn-' + idx);
        if (!cdEl || !btnEl) return;
        const rem = (wowCooldowns[idx] - now) / 1000;
        if (rem > 0.05) {
            cdEl.textContent = rem.toFixed(1) + 's';
            btnEl.style.opacity = '0.55';
        } else {
            cdEl.textContent = sp.mp > 0 ? sp.mp + ' MP' : 'Bereit';
            btnEl.style.opacity = '1';
        }
    });

    wowDrawWorld(now);
}

function wowDrawWorld(now) {
    const cv = document.getElementById('wowCanvas');
    if (!cv) return;
    const ctx = cv.getContext('2d');
    const W = cv.width;
    const H = cv.height;
    const z = WOW_ZONES[wowHero.zone] || WOW_ZONES.elwynn;

    // 1. Zonen-Hintergrund
    const grad = ctx.createLinearGradient(0, 0, W, H);
    grad.addColorStop(0, z.bgTop);
    grad.addColorStop(1, z.bgBot);
    ctx.fillStyle = grad;
    ctx.fillRect(0, 0, W, H);

    // 2. Sicheres Helden-Lager (links) mit Heilbrunnen & Questgeber
    ctx.fillStyle = z.campColor;
    ctx.beginPath();
    ctx.roundRect(12, 22, 152, H - 44, 18);
    ctx.fill();
    ctx.strokeStyle = 'rgba(251, 191, 36, 0.45)';
    ctx.lineWidth = 2;
    ctx.stroke();

    ctx.fillStyle = 'rgba(255,255,255,0.75)';
    ctx.font = 'bold 11px sans-serif';
    ctx.textAlign = 'center';
    ctx.fillText('🏕️ Sicheres Lager', 88, 44);

    // Questgeber mit gelbem "!"
    ctx.font = '24px sans-serif';
    ctx.fillText(z.npcIcon, 88, 130);
    ctx.fillStyle = '#fde047';
    ctx.font = 'bold 18px sans-serif';
    ctx.fillText('!', 88, 102);
    ctx.fillStyle = '#fef08a';
    ctx.font = 'bold 10px sans-serif';
    ctx.fillText(z.npcName, 88, 146);

    // Heilbrunnen
    ctx.beginPath();
    ctx.arc(88, 315, 24, 0, Math.PI * 2);
    ctx.fillStyle = 'rgba(56, 189, 248, 0.28)';
    ctx.fill();
    ctx.strokeStyle = '#38bdf8';
    ctx.stroke();
    ctx.font = '20px sans-serif';
    ctx.fillText('⛲', 88, 322);
    ctx.fillStyle = '#7dd3fc';
    ctx.font = 'bold 10px sans-serif';
    ctx.fillText('Heilbrunnen (+LP/MP)', 88, 352);

    // 3. Umgebungs-Dekoration
    ctx.font = '20px sans-serif';
    for (const sc of wowScenery) {
        ctx.fillText(sc.icon, sc.x, sc.y);
    }

    // 4. Boss-Bodenzonen (Telegraphierte AoE-Kreise)
    for (const hz of wowGroundHazards) {
        const pulse = 0.35 + 0.25 * Math.sin(now / 90);
        ctx.beginPath();
        ctx.arc(hz.x, hz.y, hz.r, 0, Math.PI * 2);
        ctx.fillStyle = hz.color === '#38bdf8' ? `rgba(56, 189, 248, ${pulse})` : `rgba(239, 68, 68, ${pulse})`;
        ctx.fill();
        ctx.strokeStyle = hz.color;
        ctx.lineWidth = 2.5;
        ctx.stroke();
    }

    // 5. Zauber-Ringe (AoE-Animationen)
    for (let i = wowVisualRings.length - 1; i >= 0; i--) {
        const vr = wowVisualRings[i];
        vr.r += (vr.maxR - vr.r) * 0.18;
        vr.alpha -= 0.045;
        if (vr.alpha <= 0) {
            wowVisualRings.splice(i, 1);
            continue;
        }
        ctx.save();
        ctx.globalAlpha = Math.max(0, vr.alpha);
        ctx.beginPath();
        ctx.arc(vr.x, vr.y, vr.r, 0, Math.PI * 2);
        ctx.strokeStyle = vr.color;
        ctx.lineWidth = 3.5;
        ctx.stroke();
        ctx.restore();
    }

    // 6. Andere aktive Cloud-Mitspieler in derselben Zone zeichnen
    for (const peer of wowPeerHeroes) {
        if (peer.zone !== wowHero.zone) continue;
        const pCls = WOW_CLASSES[peer.hero_class] || WOW_CLASSES.paladin;
        ctx.beginPath();
        ctx.arc(peer.x, peer.y, 16, 0, Math.PI * 2);
        ctx.fillStyle = 'rgba(59, 130, 246, 0.35)';
        ctx.fill();
        ctx.strokeStyle = '#60a5fa';
        ctx.lineWidth = 2;
        ctx.stroke();
        ctx.font = '19px sans-serif';
        ctx.fillText(pCls.icon, peer.x, peer.y + 6);
        ctx.fillStyle = '#93c5fd';
        ctx.font = 'bold 10px sans-serif';
        ctx.fillText(`👤 ${peer.username} (Lv.${peer.level})`, peer.x, peer.y - 22);
    }

    // 7. Monster & Zonen-Boss zeichnen
    for (const m of wowMobs) {
        if (m.dead) continue;
        const isTarget = m.id === wowTargetId;

        // Roter WoW-Zielring unter dem ausgewählten Gegner
        if (isTarget) {
            ctx.beginPath();
            ctx.ellipse(m.x, m.y + 12, m.isBoss ? 28 : 20, m.isBoss ? 14 : 10, 0, 0, Math.PI * 2);
            ctx.strokeStyle = '#ef4444';
            ctx.lineWidth = 2.5;
            ctx.stroke();
            ctx.fillStyle = 'rgba(239, 68, 68, 0.18)';
            ctx.fill();
        }

        // Gegner-Symbol
        ctx.font = m.isBoss ? '32px sans-serif' : '22px sans-serif';
        ctx.fillText(m.icon, m.x, m.y + 8);

        // Lebensbalken über dem Monster
        const bw = m.isBoss ? 74 : 46;
        const bh = m.isBoss ? 7 : 5;
        const bx = m.x - bw / 2;
        const by = m.y - (m.isBoss ? 32 : 22);
        ctx.fillStyle = 'rgba(15, 23, 42, 0.85)';
        ctx.fillRect(bx - 1, by - 1, bw + 2, bh + 2);
        ctx.fillStyle = m.isBoss ? '#f97316' : '#ef4444';
        ctx.fillRect(bx, by, bw * Math.max(0, m.hp / m.maxHp), bh);

        ctx.fillStyle = m.isBoss ? '#fde047' : '#e2e8f0';
        ctx.font = m.isBoss ? 'bold 11px sans-serif' : '10px sans-serif';
        ctx.fillText(`${m.isBoss ? '💀 ' : ''}${m.name} [${m.lv}]`, m.x, by - 4);
    }

    // 8. Eigenen Helden zeichnen
    const cls = WOW_CLASSES[wowHero.heroClass] || WOW_CLASSES.paladin;
    ctx.beginPath();
    ctx.arc(wowHero.x, wowHero.y, now < wowHero.buffUntil ? 22 : 18, 0, Math.PI * 2);
    ctx.fillStyle = now < wowHero.buffUntil ? 'rgba(251, 191, 36, 0.38)' : 'rgba(34, 197, 94, 0.28)';
    ctx.fill();
    ctx.strokeStyle = now < wowHero.buffUntil ? '#fde047' : '#4ade80';
    ctx.lineWidth = 2.5;
    ctx.stroke();

    ctx.font = '23px sans-serif';
    ctx.fillText(cls.icon, wowHero.x, wowHero.y + 8);
    ctx.fillStyle = '#ffffff';
    ctx.font = 'bold 11px sans-serif';
    ctx.fillText(`${CURRENT_USERNAME} (Lv.${wowHero.level})`, wowHero.x, wowHero.y - 24);

    // 9. Projektile zeichnen
    ctx.font = '16px sans-serif';
    for (const p of wowProjectiles) {
        ctx.fillText(p.icon, p.x, p.y);
    }

    // 10. Floating Combat Text (Schadenszahlen, Krit-Treffer, Heilung, Loot)
    for (let i = wowFloatTexts.length - 1; i >= 0; i--) {
        const ft = wowFloatTexts[i];
        ft.y += ft.vy;
        ft.alpha -= 0.018;
        if (ft.alpha <= 0) {
            wowFloatTexts.splice(i, 1);
            continue;
        }
        ctx.save();
        ctx.globalAlpha = Math.max(0, ft.alpha);
        ctx.font = ft.big ? 'bold 14px sans-serif' : 'bold 11px sans-serif';
        ctx.fillStyle = '#000000';
        ctx.fillText(ft.text, ft.x + 1, ft.y + 1);
        ctx.fillStyle = ft.color;
        ctx.fillText(ft.text, ft.x, ft.y);
        ctx.restore();
    }

    // 11. Klassisches WoW-HUD oben links (Spieler-Unit-Frame & Target-Unit-Frame)
    ctx.textAlign = 'left';
    ctx.fillStyle = 'rgba(15, 23, 42, 0.86)';
    ctx.beginPath();
    ctx.roundRect(14, 10, 210, 54, 10);
    ctx.fill();
    ctx.strokeStyle = '#f59e0b';
    ctx.lineWidth = 1.5;
    ctx.stroke();

    ctx.fillStyle = '#ffffff';
    ctx.font = 'bold 11px sans-serif';
    ctx.fillText(`${cls.icon} ${CURRENT_USERNAME} · Lv.${wowHero.level} ${cls.name}`, 22, 25);

    // LP-Balken
    ctx.fillStyle = '#1e293b';
    ctx.fillRect(22, 31, 194, 10);
    ctx.fillStyle = '#22c55e';
    ctx.fillRect(22, 31, 194 * Math.max(0, wowHero.hp / wowHero.maxHp), 10);
    ctx.fillStyle = '#ffffff';
    ctx.font = 'bold 9px sans-serif';
    ctx.fillText(`LP: ${Math.round(wowHero.hp)} / ${wowHero.maxHp}`, 28, 39);

    // Mana-Balken
    ctx.fillStyle = '#1e293b';
    ctx.fillRect(22, 44, 194, 8);
    ctx.fillStyle = '#3b82f6';
    ctx.fillRect(22, 44, 194 * Math.max(0, wowHero.mp / wowHero.maxMp), 8);
    ctx.fillStyle = '#e2e8f0';
    ctx.font = '8px sans-serif';
    ctx.fillText(`MP: ${Math.round(wowHero.mp)} / ${wowHero.maxMp}`, 28, 51);

    // Ziel-Unit-Frame (rechts neben dem Spieler-Frame)
    const activeTarget = wowMobs.find((m) => !m.dead && m.id === wowTargetId);
    if (activeTarget) {
        ctx.fillStyle = 'rgba(15, 23, 42, 0.86)';
        ctx.beginPath();
        ctx.roundRect(236, 10, 215, 46, 10);
        ctx.fill();
        ctx.strokeStyle = activeTarget.isBoss ? '#f97316' : '#ef4444';
        ctx.lineWidth = 1.5;
        ctx.stroke();

        ctx.fillStyle = activeTarget.isBoss ? '#fde047' : '#fca5a5';
        ctx.font = 'bold 11px sans-serif';
        ctx.fillText(`🎯 ${activeTarget.icon} ${activeTarget.name} (Lv.${activeTarget.lv})`, 244, 26);

        ctx.fillStyle = '#1e293b';
        ctx.fillRect(244, 33, 198, 12);
        ctx.fillStyle = activeTarget.isBoss ? '#f97316' : '#ef4444';
        ctx.fillRect(244, 33, 198 * Math.max(0, activeTarget.hp / activeTarget.maxHp), 12);
        ctx.fillStyle = '#ffffff';
        ctx.font = 'bold 9px sans-serif';
        ctx.fillText(`${activeTarget.hp} / ${activeTarget.maxHp} LP`, 250, 42);
    }

    // 12. WoW-Erfahrungsbalken (EP / XP-Bar) ganz unten am Bildschirmrand
    ctx.fillStyle = 'rgba(15, 23, 42, 0.9)';
    ctx.fillRect(0, H - 12, W, 12);
    ctx.fillStyle = '#9333ea';
    ctx.fillRect(0, H - 12, W * Math.min(1, wowHero.xp / Math.max(1, wowHero.nextXp)), 12);
    ctx.fillStyle = '#f3e8ff';
    ctx.font = 'bold 9px sans-serif';
    ctx.textAlign = 'center';
    ctx.fillText(`EP: ${wowHero.xp} / ${wowHero.nextXp} (${Math.round((wowHero.xp / Math.max(1, wowHero.nextXp)) * 100)} %) — ${z.name}`, W / 2, H - 3);
}

// =============================================================================
// SPIEL 1: GUMMIGERUCH KART-CUP & GHOST-HERAUSFORDERUNGEN
// =============================================================================
const GUMMI_TRACKS = {
    circuit: {
        id: 'circuit',
        name: 'GummiCircuit Klassik',
        grassTop: '#14532d',
        grassBot: '#166534',
        asphalt: '#272c38',
        curbColor: '#ef4444',
        roadWidth: 64,
        gripMod: 1.0,
        isDark: false,
        startX: 350,
        startY: 376,
        startAngle: 0,
        pts: [
            [260, 376], [520, 376], [655, 345], [685, 235],
            [610, 115], [465, 115], [390, 205], [285, 195],
            [220, 95],  [105, 115], [75, 235],  [115, 350]
        ]
    },
    coast: {
        id: 'coast',
        name: 'Küsten-Grand-Prix',
        grassTop: '#0f4c5c',
        grassBot: '#155e75',
        asphalt: '#2b303c',
        curbColor: '#06b6d4',
        roadWidth: 62,
        gripMod: 1.0,
        isDark: false,
        startX: 340,
        startY: 382,
        startAngle: 0,
        pts: [
            [240, 382], [560, 382], [680, 320], [670, 170],
            [555, 85],  [355, 85],  [280, 165], [440, 235],
            [245, 265], [140, 110], [75, 185],  [95, 345]
        ]
    },
    night: {
        id: 'night',
        name: 'Flutlicht Nacht-Ring',
        grassTop: '#0f172a',
        grassBot: '#1e1b4b',
        asphalt: '#1e2430',
        curbColor: '#ef4444',
        roadWidth: 60,
        gripMod: 0.96,
        isDark: true,
        startX: 350,
        startY: 384,
        startAngle: 0,
        pts: [
            [250, 384], [545, 384], [675, 335], [680, 115],
            [540, 75],  [455, 180], [365, 80],  [225, 80],
            [235, 235], [115, 140], [72, 245],  [110, 355]
        ]
    },
    alps: {
        id: 'alps',
        name: 'Alpen-Serpentinen',
        grassTop: '#1e293b',
        grassBot: '#0f392b',
        asphalt: '#334155',
        curbColor: '#0284c7',
        roadWidth: 52,
        gripMod: 0.94,
        isDark: false,
        startX: 350,
        startY: 396,
        startAngle: 0,
        pts: [
            [240, 396], [560, 396], [688, 355], [692, 240],
            [525, 230], [678, 140], [615, 66],  [445, 72],
            [425, 215], [320, 215], [305, 72],  [135, 72],
            [72, 145],  [225, 240], [72, 315],  [105, 388]
        ]
    },
    desert: {
        id: 'desert',
        name: 'Wüsten-Schikane',
        grassTop: '#78350f',
        grassBot: '#451a03',
        asphalt: '#292524',
        curbColor: '#f59e0b',
        roadWidth: 54,
        gripMod: 0.85,
        isDark: false,
        startX: 350,
        startY: 392,
        startAngle: 0,
        pts: [
            [235, 392], [535, 392], [680, 350], [695, 215],
            [585, 175], [675, 88],  [515, 65],  [430, 155],
            [355, 72],  [265, 165], [190, 72],  [82, 105],
            [72, 225],  [195, 285], [82, 345],  [125, 388]
        ]
    },
    volcano: {
        id: 'volcano',
        name: 'Vulkan-Höllenring',
        grassTop: '#2a0808',
        grassBot: '#450a0a',
        asphalt: '#18181b',
        curbColor: '#f97316',
        roadWidth: 48,
        gripMod: 0.90,
        isDark: true,
        startX: 350,
        startY: 398,
        startAngle: 0,
        pts: [
            [245, 398], [555, 398], [692, 360], [696, 260],
            [545, 250], [688, 165], [645, 65],  [485, 68],
            [465, 205], [375, 115], [295, 205], [265, 68],
            [105, 68],  [68, 165],  [215, 255], [72, 335],
            [115, 392]
        ]
    }
};

let gummiTrackKey         = 'circuit';
let gummiDenseTrack       = [];
let gummiSkids            = [];
let gummiSmoke            = [];
let gummiSparks           = [];
let gummiKeys             = {};
let gummiTouchState       = { left: false, right: false, gas: false, brake: false };
let gummiRaceState        = 'ready'; // 'ready' | 'countdown' | 'racing' | 'finished'
let gummiCountdownStart   = 0;
let gummiRaceStartTs      = 0;
let gummiLapStartTs       = 0;
let gummiElapsedMs        = 0;
let gummiBestLapMs        = 0;
let gummiMyRecording      = [];
let gummiLastRecordSample = 0;
let gummiRecordGhost      = null; // Schnellster Cloud-Champion auf dieser Strecke
let gummiActiveChallenge  = null; // Falls wir gerade ein Ghost-Duell gegen einen Herausforderer fahren
let gummiPendingOpponentId= 0;    // Falls wir einen anderen Cloud-Benutzer neu herausfordern
let gummiChallengesCache  = [];
let gummiLivePeers        = [];
let gummiBannerText       = '🚦 Drücke GAS (↑ oder W), um das 3-Runden-Rennen zu starten!';
let gummiLastFrameTs      = 0;
let gummiInitialized      = false;

let gummiKart = {
    x: 350,
    y: 376,
    vx: 0,
    vy: 0,
    angle: 0,
    steer: 0,
    wheelSpin: 0,
    braking: false,
    drifting: false,
    prevSkidL: null,
    prevSkidR: null,
    lap: 1,
    maxSectors: 4,
    sectorPassed: [true, false, false, false],
    boostReady: true,
    boostUntil: 0,
};

let gummiAiKarts = [];

function gummiFormatMs(ms) {
    ms = Math.max(0, Math.round(Number(ms) || 0));
    const min = Math.floor(ms / 60000);
    const sec = Math.floor((ms % 60000) / 1000);
    const hun = Math.floor((ms % 1000) / 10);
    return String(min).padStart(2, '0') + ':' + String(sec).padStart(2, '0') + '.' + String(hun).padStart(2, '0');
}

function gummiBuildDenseTrack(trackKey) {
    const tr = GUMMI_TRACKS[trackKey] || GUMMI_TRACKS.circuit;
    const pts = tr.pts;
    const n = pts.length;
    const dense = [];
    const stepsPerSeg = 14;

    // Catmull-Rom Spline für geschmeidige Kurven
    for (let i = 0; i < n; i++) {
        const p0 = pts[(i - 1 + n) % n];
        const p1 = pts[i];
        const p2 = pts[(i + 1) % n];
        const p3 = pts[(i + 2) % n];

        for (let s = 0; s < stepsPerSeg; s++) {
            const t = s / stepsPerSeg;
            const t2 = t * t;
            const t3 = t2 * t;
            const x = 0.5 * ((2 * p1[0]) + (-p0[0] + p2[0]) * t + (2 * p0[0] - 5 * p1[0] + 4 * p2[0] - p3[0]) * t2 + (-p0[0] + 3 * p1[0] - 3 * p2[0] + p3[0]) * t3);
            const y = 0.5 * ((2 * p1[1]) + (-p0[1] + p2[1]) * t + (2 * p0[1] - 5 * p1[1] + 4 * p2[1] - p3[1]) * t2 + (-p0[1] + 3 * p1[1] - 3 * p2[1] + p3[1]) * t3);
            dense.push([x, y]);
        }
    }
    gummiDenseTrack = dense;
}

function gummiInit() {
    gummiBuildDenseTrack(gummiTrackKey);
    gummiResetRace();
    gummiPollState();
    if (!gummiInitialized) {
        gummiInitialized = true;
        requestAnimationFrame(gummiGameLoop);
    }
}

function gummiChangeTrack(newTrack) {
    if (!GUMMI_TRACKS[newTrack]) return;
    gummiTrackKey = newTrack;
    gummiActiveChallenge = null;
    const badge = document.getElementById('gummiActiveMatchBadge');
    if (badge) badge.textContent = '';
    gummiBuildDenseTrack(newTrack);
    gummiResetRace();
    gummiPollState();
}

function gummiResetRace() {
    const tr = GUMMI_TRACKS[gummiTrackKey] || GUMMI_TRACKS.circuit;
    gummiKart = {
        x: tr.startX,
        y: tr.startY,
        vx: 0,
        vy: 0,
        angle: tr.startAngle,
        steer: 0,
        wheelSpin: 0,
        braking: false,
        drifting: false,
        prevSkidL: null,
        prevSkidR: null,
        lap: 1,
        sectorPassed: [true, false, false, false],
        boostReady: true,
        boostUntil: 0,
    };
    gummiSkids = [];
    gummiSmoke = [];
    gummiSparks = [];
    gummiMyRecording = [];
    gummiLastRecordSample = 0;
    gummiElapsedMs = 0;
    gummiBestLapMs = 0;
    gummiRaceState = 'ready';

    // 3 KI-Karts aufbauen (falls aktiviert)
    gummiAiKarts = [];
    const useAi = document.getElementById('gummiAiToggle')?.checked !== false;
    if (useAi && gummiDenseTrack.length > 10) {
        const aiConfigs = [
            { name: 'Roter Blitz',  color: '#ef4444', accent: '#fef08a', num: '7',  speed: 192, laneOffset: -14, startIdx: 2 },
            { name: 'Gelber Pfeil', color: '#eab308', accent: '#1e293b', num: '12', speed: 180, laneOffset: 14,  startIdx: 4 },
            { name: 'Grüner Gift',  color: '#10b981', accent: '#ecfdf5', num: '23', speed: 170, laneOffset: -6,  startIdx: 6 },
        ];
        for (const cfg of aiConfigs) {
            const p = gummiDenseTrack[cfg.startIdx];
            const pNext = gummiDenseTrack[(cfg.startIdx + 1) % gummiDenseTrack.length];
            gummiAiKarts.push({
                name: cfg.name,
                color: cfg.color,
                accent: cfg.accent,
                num: cfg.num,
                speed: cfg.speed,
                laneOffset: cfg.laneOffset,
                wpIdx: cfg.startIdx,
                x: p[0],
                y: p[1] + cfg.laneOffset * 0.5,
                angle: Math.atan2(pNext[1] - p[1], pNext[0] - p[0]),
                steer: 0,
                wheelSpin: 0,
                lap: 1
            });
        }
    }

    if (gummiActiveChallenge) {
        gummiBannerText = `👻 Ghost-Duell gegen ${gummiActiveChallenge.p1_name} (Zielzeit: ${gummiFormatMs(gummiActiveChallenge.p1_time_ms)}) – Gib Gas!`;
    } else if (gummiPendingOpponentId > 0) {
        gummiBannerText = '🏁 Herausforderungs-Fahrt bereit – Fahre 3 schnelle Runden für deinen Ghost!';
    } else {
        gummiBannerText = '🚦 Drücke GAS (↑ oder W) oder klicke auf „Rennen neu starten“!';
    }

    gummiUpdateHud();
    gummiDraw(performance.now());
}

function gummiStartCountdown() {
    gummiResetRace();
    gummiRaceState = 'countdown';
    gummiCountdownStart = performance.now();
}

function gummiStartChallengeRun() {
    const sel = document.getElementById('gummiChallengeOpponent');
    gummiPendingOpponentId = Number(sel?.value || 0);
    gummiActiveChallenge = null;
    const badge = document.getElementById('gummiActiveMatchBadge');
    if (badge) {
        badge.textContent = gummiPendingOpponentId > 0 ? '🎯 Vorlege-Fahrt aktiv' : '';
    }
    gummiStartCountdown();
}

function gummiAcceptChallenge(matchId) {
    const ch = gummiChallengesCache.find((c) => c.id === matchId);
    if (!ch) return;
    gummiActiveChallenge = ch;
    gummiPendingOpponentId = 0;
    if (ch.track_id && GUMMI_TRACKS[ch.track_id]) {
        gummiTrackKey = ch.track_id;
        const tSel = document.getElementById('gummiTrackSelect');
        if (tSel) tSel.value = gummiTrackKey;
        gummiBuildDenseTrack(gummiTrackKey);
    }
    const badge = document.getElementById('gummiActiveMatchBadge');
    if (badge) {
        badge.textContent = `⚔️ Duell gegen ${ch.p1_name} (${gummiFormatMs(ch.p1_time_ms)})`;
    }
    gummiStartCountdown();
}

function gummiTriggerBoost() {
    if (gummiRaceState !== 'racing' || !gummiKart.boostReady) return;
    gummiKart.boostReady = false;
    gummiKart.boostUntil = performance.now() + 1850;
    gummiKart.vx += Math.cos(gummiKart.angle) * 95;
    gummiKart.vy += Math.sin(gummiKart.angle) * 95;
    gummiUpdateHud();
}

function gummiTouch(ctrl, state) {
    gummiTouchState[ctrl] = state;
    if (ctrl === 'gas' && state && gummiRaceState === 'ready') {
        gummiStartCountdown();
    }
}

function gummiGetTrackDistAndSector(x, y) {
    let minDist = 99999;
    let bestIdx = 0;
    for (let i = 0; i < gummiDenseTrack.length; i++) {
        const d = Math.hypot(x - gummiDenseTrack[i][0], y - gummiDenseTrack[i][1]);
        if (d < minDist) {
            minDist = d;
            bestIdx = i;
        }
    }
    const ratio = bestIdx / Math.max(1, gummiDenseTrack.length);
    const sector = Math.min(3, Math.floor(ratio * 4));
    return { dist: minDist, sector, wpIdx: bestIdx };
}

function gummiUpdateHud() {
    const lapEl   = document.getElementById('gummiLapBadge');
    const timeEl  = document.getElementById('gummiTimeBadge');
    const bestEl  = document.getElementById('gummiBestLapBadge');
    const boostEl = document.getElementById('gummiBoostBadge');
    const spdEl   = document.getElementById('gummiSpeedBadge');

    if (lapEl)   lapEl.textContent  = `${Math.min(3, gummiKart.lap)} / 3`;
    if (timeEl)  timeEl.textContent = gummiFormatMs(gummiElapsedMs);
    if (bestEl)  bestEl.textContent = gummiBestLapMs > 0 ? gummiFormatMs(gummiBestLapMs) : '--:--.--';
    if (boostEl) {
        boostEl.textContent = gummiKart.boostReady ? '🔥 Bereit!' : 'Verbraucht';
        boostEl.className   = gummiKart.boostReady
            ? 'text-amber-600 dark:text-amber-400 font-extrabold'
            : 'text-slate-400 font-semibold';
    }
    if (spdEl) {
        const kmh = Math.round(Math.hypot(gummiKart.vx, gummiKart.vy) * 0.68);
        spdEl.textContent = `${kmh} km/h`;
    }
}

async function gummiSaveCompletedRun() {
    const fd = new FormData();
    fd.append('ajax', '1');
    fd.append('csrf_token', GAMES_CSRF);
    fd.append('action', 'gummi_save_run');
    fd.append('track_id', gummiTrackKey);
    fd.append('time_ms', String(Math.round(gummiElapsedMs)));
    fd.append('best_lap_ms', String(Math.round(gummiBestLapMs)));
    fd.append('ghost_json', JSON.stringify(gummiMyRecording));
    if (gummiActiveChallenge && gummiActiveChallenge.id) {
        fd.append('match_id', String(gummiActiveChallenge.id));
    } else if (gummiPendingOpponentId > 0) {
        fd.append('opponent_id', String(gummiPendingOpponentId));
    }

    try {
        const res = await fetch('games.php', { method: 'POST', body: fd });
        const d   = await res.json();
        if (d && d.ok) {
            totalPlaysCount++;
            const badge = document.getElementById('myTotalPlaysBadge');
            if (badge) badge.textContent = String(totalPlaysCount);

            if (d.personal_best) {
                myBestScores['gummigeruch'] = d.personal_best;
                const lbl = document.getElementById('myBestLabel-gummigeruch');
                if (lbl) lbl.textContent = '🏆 ' + Number(d.personal_best).toLocaleString('de-DE');
            }
            if (d.challenge_msg) {
                gummiBannerText = d.challenge_msg;
            }
            if (activeGameKey === 'gummigeruch' && Array.isArray(d.leaderboard)) {
                renderLeaderboardRows(d.leaderboard);
            }
            gummiActiveChallenge = null;
            const mBadge = document.getElementById('gummiActiveMatchBadge');
            if (mBadge) mBadge.textContent = '';
            await gummiPollState();
        }
    } catch (e) {}
}

async function gummiPollState() {
    const fd = new FormData();
    fd.append('ajax', '1');
    fd.append('csrf_token', GAMES_CSRF);
    fd.append('action', 'gummi_poll_state');
    fd.append('track_id', gummiTrackKey);
    if (gummiRaceState === 'racing') {
        fd.append('live_x', String(Math.round(gummiKart.x)));
        fd.append('live_y', String(Math.round(gummiKart.y)));
        fd.append('live_angle', String(Number(gummiKart.angle.toFixed(2))));
        fd.append('live_lap', String(gummiKart.lap));
    }

    try {
        const r = await fetch('games.php', { method: 'POST', body: fd });
        const d = await r.json();
        if (!d || !d.ok) return;

        gummiRecordGhost = d.record_ghost || null;
        gummiLivePeers   = Array.isArray(d.live_peers) ? d.live_peers : [];
        gummiChallengesCache = Array.isArray(d.challenges) ? d.challenges : [];

        const recBox = document.getElementById('gummiRecordGhostBox');
        if (recBox) {
            if (gummiRecordGhost) {
                recBox.innerHTML = `👻 <strong>Streckenrekord-Ghost aktiv:</strong> ${gummiRecordGhost.username} (${gummiFormatMs(gummiRecordGhost.time_ms)}) fährt live als Geist mit!`;
            } else {
                recBox.innerHTML = `👻 <strong>Streckenrekord-Ghost:</strong> Noch kein Rekord auf dieser Strecke – setze jetzt die erste Bestzeit!`;
            }
        }

        gummiRenderChallengesUI();
    } catch (e) {}
}

function gummiRenderChallengesUI() {
    const box = document.getElementById('gummiChallengesList');
    if (!box) return;
    if (!gummiChallengesCache.length) {
        box.innerHTML = '<p class="text-slate-400 italic py-2">Noch keine offenen Kart-Herausforderungen.</p>';
        return;
    }

    box.innerHTML = gummiChallengesCache.map((c) => {
        const canAccept = c.status === 'active' && Number(c.player2_id) === CURRENT_USER_ID;
        const isWaiting = c.status === 'active' && Number(c.player1_id) === CURRENT_USER_ID;
        let rightHtml = '';

        if (canAccept) {
            rightHtml = `<button type="button" onclick="gummiAcceptChallenge(${c.id})" class="px-2.5 py-1 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-[11px] font-bold shrink-0 cursor-pointer">🏎️ Gegen Ghost fahren!</button>`;
        } else if (isWaiting) {
            rightHtml = `<span class="px-2 py-0.5 rounded bg-amber-100 dark:bg-amber-950 text-amber-800 dark:text-amber-300 text-[10px] font-bold shrink-0">⏳ Wartet auf ${c.p2_name}</span>`;
        } else {
            const won = Number(c.winner_id) === CURRENT_USER_ID;
            rightHtml = `<span class="px-2 py-0.5 rounded ${won ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300' : 'bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-300'} text-[10px] font-bold shrink-0">${won ? '🏆 Gewonnen' : 'Beendet'}</span>`;
        }

        const p2Info = c.p2_time_ms ? ` vs. ${gummiFormatMs(c.p2_time_ms)}` : '';
        return `
            <div class="p-2 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 flex items-center justify-between gap-2">
                <div class="min-w-0">
                    <div class="font-bold text-slate-900 dark:text-white truncate">🏁 ${c.track_name}: ${c.p1_name} vs. ${c.p2_name}</div>
                    <div class="text-[10px] text-slate-500 dark:text-slate-400">Vorlage: ${gummiFormatMs(c.p1_time_ms)}${p2Info} · ${c.updated_at}</div>
                </div>
                ${rightHtml}
            </div>
        `;
    }).join('');
}

function gummiGameLoop(now) {
    requestAnimationFrame(gummiGameLoop);
    if (activeGameKey !== 'gummigeruch') {
        gummiLastFrameTs = now;
        return;
    }

    const dt = Math.min(0.05, (now - (gummiLastFrameTs || now)) / 1000);
    gummiLastFrameTs = now;

    // Falls im Ready-Zustand Gas gedrückt wird -> sofort Startampel starten!
    const gasPressed   = gummiKeys['ArrowUp'] || gummiKeys['w'] || gummiKeys['W'] || gummiTouchState.gas;
    const brakePressed = gummiKeys['ArrowDown'] || gummiKeys['s'] || gummiKeys['S'] || gummiTouchState.brake;
    const leftPressed  = gummiKeys['ArrowLeft'] || gummiKeys['a'] || gummiKeys['A'] || gummiTouchState.left;
    const rightPressed = gummiKeys['ArrowRight'] || gummiKeys['d'] || gummiKeys['D'] || gummiTouchState.right;
    const driftPressed = gummiKeys[' '] || gummiKeys['Shift'];

    if (gummiRaceState === 'ready' && gasPressed) {
        gummiStartCountdown();
    }

    if (gummiRaceState === 'countdown') {
        const cElapsed = now - gummiCountdownStart;
        if (cElapsed >= 2400) {
            gummiRaceState      = 'racing';
            gummiRaceStartTs    = now;
            gummiLapStartTs     = now;
            gummiLastRecordSample = 0;
            gummiBannerText     = '🟢 LOS! Fahre 3 schnelle Runden und bleibe auf dem Asphalt!';
        }
    }

    if (gummiRaceState === 'racing') {
        gummiElapsedMs = now - gummiRaceStartTs;

        // 1. Strecken-Abstand & Untergrund (Asphalt vs. Gras) ermitteln
        const tr = GUMMI_TRACKS[gummiTrackKey] || GUMMI_TRACKS.circuit;
        const { dist: trackDist, sector } = gummiGetTrackDistAndSector(gummiKart.x, gummiKart.y);
        const onAsphalt = trackDist <= (tr.roadWidth * 0.56);

        // 2. Lenkung, animierter Lenkeinschlag & echte Drift-Physik
        const speed = Math.hypot(gummiKart.vx, gummiKart.vy);
        const targetSteer = (leftPressed ? -1 : 0) + (rightPressed ? 1 : 0);
        gummiKart.steer += (targetSteer * 0.44 - gummiKart.steer) * Math.min(1, dt * 14);
        gummiKart.wheelSpin = (gummiKart.wheelSpin + speed * dt * 0.22) % (Math.PI * 2);
        gummiKart.braking = Boolean(brakePressed || driftPressed);

        if (speed > 8) {
            const steerRate = (driftPressed ? 3.5 : 2.75) * Math.min(1, speed / 75);
            if (leftPressed)  gummiKart.angle -= steerRate * dt;
            if (rightPressed) gummiKart.angle += steerRate * dt;
        }

        const forwardX = Math.cos(gummiKart.angle);
        const forwardY = Math.sin(gummiKart.angle);

        const isBoosting = now < gummiKart.boostUntil;
        const accel = isBoosting ? 380 : (onAsphalt ? 245 : 125);

        if (gasPressed) {
            gummiKart.vx += forwardX * accel * dt;
            gummiKart.vy += forwardY * accel * dt;
            // Leichter Auspuff-Dampf beim starken Beschleunigen
            if (Math.random() < (isBoosting ? 0.85 : 0.25)) {
                const exX = gummiKart.x - forwardX * 15 + (Math.random() * 4 - 2);
                const exY = gummiKart.y - forwardY * 15 + (Math.random() * 4 - 2);
                gummiSmoke.push({
                    x: exX,
                    y: exY,
                    vx: -forwardX * (isBoosting ? 65 : 25) + (Math.random() * 14 - 7),
                    vy: -forwardY * (isBoosting ? 65 : 25) + (Math.random() * 14 - 7),
                    r: isBoosting ? 3.5 : 2.2,
                    alpha: isBoosting ? 0.75 : 0.35,
                    color: isBoosting ? 'rgba(56,189,248,' : 'rgba(148,163,184,'
                });
            }
        }
        if (brakePressed) {
            gummiKart.vx -= forwardX * 190 * dt;
            gummiKart.vy -= forwardY * 190 * dt;
        }

        // Seitenführungskraft vs. Drift (Handbremse / hohes Tempo in der Kurve)
        const curSpeed = Math.hypot(gummiKart.vx, gummiKart.vy);
        let isCurrentlyDrifting = false;
        if (curSpeed > 1) {
            const moveAngle = Math.atan2(gummiKart.vy, gummiKart.vx);
            let slip = Math.atan2(Math.sin(gummiKart.angle - moveAngle), Math.cos(gummiKart.angle - moveAngle));
            const trackGrip = tr.gripMod || 1.0;
            const gripFactor = (driftPressed ? 2.4 : (onAsphalt ? 5.6 : 3.0)) * trackGrip;

            const targetVx = Math.cos(gummiKart.angle) * curSpeed;
            const targetVy = Math.sin(gummiKart.angle) * curSpeed;
            const blend = Math.min(1, gripFactor * dt);
            gummiKart.vx = gummiKart.vx * (1 - blend) + targetVx * blend;
            gummiKart.vy = gummiKart.vy * (1 - blend) + targetVy * blend;

            // Gummispuren (durchgehende Reifenspuren), Funkenflug & Reifenqualm erzeugen, wenn das Kart stark driftet!
            if (curSpeed > 90 && (Math.abs(slip) > 0.22 || driftPressed)) {
                isCurrentlyDrifting = true;
                const perpX = -Math.sin(gummiKart.angle) * 7.5;
                const perpY =  Math.cos(gummiKart.angle) * 7.5;
                const backX = gummiKart.x - forwardX * 10;
                const backY = gummiKart.y - forwardY * 10;

                const curL = { x: backX + perpX, y: backY + perpY };
                const curR = { x: backX - perpX, y: backY - perpY };
                const skidAlpha = Math.min(0.82, 0.35 + Math.abs(slip) * 0.7);

                if (gummiKart.prevSkidL && gummiKart.prevSkidR && Math.hypot(curL.x - gummiKart.prevSkidL.x, curL.y - gummiKart.prevSkidL.y) < 32) {
                    gummiSkids.push({
                        x1: gummiKart.prevSkidL.x, y1: gummiKart.prevSkidL.y,
                        x2: curL.x, y2: curL.y,
                        alpha: skidAlpha
                    });
                    gummiSkids.push({
                        x1: gummiKart.prevSkidR.x, y1: gummiKart.prevSkidR.y,
                        x2: curR.x, y2: curR.y,
                        alpha: skidAlpha
                    });
                    if (gummiSkids.length > 900) {
                        gummiSkids.splice(0, 2);
                    }
                }
                gummiKart.prevSkidL = curL;
                gummiKart.prevSkidR = curR;

                if (Math.random() < 0.78) {
                    gummiSmoke.push({
                        x: (Math.random() < 0.5 ? curL.x : curR.x) + (Math.random() * 6 - 3),
                        y: (Math.random() < 0.5 ? curL.y : curR.y) + (Math.random() * 6 - 3),
                        vx: -gummiKart.vx * 0.12 + (Math.random() * 18 - 9),
                        vy: -gummiKart.vy * 0.12 + (Math.random() * 18 - 9),
                        r: 3.5 + Math.random() * 2.5,
                        alpha: 0.68,
                        color: onAsphalt ? 'rgba(226,232,240,' : 'rgba(74,222,128,'
                    });
                }

                // Gold-orange Drift-Funken auf Asphalt
                if (onAsphalt && (Math.abs(slip) > 0.38 || driftPressed || isBoosting) && Math.random() < 0.65) {
                    const spOrigin = Math.random() < 0.5 ? curL : curR;
                    gummiSparks.push({
                        x: spOrigin.x,
                        y: spOrigin.y,
                        vx: -forwardX * (55 + Math.random() * 65) + (Math.random() * 50 - 25),
                        vy: -forwardY * (55 + Math.random() * 65) + (Math.random() * 50 - 25),
                        life: 1.0,
                        color: Math.random() < 0.5 ? '#fde047' : '#f97316'
                    });
                }
            }
        }
        gummiKart.drifting = isCurrentlyDrifting;
        if (!isCurrentlyDrifting) {
            gummiKart.prevSkidL = null;
            gummiKart.prevSkidR = null;
        }

        // Reibung / Höchstgeschwindigkeit (Gras bremst stark ab!)
        const drag = onAsphalt ? (isBoosting ? 0.992 : 0.982) : 0.935;
        const factor = Math.pow(drag, dt * 60);
        gummiKart.vx *= factor;
        gummiKart.vy *= factor;

        gummiKart.x = Math.min(742, Math.max(18, gummiKart.x + gummiKart.vx * dt));
        gummiKart.y = Math.min(432, Math.max(18, gummiKart.y + gummiKart.vy * dt));

        // 3. Sektor-Checkpoints & Rundenzählung prüfen
        if (sector === 1 && gummiKart.sectorPassed[0]) gummiKart.sectorPassed[1] = true;
        if (sector === 2 && gummiKart.sectorPassed[1]) gummiKart.sectorPassed[2] = true;
        if (sector === 3 && gummiKart.sectorPassed[2]) gummiKart.sectorPassed[3] = true;

        // Ziellinien-Überfahrt prüfen (bei Startpunkt & alle 4 Sektoren durchfahren)
        if (gummiKart.sectorPassed[3] && Math.hypot(gummiKart.x - tr.startX, gummiKart.y - tr.startY) < 48 && gummiKart.vx > 0) {
            const lapTime = now - gummiLapStartTs;
            gummiLapStartTs = now;
            if (gummiBestLapMs === 0 || lapTime < gummiBestLapMs) {
                gummiBestLapMs = lapTime;
            }

            if (gummiKart.lap >= 3) {
                gummiRaceState = 'finished';
                gummiElapsedMs = now - gummiRaceStartTs;
                gummiBannerText = `🏁 ZIEL! 3 Runden in ${gummiFormatMs(gummiElapsedMs)} (Beste Runde: ${gummiFormatMs(gummiBestLapMs)})!`;
                gummiSaveCompletedRun();
            } else {
                gummiKart.lap++;
                gummiKart.boostReady = true;
                gummiKart.sectorPassed = [true, false, false, false];
                gummiBannerText = `⏱️ Runde ${gummiKart.lap - 1}: ${gummiFormatMs(lapTime)} — 🔥 Nitro für Runde ${gummiKart.lap} wieder aufgeladen!`;
            }
        }

        // 4. Eigene Ghost-Trajektorie alle 100ms aufzeichnen
        if (gummiElapsedMs - gummiLastRecordSample >= 100) {
            gummiLastRecordSample = gummiElapsedMs;
            gummiMyRecording.push([
                Math.round(gummiKart.x),
                Math.round(gummiKart.y),
                Number(gummiKart.angle.toFixed(2))
            ]);
        }

        // 5. KI-Karts entlang der Ideallinie bewegen & Räder/Lenkung animieren
        for (const ai of gummiAiKarts) {
            const targetPt = gummiDenseTrack[ai.wpIdx];
            const dx = targetPt[0] - ai.x;
            const dy = (targetPt[1] + ai.laneOffset * 0.4) - ai.y;
            const d  = Math.hypot(dx, dy);
            if (d < 26) {
                ai.wpIdx = (ai.wpIdx + 1) % gummiDenseTrack.length;
            }
            const desiredAngle = Math.atan2(dy, dx);
            const diff = Math.atan2(Math.sin(desiredAngle - ai.angle), Math.cos(desiredAngle - ai.angle));
            ai.steer = Math.max(-0.42, Math.min(0.42, diff * 1.4));
            ai.wheelSpin = ((ai.wheelSpin || 0) + ai.speed * dt * 0.22) % (Math.PI * 2);
            ai.angle += diff * 6.5 * dt;
            ai.x += Math.cos(ai.angle) * ai.speed * dt;
            ai.y += Math.sin(ai.angle) * ai.speed * dt;
        }

        gummiUpdateHud();
    }

    gummiDraw(now);
}

function gummiDrawWheel(ctx, wx, wy, wLen, wWid, steerAngle, spinPhase) {
    ctx.save();
    ctx.translate(wx, wy);
    if (steerAngle) {
        ctx.rotate(steerAngle);
    }

    // Reifen-Außenmantel (Gummi mit abgerundeten Schultern)
    ctx.fillStyle = '#0f172a';
    ctx.beginPath();
    ctx.roundRect(-wLen / 2, -wWid / 2, wLen, wWid, 2.2);
    ctx.fill();
    ctx.strokeStyle = '#334155';
    ctx.lineWidth = 0.8;
    ctx.stroke();

    // Animiertes Reifenprofil (wandert mit der Raddrehung!)
    ctx.save();
    ctx.beginPath();
    ctx.roundRect(-wLen / 2, -wWid / 2, wLen, wWid, 2.2);
    ctx.clip();
    ctx.strokeStyle = '#1e293b';
    ctx.lineWidth = 1.2;
    const treadOffset = ((spinPhase || 0) / (Math.PI * 2)) * 4;
    for (let tx = -wLen / 2 - 2 + treadOffset; tx <= wLen / 2 + 2; tx += 3.5) {
        ctx.beginPath();
        ctx.moveTo(tx, -wWid / 2);
        ctx.lineTo(tx + 1.2, wWid / 2);
        ctx.stroke();
    }
    ctx.restore();

    // Silberne Leichtmetall-Felge an der Außenseite
    ctx.fillStyle = '#94a3b8';
    ctx.fillRect(-1.8, -wWid / 2 + 0.8, 3.6, wWid - 1.6);

    ctx.restore();
}

function gummiDrawKartSprite(ctx, x, y, angle, bodyColor, label, isGhost = false, isBoosting = false, opts = {}) {
    const now        = performance.now();
    const steer      = Number(opts.steer || 0);
    const wheelSpin  = Number(opts.wheelSpin || 0);
    const isBraking  = Boolean(opts.braking);
    const isDrifting = Boolean(opts.drifting);
    const accentCol  = opts.accent || '#ffffff';
    const raceNum    = opts.num || '1';
    const speed      = Number(opts.speed || 0);

    // Subtile Motor-Vibration im Leerlauf / während der Fahrt & Chassis-Neigung in der Kurve
    const engineVibY = !isGhost ? Math.sin(now * 0.055 + x) * (speed > 20 ? 0.35 : 0.2) : 0;
    const bodyRollY  = !isGhost ? steer * Math.min(1.6, speed / 95) : 0;

    ctx.save();
    ctx.translate(x, y + engineVibY);
    ctx.rotate(angle);

    if (isGhost) {
        ctx.globalAlpha = 0.56 + Math.sin(now * 0.008) * 0.08;
        ctx.shadowColor = bodyColor;
        ctx.shadowBlur  = 10;
    }

    // 1. Weicher dynamischer Schlagschatten unter dem Rennkart
    if (!isGhost) {
        ctx.fillStyle = 'rgba(2, 6, 23, 0.42)';
        ctx.beginPath();
        ctx.ellipse(-1, 2.2 + bodyRollY * 0.5, 17.5, 11.2, 0, 0, Math.PI * 2);
        ctx.fill();
    }

    // 2. Scheinwerfer-Lichtkegel nach vorne (besonders eindrucksvoll auf Nacht-Ring & Vulkan-Höllenring!)
    if (!isGhost) {
        const trCfg = GUMMI_TRACKS[gummiTrackKey] || GUMMI_TRACKS.circuit;
        const isNight = Boolean(trCfg.isDark);
        const coneLen = isNight ? 74 : 30;
        const coneAlpha = isNight ? 0.28 : 0.09;
        const beamGrad = ctx.createLinearGradient(14, 0, 14 + coneLen, 0);
        beamGrad.addColorStop(0, `rgba(254, 240, 138, ${coneAlpha})`);
        beamGrad.addColorStop(1, 'rgba(254, 240, 138, 0)');
        ctx.fillStyle = beamGrad;
        ctx.beginPath();
        ctx.moveTo(14, -6);
        ctx.lineTo(14 + coneLen, -22);
        ctx.lineTo(14 + coneLen, 22);
        ctx.lineTo(14, 6);
        ctx.closePath();
        ctx.fill();
    }

    // 3. Doppel-Auspuffrohre & animierte Twin-Turbo-Nitro-Flamme am Heck
    ctx.fillStyle = '#64748b';
    ctx.fillRect(-16.5, -5.2, 4.5, 2.6);
    ctx.fillRect(-16.5, 2.6, 4.5, 2.6);

    if (isBoosting) {
        const flameFlicker = Math.sin(now * 0.09) * 5 + Math.random() * 5;
        for (const ey of [-3.9, 3.9]) {
            // Äußere orange-blaue Nachbrenner-Flamme
            const fGrad = ctx.createLinearGradient(-16, ey, -34 - flameFlicker, ey);
            fGrad.addColorStop(0, '#38bdf8');
            fGrad.addColorStop(0.45, '#f97316');
            fGrad.addColorStop(1, 'rgba(239, 68, 68, 0)');
            ctx.fillStyle = fGrad;
            ctx.beginPath();
            ctx.moveTo(-16, ey - 3.2);
            ctx.lineTo(-33 - flameFlicker, ey + (Math.random() * 2 - 1));
            ctx.lineTo(-16, ey + 3.2);
            ctx.closePath();
            ctx.fill();

            // Weiß-blauer Plasma-Kern
            ctx.fillStyle = '#e0f2fe';
            ctx.beginPath();
            ctx.moveTo(-16, ey - 1.4);
            ctx.lineTo(-24 - flameFlicker * 0.5, ey);
            ctx.lineTo(-16, ey + 1.4);
            ctx.closePath();
            ctx.fill();
        }
    } else if (isDrifting && Math.random() < 0.45) {
        // Kurzes Fehlzündungs-Flämmchen beim harten Anbremsen/Driften
        const backfireY = Math.random() < 0.5 ? -3.9 : 3.9;
        ctx.fillStyle = '#fb923c';
        ctx.beginPath();
        ctx.arc(-18, backfireY, 2.4, 0, Math.PI * 2);
        ctx.fill();
    }

    // 4. Spurstangen / Querlenker zu den 4 Rädern
    ctx.strokeStyle = '#475569';
    ctx.lineWidth = 1.6;
    ctx.beginPath();
    ctx.moveTo(-9.5, -10.5); ctx.lineTo(-9.5, 10.5);
    ctx.moveTo(8.5, -10.5);  ctx.lineTo(8.5, 10.5);
    ctx.stroke();

    // 5. 4 Räder zeichnen (Hinterräder breit & starr, Vorderräder lenken live mit `steer` ein!)
    gummiDrawWheel(ctx, -9.5, -11.2, 8.8, 5.0, 0, wheelSpin);     // Hinten links
    gummiDrawWheel(ctx, -9.5,  11.2, 8.8, 5.0, 0, wheelSpin);     // Hinten rechts
    gummiDrawWheel(ctx,  8.5, -10.8, 7.8, 4.4, steer, wheelSpin); // Vorne links (mit Lenkeinschlag!)
    gummiDrawWheel(ctx,  8.5,  10.8, 7.8, 4.4, steer, wheelSpin); // Vorne rechts (mit Lenkeinschlag!)

    // 6. Aerodynamisches Renn-Chassis (Seitenkästen + verjüngte Frontnase)
    ctx.save();
    ctx.translate(0, bodyRollY * 0.45);

    // Seitenkästen (Sidepods links & rechts)
    ctx.fillStyle = bodyColor;
    ctx.beginPath();
    ctx.roundRect(-8, -9.2, 13, 18.4, 3.5);
    ctx.fill();

    // Kühler-Lufteinlässe an den Seitenkästen
    ctx.fillStyle = '#0f172a';
    ctx.fillRect(2.2, -8.4, 2.2, 3.2);
    ctx.fillRect(2.2,  5.2, 2.2, 3.2);

    // Hauptrumpf & spitz zulaufende Formel-/Kart-Frontnase mit 3D-Metallic-Glanz
    const bodyGrad = ctx.createLinearGradient(0, -8, 0, 8);
    bodyGrad.addColorStop(0, bodyColor);
    bodyGrad.addColorStop(0.5, '#ffffff38');
    bodyGrad.addColorStop(1, bodyColor);

    ctx.fillStyle = bodyColor;
    ctx.beginPath();
    ctx.moveTo(-13.5, -6.5);
    ctx.lineTo(5.5, -6.8);
    ctx.quadraticCurveTo(16.5, -4.2, 17.5, 0);
    ctx.quadraticCurveTo(16.5,  4.2, 5.5,  6.8);
    ctx.lineTo(-13.5,  6.5);
    ctx.closePath();
    ctx.fill();

    // Metallic-Glanzschicht darüberlegen
    ctx.fillStyle = bodyGrad;
    ctx.fill();

    ctx.strokeStyle = isGhost ? '#7dd3fc' : 'rgba(255, 255, 255, 0.85)';
    ctx.lineWidth = 1.1;
    ctx.stroke();

    // Doppelter Racing-Streifen über die Fahrzeugmitte
    ctx.fillStyle = accentCol;
    ctx.fillRect(-12, -2.1, 26, 1.3);
    ctx.fillRect(-12,  0.8, 26, 1.3);

    // 7. Frontspoiler / Frontflügel mit Endplatten
    ctx.fillStyle = '#1e293b';
    ctx.beginPath();
    ctx.roundRect(14.2, -8.8, 3.4, 17.6, 1.4);
    ctx.fill();
    ctx.fillStyle = accentCol;
    ctx.fillRect(14.0, -9.2, 3.8, 1.6);
    ctx.fillRect(14.0,  7.6, 3.8, 1.6);

    // Helle LED-Tagfahrlichter an der Frontnase
    ctx.fillStyle = '#fef08a';
    ctx.fillRect(15.6, -5.2, 1.8, 2.2);
    ctx.fillRect(15.6,  3.0, 1.8, 2.2);

    // 8. Carbon-Heckspoiler (Rear Wing) + leuchtende rote LED-Bremslichter
    ctx.fillStyle = '#0f172a';
    ctx.beginPath();
    ctx.roundRect(-15.5, -8.8, 4.2, 17.6, 1.4);
    ctx.fill();
    ctx.strokeStyle = accentCol;
    ctx.lineWidth = 0.8;
    ctx.stroke();

    // Rote LED-Rück-/Bremsleuchten (leuchten beim Bremsen & Driften hell auf!)
    ctx.save();
    if (isBraking || isDrifting) {
        ctx.shadowColor = '#ef4444';
        ctx.shadowBlur  = 9;
        ctx.fillStyle   = '#ff2a2a';
    } else {
        ctx.fillStyle   = '#991b1b';
    }
    ctx.fillRect(-15.8, -6.6, 1.8, 3.4);
    ctx.fillRect(-15.8,  3.2, 1.8, 3.4);
    ctx.restore();

    // 9. Cockpit, Lenkrad & animierter Rennfahrer-Helm (blickt in die Kurve!)
    ctx.fillStyle = '#090d16';
    ctx.beginPath();
    ctx.ellipse(-0.5, 0, 5.8, 4.2, 0, 0, Math.PI * 2);
    ctx.fill();

    // Lenkrad + Hände des Fahrers (drehen sich mit dem Lenkeinschlag `steer`)
    ctx.save();
    ctx.translate(3.2, 0);
    ctx.rotate(steer * 1.35);
    ctx.strokeStyle = '#cbd5e1';
    ctx.lineWidth = 1.4;
    ctx.beginPath();
    ctx.moveTo(0, -2.8);
    ctx.lineTo(0,  2.8);
    ctx.stroke();
    ctx.fillStyle = '#f8fafc';
    ctx.fillRect(-1.0, -3.2, 2.0, 1.4);
    ctx.fillRect(-1.0,  1.8, 2.0, 1.4);
    ctx.restore();

    // Fahrer-Helm (dreht sich leicht in Kurvenrichtung)
    ctx.save();
    ctx.translate(-1.6, 0);
    ctx.rotate(steer * 0.65);

    ctx.fillStyle = '#f8fafc';
    ctx.beginPath();
    ctx.arc(0, 0, 4.3, 0, Math.PI * 2);
    ctx.fill();
    ctx.strokeStyle = bodyColor;
    ctx.lineWidth = 1.1;
    ctx.stroke();

    // Dunkles verspiegeltes Helm-Visier
    ctx.fillStyle = '#0284c7';
    ctx.beginPath();
    ctx.arc(0.9, 0, 3.4, -0.85, 0.85);
    ctx.fill();

    // Lichtreflex auf dem Helm
    ctx.fillStyle = 'rgba(255,255,255,0.75)';
    ctx.beginPath();
    ctx.arc(-1.2, -1.4, 1.2, 0, Math.PI * 2);
    ctx.fill();
    ctx.restore();

    // Startnummer auf der Frontnase
    ctx.fillStyle = '#0f172a';
    ctx.font = 'bold 6px sans-serif';
    ctx.textAlign = 'center';
    ctx.fillText(String(raceNum), 9.5, 2.1);

    ctx.restore(); // Ende Chassis-Roll
    ctx.restore(); // Ende Kart-Transform

    if (label) {
        ctx.save();
        ctx.font = 'bold 10px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillStyle = 'rgba(2, 6, 23, 0.85)';
        ctx.fillText(label, x + 1, y - 17);
        ctx.fillStyle = isGhost ? '#7dd3fc' : '#ffffff';
        ctx.fillText(label, x, y - 18);
        ctx.restore();
    }
}

function gummiDrawGhostAtTime(ctx, frames, elapsedMs, color, label) {
    if (!Array.isArray(frames) || frames.length === 0) return;
    const idxFloat = Math.min(frames.length - 1, Math.max(0, elapsedMs / 100));
    const idx0 = Math.floor(idxFloat);
    const idx1 = Math.min(frames.length - 1, idx0 + 1);
    const frac = idxFloat - idx0;

    const f0 = frames[idx0];
    const f1 = frames[idx1];
    if (!Array.isArray(f0) || !Array.isArray(f1)) return;

    const gx = f0[0] + (f1[0] - f0[0]) * frac;
    const gy = f0[1] + (f1[1] - f0[1]) * frac;
    const angleDiff = Math.atan2(Math.sin(f1[2] - f0[2]), Math.cos(f1[2] - f0[2]));
    const ga = f0[2] + angleDiff * frac;
    const gSteer = Math.max(-0.42, Math.min(0.42, angleDiff * 3.5));

    gummiDrawKartSprite(ctx, gx, gy, ga, color, label, true, false, {
        steer: gSteer,
        wheelSpin: (elapsedMs * 0.035) % (Math.PI * 2),
        accent: '#e0f2fe',
        num: 'G'
    });
}

function gummiDraw(now) {
    const cv = document.getElementById('gummiCanvas');
    if (!cv) return;
    const ctx = cv.getContext('2d');
    const W = cv.width;
    const H = cv.height;
    const tr = GUMMI_TRACKS[gummiTrackKey] || GUMMI_TRACKS.circuit;

    // 1. Rasen-Hintergrund
    const grad = ctx.createLinearGradient(0, 0, W, H);
    grad.addColorStop(0, tr.grassTop);
    grad.addColorStop(1, tr.grassBot);
    ctx.fillStyle = grad;
    ctx.fillRect(0, 0, W, H);

    if (gummiDenseTrack.length > 2) {
        ctx.save();
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';

        const traceTrackPath = () => {
            ctx.beginPath();
            ctx.moveTo(gummiDenseTrack[0][0], gummiDenseTrack[0][1]);
            for (let i = 1; i < gummiDenseTrack.length; i++) {
                ctx.lineTo(gummiDenseTrack[i][0], gummiDenseTrack[i][1]);
            }
            ctx.closePath();
        };

        // Strecken-Randsteine (Curbs) in streckenspezifischer Akzentfarbe + Weiß
        traceTrackPath();
        ctx.strokeStyle = tr.curbColor || '#ef4444';
        ctx.lineWidth = tr.roadWidth + 10;
        ctx.stroke();

        traceTrackPath();
        ctx.strokeStyle = '#f8fafc';
        ctx.lineWidth = tr.roadWidth + 10;
        ctx.setLineDash([14, 14]);
        ctx.stroke();
        ctx.setLineDash([]);

        // Asphalt-Fahrbahn
        traceTrackPath();
        ctx.strokeStyle = tr.asphalt;
        ctx.lineWidth = tr.roadWidth;
        ctx.stroke();

        // Gestrichelte Ideallinie in der Fahrbahnmitte
        traceTrackPath();
        ctx.strokeStyle = 'rgba(255, 255, 255, 0.16)';
        ctx.lineWidth = 2;
        ctx.setLineDash([12, 16]);
        ctx.stroke();
        ctx.setLineDash([]);
        ctx.restore();
    }

    // 2. Schachbrett-Start-/Ziellinie
    const sx = tr.startX;
    const sy = tr.startY;
    const halfW = tr.roadWidth / 2;
    for (let r = 0; r < 8; r++) {
        for (let c = 0; c < 2; c++) {
            ctx.fillStyle = (r + c) % 2 === 0 ? '#ffffff' : '#0f172a';
            ctx.fillRect(sx - 6 + c * 6, sy - halfW + r * (tr.roadWidth / 8), 6, tr.roadWidth / 8);
        }
    }

    // 3. Dauerhafte schwarze Gummispuren (durchgehende Doppel-Reifenbänder) auf dem Asphalt!
    ctx.save();
    ctx.lineCap = 'round';
    ctx.lineWidth = 3.2;
    for (const sk of gummiSkids) {
        if (sk.x1 !== undefined) {
            ctx.strokeStyle = `rgba(9, 13, 22, ${(sk.alpha || 0.65).toFixed(2)})`;
            ctx.beginPath();
            ctx.moveTo(sk.x1, sk.y1);
            ctx.lineTo(sk.x2, sk.y2);
            ctx.stroke();
        } else {
            ctx.fillStyle = 'rgba(9, 13, 22, 0.68)';
            ctx.fillRect(sk.x - 1.5, sk.y - 1.5, 3.2, 3.2);
        }
    }
    ctx.restore();

    // 4. Reifenqualm- & Auspuff-Partikel
    for (let i = gummiSmoke.length - 1; i >= 0; i--) {
        const sm = gummiSmoke[i];
        if (sm.vx) sm.x += sm.vx * 0.016;
        if (sm.vy) sm.y += sm.vy * 0.016;
        sm.r += 0.32;
        sm.alpha -= 0.024;
        if (sm.alpha <= 0) {
            gummiSmoke.splice(i, 1);
            continue;
        }
        ctx.beginPath();
        ctx.arc(sm.x, sm.y, sm.r, 0, Math.PI * 2);
        ctx.fillStyle = sm.color + sm.alpha.toFixed(2) + ')';
        ctx.fill();
    }

    // 4b. Goldene Drift-Funken (Sparks)
    for (let i = gummiSparks.length - 1; i >= 0; i--) {
        const sp = gummiSparks[i];
        sp.x += sp.vx * 0.016;
        sp.y += sp.vy * 0.016;
        sp.life -= 0.075;
        if (sp.life <= 0) {
            gummiSparks.splice(i, 1);
            continue;
        }
        ctx.strokeStyle = sp.color;
        ctx.lineWidth = 1.6;
        ctx.beginPath();
        ctx.moveTo(sp.x, sp.y);
        ctx.lineTo(sp.x - sp.vx * 0.022, sp.y - sp.vy * 0.022);
        ctx.stroke();
    }

    // 5. Ghost-Karts zeichnen (Herausforderungs-Ghost & Streckenrekord-Ghost)
    if (gummiActiveChallenge && Array.isArray(gummiActiveChallenge.p1_ghost) && gummiActiveChallenge.p1_ghost.length > 0) {
        gummiDrawGhostAtTime(ctx, gummiActiveChallenge.p1_ghost, gummiElapsedMs, '#f43f5e', `👻 Duell: ${gummiActiveChallenge.p1_name}`);
    } else if (gummiRecordGhost && Array.isArray(gummiRecordGhost.frames) && gummiRecordGhost.frames.length > 0) {
        gummiDrawGhostAtTime(ctx, gummiRecordGhost.frames, gummiElapsedMs, '#38bdf8', `👻 Rekord: ${gummiRecordGhost.username}`);
    }

    // 6. Live fahrende andere Cloud-Mitspieler zeichnen
    for (const lp of gummiLivePeers) {
        gummiDrawKartSprite(ctx, lp.x, lp.y, lp.angle, '#a855f7', `⚡ ${lp.username} (R${lp.lap})`, false, false, {
            wheelSpin: (now * 0.025) % (Math.PI * 2),
            accent: '#f3e8ff',
            num: '99',
            speed: 160
        });
    }

    // 7. KI-Karts zeichnen
    for (const ai of gummiAiKarts) {
        gummiDrawKartSprite(ctx, ai.x, ai.y, ai.angle, ai.color, ai.name, false, false, {
            steer: ai.steer || 0,
            wheelSpin: ai.wheelSpin || 0,
            accent: ai.accent || '#ffffff',
            num: ai.num || '7',
            speed: gummiRaceState === 'racing' ? ai.speed : 0
        });
    }

    // 8. Eigenes Spieler-Kart zeichnen
    gummiDrawKartSprite(
        ctx,
        gummiKart.x,
        gummiKart.y,
        gummiKart.angle,
        '#2563eb',
        `🏎️ ${CURRENT_USERNAME}`,
        false,
        now < gummiKart.boostUntil,
        {
            steer: gummiKart.steer || 0,
            wheelSpin: gummiKart.wheelSpin || 0,
            braking: gummiKart.braking,
            drifting: gummiKart.drifting,
            accent: '#38bdf8',
            num: '1',
            speed: Math.hypot(gummiKart.vx, gummiKart.vy)
        }
    );

    // 9. Startampel-Overlay bei Countdown
    if (gummiRaceState === 'countdown') {
        const cElapsed = now - gummiCountdownStart;
        const step = Math.floor(cElapsed / 800); // 0 -> "3", 1 -> "2", 2 -> "1"
        const numTxt = step === 0 ? '3' : (step === 1 ? '2' : '1');

        ctx.fillStyle = 'rgba(15, 23, 42, 0.85)';
        ctx.beginPath();
        ctx.roundRect(W / 2 - 110, H / 2 - 55, 220, 110, 18);
        ctx.fill();
        ctx.strokeStyle = '#f59e0b';
        ctx.lineWidth = 2.5;
        ctx.stroke();

        // 3 Ampellichter
        for (let l = 0; l < 3; l++) {
            ctx.beginPath();
            ctx.arc(W / 2 - 50 + l * 50, H / 2 - 18, 14, 0, Math.PI * 2);
            ctx.fillStyle = l <= step ? '#ef4444' : '#334155';
            ctx.fill();
        }

        ctx.fillStyle = '#fde047';
        ctx.font = 'bold 28px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText(`START IN ${numTxt}…`, W / 2, H / 2 + 34);
    }

    // 10. Info-Banner am oberen Rand
    ctx.fillStyle = 'rgba(15, 23, 42, 0.82)';
    ctx.fillRect(0, 0, W, 26);
    ctx.fillStyle = '#f8fafc';
    ctx.font = 'bold 11px sans-serif';
    ctx.textAlign = 'center';
    ctx.fillText(gummiBannerText, W / 2, 17);
}

// =============================================================================
// GLOBALE TASTATUR-STEUERUNG & SEKUNDEN-TIMER
// =============================================================================
window.addEventListener('keyup', (e) => {
    delete wowKeysDown[e.key];
    delete gummiKeys[e.key];
});

window.addEventListener('keydown', (e) => {
    if (['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName || '')) return;

    if (activeGameKey === 'gummigeruch') {
        gummiKeys[e.key] = true;
        if (['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight', ' '].includes(e.key)) {
            e.preventDefault();
        }
        if (e.key === 'e' || e.key === 'E' || e.key === 'n' || e.key === 'N') {
            e.preventDefault();
            gummiTriggerBoost();
        }
        return;
    }

    if (activeGameKey === 'wow') {
        wowKeysDown[e.key] = true;
        if (['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'Tab'].includes(e.key)) {
            e.preventDefault();
        }
        if (e.key >= '1' && e.key <= '5') {
            e.preventDefault();
            wowCastSpell(Number(e.key) - 1);
        } else if (e.key === 'q' || e.key === 'Q') {
            e.preventDefault();
            wowUsePotion();
        } else if (e.key === 'Tab') {
            wowCycleTarget();
        }
        return;
    }

    if (activeGameKey === 'sudoku') {
        if (/^[1-9]$/.test(e.key)) sudokuInputNumber(Number(e.key));
        else if (e.key === 'Backspace' || e.key === 'Delete' || e.key === '0') sudokuInputNumber(0);
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
    } else if (activeGameKey === 'pacman') {
        if (e.key === 'ArrowUp'    || e.key === 'w' || e.key === 'W') { e.preventDefault(); pacmanQueueDir(0, -1); }
        if (e.key === 'ArrowDown'  || e.key === 's' || e.key === 'S') { e.preventDefault(); pacmanQueueDir(0, 1); }
        if (e.key === 'ArrowLeft'  || e.key === 'a' || e.key === 'A') { e.preventDefault(); pacmanQueueDir(-1, 0); }
        if (e.key === 'ArrowRight' || e.key === 'd' || e.key === 'D') { e.preventDefault(); pacmanQueueDir(1, 0); }
        if (e.key === 'p' || e.key === 'P') { e.preventDefault(); pacmanTogglePause(); }
    } else if (activeGameKey === 'blitz') {
        if (e.key === ' ' || e.key === 'ArrowDown' || e.key === 's' || e.key === 'S' || e.key === 'Enter') {
            e.preventDefault();
            blitzDropBomb();
        }
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
    if (activeGameKey === 'gummigeruch') {
        gummiPollState();
    } else if (activeGameKey === 'connect4' && c4Mode === 'cloud') {
        pollCloudMatches();
    } else if (activeGameKey === 'wow') {
        wowSyncPeers();
    }
}, 4000);

// =============================================================================
// SPIEL 10: CLOUD-PACMAN (NEON-LABYRINTH, 4 GEISTER-KIS, POWER-PILLEN & FRÜCHTE)
// =============================================================================
const PACMAN_TILE = 24;
const PACMAN_COLS = 19;
const PACMAN_ROWS = 21;
const PACMAN_MAZE_TEMPLATE = [
    "1111111111111111111",
    "1322222221222222231",
    "1211211121211121121",
    "1211211121211121121",
    "1222222222222222221",
    "1211212111112121121",
    "1222212221222122221",
    "1111211101011121111",
    "0001210000000121000",
    "1111210114110121111",
    "0000200100010020000",
    "1111210111110121111",
    "0001210000000121000",
    "1111210111110121111",
    "1222222221222222221",
    "1211211121211121121",
    "1321222220222221231",
    "1121212111112121211",
    "1222212221222122221",
    "1211111121211111121",
    "1111111111111111111"
];

let pacmanInitialized = false;
let pacmanRunning     = false;
let pacmanPaused      = false;
let pacmanRafId       = null;
let pacmanGrid        = [];
let pacmanDotsLeft    = 0;
let pacmanScoreVal    = 0;
let pacmanLevelVal    = 1;
let pacmanLivesVal    = 3;
let pacmanStartTime   = 0;
let pacmanTickCount   = 0;
let pacmanFrightTimer = 0;
let pacmanGhostCombo  = 0;
let pacmanBonusFruit  = null; // { col: 9, row: 12, icon: '🍒', pts: 300, timer: 450 }
let pacmanPopups      = [];   // [{ x, y, text, color, life }]

let pacmanPlayer = {
    x: 9 * PACMAN_TILE,
    y: 16 * PACMAN_TILE,
    dx: 0,
    dy: 0,
    nextDx: -1,
    nextDy: 0,
    mouthAngle: 0.2,
    mouthSpeed: 0.025,
    facingAngle: Math.PI
};

let pacmanGhosts = [];

function pacmanBuildGrid() {
    pacmanGrid = [];
    pacmanDotsLeft = 0;
    for (let r = 0; r < PACMAN_ROWS; r++) {
        const rowArr = [];
        const line = PACMAN_MAZE_TEMPLATE[r];
        for (let c = 0; c < PACMAN_COLS; c++) {
            const ch = Number(line[c] || 0);
            rowArr.push(ch);
            if (ch === 2 || ch === 3) {
                pacmanDotsLeft++;
            }
        }
        pacmanGrid.push(rowArr);
    }
}

function pacmanResetPositions() {
    pacmanPlayer.x = 9 * PACMAN_TILE;
    pacmanPlayer.y = 16 * PACMAN_TILE;
    pacmanPlayer.dx = 0;
    pacmanPlayer.dy = 0;
    pacmanPlayer.nextDx = -1;
    pacmanPlayer.nextDy = 0;
    pacmanPlayer.facingAngle = Math.PI;

    pacmanGhosts = [
        { name: 'Blinky', color: '#ef4444', x: 9 * PACMAN_TILE,  y: 8 * PACMAN_TILE,  dx: -1, dy: 0, scatterX: 17, scatterY: 1,  eaten: false, releaseDelay: 0 },
        { name: 'Pinky',  color: '#f472b6', x: 9 * PACMAN_TILE,  y: 10 * PACMAN_TILE, dx: 0,  dy: -1, scatterX: 1,  scatterY: 1,  eaten: false, releaseDelay: 35 },
        { name: 'Inky',   color: '#38bdf8', x: 8 * PACMAN_TILE,  y: 10 * PACMAN_TILE, dx: 0,  dy: -1, scatterX: 17, scatterY: 19, eaten: false, releaseDelay: 90 },
        { name: 'Clyde',  color: '#fb923c', x: 10 * PACMAN_TILE, y: 10 * PACMAN_TILE, dx: 0,  dy: -1, scatterX: 1,  scatterY: 19, eaten: false, releaseDelay: 150 }
    ];
}

function pacmanInit() {
    pacmanInitialized = true;
    pacmanBuildGrid();
    pacmanResetPositions();
    pacmanUpdateHud('Drücke Start oder Pfeiltaste');
    pacmanDraw();
}

function pacmanStartGame() {
    if (pacmanRafId) cancelAnimationFrame(pacmanRafId);
    pacmanScoreVal    = 0;
    pacmanLevelVal    = 1;
    pacmanLivesVal    = 3;
    pacmanFrightTimer = 0;
    pacmanGhostCombo  = 0;
    pacmanBonusFruit  = null;
    pacmanPopups      = [];
    pacmanRunning     = true;
    pacmanPaused      = false;
    pacmanStartTime   = performance.now();
    pacmanTickCount   = 0;

    const pauseBtn = document.getElementById('pacmanPauseBtn');
    if (pauseBtn) pauseBtn.textContent = '⏸️ Pause';

    pacmanBuildGrid();
    pacmanResetPositions();
    pacmanUpdateHud('Auf geht’s!');
    pacmanLoop();
}

function pacmanTogglePause() {
    if (!pacmanRunning) return;
    pacmanPaused = !pacmanPaused;
    const pauseBtn = document.getElementById('pacmanPauseBtn');
    if (pauseBtn) pauseBtn.textContent = pacmanPaused ? '▶️ Weiter' : '⏸️ Pause';
    pacmanUpdateHud(pacmanPaused ? '⏸️ Pausiert' : (pacmanFrightTimer > 0 ? '⚡ JAGD-MODUS!' : 'Aktiv'));
    if (!pacmanPaused) {
        pacmanLoop();
    } else {
        pacmanDraw();
    }
}

function pacmanQueueDir(dx, dy) {
    pacmanPlayer.nextDx = dx;
    pacmanPlayer.nextDy = dy;
    if (!pacmanRunning && pacmanInitialized) {
        pacmanStartGame();
        pacmanPlayer.nextDx = dx;
        pacmanPlayer.nextDy = dy;
    }
    // Sofortiger 180-Grad-Richtungswechsel ist jederzeit möglich
    if (pacmanPlayer.dx === -dx && pacmanPlayer.dy === -dy && (dx !== 0 || dy !== 0)) {
        pacmanPlayer.dx = dx;
        pacmanPlayer.dy = dy;
        pacmanPlayer.facingAngle = Math.atan2(dy, dx);
    }
}

function pacmanUpdateHud(statusText = null) {
    const scEl = document.getElementById('pacmanScore');
    const lvEl = document.getElementById('pacmanLevel');
    const liEl = document.getElementById('pacmanLives');
    const stEl = document.getElementById('pacmanStatus');

    if (scEl) scEl.textContent = pacmanScoreVal.toLocaleString('de-DE');
    if (lvEl) lvEl.textContent = String(pacmanLevelVal);
    if (liEl) liEl.textContent = '❤️'.repeat(Math.max(0, pacmanLivesVal)) || '💀';
    if (stEl && statusText !== null) {
        stEl.textContent = statusText;
    }
}

function pacmanCanMoveTile(col, row, isGhost = false, inGhostHouse = false) {
    if (row < 0 || row >= PACMAN_ROWS) return false;
    // Warp-Tunnel links/rechts
    if (col < 0 || col >= PACMAN_COLS) {
        return row === 10;
    }
    const cell = pacmanGrid[row][col];
    if (cell === 1) return false;
    if (cell === 4) return isGhost && inGhostHouse;
    return true;
}

function pacmanStepPlayer() {
    const speed = 2; // Teiler von 24 (24 / 2 = 12 Subschritte pro Kachel)
    // Warp-Tunnel-Umbruch
    if (pacmanPlayer.x < -PACMAN_TILE / 2) {
        pacmanPlayer.x = (PACMAN_COLS - 1) * PACMAN_TILE;
    } else if (pacmanPlayer.x > (PACMAN_COLS - 1) * PACMAN_TILE + PACMAN_TILE / 2) {
        pacmanPlayer.x = 0;
    }

    const onGridX = (pacmanPlayer.x % PACMAN_TILE === 0);
    const onGridY = (pacmanPlayer.y % PACMAN_TILE === 0);

    if (onGridX && onGridY) {
        const col = Math.round(pacmanPlayer.x / PACMAN_TILE);
        const row = Math.round(pacmanPlayer.y / PACMAN_TILE);

        // Punkt oder Power-Pille auf dieser Kachel einsammeln
        if (col >= 0 && col < PACMAN_COLS && row >= 0 && row < PACMAN_ROWS) {
            const cell = pacmanGrid[row][col];
            if (cell === 2) {
                pacmanGrid[row][col] = 0;
                pacmanScoreVal += 10;
                pacmanDotsLeft--;
                pacmanUpdateHud();
            } else if (cell === 3) {
                pacmanGrid[row][col] = 0;
                pacmanScoreVal += 50;
                pacmanDotsLeft--;
                pacmanFrightTimer = Math.max(180, 440 - (pacmanLevelVal - 1) * 45);
                pacmanGhostCombo  = 0;
                // Geister kehren bei Power-Pille sofort die Richtung um
                pacmanGhosts.forEach((g) => {
                    if (!g.eaten) {
                        g.dx = -g.dx;
                        g.dy = -g.dy;
                    }
                });
                pacmanUpdateHud('⚡ JAGD-MODUS!');
            }

            // Bonusfrucht erscheint bei 70 und 30 verbleibenden Punkten
            if ((pacmanDotsLeft === 70 || pacmanDotsLeft === 30) && !pacmanBonusFruit) {
                const fruits = [
                    { icon: '🍒', pts: 200 },
                    { icon: '🍓', pts: 350 },
                    { icon: '🍊', pts: 500 },
                    { icon: '🍎', pts: 700 },
                    { icon: '👑', pts: 1000 }
                ];
                const f = fruits[(pacmanLevelVal - 1) % fruits.length];
                pacmanBonusFruit = { col: 9, row: 12, icon: f.icon, pts: f.pts, timer: 480 };
            }

            // Alle Punkte im Level abgeräumt?
            if (pacmanDotsLeft <= 0) {
                pacmanLevelVal++;
                pacmanScoreVal += 500;
                pacmanFrightTimer = 0;
                pacmanBonusFruit  = null;
                pacmanPopups.push({ x: 9 * PACMAN_TILE, y: 10 * PACMAN_TILE, text: 'LEVEL ' + pacmanLevelVal + '! +500', color: '#fde047', life: 70 });
                pacmanBuildGrid();
                pacmanResetPositions();
                pacmanUpdateHud('🎉 Level ' + pacmanLevelVal + '!');
                return;
            }
        }

        // Vorgemerkte Richtung prüfen
        if (pacmanPlayer.nextDx !== 0 || pacmanPlayer.nextDy !== 0) {
            if (pacmanCanMoveTile(col + pacmanPlayer.nextDx, row + pacmanPlayer.nextDy, false, false)) {
                pacmanPlayer.dx = pacmanPlayer.nextDx;
                pacmanPlayer.dy = pacmanPlayer.nextDy;
                pacmanPlayer.facingAngle = Math.atan2(pacmanPlayer.dy, pacmanPlayer.dx);
            }
        }

        // Ist die aktuelle Richtung blockiert?
        if (!pacmanCanMoveTile(col + pacmanPlayer.dx, row + pacmanPlayer.dy, false, false)) {
            pacmanPlayer.dx = 0;
            pacmanPlayer.dy = 0;
        }
    }

    pacmanPlayer.x += pacmanPlayer.dx * speed;
    pacmanPlayer.y += pacmanPlayer.dy * speed;

    // Mund-Animation
    if (pacmanPlayer.dx !== 0 || pacmanPlayer.dy !== 0) {
        pacmanPlayer.mouthAngle += pacmanPlayer.mouthSpeed;
        if (pacmanPlayer.mouthAngle > 0.36 || pacmanPlayer.mouthAngle < 0.03) {
            pacmanPlayer.mouthSpeed = -pacmanPlayer.mouthSpeed;
        }
    }

    // Bonusfrucht-Kollision prüfen
    if (pacmanBonusFruit) {
        pacmanBonusFruit.timer--;
        const fx = pacmanBonusFruit.col * PACMAN_TILE;
        const fy = pacmanBonusFruit.row * PACMAN_TILE;
        if (Math.hypot(pacmanPlayer.x - fx, pacmanPlayer.y - fy) < PACMAN_TILE * 0.75) {
            pacmanScoreVal += pacmanBonusFruit.pts;
            pacmanPopups.push({
                x: fx + 12,
                y: fy,
                text: '+' + pacmanBonusFruit.pts + ' ' + pacmanBonusFruit.icon,
                color: '#4ade80',
                life: 55
            });
            pacmanBonusFruit = null;
            pacmanUpdateHud('🍒 Bonus gesammelt!');
        } else if (pacmanBonusFruit.timer <= 0) {
            pacmanBonusFruit = null;
        }
    }
}

function pacmanStepGhosts() {
    const pacCol = Math.round(pacmanPlayer.x / PACMAN_TILE);
    const pacRow = Math.round(pacmanPlayer.y / PACMAN_TILE);
    const scatterMode = (Math.floor(pacmanTickCount / 420) % 3 === 1) && pacmanFrightTimer === 0;

    pacmanGhosts.forEach((g, idx) => {
        if (g.releaseDelay > 0) {
            g.releaseDelay--;
            return;
        }

        // Geschwindigkeit muss Teiler von 24 sein (1, 2 oder 4), bzw. wenn nicht auf Gitter, sanft einrasten
        let speed = 2;
        if (g.eaten) {
            speed = 4;
        } else if (pacmanFrightTimer > 0) {
            speed = (pacmanTickCount % 2 === 0) ? 2 : 0; // halbierte Geschwindigkeit im Frightened-Modus, bleibt auf 2px-Raster!
        }
        if (speed === 0) return;

        // Sicherstellen, dass Koordinaten für speed=4 auf Vielfaches von 4 einrasten
        if (speed === 4) {
            if (g.x % 4 !== 0) g.x += (g.dx >= 0 ? 2 : -2);
            if (g.y % 4 !== 0) g.y += (g.dy >= 0 ? 2 : -2);
        }

        // Warp-Tunnel
        if (g.x < -PACMAN_TILE / 2) {
            g.x = (PACMAN_COLS - 1) * PACMAN_TILE;
        } else if (g.x > (PACMAN_COLS - 1) * PACMAN_TILE + PACMAN_TILE / 2) {
            g.x = 0;
        }

        const onGridX = (g.x % PACMAN_TILE === 0);
        const onGridY = (g.y % PACMAN_TILE === 0);

        if (onGridX && onGridY) {
            const col = Math.round(g.x / PACMAN_TILE);
            const row = Math.round(g.y / PACMAN_TILE);
            const inHouse = (row >= 9 && row <= 10 && col >= 8 && col <= 10);

            // Wenn gefressener Geist vor oder im Geisterhaus ankommt -> wiederbeleben!
            if (g.eaten && ((col === 9 && row === 8) || inHouse)) {
                g.eaten = false;
                g.dx = (Math.random() < 0.5) ? -1 : 1;
                g.dy = 0;
            }

            // Zielkachel bestimmen
            let targetX = pacCol;
            let targetY = pacRow;

            if (g.eaten) {
                targetX = 9;
                targetY = 8;
            } else if (inHouse) {
                targetX = 9;
                targetY = 7;
            } else if (scatterMode) {
                targetX = g.scatterX;
                targetY = g.scatterY;
            } else {
                if (idx === 0) {
                    // Blinky: direkte Jagd
                    targetX = pacCol;
                    targetY = pacRow;
                } else if (idx === 1) {
                    // Pinky: 4 Felder vor Pac-Man
                    targetX = pacCol + pacmanPlayer.dx * 4;
                    targetY = pacRow + pacmanPlayer.dy * 4;
                } else if (idx === 2) {
                    // Inky: Flankiert zusammen mit Blinky
                    const blinky = pacmanGhosts[0];
                    const bCol = Math.round(blinky.x / PACMAN_TILE);
                    const bRow = Math.round(blinky.y / PACMAN_TILE);
                    const aheadX = pacCol + pacmanPlayer.dx * 2;
                    const aheadY = pacRow + pacmanPlayer.dy * 2;
                    targetX = aheadX + (aheadX - bCol);
                    targetY = aheadY + (aheadY - bRow);
                } else {
                    // Clyde: Jagt wenn weit weg, zieht sich in die Ecke zurück wenn nah
                    const dist = Math.hypot(col - pacCol, row - pacRow);
                    if (dist > 6) {
                        targetX = pacCol;
                        targetY = pacRow;
                    } else {
                        targetX = g.scatterX;
                        targetY = g.scatterY;
                    }
                }
            }

            const dirs = [
                { dx: 0, dy: -1 },
                { dx: -1, dy: 0 },
                { dx: 0, dy: 1 },
                { dx: 1, dy: 0 }
            ];

            let validDirs = dirs.filter((d) => {
                // Kein direktes Umdrehen (außer in Sackgasse)
                if (d.dx === -g.dx && d.dy === -g.dy) return false;
                return pacmanCanMoveTile(col + d.dx, row + d.dy, true, inHouse || g.eaten);
            });

            if (validDirs.length === 0) {
                validDirs = dirs.filter((d) => pacmanCanMoveTile(col + d.dx, row + d.dy, true, inHouse || g.eaten));
            }

            if (validDirs.length > 0) {
                if (pacmanFrightTimer > 0 && !g.eaten && !inHouse) {
                    // Im Frightened-Modus zufällige Abzweigung wählen
                    const pick = validDirs[Math.floor(Math.random() * validDirs.length)];
                    g.dx = pick.dx;
                    g.dy = pick.dy;
                } else {
                    // Kürzeste Distanz zur Zielkachel wählen
                    validDirs.sort((a, b) => {
                        const da = Math.hypot((col + a.dx) - targetX, (row + a.dy) - targetY);
                        const db = Math.hypot((col + b.dx) - targetX, (row + b.dy) - targetY);
                        return da - db;
                    });
                    g.dx = validDirs[0].dx;
                    g.dy = validDirs[0].dy;
                }
            }
        }

        g.x += g.dx * speed;
        g.y += g.dy * speed;
    });
}

function pacmanCheckCollisions() {
    for (const g of pacmanGhosts) {
        const dist = Math.hypot(pacmanPlayer.x - g.x, pacmanPlayer.y - g.y);
        if (dist < PACMAN_TILE * 0.68) {
            if (g.eaten) {
                continue;
            }
            if (pacmanFrightTimer > 0) {
                // Geist gefressen!
                g.eaten = true;
                pacmanGhostCombo++;
                const pts = 200 * Math.pow(2, pacmanGhostCombo - 1);
                pacmanScoreVal += pts;
                pacmanPopups.push({
                    x: g.x + 12,
                    y: g.y,
                    text: '+' + pts,
                    color: '#38bdf8',
                    life: 50
                });
                pacmanUpdateHud('👻 +' + pts + ' Pkt.!');
            } else {
                // Leben verloren!
                pacmanLivesVal--;
                pacmanFrightTimer = 0;
                if (pacmanLivesVal <= 0) {
                    pacmanRunning = false;
                    pacmanUpdateHud('💥 Game Over!');
                    const durSec = Math.max(1, Math.round((performance.now() - pacmanStartTime) / 1000));
                    submitCloudScore('pacman', pacmanScoreVal, durSec, 'Level ' + pacmanLevelVal);
                    return;
                } else {
                    pacmanPopups.push({
                        x: pacmanPlayer.x + 12,
                        y: pacmanPlayer.y,
                        text: '💔 Autsch!',
                        color: '#f87171',
                        life: 55
                    });
                    pacmanResetPositions();
                    pacmanUpdateHud('Noch ' + pacmanLivesVal + ' Leben');
                    return;
                }
            }
        }
    }
}

function pacmanDraw() {
    const cvs = document.getElementById('pacmanCanvas');
    if (!cvs) return;
    const ctx = cvs.getContext('2d');

    ctx.fillStyle = '#020617';
    ctx.fillRect(0, 0, cvs.width, cvs.height);

    // 1. Labyrinth, Punkte & Power-Pillen zeichnen
    const pelletBlink = Math.floor(pacmanTickCount / 12) % 2 === 0;
    for (let r = 0; r < PACMAN_ROWS; r++) {
        for (let c = 0; c < PACMAN_COLS; c++) {
            const cell = pacmanGrid[r]?.[c] ?? 0;
            const x = c * PACMAN_TILE;
            const y = r * PACMAN_TILE;

            if (cell === 1) {
                ctx.fillStyle   = '#1e3a8a';
                ctx.strokeStyle = '#3b82f6';
                ctx.lineWidth   = 1.5;
                ctx.beginPath();
                ctx.roundRect(x + 2, y + 2, PACMAN_TILE - 4, PACMAN_TILE - 4, 5);
                ctx.fill();
                ctx.stroke();
            } else if (cell === 4) {
                // Geisterhaus-Schranke
                ctx.fillStyle = '#f472b6';
                ctx.fillRect(x + 2, y + 10, PACMAN_TILE - 4, 4);
            } else if (cell === 2) {
                // Pac-Dot
                ctx.fillStyle = '#fde68a';
                ctx.beginPath();
                ctx.arc(x + PACMAN_TILE / 2, y + PACMAN_TILE / 2, 3, 0, Math.PI * 2);
                ctx.fill();
            } else if (cell === 3) {
                // Blinkende Power-Pille
                ctx.fillStyle = pelletBlink ? '#facc15' : '#fef08a';
                ctx.beginPath();
                ctx.arc(x + PACMAN_TILE / 2, y + PACMAN_TILE / 2, pelletBlink ? 7.5 : 5.5, 0, Math.PI * 2);
                ctx.fill();
            }
        }
    }

    // 2. Bonusfrucht zeichnen
    if (pacmanBonusFruit) {
        const fx = pacmanBonusFruit.col * PACMAN_TILE + PACMAN_TILE / 2;
        const fy = pacmanBonusFruit.row * PACMAN_TILE + PACMAN_TILE / 2 + 5;
        ctx.font = '18px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText(pacmanBonusFruit.icon, fx, fy);
    }

    // 3. Pac-Man zeichnen
    const px = pacmanPlayer.x + PACMAN_TILE / 2;
    const py = pacmanPlayer.y + PACMAN_TILE / 2;
    const mouth = pacmanPlayer.mouthAngle * Math.PI;
    ctx.save();
    ctx.translate(px, py);
    ctx.rotate(pacmanPlayer.facingAngle);
    ctx.fillStyle = '#facc15';
    ctx.shadowColor = '#facc15';
    ctx.shadowBlur = 8;
    ctx.beginPath();
    ctx.arc(0, 0, 10.5, mouth, Math.PI * 2 - mouth);
    ctx.lineTo(0, 0);
    ctx.closePath();
    ctx.fill();
    ctx.restore();

    // 4. Die 4 Geister zeichnen
    pacmanGhosts.forEach((g) => {
        const gx = g.x + PACMAN_TILE / 2;
        const gy = g.y + PACMAN_TILE / 2;

        ctx.save();
        ctx.translate(gx, gy);

        if (!g.eaten) {
            let bodyColor = g.color;
            if (pacmanFrightTimer > 0) {
                const flashWhite = pacmanFrightTimer < 110 && (Math.floor(pacmanTickCount / 8) % 2 === 0);
                bodyColor = flashWhite ? '#f8fafc' : '#1d4ed8';
            }
            ctx.fillStyle = bodyColor;
            ctx.beginPath();
            ctx.arc(0, -2, 10, Math.PI, 0, false);
            ctx.lineTo(10, 9);
            // Gewellter Geister-Saum unten
            const waveShift = (Math.floor(pacmanTickCount / 6) % 2 === 0) ? 1 : -1;
            ctx.lineTo(6, 7 + waveShift);
            ctx.lineTo(2, 9 - waveShift);
            ctx.lineTo(-2, 7 + waveShift);
            ctx.lineTo(-6, 9 - waveShift);
            ctx.lineTo(-10, 9);
            ctx.closePath();
            ctx.fill();

            if (pacmanFrightTimer > 0) {
                // Gezackter erschrockener Mund
                ctx.strokeStyle = '#fde047';
                ctx.lineWidth = 1.5;
                ctx.beginPath();
                ctx.moveTo(-6, 4);
                ctx.lineTo(-3, 2);
                ctx.lineTo(0, 4);
                ctx.lineTo(3, 2);
                ctx.lineTo(6, 4);
                ctx.stroke();
            }
        }

        // Geister-Augen (schauen in Bewegungsrichtung)
        const lookX = g.dx * 2;
        const lookY = g.dy * 2;
        ctx.fillStyle = '#ffffff';
        ctx.beginPath();
        ctx.arc(-4 + lookX * 0.5, -3 + lookY * 0.5, 3.3, 0, Math.PI * 2);
        ctx.arc(4 + lookX * 0.5, -3 + lookY * 0.5, 3.3, 0, Math.PI * 2);
        ctx.fill();

        ctx.fillStyle = (pacmanFrightTimer > 0 && !g.eaten) ? '#fde047' : '#0f172a';
        ctx.beginPath();
        ctx.arc(-4 + lookX * 1.2, -3 + lookY * 1.2, 1.7, 0, Math.PI * 2);
        ctx.arc(4 + lookX * 1.2, -3 + lookY * 1.2, 1.7, 0, Math.PI * 2);
        ctx.fill();

        ctx.restore();
    });

    // 5. Schwebende Punkte-Popups (+200, +400, Bonusfrucht)
    for (let i = pacmanPopups.length - 1; i >= 0; i--) {
        const p = pacmanPopups[i];
        ctx.save();
        ctx.font = 'bold 12px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillStyle = p.color;
        ctx.fillText(p.text, p.x, p.y);
        ctx.restore();
        p.y -= 0.5;
        p.life--;
        if (p.life <= 0) {
            pacmanPopups.splice(i, 1);
        }
    }

    // 6. Start- / Pausen- / Game-Over-Banner
    if (!pacmanRunning || pacmanPaused) {
        ctx.fillStyle = 'rgba(2, 6, 23, 0.72)';
        ctx.fillRect(0, cvs.height / 2 - 42, cvs.width, 84);
        ctx.textAlign = 'center';
        ctx.fillStyle = '#facc15';
        ctx.font = 'bold 22px sans-serif';
        if (pacmanPaused) {
            ctx.fillText('⏸️ SPIEL PAUSIERT', cvs.width / 2, cvs.height / 2 - 4);
            ctx.fillStyle = '#cbd5e1';
            ctx.font = '12px sans-serif';
            ctx.fillText('Klicke auf „Weiter“ oder drücke P zum Fortsetzen', cvs.width / 2, cvs.height / 2 + 20);
        } else if (pacmanLivesVal <= 0) {
            ctx.fillText('👻 GAME OVER – ' + pacmanScoreVal.toLocaleString('de-DE') + ' Pkt.', cvs.width / 2, cvs.height / 2 - 4);
            ctx.fillStyle = '#cbd5e1';
            ctx.font = '12px sans-serif';
            ctx.fillText('Drücke eine Pfeiltaste oder „Neues Spiel starten“', cvs.width / 2, cvs.height / 2 + 20);
        } else {
            ctx.fillText('🟡 CLOUD-PACMAN BEREIT!', cvs.width / 2, cvs.height / 2 - 4);
            ctx.fillStyle = '#cbd5e1';
            ctx.font = '12px sans-serif';
            ctx.fillText('Drücke eine Pfeiltaste / WASD oder „Neues Spiel starten“', cvs.width / 2, cvs.height / 2 + 20);
        }
    }
}

function pacmanLoop() {
    if (!pacmanRunning || pacmanPaused) return;
    pacmanTickCount++;

    if (pacmanFrightTimer > 0) {
        pacmanFrightTimer--;
        if (pacmanFrightTimer === 0) {
            pacmanUpdateHud('Aktiv');
        }
    }

    pacmanStepPlayer();
    if (pacmanRunning) {
        pacmanStepGhosts();
        pacmanCheckCollisions();
    }
    pacmanDraw();

    if (pacmanRunning && !pacmanPaused) {
        pacmanRafId = requestAnimationFrame(pacmanLoop);
    }
}

// =============================================================================
// SPIEL 11: COMMODORE VC-20 „BLITZ“ (1981 RETRO-KULTKLASSIKER)
// =============================================================================
const BLITZ_TILE = 24;
const BLITZ_COLS = 22;
const BLITZ_ROWS = 18;
const BLITZ_GROUND_ROW = 16; // Zeile 17 ist das Rollfeld / Fundament
const BLITZ_COLORS_VC20 = ['#ef4444', '#facc15', '#22c55e', '#a855f7', '#fb923c', '#38bdf8'];

let blitzInitialized = false;
let blitzRunning     = false;
let blitzLanding     = false;
let blitzCrashed     = false;
let blitzClassicMode = true; // true = VC-20 Weiß/Cyan/Blau-Look, false = Moderne Nacht-Skyline
let blitzRafId       = null;
let blitzScoreVal    = 0;
let blitzCityVal     = 1;
let blitzStartTime   = 0;
let blitzTowers      = []; // Array der Länge 22: { height, color, windowsSeed }
let blitzPlane       = { x: 0, row: 1, speed: 2.3 };
let blitzBomb        = null; // { col, y, power }
let blitzParticles   = [];
let blitzBannerText  = '';

function blitzBuildCity(cityNum) {
    blitzTowers = [];
    const minH = Math.min(8, 2 + Math.floor((cityNum - 1) * 0.7));
    const maxH = Math.min(13, 6 + Math.floor(cityNum * 1.1));

    for (let c = 0; c < BLITZ_COLS; c++) {
        // Spalten 0..2 links und 19..21 rechts bleiben als Einflug-/Ausflugschneise frei
        if (c < 3 || c > 18) {
            blitzTowers.push({ height: 0, color: '#64748b', seed: 0 });
        } else {
            const h = Math.floor(minH + Math.random() * (maxH - minH + 1));
            const colHex = BLITZ_COLORS_VC20[(c + cityNum) % BLITZ_COLORS_VC20.length];
            blitzTowers.push({ height: h, color: colHex, seed: (c * 37 + cityNum * 13) });
        }
    }
}

function blitzCountBlocksLeft() {
    let sum = 0;
    for (let c = 0; c < BLITZ_COLS; c++) {
        sum += Math.max(0, blitzTowers[c]?.height || 0);
    }
    return sum;
}

function blitzUpdateHud(bombMsg = null) {
    const scEl = document.getElementById('blitzScore');
    const ctEl = document.getElementById('blitzCity');
    const blEl = document.getElementById('blitzBlocksLeft');
    const bmEl = document.getElementById('blitzBombStatus');

    if (scEl) scEl.textContent = blitzScoreVal.toLocaleString('de-DE');
    if (ctEl) ctEl.textContent = String(blitzCityVal);
    if (blEl) blEl.textContent = String(blitzCountBlocksLeft());
    if (bmEl) {
        if (bombMsg !== null) {
            bmEl.textContent = bombMsg;
        } else if (blitzBomb) {
            bmEl.textContent = '🔴 Bombe fällt…';
        } else {
            bmEl.textContent = '🟢 Bereit (Space)';
        }
    }
}

function blitzToggleTheme() {
    blitzClassicMode = !blitzClassicMode;
    const btn = document.getElementById('blitzThemeBtn');
    if (btn) {
        btn.textContent = blitzClassicMode ? '🖥️ Optik: VC-20 Klassik' : '🌙 Optik: Nacht-Skyline';
    }
    blitzDraw();
}

function blitzInit() {
    blitzInitialized = true;
    blitzCityVal  = 1;
    blitzScoreVal = 0;
    blitzBuildCity(1);
    blitzPlane = { x: 1 * BLITZ_TILE, row: 1, speed: 2.3 };
    blitzBomb  = null;
    blitzBannerText = 'DRÜCKE LEERTASTE ODER START';
    blitzUpdateHud('🟢 Bereit');
    blitzDraw();
}

function blitzStartGame() {
    if (blitzRafId) cancelAnimationFrame(blitzRafId);
    blitzScoreVal   = 0;
    blitzCityVal    = 1;
    blitzRunning    = true;
    blitzLanding    = false;
    blitzCrashed    = false;
    blitzBomb       = null;
    blitzParticles  = [];
    blitzBannerText = '';
    blitzStartTime  = performance.now();

    blitzBuildCity(1);
    blitzPlane = { x: 0, row: 1, speed: 2.3 };
    blitzUpdateHud('🟢 Bereit');
    blitzLoop();
}

function blitzStartNextCity() {
    blitzCityVal++;
    blitzLanding    = false;
    blitzCrashed    = false;
    blitzBomb       = null;
    blitzParticles  = [];
    blitzBannerText = '';
    blitzBuildCity(blitzCityVal);
    blitzPlane = {
        x: 0,
        row: 1,
        speed: Math.min(4.4, 2.3 + (blitzCityVal - 1) * 0.28)
    };
    blitzUpdateHud('🎉 City ' + blitzCityVal + '!');
}

function blitzDropBomb() {
    if (!blitzRunning) {
        blitzStartGame();
        return;
    }
    if (blitzLanding || blitzCrashed || blitzBomb !== null) {
        return;
    }
    // Aktuelle Spalte unter der Flugzeugmitte bestimmen
    const centerCol = Math.floor((blitzPlane.x + BLITZ_TILE * 0.6) / BLITZ_TILE);
    if (centerCol < 0 || centerCol >= BLITZ_COLS) return;

    blitzBomb = {
        col: centerCol,
        y: (blitzPlane.row + 0.8) * BLITZ_TILE,
        power: 3 + (Math.random() < 0.45 ? 1 : 0) // zerstört 3 bis 4 Stockwerke wie im VC-20 Original!
    };
    blitzUpdateHud();
}

function blitzSpawnExplosion(x, y, color, count = 14) {
    for (let i = 0; i < count; i++) {
        const ang = Math.random() * Math.PI * 2;
        const spd = 1 + Math.random() * 3.5;
        blitzParticles.push({
            x,
            y,
            vx: Math.cos(ang) * spd,
            vy: Math.sin(ang) * spd - 1.2,
            color,
            life: 24 + Math.floor(Math.random() * 18)
        });
    }
}

function blitzStep() {
    // 1. Sind alle Hochhäuser abgetragen? -> Automatischer Sinkflug zur Landebahn!
    if (!blitzLanding && blitzCountBlocksLeft() === 0) {
        blitzLanding = true;
        blitzUpdateHud('🛬 Landeanflug!');
    }

    // 2. Flugzeug bewegen
    const currentSpeed = blitzLanding ? Math.max(blitzPlane.speed * 1.4, 3.6) : blitzPlane.speed;
    blitzPlane.x += currentSpeed;

    if (blitzLanding && blitzPlane.row < BLITZ_GROUND_ROW) {
        // Im Landeanflug gleitet das Flugzeug sanft direkt zur Landebahn hinunter
        if (blitzPlane.x > BLITZ_COLS * BLITZ_TILE) {
            blitzPlane.x = -BLITZ_TILE;
            blitzPlane.row = Math.min(BLITZ_GROUND_ROW, blitzPlane.row + 3);
        }
    } else if (blitzPlane.x > BLITZ_COLS * BLITZ_TILE) {
        blitzPlane.x = -BLITZ_TILE;
        blitzPlane.row++;
    }

    // Hat das Flugzeug auf der untersten Zeile (Rollbahn) sicher das rechte Ende erreicht?
    if (blitzPlane.row >= BLITZ_GROUND_ROW) {
        blitzPlane.row = BLITZ_GROUND_ROW;
        if (blitzPlane.x >= (BLITZ_COLS - 3) * BLITZ_TILE && blitzCountBlocksLeft() === 0) {
            const bonus = 500 * blitzCityVal;
            blitzScoreVal += bonus;
            blitzSpawnExplosion(blitzPlane.x, blitzPlane.row * BLITZ_TILE, '#facc15', 22);
            blitzStartNextCity();
            return;
        }
    }

    // 3. Kollision des Flugzeugs mit einem Hochhaus prüfen
    const noseCol = Math.floor((blitzPlane.x + BLITZ_TILE * 0.85) / BLITZ_TILE);
    const tailCol = Math.floor((blitzPlane.x + BLITZ_TILE * 0.15) / BLITZ_TILE);
    for (const checkCol of [noseCol, tailCol]) {
        if (checkCol >= 0 && checkCol < BLITZ_COLS) {
            const towerH = blitzTowers[checkCol].height;
            const towerTopRow = BLITZ_GROUND_ROW - towerH + 1;
            if (towerH > 0 && blitzPlane.row >= towerTopRow) {
                // Kollision mit Wolkenkratzer!
                blitzCrashed    = true;
                blitzRunning    = false;
                blitzBannerText = '💥 CRASH IN CITY ' + blitzCityVal + '! (' + blitzScoreVal.toLocaleString('de-DE') + ' PKT.)';
                blitzSpawnExplosion(blitzPlane.x + 12, blitzPlane.row * BLITZ_TILE + 12, '#ef4444', 32);
                blitzUpdateHud('💥 Abgestürzt!');
                const durSec = Math.max(1, Math.round((performance.now() - blitzStartTime) / 1000));
                submitCloudScore('blitz', blitzScoreVal, durSec, 'City ' + blitzCityVal);
                return;
            }
        }
    }

    // 4. Fallende Bombe bewegen
    if (blitzBomb) {
        blitzBomb.y += 5.5;
        const bCol = blitzBomb.col;
        const tower = blitzTowers[bCol];
        const towerTopY = (BLITZ_GROUND_ROW - tower.height + 1) * BLITZ_TILE;
        const groundY   = (BLITZ_GROUND_ROW + 1) * BLITZ_TILE;

        if (tower.height > 0 && blitzBomb.y >= towerTopY) {
            // Treffer auf Wolkenkratzer!
            const destroyed = Math.min(tower.height, blitzBomb.power);
            tower.height   -= destroyed;
            blitzScoreVal  += destroyed * 10;
            blitzSpawnExplosion(bCol * BLITZ_TILE + 12, blitzBomb.y, tower.color, 14);

            // Wenn die Bombe noch Rest-Sprengkraft hat, erst auf der neuen Dachkante explodieren lassen
            blitzBomb = null;
            blitzUpdateHud();
        } else if (blitzBomb.y >= groundY) {
            // Bombe schlägt auf freiem Boden auf
            blitzSpawnExplosion(bCol * BLITZ_TILE + 12, groundY - 4, '#94a3b8', 8);
            blitzBomb = null;
            blitzUpdateHud();
        }
    }

    // 5. Partikel aktualisieren
    for (let i = blitzParticles.length - 1; i >= 0; i--) {
        const p = blitzParticles[i];
        p.x += p.vx;
        p.y += p.vy;
        p.vy += 0.15;
        p.life--;
        if (p.life <= 0) {
            blitzParticles.splice(i, 1);
        }
    }
}

function blitzDraw() {
    const cvs = document.getElementById('blitzCanvas');
    if (!cvs) return;
    const ctx = cvs.getContext('2d');

    // Hintergrund (VC-20 Klassik vs. Nacht-Skyline)
    if (blitzClassicMode) {
        ctx.fillStyle = '#0f172a';
    } else {
        const grad = ctx.createLinearGradient(0, 0, 0, cvs.height);
        grad.addColorStop(0, '#020617');
        grad.addColorStop(1, '#1e1b4b');
        ctx.fillStyle = grad;
    }
    ctx.fillRect(0, 0, cvs.width, cvs.height);

    // VC-20 Retro-Kopfzeile im Canvas
    ctx.fillStyle = blitzClassicMode ? '#22d3ee' : '#94a3b8';
    ctx.font = 'bold 13px monospace';
    ctx.textAlign = 'left';
    ctx.fillText('COMMODORE VIC-20 BLITZ', 12, 18);
    ctx.textAlign = 'right';
    ctx.fillText('CITY ' + blitzCityVal + '  SCORE ' + String(blitzScoreVal).padStart(5, '0'), cvs.width - 12, 18);

    // Landebahn / Boden (Zeile 17)
    const groundY = (BLITZ_GROUND_ROW + 1) * BLITZ_TILE;
    ctx.fillStyle = blitzClassicMode ? '#22c55e' : '#334155';
    ctx.fillRect(0, groundY, cvs.width, BLITZ_TILE);
    // Landebahn-Markierungen
    ctx.fillStyle = '#facc15';
    for (let rx = 8; rx < cvs.width; rx += 32) {
        ctx.fillRect(rx, groundY + 10, 16, 4);
    }

    // Wolkenkratzer zeichnen
    for (let c = 0; c < BLITZ_COLS; c++) {
        const t = blitzTowers[c];
        if (!t || t.height <= 0) continue;
        const x = c * BLITZ_TILE;

        for (let f = 0; f < t.height; f++) {
            const row = BLITZ_GROUND_ROW - f;
            const y   = row * BLITZ_TILE;

            ctx.fillStyle = t.color;
            ctx.fillRect(x + 1, y + 1, BLITZ_TILE - 2, BLITZ_TILE - 2);

            // Beleuchtete PETSCII-Fenster im 8-Bit-Stil
            ctx.fillStyle = ((t.seed + f * 7) % 3 === 0) ? '#0f172a' : '#fef08a';
            ctx.fillRect(x + 5, y + 5, 5, 6);
            ctx.fillStyle = ((t.seed + f * 11) % 4 === 0) ? '#0f172a' : '#fef08a';
            ctx.fillRect(x + 14, y + 5, 5, 6);
            ctx.fillRect(x + 5, y + 14, 5, 6);
            ctx.fillRect(x + 14, y + 14, 5, 6);
        }

        // Kleine Dach-Antenne auf dem obersten Stockwerk
        const topY = (BLITZ_GROUND_ROW - t.height + 1) * BLITZ_TILE;
        ctx.fillStyle = '#cbd5e1';
        ctx.fillRect(x + 11, topY - 5, 2, 5);
    }

    // Fallende Bombe zeichnen
    if (blitzBomb) {
        const bx = blitzBomb.col * BLITZ_TILE + BLITZ_TILE / 2;
        const by = blitzBomb.y;
        ctx.fillStyle = '#f8fafc';
        ctx.beginPath();
        ctx.arc(bx, by, 5, 0, Math.PI * 2);
        ctx.fill();
        ctx.fillStyle = '#ef4444';
        ctx.fillRect(bx - 4, by - 8, 8, 3);
    }

    // Flugzeug im VC-20 Pixel-Stil zeichnen
    const px = blitzPlane.x;
    const py = blitzPlane.row * BLITZ_TILE;
    ctx.save();
    ctx.translate(px, py);

    // Rumpf
    ctx.fillStyle = blitzCrashed ? '#ef4444' : '#f8fafc';
    ctx.fillRect(2, 9, 22, 6);
    // Heckflosse
    ctx.fillStyle = '#ef4444';
    ctx.beginPath();
    ctx.moveTo(2, 9);
    ctx.lineTo(2, 2);
    ctx.lineTo(9, 9);
    ctx.closePath();
    ctx.fill();
    // Cockpit-Fenster
    ctx.fillStyle = '#38bdf8';
    ctx.fillRect(16, 6, 6, 4);
    // Tragfläche
    ctx.fillStyle = '#facc15';
    ctx.fillRect(9, 13, 8, 4);
    // Propeller vorne (animiert)
    ctx.fillStyle = (Math.floor(performance.now() / 40) % 2 === 0) ? '#facc15' : '#94a3b8';
    ctx.fillRect(24, 5, 2, 14);

    ctx.restore();

    // Explosions-Partikel zeichnen
    for (const p of blitzParticles) {
        ctx.fillStyle = p.color;
        ctx.fillRect(p.x - 2, p.y - 2, 4, 4);
    }

    // Start- oder Crash-Banner
    if (!blitzRunning) {
        ctx.fillStyle = 'rgba(2, 6, 23, 0.78)';
        ctx.fillRect(0, cvs.height / 2 - 44, cvs.width, 88);
        ctx.textAlign = 'center';
        ctx.fillStyle = blitzCrashed ? '#f87171' : '#22d3ee';
        ctx.font = 'bold 20px monospace';
        ctx.fillText(blitzBannerText || '✈️ VC-20 BLITZ BEREIT', cvs.width / 2, cvs.height / 2 - 6);
        ctx.fillStyle = '#e2e8f0';
        ctx.font = '13px sans-serif';
        ctx.fillText('Drücke die LEERTASTE oder klicke ins Bild, um zu starten & Bomben abzuwerfen!', cvs.width / 2, cvs.height / 2 + 20);
    }
}

function blitzLoop() {
    if (!blitzRunning) {
        blitzDraw();
        return;
    }
    blitzStep();
    blitzDraw();
    if (blitzRunning) {
        blitzRafId = requestAnimationFrame(blitzLoop);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    renderLeaderboardRows(<?= json_encode($initialLeaderboard) ?>);
    gummiInit();
    pacmanInit();
    blitzInit();
    sudokuInit();
    mineNewGame('medium');
    memoryNewGame(16);
    tetrisDraw();
    snakeDraw();
    c4AiNewGame();
    wowInit();

    const urlGame = new URLSearchParams(window.location.search).get('game');
    if (urlGame && GAMES_CATALOG[urlGame]) {
        selectCloudGame(urlGame);
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
