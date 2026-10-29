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
                        10 Spiele &middot; Cloud-Highscores &middot; Live-Duelle &amp; RPG
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

    <!-- SPIELAUSWAHL-LEISTE (10 SPIEL-KARTEN) -->
    <div class="grid grid-cols-2 sm:grid-cols-5 lg:grid-cols-10 gap-2.5">
        <?php foreach ($gamesCatalog as $gKey => $gMeta): ?>
            <?php
            $gKeyStr = (string)$gKey;
            $myBest  = $myBestByGame[$gKeyStr] ?? 0;
            ?>
            <button type="button"
                    onclick="selectCloudGame('<?= e($gKeyStr) ?>')"
                    id="gameTabBtn-<?= e($gKeyStr) ?>"
                    class="game-tab-btn text-left p-3 rounded-2xl border transition-all cursor-pointer flex flex-col justify-between gap-1.5 <?= $gKeyStr === '2048' ? 'bg-brand-600 text-white border-brand-600 shadow-md' : 'bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100 border-slate-200 dark:border-slate-800 hover:border-brand-400' ?>">
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
// GLOBALE TASTATUR-STEUERUNG & SEKUNDEN-TIMER
// =============================================================================
window.addEventListener('keyup', (e) => {
    delete wowKeysDown[e.key];
});

window.addEventListener('keydown', (e) => {
    if (['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName || '')) return;

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
    } else if (activeGameKey === 'wow') {
        wowSyncPeers();
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
    wowInit();

    const urlGame = new URLSearchParams(window.location.search).get('game');
    if (urlGame && GAMES_CATALOG[urlGame]) {
        selectCloudGame(urlGame);
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
