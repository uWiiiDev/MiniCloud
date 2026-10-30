<?php
declare(strict_types=1);

/**
 * MiniCloud – Browserspiele-Zentrale, Cloud-Bestenlisten & Mehrspieler-Duelle (games.php)
 * 100 % lokal, werbefrei, Strato-, Intranet- & DSGVO-tauglich (HTML5 / Canvas / PHP / MySQL).
 *
 * Enthaltene 17 Spiele:
 * 1.  🏎️ Gummigeruch Kart-Cup (6 Strecken, KI-Fahrer & Ghost-Duelle)
 * 2.  🚘 Cloud-Traffic-Racer (Highway-Raser mit Gegenverkehr, Close-Overtake-Kombo, Lichthupe & 5 Tuning-Autos)
 * 3.  🐹 Cloud-Lemmings (1991 Klassiker: Zerstörbares Pixel-Gelände, 8 Fähigkeiten & 4 Kult-Level)
 * 4.  🥷 The Last Ninja (1987 C64-Klassiker: 6 isometrische Tempel-Räume, Waffen, Rätsel & Shogun Kunitoki)
 * 5.  🏃‍♂️ Cloud-Canabalt (Ein-Tasten-Parkour über einstürzende Dächer, Glasfenster & Taubenschwärme)
 * 6.  👾 Cloud-Invaders (1978 Arcade: 55 Pixel-Aliens, 4 Schutzbunker & Mystery-UFO)
 * 7.  🦍 Cloud-Kong (1981 Arcade: Stahlträger, Leitern, Fässer & Power-Hammer)
 * 8.  ✈️ VC-20 Blitz (1981 Retro-Klassiker: Hochhäuser per Bombe abtragen)
 * 9.  🟡 Cloud-PacMan (Arcade-Labyrinth mit 4 Geister-KIs & Power-Pillen)
 * 10. ⚔️ World of MiniCloud (Co-Op Fantasy-Rollenspiel & Boss-Raids)
 * 11. 🧠 Sudoku (3 Schwierigkeitsgrade, Notiz-Modus, Auto-Save & Timer)
 * 12. 💣 Minesweeper (3 Feldgrößen, Touch-Flaggenmodus & Bestzeit)
 * 13. 🃏 Memory (4x4 & 6x4 Paar-Suche)
 * 14. 🧱 Block-Puzzle (Tetris-Klassiker mit Vorschau & Leveln)
 * 15. 🐍 Snake (Arcade-Klassiker mit 3 Tempostufen)
 * 16. 🏓 Brick Breaker (Breakout mit Level-Fortschritt & Maus/Touch)
 * 17. 🔴🟡 Vier Gewinnt & ❌⭕ Tic-Tac-Toe (gegen Computer-KI ODER live gegen andere Cloud-Benutzer!)
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
 * Liefert den Katalog aller 17 Spiele inklusive Metadaten.
 *
 * @return array<string, array{key: string, title: string, icon: string, category: string, group: string, desc: string, color: string}>
 */
function getGamesCatalog(): array
{
    return [
        'gummigeruch' => [
            'key'      => 'gummigeruch',
            'title'    => 'Gummigeruch Kart',
            'icon'     => '🏎️',
            'category' => 'Rennspiel',
            'group'    => 'action',
            'desc'     => 'Kult-Kart-Rennen mit Drift-Physik, 6 Strecken, Ghost-Duellen & Live-Herausforderungen!',
            'color'    => 'from-rose-600 to-amber-500',
        ],
        'trafficracer' => [
            'key'      => 'trafficracer',
            'title'    => 'Cloud-Traffic-Racer',
            'icon'     => '🚘',
            'category' => 'Highway-Action',
            'group'    => 'action',
            'desc'     => 'Vollgas auf der 4-spurigen Autobahn: Knappe Überholmanöver (Close Overtake), Gegenverkehr, Lichthupe & 5 Tuning-Autos!',
            'color'    => 'from-orange-500 to-red-700',
        ],
        'darts' => [
            'key'      => 'darts',
            'title'    => 'Cloud-Darts (Ally Pally)',
            'icon'     => '🎯',
            'category' => 'Darts & Turnier',
            'group'    => 'action',
            'desc'     => '501 Double-Out, 301, Around the Clock & Highscore-Shootout mit Checkout-Rechner, 2.5× Ziel-Lupe & „ONE HUNDRED AND EIGHTY!“-Caller!',
            'color'    => 'from-emerald-600 to-red-700',
        ],
        'lemmings' => [
            'key'      => 'lemmings',
            'title'    => 'Cloud-Lemmings (1991)',
            'icon'     => '🐹',
            'category' => 'Strategie 1991',
            'group'    => 'logic',
            'desc'     => 'Rette die Kolonie mit Digger, Builder, Blocker, Basher, Floater & Bomber durch zerstörbares Pixel-Gelände!',
            'color'    => 'from-lime-500 to-emerald-700',
        ],
        'impossible' => [
            'key'      => 'impossible',
            'title'    => 'Impossible Mission (1984)',
            'icon'     => '🕵️‍♂️',
            'category' => 'C64 Klassiker',
            'group'    => 'arcade',
            'desc'     => '„Stay a while… stay forever!“ – Akrobatische Salti über Killer-Roboter, Möbel nach Lochkarten durchsuchen & Elvin Atombender stoppen!',
            'color'    => 'from-cyan-600 to-blue-900',
        ],
        'frogger' => [
            'key'      => 'frogger',
            'title'    => 'Cloud-Frogger (1981)',
            'icon'     => '🐸',
            'category' => 'Arcade 1981',
            'group'    => 'arcade',
            'desc'     => 'Hüpfe über den 5-spurigen Highway, reite auf Baumstämmen & Tauch-Schildkröten über den reißenden Fluss in die 5 Seerosen-Häuser!',
            'color'    => 'from-green-500 to-emerald-800',
        ],
        'lastninja' => [
            'key'      => 'lastninja',
            'title'    => 'The Last Ninja (1987)',
            'icon'     => '🥷',
            'category' => 'C64 Adventure',
            'group'    => 'arcade',
            'desc'     => 'Das legendäre C64-Action-Adventure: 6 isometrische Räume, Katana, Shuriken, Fluss-Sprünge & Shogun Kunitoki!',
            'color'    => 'from-amber-700 to-stone-900',
        ],
        'canabalt' => [
            'key'      => 'canabalt',
            'title'    => 'Cloud-Canabalt',
            'icon'     => '🏃‍♂️',
            'category' => 'Parkour-Runner',
            'group'    => 'arcade',
            'desc'     => 'Rasante Ein-Tasten-Flucht über einstürzende Wolkenkratzer, durch Glasfenster & Taubenschwärme!',
            'color'    => 'from-slate-600 to-zinc-900',
        ],
        'invaders' => [
            'key'      => 'invaders',
            'title'    => 'Cloud-Invaders (1978)',
            'icon'     => '👾',
            'category' => 'Arcade 1978',
            'group'    => 'arcade',
            'desc'     => 'Verteidige die Erde hinter 4 Schutzbunkern gegen 55 marschierende Pixel-Aliens & das Mystery-UFO!',
            'color'    => 'from-emerald-500 to-teal-700',
        ],
        'pacman' => [
            'key'      => 'pacman',
            'title'    => 'Cloud-PacMan',
            'icon'     => '🟡',
            'category' => 'Arcade',
            'group'    => 'arcade',
            'desc'     => 'Neon-Labyrinth mit 4 schlauen Geistern (Blinky, Pinky, Inky, Clyde), Power-Pillen & Bonusfrüchten!',
            'color'    => 'from-amber-400 to-yellow-600',
        ],
        'kong' => [
            'key'      => 'kong',
            'title'    => 'Cloud-Kong (1981)',
            'icon'     => '🦍',
            'category' => 'Arcade 1981',
            'group'    => 'arcade',
            'desc'     => 'Weiche auf dem Stahlträger-Gerüst rollenden Fässern aus, schnapp dir den 🔨 Hammer & klettere nach ganz oben!',
            'color'    => 'from-red-600 to-rose-700',
        ],
        'blitz' => [
            'key'      => 'blitz',
            'title'    => 'VC-20 Blitz',
            'icon'     => '✈️',
            'category' => 'Retro 1981',
            'group'    => 'arcade',
            'desc'     => 'Der Commodore VC-20 Kult-Klassiker: Trage im Sinkflug alle Wolkenkratzer per Bombe ab, um sicher zu landen!',
            'color'    => 'from-cyan-500 to-blue-700',
        ],
        'wow' => [
            'key'      => 'wow',
            'title'    => 'World of MiniCloud',
            'icon'     => '⚔️',
            'category' => 'MMO-RPG',
            'group'    => 'action',
            'desc'     => 'Fantasy-Rollenspiel im WoW-Stil: 3 Klassen, Quests, 4 Zonen, Raid-Bosse, Epischer Loot & Live-Mitspieler!',
            'color'    => 'from-amber-600 to-red-700',
        ],
        'tetris' => [
            'key'      => 'tetris',
            'title'    => 'Block-Puzzle',
            'icon'     => '🧱',
            'category' => 'Arcade',
            'group'    => 'arcade',
            'desc'     => 'Staple fallende Blöcke, räume bis zu 4 Reihen gleichzeitig ab und knacke den Highscore.',
            'color'    => 'from-purple-500 to-indigo-600',
        ],
        'snake' => [
            'key'      => 'snake',
            'title'    => 'Retro-Snake',
            'icon'     => '🐍',
            'category' => 'Arcade',
            'group'    => 'arcade',
            'desc'     => 'Sammle Energiepunkte, werde länger und weiche den Wänden und dir selbst aus.',
            'color'    => 'from-green-500 to-emerald-700',
        ],
        'breakout' => [
            'key'      => 'breakout',
            'title'    => 'Brick Breaker',
            'icon'     => '🏓',
            'category' => 'Arcade',
            'group'    => 'arcade',
            'desc'     => 'Halte den Ball mit dem Paddel im Spiel und räume alle farbigen Steinreihen ab.',
            'color'    => 'from-sky-500 to-blue-700',
        ],
        'connect4' => [
            'key'      => 'connect4',
            'title'    => 'Vier Gewinnt & Tic-Tac-Toe',
            'icon'     => '🔴',
            'category' => 'Duell & KI',
            'group'    => 'logic',
            'desc'     => 'Spiele gegen die Computer-KI oder fordere andere Cloud-Benutzer zum Live-Duell heraus!',
            'color'    => 'from-red-500 to-amber-500',
        ],
        'sudoku' => [
            'key'      => 'sudoku',
            'title'    => 'Cloud-Sudoku',
            'icon'     => '🧠',
            'category' => 'Logik',
            'group'    => 'logic',
            'desc'     => '9×9 Logik-Rätsel mit 3 Schwierigkeitsgraden, Bleistift-Notizen & Spielstand-Sync.',
            'color'    => 'from-indigo-500 to-blue-600',
        ],
        'minesweeper' => [
            'key'      => 'minesweeper',
            'title'    => 'Minesweeper',
            'icon'     => '💣',
            'category' => 'Logik',
            'group'    => 'logic',
            'desc'     => 'Spüre alle Minen fehlerfrei auf – mit Touch-Flaggen-Umschalter und Stoppuhr.',
            'color'    => 'from-slate-600 to-slate-800',
        ],
        'memory' => [
            'key'      => 'memory',
            'title'    => 'Cloud-Memory',
            'icon'     => '🃏',
            'category' => 'Gedächtnis',
            'group'    => 'logic',
            'desc'     => 'Finde alle verdeckten Kartenpaare in möglichst wenigen Zügen und Bestzeit.',
            'color'    => 'from-pink-500 to-rose-600',
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

<style>
/* Universal Vollbild- & Smartphone-Theater-Modus für alle 16 Cloud-Spiele */
#gamesActiveStageCard.mc-game-fullscreen {
    position: fixed !important;
    inset: 0 !important;
    z-index: 9999 !important;
    width: 100vw !important;
    height: 100dvh !important;
    max-width: 100vw !important;
    max-height: 100dvh !important;
    margin: 0 !important;
    padding: 8px 12px 14px 12px !important;
    border-radius: 0 !important;
    border: none !important;
    overflow-y: auto !important;
    overflow-x: hidden !important;
    display: flex !important;
    flex-direction: column !important;
}
#gamesActiveStageCard.mc-game-fullscreen canvas {
    width: auto !important;
    height: auto !important;
    max-width: 96vw !important;
    max-height: calc(100dvh - 185px) !important;
    object-fit: contain !important;
    margin-left: auto !important;
    margin-right: auto !important;
}
@media (max-width: 640px) {
    #gamesActiveStageCard.mc-game-fullscreen canvas {
        width: 96vw !important;
        max-height: calc(100dvh - 195px) !important;
    }
    #gamesActiveStageCard.mc-game-fullscreen .game-panel > div:first-child p {
        display: none !important;
    }
}
</style>

<div class="space-y-6">
    <!-- KOPFBEREICH & KOMFORT-SPIELAUSWAHL -->
    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-4 sm:p-6 shadow-xs space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-4">
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
                            <?= count($gamesCatalog) ?> Spiele &middot; Cloud-Highscores &middot; Ghost- &amp; Live-Duelle
                        </span>
                    </div>
                    <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-0.5">
                        100 % werbefrei &amp; lokal im Browser – wähle unten eine Kategorie, hefte deine Lieblingsspiele per <strong>⭐ Stern</strong> an oder wechsle direkt per Schnellauswahl.
                    </p>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2.5 text-xs">
                <!-- Direkt-Umschalter (auch sichtbar, wenn die große Übersicht eingeklappt ist) -->
                <div class="flex items-center gap-1.5 px-2.5 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700">
                    <span class="text-slate-500 dark:text-slate-400 font-semibold">Spiel:</span>
                    <select id="quickGameSelect" onchange="selectCloudGame(this.value)"
                            class="bg-transparent font-extrabold text-slate-900 dark:text-white focus:outline-none cursor-pointer">
                        <?php foreach ($gamesCatalog as $gKey => $gMeta): ?>
                            <option value="<?= e((string)$gKey) ?>" class="bg-white dark:bg-slate-900 text-slate-900 dark:text-white">
                                <?= $gMeta['icon'] ?> <?= e($gMeta['title']) ?> (<?= e($gMeta['category']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700">
                    <span class="text-slate-500 dark:text-slate-400">Meine Runden:</span>
                    <strong class="ml-1 text-slate-900 dark:text-white" id="myTotalPlaysBadge"><?= $myTotalPlays ?></strong>
                </div>

                <button type="button" id="toggleCatalogBtn" onclick="toggleGamesCatalogCollapse()"
                        class="px-3 py-2 rounded-xl bg-brand-50 dark:bg-brand-950/70 hover:bg-brand-100 dark:hover:bg-brand-900/70 text-brand-700 dark:text-brand-300 border border-brand-200 dark:border-brand-800 font-bold cursor-pointer transition-colors">
                    🔼 Übersicht einklappen
                </button>

                <button type="button" onclick="toggleGameFullscreen()"
                        class="px-3.5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-extrabold shadow-xs cursor-pointer transition-colors flex items-center gap-1.5"
                        title="Aktuelles Spiel im Vollbildmodus öffnen (ideal für Smartphone & Tablet)">
                    <span>⛶</span>
                    <span>Vollbild</span>
                </button>
            </div>
        </div>

        <!-- KATEGORIE-REITER, SUCHE & ÜBERSICHTLICHES 4-SPALTEN-KARTENRASTER -->
        <div id="gamesCatalogWrapper" class="pt-3 border-t border-slate-200/80 dark:border-slate-800 space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex flex-wrap items-center gap-1.5 text-xs font-bold">
                    <button type="button" onclick="filterGamesCatalog('all')" id="gameCatBtn-all"
                            class="game-cat-btn px-3 py-1.5 rounded-xl bg-brand-600 text-white shadow-2xs cursor-pointer transition-all">
                        🌟 Alle Spiele (<?= count($gamesCatalog) ?>)
                    </button>
                    <button type="button" onclick="filterGamesCatalog('fav')" id="gameCatBtn-fav"
                            class="game-cat-btn px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700 cursor-pointer transition-all">
                        ⭐ Favoriten
                    </button>
                    <button type="button" onclick="filterGamesCatalog('arcade')" id="gameCatBtn-arcade"
                            class="game-cat-btn px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700 cursor-pointer transition-all">
                        🕹️ Retro &amp; Arcade (11)
                    </button>
                    <button type="button" onclick="filterGamesCatalog('action')" id="gameCatBtn-action"
                            class="game-cat-btn px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700 cursor-pointer transition-all">
                        🏎️ Rennspiel, Sport &amp; RPG (4)
                    </button>
                    <button type="button" onclick="filterGamesCatalog('logic')" id="gameCatBtn-logic"
                            class="game-cat-btn px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700 cursor-pointer transition-all">
                        🧠 Logik &amp; Duell (5)
                    </button>
                </div>

                <div class="relative w-full sm:w-56">
                    <input type="search" id="gameSearchInput" oninput="filterGamesCatalog(activeGameCategory)"
                           placeholder="🔎 Spiel suchen…"
                           class="w-full pl-3 pr-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500">
                </div>
            </div>

            <div id="gamesCatalogGrid" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-2.5">
                <?php foreach ($gamesCatalog as $gKey => $gMeta): ?>
                    <?php
                    $gKeyStr = (string)$gKey;
                    $myBest  = $myBestByGame[$gKeyStr] ?? 0;
                    $gGroup  = (string)($gMeta['group'] ?? 'arcade');
                    ?>
                    <div onclick="selectCloudGame('<?= e($gKeyStr) ?>')"
                         id="gameTabBtn-<?= e($gKeyStr) ?>"
                         data-game-key="<?= e($gKeyStr) ?>"
                         data-game-group="<?= e($gGroup) ?>"
                         data-game-search="<?= e(mb_strtolower($gMeta['title'] . ' ' . $gMeta['category'] . ' ' . $gMeta['desc'], 'UTF-8')) ?>"
                         class="game-tab-btn group relative text-left p-3 rounded-2xl border transition-all cursor-pointer flex items-start gap-3 <?= $gKeyStr === 'gummigeruch' ? 'bg-brand-600 text-white border-brand-600 shadow-md ring-2 ring-brand-400/50' : 'bg-slate-50/70 dark:bg-slate-800/50 text-slate-800 dark:text-slate-100 border-slate-200/90 dark:border-slate-700/80 hover:border-brand-400 hover:bg-white dark:hover:bg-slate-800' ?>">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br <?= e($gMeta['color']) ?> text-white flex items-center justify-center text-xl shadow-xs shrink-0">
                            <?= $gMeta['icon'] ?>
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center justify-between gap-1">
                                <p class="text-xs sm:text-sm font-extrabold leading-tight truncate"><?= e($gMeta['title']) ?></p>
                                <button type="button"
                                        onclick="event.stopPropagation(); toggleFavoriteGame('<?= e($gKeyStr) ?>')"
                                        id="gameFavStar-<?= e($gKeyStr) ?>"
                                        class="text-sm opacity-60 hover:opacity-100 hover:scale-110 transition-transform cursor-pointer shrink-0"
                                        title="Als Lieblingsspiel anheften / lösen">
                                    ☆
                                </button>
                            </div>
                            <p class="text-[11px] opacity-80 line-clamp-1 mt-0.5"><?= e($gMeta['desc']) ?></p>
                            <div class="flex items-center justify-between gap-2 mt-1.5">
                                <span class="text-[10px] font-bold px-1.5 py-0.5 rounded-md bg-black/10 dark:bg-white/10 truncate">
                                    <?= e($gMeta['category']) ?>
                                </span>
                                <span class="text-[10px] font-bold opacity-90 truncate" id="myBestLabel-<?= e($gKeyStr) ?>">
                                    <?= $myBest > 0 ? '🏆 ' . number_format($myBest, 0, ',', '.') : 'Noch ohne Wertung' ?>
                                </span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <p id="gamesEmptyFilterHint" class="hidden text-center text-xs text-slate-400 py-3 italic">
                Keine Spiele in dieser Ansicht gefunden. Klicke auf den ☆ Stern einer Spielkarte, um Favoriten zu markieren!
            </p>
        </div>
    </div>

    <!-- HAUPTBEREICH: LINKS DAS AKTIVE SPIEL (8 SPALTEN) / RECHTS DIE CLOUD-BESTENLISTE (4 SPALTEN) -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">

        <!-- =============================================================== -->
        <!-- LINKE SPALTE: AKTIVE SPIELFLÄCHE                                -->
        <!-- =============================================================== -->
        <div id="gamesActiveStageCard" class="lg:col-span-8 mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-4 sm:p-6 shadow-xs min-h-[540px]">

            <!-- KOMPAKTE VOLLBILD- & SCHNELLWAHL-LEISTE DIREKT ÜBER DEM AKTIVEN SPIEL -->
            <div class="flex flex-wrap items-center justify-between gap-2 mb-3 pb-2.5 border-b border-slate-200/80 dark:border-slate-800 text-xs">
                <div class="flex items-center gap-2 min-w-0">
                    <span class="text-slate-400 dark:text-slate-500 font-semibold hidden sm:inline">Aktives Spiel:</span>
                    <select id="stageQuickGameSelect" onchange="selectCloudGame(this.value)"
                            class="px-2.5 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 font-extrabold text-slate-900 dark:text-white focus:outline-none cursor-pointer max-w-[230px] sm:max-w-xs truncate">
                        <?php foreach ($gamesCatalog as $gKey => $gMeta): ?>
                            <option value="<?= e((string)$gKey) ?>" class="bg-white dark:bg-slate-900 text-slate-900 dark:text-white">
                                <?= $gMeta['icon'] ?> <?= e($gMeta['title']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" id="btnStageFullscreen" onclick="toggleGameFullscreen()"
                            class="px-3.5 py-1.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-extrabold shadow-2xs cursor-pointer transition-colors flex items-center gap-1.5">
                        <span id="btnStageFullscreenIcon">⛶</span>
                        <span id="btnStageFullscreenLabel">Vollbild / Handy-Großansicht</span>
                    </button>
                </div>
            </div>

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

            <!-- 12. SPIEL: CLOUD-KONG (1981 DONKEY-KONG ARCADE-KLASSIKER) -->
            <div id="gamePanel-kong" class="game-panel hidden space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3 pb-3 border-b border-slate-200 dark:border-slate-800">
                    <div>
                        <h2 class="text-base sm:text-lg font-extrabold text-slate-900 dark:text-white flex items-center gap-2">
                            <span>🦍 Cloud-Kong (1981 Arcade)</span>
                            <span class="text-[11px] font-semibold px-2 py-0.5 rounded-full bg-rose-100 dark:bg-rose-950 text-rose-800 dark:text-rose-300 border border-rose-300/70 dark:border-rose-800">
                                🛢️ Rollende Fässer &middot; 🔨 Power-Hammer &middot; 6 Etagen
                            </span>
                        </h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            Steuerung: <strong>← → / A D</strong> Laufen &middot; <strong>↑ ↓ / W S</strong> Leitern klettern &middot; <strong>Leertaste</strong> über Fässer springen (<code>+100 Pkt.</code>) oder den <strong>🔨 Hammer</strong> einsammeln!
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="button" onclick="kongStartGame()"
                                class="px-3.5 py-1.5 rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-xs font-extrabold shadow-2xs cursor-pointer">
                            ▶️ Neues Spiel starten
                        </button>
                    </div>
                </div>

                <!-- Status- & Punktezeile -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 text-xs">
                    <div class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 flex items-center justify-between">
                        <span class="text-slate-500 dark:text-slate-400">Punkte:</span>
                        <strong id="kongScore" class="text-rose-600 dark:text-rose-400 font-extrabold text-sm">0</strong>
                    </div>
                    <div class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 flex items-center justify-between">
                        <span class="text-slate-500 dark:text-slate-400">Level (Stage):</span>
                        <strong id="kongLevel" class="text-slate-900 dark:text-white font-extrabold">1</strong>
                    </div>
                    <div class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 flex items-center justify-between">
                        <span class="text-slate-500 dark:text-slate-400">Leben:</span>
                        <strong id="kongLives" class="tracking-widest">❤️❤️❤️</strong>
                    </div>
                    <div class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 flex items-center justify-between">
                        <span class="text-slate-500 dark:text-slate-400">Bonus / Status:</span>
                        <strong id="kongBonus" class="text-amber-600 dark:text-amber-400 font-extrabold truncate">5000</strong>
                    </div>
                </div>

                <!-- Arcade-Spielfeld (Canvas) + Touch-Steuerung -->
                <div class="flex flex-col items-center gap-3">
                    <canvas id="kongCanvas" width="480" height="520"
                            class="rounded-2xl bg-slate-950 border-2 border-rose-900/80 shadow-xl max-w-full h-auto select-none"></canvas>

                    <!-- Touch- / Mobil-Steuerleiste -->
                    <div class="flex flex-wrap items-center justify-center gap-2 select-none">
                        <button type="button"
                                onmousedown="kongKeys['ArrowLeft']=true" onmouseup="kongKeys['ArrowLeft']=false" onmouseleave="kongKeys['ArrowLeft']=false"
                                ontouchstart="event.preventDefault(); kongKeys['ArrowLeft']=true" ontouchend="kongKeys['ArrowLeft']=false"
                                class="px-4 py-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 active:bg-rose-600 active:text-white font-extrabold text-sm shadow-2xs cursor-pointer">
                            ⬅️ Links
                        </button>
                        <button type="button"
                                onmousedown="kongKeys['ArrowUp']=true" onmouseup="kongKeys['ArrowUp']=false" onmouseleave="kongKeys['ArrowUp']=false"
                                ontouchstart="event.preventDefault(); kongKeys['ArrowUp']=true" ontouchend="kongKeys['ArrowUp']=false"
                                class="px-4 py-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 active:bg-rose-600 active:text-white font-extrabold text-sm shadow-2xs cursor-pointer">
                            ⬆️ Leiter hoch
                        </button>
                        <button type="button"
                                onmousedown="kongKeys['ArrowDown']=true" onmouseup="kongKeys['ArrowDown']=false" onmouseleave="kongKeys['ArrowDown']=false"
                                ontouchstart="event.preventDefault(); kongKeys['ArrowDown']=true" ontouchend="kongKeys['ArrowDown']=false"
                                class="px-4 py-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 active:bg-rose-600 active:text-white font-extrabold text-sm shadow-2xs cursor-pointer">
                            ⬇️ Leiter runter
                        </button>
                        <button type="button"
                                onmousedown="kongKeys['ArrowRight']=true" onmouseup="kongKeys['ArrowRight']=false" onmouseleave="kongKeys['ArrowRight']=false"
                                ontouchstart="event.preventDefault(); kongKeys['ArrowRight']=true" ontouchend="kongKeys['ArrowRight']=false"
                                class="px-4 py-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 active:bg-rose-600 active:text-white font-extrabold text-sm shadow-2xs cursor-pointer">
                            ➡️ Rechts
                        </button>
                        <button type="button"
                                onclick="kongTriggerJump()"
                                class="px-5 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-extrabold text-sm shadow-sm cursor-pointer">
                            🦘 SPRINGEN (Space)
                        </button>
                    </div>
                </div>
            </div>

            <!-- 13. SPIEL: CLOUD-INVADERS (1978 SPACE-INVADERS ARCADE-KLASSIKER) -->
            <div id="gamePanel-invaders" class="game-panel hidden space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3 pb-3 border-b border-slate-200 dark:border-slate-800">
                    <div>
                        <h2 class="text-base sm:text-lg font-extrabold text-slate-900 dark:text-white flex items-center gap-2">
                            <span>👾 Cloud-Invaders (1978 Arcade)</span>
                            <span class="text-[11px] font-semibold px-2 py-0.5 rounded-full bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300 border border-emerald-300/70 dark:border-emerald-800">
                                🛸 Mystery-UFO &middot; 🛡️ 4 Schutzbunker &middot; 55 Pixel-Aliens
                            </span>
                        </h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            Steuerung: <strong>← → / A D</strong> Laser-Kanone bewegen &middot; <strong>Leertaste / W / ↑</strong> Laser abfeuern &middot; Je weniger Aliens übrig sind, desto schneller marschieren sie!
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="button" onclick="invadersStartGame()"
                                class="px-3.5 py-1.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-extrabold shadow-2xs cursor-pointer">
                            ▶️ Neues Spiel starten
                        </button>
                    </div>
                </div>

                <!-- Status- & Punktezeile -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 text-xs">
                    <div class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 flex items-center justify-between">
                        <span class="text-slate-500 dark:text-slate-400">Punkte:</span>
                        <strong id="invadersScore" class="text-emerald-600 dark:text-emerald-400 font-extrabold text-sm">0</strong>
                    </div>
                    <div class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 flex items-center justify-between">
                        <span class="text-slate-500 dark:text-slate-400">Welle (Wave):</span>
                        <strong id="invadersWave" class="text-slate-900 dark:text-white font-extrabold">1</strong>
                    </div>
                    <div class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 flex items-center justify-between">
                        <span class="text-slate-500 dark:text-slate-400">Leben:</span>
                        <strong id="invadersLives" class="tracking-widest">❤️❤️❤️</strong>
                    </div>
                    <div class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 flex items-center justify-between">
                        <span class="text-slate-500 dark:text-slate-400">Übrige Aliens:</span>
                        <strong id="invadersLeft" class="text-cyan-600 dark:text-cyan-400 font-extrabold">55 / 55</strong>
                    </div>
                </div>

                <!-- Arcade-Spielfeld (Canvas) + Touch-Steuerung -->
                <div class="flex flex-col items-center gap-3">
                    <canvas id="invadersCanvas" width="520" height="480"
                            class="rounded-2xl bg-slate-950 border-2 border-emerald-900/80 shadow-xl max-w-full h-auto select-none"></canvas>

                    <!-- Touch- / Mobil-Steuerleiste -->
                    <div class="flex flex-wrap items-center justify-center gap-2 select-none">
                        <button type="button"
                                onmousedown="invadersKeys['ArrowLeft']=true" onmouseup="invadersKeys['ArrowLeft']=false" onmouseleave="invadersKeys['ArrowLeft']=false"
                                ontouchstart="event.preventDefault(); invadersKeys['ArrowLeft']=true" ontouchend="invadersKeys['ArrowLeft']=false"
                                class="px-5 py-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 active:bg-emerald-600 active:text-white font-extrabold text-sm shadow-2xs cursor-pointer">
                            ⬅️ Links
                        </button>
                        <button type="button"
                                onclick="invadersFireLaser()"
                                class="px-6 py-2.5 rounded-xl bg-emerald-500 hover:bg-emerald-600 text-slate-950 font-extrabold text-sm shadow-sm cursor-pointer">
                            ⚡ LASER FEUERN (Space)
                        </button>
                        <button type="button"
                                onmousedown="invadersKeys['ArrowRight']=true" onmouseup="invadersKeys['ArrowRight']=false" onmouseleave="invadersKeys['ArrowRight']=false"
                                ontouchstart="event.preventDefault(); invadersKeys['ArrowRight']=true" ontouchend="invadersKeys['ArrowRight']=false"
                                class="px-5 py-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 active:bg-emerald-600 active:text-white font-extrabold text-sm shadow-2xs cursor-pointer">
                            ➡️ Rechts
                        </button>
                    </div>
                </div>
            </div>

            <!-- 14. SPIEL: CLOUD-CANABALT (EIN-TASTEN ROOFTOP-RUNNER) -->
            <div id="gamePanel-canabalt" class="game-panel hidden space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3 pb-3 border-b border-slate-200 dark:border-slate-800">
                    <div>
                        <h2 class="text-base sm:text-lg font-extrabold text-slate-900 dark:text-white flex items-center gap-2">
                            <span>🏃‍♂️ Cloud-Canabalt (2009 Parkour-Runner)</span>
                            <span class="text-[11px] font-semibold px-2 py-0.5 rounded-full bg-slate-200 dark:bg-slate-800 text-slate-800 dark:text-slate-200 border border-slate-300/70 dark:border-slate-700">
                                🏢 Einstürzende Dächer &middot; 🪟 Glasfenster &middot; 🕊️ Taubenschwärme
                            </span>
                        </h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            Steuerung: <strong>Leertaste / W / ↑ / Klick aufs Spielfeld</strong> &ndash; <em>kurz antippen</em> = flacher Sprung (für Bürofenster), <em>gedrückt halten</em> = weiter Satz! Kisten bremsen dich ab, fallende Bombenblöcke sind tödlich.
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="button" onclick="canabaltStartGame()"
                                class="px-3.5 py-1.5 rounded-xl bg-slate-800 hover:bg-slate-900 dark:bg-slate-200 dark:hover:bg-white dark:text-slate-900 text-white text-xs font-extrabold shadow-2xs cursor-pointer">
                            ▶️ Flucht starten
                        </button>
                    </div>
                </div>

                <!-- Status- & Distanzzeile -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 text-xs">
                    <div class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 flex items-center justify-between">
                        <span class="text-slate-500 dark:text-slate-400">Distanz:</span>
                        <strong id="canabaltDist" class="text-slate-900 dark:text-white font-extrabold text-sm">0 m</strong>
                    </div>
                    <div class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 flex items-center justify-between">
                        <span class="text-slate-500 dark:text-slate-400">Tempo:</span>
                        <strong id="canabaltSpeed" class="text-cyan-600 dark:text-cyan-400 font-extrabold">0 km/h</strong>
                    </div>
                    <div class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 flex items-center justify-between">
                        <span class="text-slate-500 dark:text-slate-400">Spitzentempo:</span>
                        <strong id="canabaltMaxSpeed" class="text-amber-600 dark:text-amber-400 font-extrabold">0 km/h</strong>
                    </div>
                    <div class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 flex items-center justify-between">
                        <span class="text-slate-500 dark:text-slate-400">Status:</span>
                        <strong id="canabaltStatus" class="text-emerald-600 dark:text-emerald-400 font-extrabold truncate">Bereit</strong>
                    </div>
                </div>

                <!-- Breitbild-Skyline-Spielfeld (Canvas) + Ein-Tasten-Button -->
                <div class="flex flex-col items-center gap-3">
                    <canvas id="canabaltCanvas" width="760" height="380"
                            onmousedown="canabaltPressJump(true)" onmouseup="canabaltPressJump(false)" onmouseleave="canabaltPressJump(false)"
                            ontouchstart="event.preventDefault(); canabaltPressJump(true)" ontouchend="canabaltPressJump(false)"
                            class="w-full max-w-[760px] h-auto rounded-2xl bg-slate-900 border-2 border-slate-700 shadow-xl cursor-pointer select-none"></canvas>

                    <div class="flex flex-wrap items-center justify-center gap-3 select-none">
                        <button type="button"
                                onmousedown="canabaltPressJump(true)" onmouseup="canabaltPressJump(false)" onmouseleave="canabaltPressJump(false)"
                                ontouchstart="event.preventDefault(); canabaltPressJump(true)" ontouchend="canabaltPressJump(false)"
                                class="px-8 py-3 rounded-2xl bg-slate-900 hover:bg-slate-800 dark:bg-white dark:hover:bg-slate-200 text-white dark:text-slate-950 font-extrabold text-sm shadow-md cursor-pointer">
                            🦘 SPRINGEN (Kurz antippen = flach &middot; Gedrückt halten = hoch &amp; weit)
                        </button>
                    </div>
                </div>
            </div>

            <!-- 15. SPIEL: THE LAST NINJA (1987 C64 ISOMETRISCHES ACTION-ADVENTURE) -->
            <div id="gamePanel-lastninja" class="game-panel hidden space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3 pb-3 border-b border-slate-200 dark:border-slate-800">
                    <div>
                        <h2 class="text-base sm:text-lg font-extrabold text-slate-900 dark:text-white flex items-center gap-2">
                            <span>🥷 The Last Ninja (1987 C64-Edition)</span>
                            <span class="text-[11px] font-semibold px-2 py-0.5 rounded-full bg-amber-100 dark:bg-amber-950 text-amber-800 dark:text-amber-300 border border-amber-300/70 dark:border-amber-800">
                                ⛩️ 6 Iso-Räume &middot; ⚔️ Katana &amp; Shuriken &middot; 🌊 Fluss-Sprünge &middot; 👹 Shogun Kunitoki
                            </span>
                        </h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            Steuerung: <strong>WASD / Pfeiltasten</strong> Laufen &middot; <strong>Leertaste</strong> Angriff &middot; <strong>E / Shift</strong> Vorwärtssalto (über Fluss-Felsen!) &middot; <strong>F / Enter</strong> Objekt am Schrein aufnehmen &middot; <strong>Q / 1–5</strong> Waffe wechseln.
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="button" onclick="ninjaStartGame()"
                                class="px-3.5 py-1.5 rounded-xl bg-amber-700 hover:bg-amber-800 text-white text-xs font-extrabold shadow-2xs cursor-pointer">
                            ▶️ Mission auf Lin Fen starten
                        </button>
                    </div>
                </div>

                <!-- Status- & Punktezeile -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 text-xs">
                    <div class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 flex items-center justify-between">
                        <span class="text-slate-500 dark:text-slate-400">Punkte:</span>
                        <strong id="ninjaScore" class="text-amber-600 dark:text-amber-400 font-extrabold text-sm">0</strong>
                    </div>
                    <div class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 flex items-center justify-between">
                        <span class="text-slate-500 dark:text-slate-400">Ort (Raum):</span>
                        <strong id="ninjaRoomName" class="text-slate-900 dark:text-white font-extrabold truncate">1/6 Einöde</strong>
                    </div>
                    <div class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 flex items-center justify-between">
                        <span class="text-slate-500 dark:text-slate-400">Waffe:</span>
                        <strong id="ninjaWeaponName" class="text-cyan-600 dark:text-cyan-400 font-extrabold truncate">👊 Fäuste &amp; Kick</strong>
                    </div>
                    <div class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 flex items-center justify-between">
                        <span class="text-slate-500 dark:text-slate-400">Leben:</span>
                        <strong id="ninjaLives" class="tracking-widest">🥷🥷🥷</strong>
                    </div>
                </div>

                <!-- C64-Breitbild-Spielfeld (Isometrische 3D-Ansicht + Original C64-Rahmen-HUD) -->
                <div class="flex flex-col items-center gap-3">
                    <canvas id="ninjaCanvas" width="760" height="440"
                            class="w-full max-w-[760px] h-auto rounded-2xl bg-stone-950 border-2 border-amber-900/80 shadow-xl select-none"></canvas>

                    <!-- Touch- & Aktionsleiste -->
                    <div class="flex flex-wrap items-center justify-center gap-2 select-none">
                        <button type="button"
                                onmousedown="ninjaKeys['ArrowLeft']=true" onmouseup="ninjaKeys['ArrowLeft']=false" onmouseleave="ninjaKeys['ArrowLeft']=false"
                                ontouchstart="event.preventDefault(); ninjaKeys['ArrowLeft']=true" ontouchend="ninjaKeys['ArrowLeft']=false"
                                class="px-3.5 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 active:bg-amber-700 active:text-white font-extrabold text-xs shadow-2xs cursor-pointer">
                            ↙️ Zurück
                        </button>
                        <button type="button"
                                onmousedown="ninjaKeys['ArrowUp']=true" onmouseup="ninjaKeys['ArrowUp']=false" onmouseleave="ninjaKeys['ArrowUp']=false"
                                ontouchstart="event.preventDefault(); ninjaKeys['ArrowUp']=true" ontouchend="ninjaKeys['ArrowUp']=false"
                                class="px-3.5 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 active:bg-amber-700 active:text-white font-extrabold text-xs shadow-2xs cursor-pointer">
                            ↖️ Links
                        </button>
                        <button type="button"
                                onmousedown="ninjaKeys['ArrowDown']=true" onmouseup="ninjaKeys['ArrowDown']=false" onmouseleave="ninjaKeys['ArrowDown']=false"
                                ontouchstart="event.preventDefault(); ninjaKeys['ArrowDown']=true" ontouchend="ninjaKeys['ArrowDown']=false"
                                class="px-3.5 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 active:bg-amber-700 active:text-white font-extrabold text-xs shadow-2xs cursor-pointer">
                            ↘️ Rechts
                        </button>
                        <button type="button"
                                onmousedown="ninjaKeys['ArrowRight']=true" onmouseup="ninjaKeys['ArrowRight']=false" onmouseleave="ninjaKeys['ArrowRight']=false"
                                ontouchstart="event.preventDefault(); ninjaKeys['ArrowRight']=true" ontouchend="ninjaKeys['ArrowRight']=false"
                                class="px-3.5 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 active:bg-amber-700 active:text-white font-extrabold text-xs shadow-2xs cursor-pointer">
                            ↗️ Vorwärts
                        </button>
                        <button type="button" onclick="ninjaTriggerAttack()"
                                class="px-4 py-2 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-extrabold text-xs shadow-sm cursor-pointer">
                            ⚔️ ANGRIFF (Space)
                        </button>
                        <button type="button" onclick="ninjaTriggerLeap()"
                                class="px-4 py-2 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-extrabold text-xs shadow-sm cursor-pointer">
                            🤸 SALTO-SPRUNG (E)
                        </button>
                        <button type="button" onclick="ninjaTriggerPickup()"
                                class="px-3.5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-extrabold text-xs shadow-sm cursor-pointer">
                            ✋ AUFHEBEN (F)
                        </button>
                        <button type="button" onclick="ninjaCycleWeapon()"
                                class="px-3.5 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-extrabold text-xs shadow-sm cursor-pointer">
                            🔄 WAFFE (Q)
                        </button>
                    </div>
                </div>
            </div>

            <!-- 16. SPIEL: CLOUD-LEMMINGS (1991 ZERSTÖRBARES PIXEL-GELÄNDE & 8 SKILLS) -->
            <div id="gamePanel-lemmings" class="game-panel hidden space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3 pb-3 border-b border-slate-200 dark:border-slate-800">
                    <div>
                        <h2 class="text-base sm:text-lg font-extrabold text-slate-900 dark:text-white flex items-center gap-2">
                            <span>🐹 Cloud-Lemmings (1991 Klassiker)</span>
                            <span class="text-[11px] font-semibold px-2 py-0.5 rounded-full bg-lime-100 dark:bg-lime-950 text-lime-800 dark:text-lime-300 border border-lime-300/70 dark:border-lime-800">
                                ⛏️ Pixel-Tunnelbau &middot; 🪜 Treppenbauer &middot; 4 Kult-Level
                            </span>
                        </h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            Wähle unten eine <strong>Fähigkeit</strong> (z. B. <em>🕳️ Digger, 🪜 Builder, 🛑 Blocker, 🔨 Basher, ☂️ Floater</em>) und tippe/klicke auf einen laufenden Lemming!
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <select id="lemLevelSelect" onchange="lemLoadLevel(parseInt(this.value,10))"
                                class="px-2.5 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs font-bold text-slate-900 dark:text-white cursor-pointer">
                            <option value="0">Level 1: Just Dig! (Grabe einfach!)</option>
                            <option value="1">Level 2: Only Floaters Survive (Klippensprung)</option>
                            <option value="2">Level 3: Pillars of Hercules (Steinsäulen)</option>
                            <option value="3">Level 4: Through the Mountain (Bergmassiv)</option>
                        </select>
                        <button type="button" onclick="lemStartLevel()"
                                class="px-3.5 py-1.5 rounded-xl bg-lime-600 hover:bg-lime-700 text-white text-xs font-extrabold shadow-2xs cursor-pointer">
                            ▶️ Level neu starten
                        </button>
                    </div>
                </div>

                <!-- Status- & Rettungs-Zeile -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 text-xs">
                    <div class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 flex items-center justify-between">
                        <span class="text-slate-500 dark:text-slate-400">Im Feld (OUT):</span>
                        <strong id="lemOutCount" class="text-lime-600 dark:text-lime-400 font-extrabold text-sm">0 / 20</strong>
                    </div>
                    <div class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 flex items-center justify-between">
                        <span class="text-slate-500 dark:text-slate-400">Gerettet (IN):</span>
                        <strong id="lemSavedPct" class="text-emerald-600 dark:text-emerald-400 font-extrabold">0% (Ziel: 50%)</strong>
                    </div>
                    <div class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 flex items-center justify-between">
                        <span class="text-slate-500 dark:text-slate-400">Zeit:</span>
                        <strong id="lemTimeLeft" class="text-amber-600 dark:text-amber-400 font-extrabold">03:00</strong>
                    </div>
                    <div class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 flex items-center justify-between">
                        <span class="text-slate-500 dark:text-slate-400">Punkte:</span>
                        <strong id="lemScore" class="text-slate-900 dark:text-white font-extrabold">0</strong>
                    </div>
                </div>

                <!-- Pixel-Gelände-Spielfeld (Canvas) + Original 8-Skill-Leiste -->
                <div class="flex flex-col items-center gap-3">
                    <canvas id="lemCanvas" width="640" height="320"
                            onclick="lemCanvasClick(event)"
                            onmousemove="lemCanvasHover(event)"
                            ontouchstart="lemCanvasTouch(event)"
                            class="w-full max-w-[760px] h-auto rounded-2xl bg-slate-950 border-2 border-lime-900/80 shadow-xl cursor-crosshair select-none"></canvas>

                    <!-- Klassische Lemmings-Werkzeugleiste (8 Fähigkeiten + Tempo + Nuke) -->
                    <div class="w-full max-w-[760px] grid grid-cols-5 sm:grid-cols-10 gap-1.5 select-none">
                        <button type="button" onclick="lemSelectSkill('climber')" id="lemSkillBtn-climber"
                                class="lem-skill-btn flex flex-col items-center justify-center p-1.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-100 dark:bg-slate-800 text-[11px] font-extrabold cursor-pointer">
                            <span class="text-base">🧗</span>
                            <span>Climber</span>
                            <span id="lemSkillCnt-climber" class="text-[10px] text-lime-600 dark:text-lime-400">10</span>
                        </button>
                        <button type="button" onclick="lemSelectSkill('floater')" id="lemSkillBtn-floater"
                                class="lem-skill-btn flex flex-col items-center justify-center p-1.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-100 dark:bg-slate-800 text-[11px] font-extrabold cursor-pointer">
                            <span class="text-base">☂️</span>
                            <span>Floater</span>
                            <span id="lemSkillCnt-floater" class="text-[10px] text-lime-600 dark:text-lime-400">10</span>
                        </button>
                        <button type="button" onclick="lemSelectSkill('bomber')" id="lemSkillBtn-bomber"
                                class="lem-skill-btn flex flex-col items-center justify-center p-1.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-100 dark:bg-slate-800 text-[11px] font-extrabold cursor-pointer">
                            <span class="text-base">💣</span>
                            <span>Bomber</span>
                            <span id="lemSkillCnt-bomber" class="text-[10px] text-lime-600 dark:text-lime-400">10</span>
                        </button>
                        <button type="button" onclick="lemSelectSkill('blocker')" id="lemSkillBtn-blocker"
                                class="lem-skill-btn flex flex-col items-center justify-center p-1.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-100 dark:bg-slate-800 text-[11px] font-extrabold cursor-pointer">
                            <span class="text-base">🛑</span>
                            <span>Blocker</span>
                            <span id="lemSkillCnt-blocker" class="text-[10px] text-lime-600 dark:text-lime-400">10</span>
                        </button>
                        <button type="button" onclick="lemSelectSkill('builder')" id="lemSkillBtn-builder"
                                class="lem-skill-btn flex flex-col items-center justify-center p-1.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-100 dark:bg-slate-800 text-[11px] font-extrabold cursor-pointer">
                            <span class="text-base">🪜</span>
                            <span>Builder</span>
                            <span id="lemSkillCnt-builder" class="text-[10px] text-lime-600 dark:text-lime-400">15</span>
                        </button>
                        <button type="button" onclick="lemSelectSkill('basher')" id="lemSkillBtn-basher"
                                class="lem-skill-btn flex flex-col items-center justify-center p-1.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-100 dark:bg-slate-800 text-[11px] font-extrabold cursor-pointer">
                            <span class="text-base">🔨</span>
                            <span>Basher</span>
                            <span id="lemSkillCnt-basher" class="text-[10px] text-lime-600 dark:text-lime-400">10</span>
                        </button>
                        <button type="button" onclick="lemSelectSkill('miner')" id="lemSkillBtn-miner"
                                class="lem-skill-btn flex flex-col items-center justify-center p-1.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-100 dark:bg-slate-800 text-[11px] font-extrabold cursor-pointer">
                            <span class="text-base">⛏️</span>
                            <span>Miner</span>
                            <span id="lemSkillCnt-miner" class="text-[10px] text-lime-600 dark:text-lime-400">10</span>
                        </button>
                        <button type="button" onclick="lemSelectSkill('digger')" id="lemSkillBtn-digger"
                                class="lem-skill-btn flex flex-col items-center justify-center p-1.5 rounded-xl border-2 border-lime-500 bg-lime-100 dark:bg-lime-950/80 text-[11px] font-extrabold cursor-pointer">
                            <span class="text-base">🕳️</span>
                            <span>Digger</span>
                            <span id="lemSkillCnt-digger" class="text-[10px] text-lime-600 dark:text-lime-400">10</span>
                        </button>
                        <button type="button" onclick="lemToggleFastForward()" id="lemFastForwardBtn"
                                class="flex flex-col items-center justify-center p-1.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-100 dark:bg-slate-800 text-[11px] font-extrabold cursor-pointer">
                            <span class="text-base">⏩</span>
                            <span>Tempo</span>
                            <span id="lemSpeedBadge" class="text-[10px] text-amber-600 dark:text-amber-400">1×</span>
                        </button>
                        <button type="button" onclick="lemTriggerNuke()"
                                class="flex flex-col items-center justify-center p-1.5 rounded-xl border border-rose-300 dark:border-rose-800 bg-rose-50 dark:bg-rose-950/60 text-rose-700 dark:text-rose-300 text-[11px] font-extrabold cursor-pointer"
                                title="Oh no! Alle Lemminge sprengen (Nuke)">
                            <span class="text-base">☢️</span>
                            <span>Nuke</span>
                            <span class="text-[10px]">Oh no!</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- 17. SPIEL: CLOUD-TRAFFIC-RACER (HIGHWAY-OVERTAKE, GEGENVERKEHR & GARAGE) -->
            <div id="gamePanel-trafficracer" class="game-panel hidden space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3 pb-3 border-b border-slate-200 dark:border-slate-800">
                    <div>
                        <h2 class="text-base sm:text-lg font-extrabold text-slate-900 dark:text-white flex items-center gap-2">
                            <span>🚘 Cloud-Traffic-Racer</span>
                            <span class="text-[11px] font-semibold px-2 py-0.5 rounded-full bg-orange-100 dark:bg-orange-950 text-orange-800 dark:text-orange-300 border border-orange-300/70 dark:border-orange-800">
                                ⚡ Close-Overtake-Kombo &middot; 🔄 Gegenverkehr &middot; 🔧 5 Tuning-Autos
                            </span>
                        </h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            Steuerung: <strong>← → / A D</strong> Spur wechseln &middot; <strong>↑ / W</strong> Vollgas &middot; <strong>↓ / S / Space</strong> Bremsen &middot; <strong>H</strong> Lichthupe &amp; Hupe (macht die Spur frei!). Ab <strong>100 km/h</strong> bringen knappe Überholmanöver fette Kombo-Punkte!
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 text-xs">
                        <select id="trModeSelect" onchange="trChangeConfig()"
                                class="px-2.5 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 font-bold text-slate-900 dark:text-white cursor-pointer">
                            <option value="twoway">🔄 Gegenverkehr (2+2 Spuren · 2× Bonus)</option>
                            <option value="oneway">🛣️ Einbahnstraße (4 Spuren)</option>
                            <option value="timeattack">⏱️ Zeitfahren (90 Sek. Vollgas)</option>
                            <option value="police">🚓 Polizei-Jagd (Raser stoppen!)</option>
                        </select>
                        <select id="trEnvSelect" onchange="trChangeConfig()"
                                class="px-2.5 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 font-bold text-slate-900 dark:text-white cursor-pointer">
                            <option value="night">🌃 Neon-Nachtfahrt (Lichtkegel)</option>
                            <option value="desert">☀️ Sonnen-Highway</option>
                            <option value="rain">🌧️ Regen-Autobahn</option>
                        </select>
                        <button type="button" onclick="trStartGame()"
                                class="px-3.5 py-1.5 rounded-xl bg-orange-600 hover:bg-orange-700 text-white font-extrabold shadow-2xs cursor-pointer">
                            ▶️ Auf die Autobahn!
                        </button>
                    </div>
                </div>

                <!-- Garage- & Fahrzeug-Auswahlleiste (Cloud-gesichert!) -->
                <div class="p-3 rounded-2xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 space-y-2.5 text-xs">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <span class="font-extrabold text-slate-800 dark:text-slate-100">🔧 Meine Highway-Garage:</span>
                            <span class="px-2.5 py-0.5 rounded-full bg-amber-100 dark:bg-amber-950/80 text-amber-800 dark:text-amber-300 font-extrabold border border-amber-300/70 dark:border-amber-800">
                                💰 <span id="trCashLabel">0</span> Credits
                            </span>
                        </div>
                        <div class="flex flex-wrap items-center gap-1.5">
                            <button type="button" onclick="trUpgradeCurrentCar()" id="trUpgradeBtn"
                                    class="px-3 py-1 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-extrabold cursor-pointer">
                                ⚙️ Motor &amp; Bremsen tunen (500 💰)
                            </button>
                        </div>
                    </div>
                    <div id="trCarSelectorRow" class="grid grid-cols-2 sm:grid-cols-5 gap-2"></div>
                </div>

                <!-- Tacho-, Kombo- & Punkte-Zeile -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 text-xs">
                    <div class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 flex items-center justify-between">
                        <span class="text-slate-500 dark:text-slate-400">Punkte:</span>
                        <strong id="trScore" class="text-orange-600 dark:text-orange-400 font-extrabold text-sm">0</strong>
                    </div>
                    <div class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 flex items-center justify-between">
                        <span class="text-slate-500 dark:text-slate-400">Tacho:</span>
                        <strong id="trSpeedKmh" class="text-emerald-600 dark:text-emerald-400 font-extrabold text-sm">0 km/h</strong>
                    </div>
                    <div class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 flex items-center justify-between">
                        <span class="text-slate-500 dark:text-slate-400">Close Overtakes:</span>
                        <strong id="trOvertakes" class="text-cyan-600 dark:text-cyan-400 font-extrabold">0 (×1)</strong>
                    </div>
                    <div class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 flex items-center justify-between">
                        <span class="text-slate-500 dark:text-slate-400">Distanz / Zeit:</span>
                        <strong id="trDistInfo" class="text-slate-900 dark:text-white font-extrabold truncate">0,0 km</strong>
                    </div>
                </div>

                <!-- 4-Spur-Highway-Spielfeld (Canvas) + Touch-Cockpit -->
                <div class="flex flex-col items-center gap-3">
                    <canvas id="trCanvas" width="460" height="540"
                            class="rounded-2xl bg-slate-950 border-2 border-orange-900/80 shadow-xl max-w-full h-auto select-none"></canvas>

                    <!-- Touch- & Mobil-Lenkrad -->
                    <div class="flex flex-wrap items-center justify-center gap-2 select-none">
                        <button type="button"
                                onmousedown="trKeys['ArrowLeft']=true" onmouseup="trKeys['ArrowLeft']=false" onmouseleave="trKeys['ArrowLeft']=false"
                                ontouchstart="event.preventDefault(); trKeys['ArrowLeft']=true" ontouchend="trKeys['ArrowLeft']=false"
                                class="px-5 py-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 active:bg-orange-600 active:text-white font-extrabold text-sm shadow-2xs cursor-pointer">
                            ⬅️ Spur links
                        </button>
                        <button type="button"
                                onmousedown="trKeys['ArrowRight']=true" onmouseup="trKeys['ArrowRight']=false" onmouseleave="trKeys['ArrowRight']=false"
                                ontouchstart="event.preventDefault(); trKeys['ArrowRight']=true" ontouchend="trKeys['ArrowRight']=false"
                                class="px-5 py-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 active:bg-orange-600 active:text-white font-extrabold text-sm shadow-2xs cursor-pointer">
                            ➡️ Spur rechts
                        </button>
                        <button type="button"
                                onmousedown="trKeys['ArrowDown']=true" onmouseup="trKeys['ArrowDown']=false" onmouseleave="trKeys['ArrowDown']=false"
                                ontouchstart="event.preventDefault(); trKeys['ArrowDown']=true" ontouchend="trKeys['ArrowDown']=false"
                                class="px-4 py-2.5 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-extrabold text-sm shadow-sm cursor-pointer">
                            🛑 BREMSE
                        </button>
                        <button type="button"
                                onmousedown="trKeys['ArrowUp']=true" onmouseup="trKeys['ArrowUp']=false" onmouseleave="trKeys['ArrowUp']=false"
                                ontouchstart="event.preventDefault(); trKeys['ArrowUp']=true" ontouchend="trKeys['ArrowUp']=false"
                                class="px-5 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-extrabold text-sm shadow-sm cursor-pointer">
                            🔥 VOLLGAS
                        </button>
                        <button type="button"
                                onclick="trTriggerHorn()"
                                class="px-4 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-extrabold text-sm shadow-sm cursor-pointer">
                            📣 LICHTHUPE (H)
                        </button>
                    </div>
                </div>
            </div>

            <!-- =========================================================== -->
            <!-- SPIEL 18: 🕵️‍♂️ IMPOSSIBLE MISSION (1984 C64-EDITION)          -->
            <!-- =========================================================== -->
            <div id="gamePanel-impossible" class="game-panel hidden space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-2 pb-3 border-b border-slate-200 dark:border-slate-800">
                    <div>
                        <h2 class="text-base sm:text-lg font-extrabold text-slate-900 dark:text-white flex items-center gap-2">
                            <span>🕵️‍♂️</span>
                            <span>Impossible Mission (1984 C64-Edition)</span>
                            <span class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-cyan-100 dark:bg-cyan-950 text-cyan-800 dark:text-cyan-300 border border-cyan-200 dark:border-cyan-800">
                                Epyx 1984 &middot; Agent 4125 vs. Prof. Elvin Atombender
                            </span>
                        </h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            <em>„Another visitor! Stay a while… stay FOREVER!“</em> – Springe per Vorwärts-Salto über Killer-Roboter, durchsuche Möbel nach den 9 Lochkarten-Passwortteilen und stürme Elvin Atombenders Kontrollraum, bevor die 6 Stunden ablaufen!
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="button" onclick="imSpeak('Another visitor! Stay a while... stay forever!')"
                                class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-800 dark:text-slate-200 text-xs font-bold cursor-pointer"
                                title="Legendäres C64-Sprachsample abspielen">
                            🔊 „Stay a while…“
                        </button>
                        <button type="button" onclick="imToggleMap()"
                                class="px-3 py-1.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold cursor-pointer">
                            🛗 Aufzug / Bunker-Karte (M)
                        </button>
                        <button type="button" onclick="imStartGame()"
                                class="px-4 py-1.5 rounded-xl bg-cyan-600 hover:bg-cyan-700 text-white text-xs font-extrabold shadow-xs cursor-pointer">
                            ▶️ Neue Mission starten
                        </button>
                    </div>
                </div>

                <!-- C64 Pocket Computer & Missions-HUD -->
                <div class="grid grid-cols-2 sm:grid-cols-5 gap-2 text-xs">
                    <div class="px-3 py-2 rounded-xl bg-slate-900 border border-cyan-800/80 text-cyan-300 font-mono flex items-center justify-between">
                        <span>⏰ RESTZEIT:</span>
                        <strong id="imTimeHud" class="text-amber-400 font-extrabold">06:00:00</strong>
                    </div>
                    <div class="px-3 py-2 rounded-xl bg-slate-900 border border-cyan-800/80 text-cyan-300 font-mono flex items-center justify-between">
                        <span>💳 PUZZLE:</span>
                        <strong id="imPiecesHud" class="text-emerald-400 font-extrabold">0 / 9</strong>
                    </div>
                    <div class="px-3 py-2 rounded-xl bg-slate-900 border border-cyan-800/80 text-cyan-300 font-mono flex items-center justify-between">
                        <span>📍 SEKTOR:</span>
                        <strong id="imRoomHud" class="text-white font-extrabold truncate">Aufzug-Schacht</strong>
                    </div>
                    <button type="button" onclick="imUseSnooze()"
                            class="px-3 py-2 rounded-xl bg-emerald-950/90 hover:bg-emerald-900 border border-emerald-600 text-emerald-300 font-mono font-bold flex items-center justify-between cursor-pointer">
                        <span>💤 SNOOZE (S):</span>
                        <strong id="imSnoozeHud" class="text-white">1</strong>
                    </button>
                    <button type="button" onclick="imUseLiftInit()"
                            class="px-3 py-2 rounded-xl bg-amber-950/90 hover:bg-amber-900 border border-amber-600 text-amber-300 font-mono font-bold flex items-center justify-between cursor-pointer">
                        <span>🛗 LIFT-INIT (L):</span>
                        <strong id="imLiftHud" class="text-white">1</strong>
                    </button>
                </div>

                <!-- Haupt-Canvas (C64 Bunker & Pocket Computer) -->
                <div class="flex flex-col items-center gap-3">
                    <canvas id="imCanvas" width="760" height="470"
                            class="rounded-2xl bg-slate-950 border-2 border-cyan-700/80 shadow-xl max-w-full h-auto select-none"></canvas>

                    <!-- Touch- & Schnellsteuerungs-Leiste -->
                    <div class="flex flex-wrap items-center justify-center gap-2 select-none">
                        <button type="button"
                                onmousedown="imKeys['ArrowLeft']=true" onmouseup="imKeys['ArrowLeft']=false" onmouseleave="imKeys['ArrowLeft']=false"
                                ontouchstart="event.preventDefault(); imKeys['ArrowLeft']=true" ontouchend="imKeys['ArrowLeft']=false"
                                class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 active:bg-cyan-600 active:text-white font-extrabold text-xs sm:text-sm cursor-pointer">
                            ⬅️ Links
                        </button>
                        <button type="button"
                                onmousedown="imKeys['ArrowRight']=true" onmouseup="imKeys['ArrowRight']=false" onmouseleave="imKeys['ArrowRight']=false"
                                ontouchstart="event.preventDefault(); imKeys['ArrowRight']=true" ontouchend="imKeys['ArrowRight']=false"
                                class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 active:bg-cyan-600 active:text-white font-extrabold text-xs sm:text-sm cursor-pointer">
                            ➡️ Rechts
                        </button>
                        <button type="button"
                                onclick="imTriggerJump()"
                                class="px-5 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-extrabold text-xs sm:text-sm shadow-xs cursor-pointer">
                            🤸 SALTO-SPRUNG (Leertaste)
                        </button>
                        <button type="button"
                                onmousedown="imKeys['ArrowUp']=true" onmouseup="imKeys['ArrowUp']=false" onmouseleave="imKeys['ArrowUp']=false"
                                ontouchstart="event.preventDefault(); imKeys['ArrowUp']=true" ontouchend="imKeys['ArrowUp']=false"
                                class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-extrabold text-xs sm:text-sm shadow-xs cursor-pointer">
                            🔍 DURCHSUCHEN / ⬆️ LIFT HOCH
                        </button>
                        <button type="button"
                                onmousedown="imKeys['ArrowDown']=true" onmouseup="imKeys['ArrowDown']=false" onmouseleave="imKeys['ArrowDown']=false"
                                ontouchstart="event.preventDefault(); imKeys['ArrowDown']=true" ontouchend="imKeys['ArrowDown']=false"
                                class="px-4 py-2 rounded-xl bg-amber-600 hover:bg-amber-700 text-white font-extrabold text-xs sm:text-sm shadow-xs cursor-pointer">
                            ⬇️ LIFT RUNTER
                        </button>
                    </div>
                </div>
            </div>

            <!-- =========================================================== -->
            <!-- SPIEL 19: 🎯 CLOUD-DARTS (ALLY PALLY TURNIER-EDITION)       -->
            <!-- =========================================================== -->
            <div id="gamePanel-darts" class="game-panel hidden space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-2 pb-3 border-b border-slate-200 dark:border-slate-800">
                    <div>
                        <h2 class="text-base sm:text-lg font-extrabold text-slate-900 dark:text-white flex items-center gap-2">
                            <span>🎯</span>
                            <span>Cloud-Darts (Ally Pally Edition)</span>
                            <span class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800">
                                501 Double-Out &middot; 301 &middot; Around the Clock &middot; 180-Caller
                            </span>
                        </h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            Ziele mit Maus, Touchscreen oder Pfeiltasten (inkl. 2,5× Präzisions-Lupe) und wirf im richtigen Moment per Klick oder <strong>Leertaste</strong>!
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 text-xs">
                        <select id="dartsModeSelect" onchange="dartsStartMatch()"
                                class="px-2.5 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 font-bold text-slate-800 dark:text-slate-100 cursor-pointer">
                            <option value="501_do" selected>🏆 501 (Double-Out)</option>
                            <option value="301_so">⚡ 301 (Straight-Out)</option>
                            <option value="highscore">🔥 Highscore-Shootout (7 Runden)</option>
                            <option value="clock">🕒 Around the Clock (1–20 + Bull)</option>
                        </select>

                        <select id="dartsOppSelect" onchange="dartsStartMatch()"
                                class="px-2.5 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 font-bold text-slate-800 dark:text-slate-100 cursor-pointer">
                            <option value="bot_pub" selected>🍺 Gegen Kneipen-KI</option>
                            <option value="bot_pro">👑 Gegen Ally-Pally-Profi</option>
                            <option value="human_2p">👥 2 Spieler lokal</option>
                        </select>

                        <button type="button" onclick="dartsCallerSpeak('One hundred and eighty!')"
                                class="px-2.5 py-1.5 rounded-xl bg-amber-100 dark:bg-amber-950/70 text-amber-800 dark:text-amber-300 font-bold cursor-pointer"
                                title="180-Caller Probe hören">
                            🎙️ „180!“
                        </button>

                        <button type="button" onclick="dartsStartMatch()"
                                class="px-3.5 py-1.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-extrabold shadow-xs cursor-pointer">
                            🔄 Neues Match
                        </button>
                    </div>
                </div>

                <!-- Live TV-Scoreboard & Checkout-Berater -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5 text-xs">
                    <div id="dartsP1Card" class="p-3 rounded-xl bg-emerald-950/90 border-2 border-emerald-500 text-white flex flex-col justify-between">
                        <div class="flex items-center justify-between">
                            <span class="font-bold text-emerald-300 truncate">🎯 <?= e($username) ?></span>
                            <span id="dartsP1Avg" class="text-[11px] font-mono text-emerald-200">Ø 0.0</span>
                        </div>
                        <div id="dartsP1Score" class="text-2xl sm:text-3xl font-extrabold font-mono tracking-tight mt-0.5">501</div>
                    </div>

                    <div id="dartsP2Card" class="p-3 rounded-xl bg-slate-900 border border-slate-700 text-white flex flex-col justify-between">
                        <div class="flex items-center justify-between">
                            <span id="dartsP2Name" class="font-bold text-amber-300 truncate">🍺 Kneipen-KI</span>
                            <span id="dartsP2Avg" class="text-[11px] font-mono text-slate-400">Ø 0.0</span>
                        </div>
                        <div id="dartsP2Score" class="text-2xl sm:text-3xl font-extrabold font-mono tracking-tight mt-0.5">501</div>
                    </div>

                    <div class="p-3 rounded-xl bg-slate-100 dark:bg-slate-800/90 border border-slate-200 dark:border-slate-700 flex flex-col justify-between">
                        <span class="text-slate-500 dark:text-slate-400 font-semibold">Aufnahme (3 Pfeile):</span>
                        <div id="dartsTurnThrows" class="text-sm sm:text-base font-extrabold font-mono text-slate-900 dark:text-white mt-1 truncate">
                            🎯 — &middot; 🎯 — &middot; 🎯 —
                        </div>
                    </div>

                    <div class="p-3 rounded-xl bg-amber-50 dark:bg-amber-950/40 border border-amber-200/80 dark:border-amber-800/70 flex flex-col justify-between">
                        <span class="text-amber-800 dark:text-amber-300 font-semibold">💡 Checkout / Ziel-Tipp:</span>
                        <div id="dartsCheckoutHint" class="text-sm sm:text-base font-extrabold font-mono text-amber-900 dark:text-amber-200 mt-1 truncate">
                            T20 • T20 • T20 (180)
                        </div>
                    </div>
                </div>

                <!-- Dartboard-Canvas + Wurf-Button für Smartphone -->
                <div class="flex flex-col items-center gap-3">
                    <canvas id="dartsCanvas" width="640" height="490"
                            class="rounded-2xl bg-slate-950 border-2 border-emerald-700/80 shadow-xl max-w-full h-auto cursor-crosshair select-none"></canvas>

                    <div class="flex flex-wrap items-center justify-center gap-2 select-none">
                        <button type="button" onclick="dartsQuickAim(20, 3)"
                                class="px-3 py-2 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-extrabold text-xs cursor-pointer">
                            🔥 Visier auf Triple-20
                        </button>
                        <button type="button" onclick="dartsQuickAim(19, 3)"
                                class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 font-bold text-xs cursor-pointer">
                            🎯 Triple-19
                        </button>
                        <button type="button" onclick="dartsQuickAim(25, 2)"
                                class="px-3 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-extrabold text-xs cursor-pointer">
                            🐂 Bullseye (50)
                        </button>
                        <button type="button" onclick="dartsAimCheckoutDouble()"
                                class="px-3 py-2 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-extrabold text-xs cursor-pointer">
                            💍 Passendes Checkout-Doppel anvisieren
                        </button>
                        <button type="button" onclick="dartsTriggerThrow()"
                                class="px-6 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-extrabold text-xs sm:text-sm shadow-md cursor-pointer">
                            🏹 PFEIL WERFEN (Klick / Leertaste)
                        </button>
                    </div>
                </div>
            </div>

            <!-- 20. SPIEL: CLOUD-FROGGER (1981 ARCADE-KLASSIKER) -->
            <div id="gamePanel-frogger" class="game-panel hidden space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3 pb-3 border-b border-slate-200 dark:border-slate-800">
                    <div>
                        <h2 class="text-base sm:text-lg font-extrabold text-slate-900 dark:text-white flex items-center gap-2">
                            <span>🐸 Cloud-Frogger (1981 Arcade)</span>
                            <span class="text-[11px] font-semibold px-2 py-0.5 rounded-full bg-green-100 dark:bg-green-950/80 text-green-800 dark:text-green-300">🌊 Baumstämme • 🐢 Tauch-Schildkröten • 🪰 Bonus-Fliege</span>
                        </h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            Bringe alle <strong>5 Frösche</strong> sicher über den 5-spurigen Highway und über den reißenden Fluss in die 5 Seerosen-Buchten!
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="px-2.5 py-1.5 rounded-xl bg-emerald-100 dark:bg-emerald-950/70 text-emerald-800 dark:text-emerald-300 text-xs font-extrabold">
                            Punkte: <span id="froggerScore">0</span>
                        </span>
                        <span class="px-2.5 py-1.5 rounded-xl bg-amber-100 dark:bg-amber-950/70 text-amber-800 dark:text-amber-300 text-xs font-extrabold">
                            Level <span id="froggerLevel">1</span>
                        </span>
                        <span class="px-2.5 py-1.5 rounded-xl bg-sky-100 dark:bg-sky-950/70 text-sky-800 dark:text-sky-300 text-xs font-extrabold">
                            🏡 <span id="froggerHomes">0/5</span>
                        </span>
                        <span class="px-2.5 py-1.5 rounded-xl bg-rose-100 dark:bg-rose-950/70 text-rose-800 dark:text-rose-300 text-xs font-extrabold" id="froggerLives">
                            🐸🐸🐸
                        </span>
                        <button type="button" onclick="froggerToggleSound()" id="froggerSoundBtn"
                                class="px-2.5 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 text-xs font-bold cursor-pointer"
                                title="8-Bit Arcade-Sound ein-/ausschalten">
                            🔊 Ton
                        </button>
                        <button type="button" onclick="froggerNewGame()"
                                class="px-3.5 py-1.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-extrabold cursor-pointer shadow-xs">
                            ▶ Neues Spiel
                        </button>
                    </div>
                </div>

                <div class="flex flex-col items-center gap-3">
                    <canvas id="froggerCanvas" width="600" height="520"
                            class="rounded-2xl bg-slate-950 border-2 border-emerald-700/80 shadow-xl max-w-full h-auto select-none touch-none"></canvas>

                    <!-- Touch-Steuerkreuz für Smartphone & Tablet -->
                    <div class="flex flex-col items-center gap-1.5 select-none">
                        <button type="button" onclick="froggerHop(0, -1)"
                                class="w-16 h-11 rounded-xl bg-emerald-600 hover:bg-emerald-700 active:scale-95 text-white font-extrabold text-base shadow-sm cursor-pointer">
                            ▲
                        </button>
                        <div class="flex items-center gap-2">
                            <button type="button" onclick="froggerHop(-1, 0)"
                                    class="w-16 h-11 rounded-xl bg-slate-200 dark:bg-slate-800 hover:bg-slate-300 active:scale-95 font-extrabold text-base shadow-sm cursor-pointer">
                                ◀
                            </button>
                            <button type="button" onclick="froggerHop(0, 1)"
                                    class="w-16 h-11 rounded-xl bg-slate-200 dark:bg-slate-800 hover:bg-slate-300 active:scale-95 font-extrabold text-base shadow-sm cursor-pointer">
                                ▼
                            </button>
                            <button type="button" onclick="froggerHop(1, 0)"
                                    class="w-16 h-11 rounded-xl bg-slate-200 dark:bg-slate-800 hover:bg-slate-300 active:scale-95 font-extrabold text-base shadow-sm cursor-pointer">
                                ▶
                            </button>
                        </div>
                        <p class="text-[11px] text-slate-500 dark:text-slate-400 text-center">
                            Steuerung: <strong>Pfeiltasten / WASD</strong> oder Touch-Tasten • Vorsicht vor <strong>🐢 abtauchenden Schildkröten (rot blinkend)</strong>, <strong>🐍 Ufer-Schlangen</strong> und <strong>🐊 Krokodil-Mäulern</strong>!
                        </p>
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

let activeGameKey      = 'gummigeruch';
let activeGameCategory = 'all';
let activeLbPeriod     = 'all';
let myBestScores       = <?= json_encode($myBestByGame) ?>;
let totalPlaysCount    = <?= (int)$myTotalPlays ?>;
const FAV_GAMES_STORAGE_KEY = 'mc_fav_games_u' + CURRENT_USER_ID;

function getFavoriteGamesList() {
    try {
        const raw = localStorage.getItem(FAV_GAMES_STORAGE_KEY);
        const arr = raw ? JSON.parse(raw) : [];
        return Array.isArray(arr) ? arr : [];
    } catch (e) {
        return [];
    }
}

function toggleFavoriteGame(gameKey) {
    const favs = getFavoriteGamesList();
    const idx  = favs.indexOf(gameKey);
    if (idx >= 0) {
        favs.splice(idx, 1);
    } else {
        favs.push(gameKey);
    }
    try {
        localStorage.setItem(FAV_GAMES_STORAGE_KEY, JSON.stringify(favs));
    } catch (e) {}
    renderFavoriteGameStars();
    if (activeGameCategory === 'fav') {
        filterGamesCatalog('fav');
    }
}

function renderFavoriteGameStars() {
    const favs = getFavoriteGamesList();
    Object.keys(GAMES_CATALOG).forEach((k) => {
        const starBtn = document.getElementById('gameFavStar-' + k);
        if (starBtn) {
            const isFav = favs.includes(k);
            starBtn.textContent = isFav ? '⭐' : '☆';
            starBtn.classList.toggle('opacity-100', isFav);
            starBtn.classList.toggle('opacity-60', !isFav);
        }
    });
}

function filterGamesCatalog(cat) {
    activeGameCategory = cat || 'all';
    const searchVal = (document.getElementById('gameSearchInput')?.value || '').trim().toLowerCase();
    const favs      = getFavoriteGamesList();

    ['all', 'fav', 'arcade', 'action', 'logic'].forEach((c) => {
        const btn = document.getElementById('gameCatBtn-' + c);
        if (!btn) return;
        if (c === activeGameCategory) {
            btn.className = 'game-cat-btn px-3 py-1.5 rounded-xl bg-brand-600 text-white shadow-2xs cursor-pointer transition-all';
        } else {
            btn.className = 'game-cat-btn px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700 cursor-pointer transition-all';
        }
    });

    let visibleCount = 0;
    document.querySelectorAll('#gamesCatalogGrid .game-tab-btn').forEach((card) => {
        const gKey   = card.getAttribute('data-game-key') || '';
        const gGroup = card.getAttribute('data-game-group') || '';
        const gText  = card.getAttribute('data-game-search') || '';

        const matchCat = (activeGameCategory === 'all')
            || (activeGameCategory === 'fav' && favs.includes(gKey))
            || (gGroup === activeGameCategory);
        const matchSearch = (searchVal === '' || gText.includes(searchVal));

        if (matchCat && matchSearch) {
            card.classList.remove('hidden');
            visibleCount++;
        } else {
            card.classList.add('hidden');
        }
    });

    const emptyHint = document.getElementById('gamesEmptyFilterHint');
    if (emptyHint) {
        emptyHint.classList.toggle('hidden', visibleCount > 0);
    }
}

function toggleGamesCatalogCollapse() {
    const wrap = document.getElementById('gamesCatalogWrapper');
    const btn  = document.getElementById('toggleCatalogBtn');
    if (!wrap || !btn) return;
    const isHidden = wrap.classList.toggle('hidden');
    btn.textContent = isHidden ? '🔽 Spiele-Übersicht öffnen' : '🔼 Übersicht einklappen';
}

function syncGameFullscreenUi() {
    const card = document.getElementById('gamesActiveStageCard');
    const icon = document.getElementById('btnStageFullscreenIcon');
    const lbl  = document.getElementById('btnStageFullscreenLabel');
    const btn  = document.getElementById('btnStageFullscreen');
    if (!card) return;
    const isFs = card.classList.contains('mc-game-fullscreen') || document.fullscreenElement === card;
    if (icon) icon.textContent = isFs ? '✕' : '⛶';
    if (lbl)  lbl.textContent  = isFs ? 'Vollbild beenden' : 'Vollbild / Handy-Großansicht';
    if (btn) {
        btn.className = isFs
            ? 'px-3.5 py-1.5 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-extrabold shadow-2xs cursor-pointer transition-colors flex items-center gap-1.5'
            : 'px-3.5 py-1.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-extrabold shadow-2xs cursor-pointer transition-colors flex items-center gap-1.5';
    }
}

function toggleGameFullscreen() {
    const card = document.getElementById('gamesActiveStageCard');
    if (!card) return;

    const isCurrentlyFs = card.classList.contains('mc-game-fullscreen') || document.fullscreenElement === card;
    if (isCurrentlyFs) {
        card.classList.remove('mc-game-fullscreen');
        document.body.style.overflow = '';
        if (document.fullscreenElement && document.exitFullscreen) {
            document.exitFullscreen().catch(() => {});
        }
    } else {
        card.classList.add('mc-game-fullscreen');
        document.body.style.overflow = 'hidden';
        if (card.requestFullscreen) {
            card.requestFullscreen().catch(() => {});
        }
    }
    syncGameFullscreenUi();
}

document.addEventListener('fullscreenchange', () => {
    const card = document.getElementById('gamesActiveStageCard');
    if (card && !document.fullscreenElement) {
        // Falls per ESC aus dem nativen Browser-Vollbild gegangen wurde, auch CSS-Overlay beenden
        card.classList.remove('mc-game-fullscreen');
        document.body.style.overflow = '';
    }
    syncGameFullscreenUi();
});

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        const card = document.getElementById('gamesActiveStageCard');
        if (card && card.classList.contains('mc-game-fullscreen')) {
            card.classList.remove('mc-game-fullscreen');
            document.body.style.overflow = '';
            syncGameFullscreenUi();
        }
    }
});

// =============================================================================
// ZENTRALE SPIEL-UMSCHALTUNG & HIGHSCORE-API
// =============================================================================
function selectCloudGame(gameKey) {
    if (!GAMES_CATALOG[gameKey]) return;
    activeGameKey = gameKey;

    const quickSel = document.getElementById('quickGameSelect');
    if (quickSel && quickSel.value !== gameKey) {
        quickSel.value = gameKey;
    }
    const stageSel = document.getElementById('stageQuickGameSelect');
    if (stageSel && stageSel.value !== gameKey) {
        stageSel.value = gameKey;
    }

    document.querySelectorAll('.game-panel').forEach((el) => el.classList.add('hidden'));
    const targetPanel = document.getElementById('gamePanel-' + gameKey);
    if (targetPanel) targetPanel.classList.remove('hidden');

    document.querySelectorAll('.game-tab-btn').forEach((btn) => {
        btn.className = 'game-tab-btn group relative text-left p-3 rounded-2xl border transition-all cursor-pointer flex items-start gap-3 bg-slate-50/70 dark:bg-slate-800/50 text-slate-800 dark:text-slate-100 border-slate-200/90 dark:border-slate-700/80 hover:border-brand-400 hover:bg-white dark:hover:bg-slate-800';
    });
    const activeBtn = document.getElementById('gameTabBtn-' + gameKey);
    if (activeBtn) {
        activeBtn.className = 'game-tab-btn group relative text-left p-3 rounded-2xl border transition-all cursor-pointer flex items-start gap-3 bg-brand-600 text-white border-brand-600 shadow-md ring-2 ring-brand-400/50';
    }
    filterGamesCatalog(activeGameCategory);

    const titleEl = document.getElementById('leaderboardGameTitle');
    if (titleEl) {
        titleEl.textContent = 'Bestenliste: ' + GAMES_CATALOG[gameKey].title;
    }

    if (gameKey === 'gummigeruch') {
        if (!gummiInitialized) {
            gummiInit();
        }
        gummiPollState();
    } else if (gameKey === 'trafficracer') {
        if (!trInitialized) {
            trInit();
        }
        trDraw();
    } else if (gameKey === 'darts') {
        if (!dartsInitialized) {
            dartsInit();
        }
        dartsDraw();
    } else if (gameKey === 'frogger') {
        if (!froggerInitialized) {
            froggerInit();
        }
        froggerDraw();
    } else if (gameKey === 'pacman') {
        if (!pacmanInitialized) {
            pacmanInit();
        }
        pacmanDraw();
    } else if (gameKey === 'invaders') {
        if (!invadersInitialized) {
            invadersInit();
        }
        invadersDraw();
    } else if (gameKey === 'canabalt') {
        if (!canabaltInitialized) {
            canabaltInit();
        }
        canabaltDraw();
    } else if (gameKey === 'lastninja') {
        if (!ninjaInitialized) {
            ninjaInit();
        }
        ninjaDraw();
    } else if (gameKey === 'impossible') {
        if (!imInitialized) {
            imInit();
        }
        imDraw();
    } else if (gameKey === 'lemmings') {
        if (!lemInitialized) {
            lemInit();
        }
        lemDraw();
    } else if (gameKey === 'kong') {
        if (!kongInitialized) {
            kongInit();
        }
        kongDraw();
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
    } else if (activeGameKey === 'kong') {
        kongKeys[e.key] = true;
        if (['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight', ' '].includes(e.key)) {
            e.preventDefault();
        }
        if (e.key === ' ') {
            kongTriggerJump();
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

// =============================================================================
// SPIEL 12: CLOUD-KONG (1981 DONKEY-KONG ARCADE-KLASSIKER)
// =============================================================================
const KONG_GIRDERS = [
    // Etage 0 (Unten): leicht von rechts nach links geneigt, reicht über die gesamte Breite
    { x1: 20,  x2: 460, y1: 492, y2: 484, rollDir: -1 },
    // Etage 1: fällt von links nach rechts ab
    { x1: 20,  x2: 435, y1: 404, y2: 416, rollDir: 1 },
    // Etage 2: fällt von rechts nach links ab
    { x1: 45,  x2: 460, y1: 338, y2: 326, rollDir: -1 },
    // Etage 3: fällt von links nach rechts ab
    { x1: 20,  x2: 435, y1: 246, y2: 258, rollDir: 1 },
    // Etage 4 (Kongs Etage): links flach, fällt nach rechts ab
    { x1: 20,  x2: 460, y1: 170, y2: 180, rollDir: 1 },
    // Etage 5 (Zielplattform oben – Pauline!)
    { x1: 185, x2: 325, y1: 96,  y2: 96,  rollDir: 0 }
];

const KONG_LADDERS = [
    { x: 395, fromGirder: 0, toGirder: 1 },
    { x: 210, fromGirder: 0, toGirder: 1 },
    { x: 85,  fromGirder: 1, toGirder: 2 },
    { x: 265, fromGirder: 1, toGirder: 2 },
    { x: 385, fromGirder: 2, toGirder: 3 },
    { x: 165, fromGirder: 2, toGirder: 3 },
    { x: 85,  fromGirder: 3, toGirder: 4 },
    { x: 280, fromGirder: 3, toGirder: 4 },
    { x: 295, fromGirder: 4, toGirder: 5 }
];

let kongInitialized = false;
let kongRunning     = false;
let kongRafId       = null;
let kongScoreVal    = 0;
let kongLevelVal    = 1;
let kongLivesVal    = 3;
let kongBonusTimer  = 5000;
let kongStartTime   = 0;
let kongTick        = 0;
let kongKeys        = {};
let kongBarrels     = [];
let kongHammers     = [];
let kongPopups      = [];
let kongBannerText  = '';

let kongPlayer = {
    x: 62,
    y: 490,
    vx: 0,
    vy: 0,
    facing: 1,
    onGround: true,
    climbing: false,
    hammerTimer: 0
};

window.addEventListener('keyup', (e) => {
    if (kongKeys[e.key]) {
        kongKeys[e.key] = false;
    }
});

function kongGirderYAt(girderIdx, x) {
    const g = KONG_GIRDERS[girderIdx];
    if (!g) return 490;
    const t = Math.max(0, Math.min(1, (x - g.x1) / Math.max(1, g.x2 - g.x1)));
    return g.y1 + (g.y2 - g.y1) * t;
}

function kongFindGirderUnder(x, y) {
    for (let i = 0; i < KONG_GIRDERS.length; i++) {
        const g = KONG_GIRDERS[i];
        if (x >= g.x1 - 8 && x <= g.x2 + 8) {
            const gy = kongGirderYAt(i, x);
            if (Math.abs(y - gy) <= 12) {
                return { idx: i, y: gy };
            }
        }
    }
    return null;
}

function kongResetRound(resetHammers = true) {
    kongPlayer.x           = 62;
    kongPlayer.y           = kongGirderYAt(0, 62);
    kongPlayer.vx          = 0;
    kongPlayer.vy          = 0;
    kongPlayer.facing      = 1;
    kongPlayer.onGround    = true;
    kongPlayer.climbing    = false;
    kongPlayer.hammerTimer = 0;
    kongBarrels            = [];
    kongBonusTimer         = 5000;

    if (resetHammers) {
        kongHammers = [
            { x: 120, y: 376, active: true },
            { x: 335, y: 218, active: true }
        ];
    }
}

function kongUpdateHud(statusOverride = null) {
    const scEl = document.getElementById('kongScore');
    const lvEl = document.getElementById('kongLevel');
    const liEl = document.getElementById('kongLives');
    const bnEl = document.getElementById('kongBonus');

    if (scEl) scEl.textContent = kongScoreVal.toLocaleString('de-DE');
    if (lvEl) lvEl.textContent = String(kongLevelVal);
    if (liEl) liEl.textContent = '❤️'.repeat(Math.max(0, kongLivesVal)) || '💀';
    if (bnEl) {
        if (statusOverride !== null) {
            bnEl.textContent = statusOverride;
        } else if (kongPlayer.hammerTimer > 0) {
            bnEl.textContent = '🔨 HAMMER (' + Math.ceil(kongPlayer.hammerTimer / 60) + 's)';
        } else {
            bnEl.textContent = String(kongBonusTimer);
        }
    }
}

function kongInit() {
    kongInitialized = true;
    kongScoreVal    = 0;
    kongLevelVal    = 1;
    kongLivesVal    = 3;
    kongResetRound(true);
    kongBannerText  = '🦍 CLOUD-KONG BEREIT!';
    kongUpdateHud();
    kongDraw();
}

function kongStartGame() {
    if (kongRafId) cancelAnimationFrame(kongRafId);
    kongScoreVal   = 0;
    kongLevelVal   = 1;
    kongLivesVal   = 3;
    kongRunning    = true;
    kongTick       = 0;
    kongPopups     = [];
    kongBannerText = '';
    kongStartTime  = performance.now();
    kongResetRound(true);
    kongSpawnBarrel();
    kongUpdateHud();
    kongLoop();
}

function kongTriggerJump() {
    if (!kongRunning && kongInitialized) {
        kongStartGame();
        return;
    }
    if (kongPlayer.onGround && !kongPlayer.climbing) {
        kongPlayer.vy       = -6.4;
        kongPlayer.onGround = false;
    }
}

function kongSpawnBarrel() {
    kongBarrels.push({
        x: 112,
        y: kongGirderYAt(4, 112),
        girderIdx: 4,
        falling: false,
        onLadder: false,
        targetGirder: 3,
        vy: 0,
        rot: 0,
        jumpedOver: false
    });
}

function kongStepPlayer() {
    const moveLeft  = kongKeys['ArrowLeft']  || kongKeys['a'] || kongKeys['A'];
    const moveRight = kongKeys['ArrowRight'] || kongKeys['d'] || kongKeys['D'];
    const moveUp    = kongKeys['ArrowUp']    || kongKeys['w'] || kongKeys['W'];
    const moveDown  = kongKeys['ArrowDown']  || kongKeys['s'] || kongKeys['S'];

    if (kongPlayer.hammerTimer > 0) {
        kongPlayer.hammerTimer--;
    }

    // Leiter-Prüfung (nur ohne aktiven Hammer)
    let activeLadder = null;
    if (kongPlayer.hammerTimer === 0) {
        for (const lad of KONG_LADDERS) {
            const botY = kongGirderYAt(lad.fromGirder, lad.x);
            const topY = kongGirderYAt(lad.toGirder, lad.x);
            if (Math.abs(kongPlayer.x - lad.x) <= 13 && kongPlayer.y <= botY + 6 && kongPlayer.y >= topY - 6) {
                activeLadder = { ...lad, botY, topY };
                break;
            }
        }
    }

    if (activeLadder && (moveUp || moveDown || kongPlayer.climbing)) {
        if (moveUp && kongPlayer.y > activeLadder.topY) {
            kongPlayer.climbing = true;
            kongPlayer.x        = activeLadder.x;
            kongPlayer.y        = Math.max(activeLadder.topY, kongPlayer.y - 2.3);
            kongPlayer.vy       = 0;
        } else if (moveDown && kongPlayer.y < activeLadder.botY) {
            kongPlayer.climbing = true;
            kongPlayer.x        = activeLadder.x;
            kongPlayer.y        = Math.min(activeLadder.botY, kongPlayer.y + 2.3);
            kongPlayer.vy       = 0;
        }
        if (kongPlayer.y <= activeLadder.topY + 1 || kongPlayer.y >= activeLadder.botY - 1) {
            if (!moveUp && !moveDown) {
                kongPlayer.climbing = false;
                kongPlayer.onGround = true;
            }
        }
    } else {
        kongPlayer.climbing = false;
    }

    if (!kongPlayer.climbing) {
        if (moveLeft) {
            kongPlayer.x -= 2.6;
            kongPlayer.facing = -1;
        }
        if (moveRight) {
            kongPlayer.x += 2.6;
            kongPlayer.facing = 1;
        }
        kongPlayer.x = Math.max(24, Math.min(456, kongPlayer.x));

        // Schwerkraft & Plattform-Kontakt
        kongPlayer.vy += 0.34;
        kongPlayer.y  += kongPlayer.vy;

        if (kongPlayer.vy >= 0) {
            const gHit = kongFindGirderUnder(kongPlayer.x, kongPlayer.y);
            if (gHit) {
                kongPlayer.y        = gHit.y;
                kongPlayer.vy       = 0;
                kongPlayer.onGround = true;
            } else {
                kongPlayer.onGround = false;
            }
        }
    }

    // Power-Hammer aufsammeln
    for (const hm of kongHammers) {
        if (hm.active && Math.hypot(kongPlayer.x - hm.x, (kongPlayer.y - 14) - hm.y) < 22) {
            hm.active = false;
            kongPlayer.hammerTimer = 460; // ~7,5 Sekunden Hammer-Power!
            kongPopups.push({ x: hm.x, y: hm.y, text: '🔨 HAMMER!', color: '#facc15', life: 45 });
            kongUpdateHud();
        }
    }

    // Hat der Spieler die oberste Plattform (Etage 5 bei Pauline) erreicht?
    if (kongPlayer.y <= 102 && kongPlayer.x >= 185 && kongPlayer.x <= 325 && kongPlayer.onGround) {
        const stageBonus = kongBonusTimer + 1000 * kongLevelVal;
        kongScoreVal += stageBonus;
        kongLevelVal++;
        kongPopups.push({ x: 250, y: 80, text: '💖 GERETTET! +' + stageBonus, color: '#4ade80', life: 70 });
        kongResetRound(true);
        kongSpawnBarrel();
        kongUpdateHud('🎉 Stage ' + kongLevelVal + '!');
    }
}

function kongStepBarrels() {
    const spawnInterval = Math.max(78, 145 - (kongLevelVal - 1) * 14);
    if (kongTick % spawnInterval === 0) {
        kongSpawnBarrel();
    }

    const rollSpeed = Math.min(3.9, 2.2 + (kongLevelVal - 1) * 0.25);

    for (let i = kongBarrels.length - 1; i >= 0; i--) {
        const b = kongBarrels[i];
        b.rot += 0.16;

        if (b.onLadder) {
            const targetY = kongGirderYAt(b.targetGirder, b.x);
            b.y += 3.1;
            if (b.y >= targetY) {
                b.y         = targetY;
                b.girderIdx = b.targetGirder;
                b.onLadder  = false;
            }
        } else if (b.falling) {
            b.vy += 0.35;
            b.y  += b.vy;
            const nextG = b.girderIdx - 1;
            if (nextG >= 0) {
                const gy = kongGirderYAt(nextG, b.x);
                if (b.y >= gy) {
                    b.y         = gy;
                    b.vy        = 0;
                    b.falling   = false;
                    b.girderIdx = nextG;
                }
            } else if (b.y > 520) {
                kongBarrels.splice(i, 1);
                continue;
            }
        } else {
            const g = KONG_GIRDERS[b.girderIdx];
            b.x += g.rollDir * rollSpeed;
            b.y  = kongGirderYAt(b.girderIdx, b.x);

            // Mit 25 % Chance kullert ein Fass eine kreuzende Leiter hinab!
            for (const lad of KONG_LADDERS) {
                if (lad.toGirder === b.girderIdx && Math.abs(b.x - lad.x) < 2.2 && Math.random() < 0.22) {
                    b.x            = lad.x;
                    b.onLadder     = true;
                    b.targetGirder = lad.fromGirder;
                    break;
                }
            }

            if (!b.onLadder) {
                if (b.girderIdx === 0 && b.x <= 42) {
                    // Unten links im Ölfass verschwunden
                    kongBarrels.splice(i, 1);
                    continue;
                }
                if ((g.rollDir > 0 && b.x >= g.x2) || (g.rollDir < 0 && b.x <= g.x1)) {
                    b.falling = true;
                    b.vy      = 1.2;
                }
            }
        }

        // 1. Prüfen, ob Spieler über das Fass gesprungen ist (+100 Pkt.)
        if (!b.jumpedOver && !kongPlayer.onGround && !kongPlayer.climbing) {
            const dx = Math.abs(kongPlayer.x - b.x);
            const dy = b.y - kongPlayer.y;
            if (dx < 16 && dy > 10 && dy < 44) {
                b.jumpedOver = true;
                kongScoreVal += 100;
                kongPopups.push({ x: b.x, y: b.y - 16, text: '+100', color: '#38bdf8', life: 40 });
                kongUpdateHud();
            }
        }

        // 2. Kollision mit Spieler oder aktivem Power-Hammer prüfen
        const dist = Math.hypot(kongPlayer.x - b.x, (kongPlayer.y - 10) - (b.y - 8));
        if (dist < (kongPlayer.hammerTimer > 0 ? 25 : 15)) {
            if (kongPlayer.hammerTimer > 0) {
                // Fass mit dem Hammer zerschmettert!
                kongScoreVal += 300;
                kongPopups.push({ x: b.x, y: b.y - 12, text: '💥 +300', color: '#facc15', life: 45 });
                kongBarrels.splice(i, 1);
                kongUpdateHud();
            } else {
                // Getroffen!
                kongLivesVal--;
                if (kongLivesVal <= 0) {
                    kongRunning    = false;
                    kongBannerText = '💥 GAME OVER – ' + kongScoreVal.toLocaleString('de-DE') + ' PKT.';
                    kongUpdateHud('💥 Game Over');
                    const durSec = Math.max(1, Math.round((performance.now() - kongStartTime) / 1000));
                    submitCloudScore('kong', kongScoreVal, durSec, 'Stage ' + kongLevelVal);
                    return;
                } else {
                    kongPopups.push({ x: kongPlayer.x, y: kongPlayer.y - 20, text: '💔 Autsch!', color: '#f87171', life: 55 });
                    kongResetRound(false);
                    kongUpdateHud('Noch ' + kongLivesVal + ' Leben');
                    return;
                }
            }
        }
    }
}

function kongDraw() {
    const cvs = document.getElementById('kongCanvas');
    if (!cvs) return;
    const ctx = cvs.getContext('2d');

    ctx.fillStyle = '#020617';
    ctx.fillRect(0, 0, cvs.width, cvs.height);

    // 1. Türkisfarbene Leitern zeichnen
    ctx.strokeStyle = '#38bdf8';
    ctx.lineWidth   = 2.5;
    for (const lad of KONG_LADDERS) {
        const yBot = kongGirderYAt(lad.fromGirder, lad.x);
        const yTop = kongGirderYAt(lad.toGirder, lad.x);
        ctx.beginPath();
        ctx.moveTo(lad.x - 7, yTop);
        ctx.lineTo(lad.x - 7, yBot);
        ctx.moveTo(lad.x + 7, yTop);
        ctx.lineTo(lad.x + 7, yBot);
        for (let ry = yTop + 6; ry < yBot; ry += 9) {
            ctx.moveTo(lad.x - 7, ry);
            ctx.lineTo(lad.x + 7, ry);
        }
        ctx.stroke();
    }

    // 2. Rote Arcade-Stahlträger (Girders) mit Fachwerk-Zickzack zeichnen
    for (const g of KONG_GIRDERS) {
        ctx.strokeStyle = '#e11d48';
        ctx.lineWidth   = 4;
        ctx.beginPath();
        ctx.moveTo(g.x1, g.y1);
        ctx.lineTo(g.x2, g.y2);
        ctx.moveTo(g.x1, g.y1 + 8);
        ctx.lineTo(g.x2, g.y2 + 8);
        ctx.stroke();

        ctx.strokeStyle = '#fb7185';
        ctx.lineWidth   = 1.5;
        ctx.beginPath();
        const len = g.x2 - g.x1;
        for (let sx = 0; sx < len - 10; sx += 14) {
            const xA = g.x1 + sx;
            const xB = g.x1 + sx + 7;
            const yA = g.y1 + (g.y2 - g.y1) * (sx / len);
            const yB = g.y1 + (g.y2 - g.y1) * ((sx + 7) / len);
            ctx.moveTo(xA, yA);
            ctx.lineTo(xB, yB + 8);
            ctx.lineTo(xA + 14, yA);
        }
        ctx.stroke();
    }

    // 3. Brennendes Ölfass unten links & Fässer-Vorrat oben links
    ctx.fillStyle = '#1d4ed8';
    ctx.fillRect(22, 462, 24, 28);
    ctx.fillStyle = '#facc15';
    ctx.font = 'bold 9px monospace';
    ctx.textAlign = 'center';
    ctx.fillText('OIL', 34, 480);
    ctx.font = '16px sans-serif';
    ctx.fillText((Math.floor(kongTick / 10) % 2 === 0) ? '🔥' : '💥', 34, 458);

    // 4. 🦍 Kong oben links & 👸 Pauline auf der Zielplattform
    const kongShake = (Math.floor(kongTick / 18) % 2 === 0) ? -2 : 2;
    ctx.font = '36px sans-serif';
    ctx.fillText('🦍', 72 + kongShake, 166);
    ctx.font = '24px sans-serif';
    ctx.fillText('👸', 245, 92);
    ctx.fillStyle = '#f472b6';
    ctx.font = 'bold 11px monospace';
    ctx.fillText('HELP! 💖', 210, 74);

    // 5. Power-Hämmer zeichnen
    for (const hm of kongHammers) {
        if (!hm.active) continue;
        ctx.font = '20px sans-serif';
        ctx.fillText('🔨', hm.x, hm.y);
    }

    // 6. Rollende Fässer zeichnen
    for (const b of kongBarrels) {
        ctx.save();
        ctx.translate(b.x, b.y - 8);
        ctx.rotate(b.rot);
        ctx.fillStyle   = '#d97706';
        ctx.strokeStyle = '#fef08a';
        ctx.lineWidth   = 2;
        ctx.beginPath();
        ctx.arc(0, 0, 8, 0, Math.PI * 2);
        ctx.fill();
        ctx.stroke();
        ctx.beginPath();
        ctx.moveTo(-6, 0);
        ctx.lineTo(6, 0);
        ctx.stroke();
        ctx.restore();
    }

    // 7. Spieler (Jumpman) zeichnen
    ctx.save();
    ctx.translate(kongPlayer.x, kongPlayer.y);
    // Schuhe & blauer Overall
    ctx.fillStyle = '#2563eb';
    ctx.fillRect(-6, -14, 12, 11);
    // Rotes Hemd & Mütze
    ctx.fillStyle = '#ef4444';
    ctx.fillRect(-7, -20, 14, 7);
    ctx.fillRect(-7, -27, 14, 4);
    // Gesicht
    ctx.fillStyle = '#fde68a';
    ctx.fillRect(-5, -24, 10, 5);

    // Schwingender Hammer, falls aktiv!
    if (kongPlayer.hammerTimer > 0) {
        const swingUp = (Math.floor(kongTick / 5) % 2 === 0);
        ctx.font = '20px sans-serif';
        ctx.fillText('🔨', kongPlayer.facing * 14, swingUp ? -24 : -8);
    }
    ctx.restore();

    // 8. Punkte-Popups
    for (let i = kongPopups.length - 1; i >= 0; i--) {
        const p = kongPopups[i];
        ctx.fillStyle = p.color;
        ctx.font = 'bold 12px monospace';
        ctx.textAlign = 'center';
        ctx.fillText(p.text, p.x, p.y);
        p.y -= 0.5;
        p.life--;
        if (p.life <= 0) {
            kongPopups.splice(i, 1);
        }
    }

    // 9. Start- / Game-Over-Banner
    if (!kongRunning) {
        ctx.fillStyle = 'rgba(2, 6, 23, 0.78)';
        ctx.fillRect(0, cvs.height / 2 - 44, cvs.width, 88);
        ctx.textAlign = 'center';
        ctx.fillStyle = '#fb7185';
        ctx.font = 'bold 21px monospace';
        ctx.fillText(kongBannerText || '🦍 CLOUD-KONG BEREIT!', cvs.width / 2, cvs.height / 2 - 6);
        ctx.fillStyle = '#e2e8f0';
        ctx.font = '12px sans-serif';
        ctx.fillText('Drücke LEERTASTE oder „Neues Spiel starten“ zum Klettern & Springen!', cvs.width / 2, cvs.height / 2 + 20);
    }
}

function kongLoop() {
    if (!kongRunning) {
        kongDraw();
        return;
    }
    kongTick++;
    if (kongTick % 45 === 0 && kongBonusTimer > 100) {
        kongBonusTimer = Math.max(0, kongBonusTimer - 100);
        kongUpdateHud();
    }
    kongStepPlayer();
    if (kongRunning) {
        kongStepBarrels();
    }
    kongDraw();
    if (kongRunning) {
        kongRafId = requestAnimationFrame(kongLoop);
    }
}

// =============================================================================
// 13. CLOUD-INVADERS (1978 TAITO SPACE-INVADERS ARCADE-KLASSIKER)
// =============================================================================
let invadersInitialized = false;
let invadersRunning     = false;
let invadersRafId       = null;
let invadersScore       = 0;
let invadersWave        = 1;
let invadersLives       = 3;
let invadersStartAt     = 0;
let invadersTick        = 0;
let invadersBannerText  = '';
const invadersKeys      = {};

let invadersPlayer = { x: 260, y: 442, w: 34, h: 16, invuln: 0 };
let invadersShots  = []; // Spieler-Laser
let invadersBombs  = []; // Alien-Plasmabomben
let invadersAliens = []; // { row, col, x, y, w, h, alive, type, points, color }
let invadersBunkers = []; // Zerstörbare Pixel-Blöcke { x, y, w, h, hp }
let invadersUfo     = null; // { x, y, vx, points }
let invadersPopups  = []; // { x, y, text, color, life }
let invadersPart    = []; // Funken
let invadersDir     = 1;  // +1 rechts, -1 links
let invadersStepAcc = 0;
let invadersStepFrame = 0; // 0 oder 1 für 2-Frame-Marsch-Animation

const INVADERS_PIXEL_SPRITES = {
    // 🦑 Squid (Reihe 0, +30 Pkt.) – 8x8 Pixelmatrix
    squid0: [
        '00011000',
        '00111100',
        '01111110',
        '11011011',
        '11111111',
        '00100100',
        '01011010',
        '10100101'
    ],
    squid1: [
        '00011000',
        '00111100',
        '01111110',
        '11011011',
        '11111111',
        '01011010',
        '10000001',
        '01000010'
    ],
    // 🦀 Crab (Reihe 1-2, +20 Pkt.) – 11x8 Pixelmatrix
    crab0: [
        '00100000100',
        '00010001000',
        '00111111100',
        '01101110110',
        '11111111111',
        '10111111101',
        '10100000101',
        '00011011000'
    ],
    crab1: [
        '00100000100',
        '10010001001',
        '10111111101',
        '11101110111',
        '11111111111',
        '01111111110',
        '00100000100',
        '01000000010'
    ],
    // 🐙 Octopus (Reihe 3-4, +10 Pkt.) – 12x8 Pixelmatrix
    octo0: [
        '000011110000',
        '011111111110',
        '111111111111',
        '111001100111',
        '111111111111',
        '000110011000',
        '001101101100',
        '110000000011'
    ],
    octo1: [
        '000011110000',
        '011111111110',
        '111111111111',
        '111001100111',
        '111111111111',
        '001110011100',
        '011001100110',
        '001100001100'
    ]
};

function invadersBuildBunkers() {
    invadersBunkers = [];
    const bunkerXs = [62, 174, 286, 398];
    const baseY    = 378;
    const blockW   = 6;
    const blockH   = 6;
    // Klassische Schutzbunker-Form (10x6 Blöcke mit Ausschnitt unten in der Mitte)
    const shape = [
        '0011111100',
        '0111111110',
        '1111111111',
        '1111111111',
        '1110000111',
        '1100000011'
    ];
    for (const bx of bunkerXs) {
        for (let r = 0; r < shape.length; r++) {
            for (let c = 0; c < shape[r].length; c++) {
                if (shape[r][c] === '1') {
                    invadersBunkers.push({
                        x: bx + c * blockW,
                        y: baseY + r * blockH,
                        w: blockW,
                        h: blockH,
                        hp: 2
                    });
                }
            }
        }
    }
}

function invadersBuildWave(waveNum) {
    invadersAliens    = [];
    invadersShots     = [];
    invadersBombs     = [];
    invadersUfo       = null;
    invadersDir       = 1;
    invadersStepAcc   = 0;
    invadersStepFrame = 0;

    // 5 Reihen x 11 Spalten = 55 Aliens
    const startX = 48;
    const startY = Math.min(130, 68 + (waveNum - 1) * 10);
    const gapX   = 36;
    const gapY   = 28;

    for (let r = 0; r < 5; r++) {
        let type   = 'octo';
        let points = 10;
        let color  = '#4ade80'; // Neon-Grün
        if (r === 0) {
            type   = 'squid';
            points = 30;
            color  = '#f43f5e'; // Neon-Rose
        } else if (r === 1 || r === 2) {
            type   = 'crab';
            points = 20;
            color  = '#38bdf8'; // Neon-Cyan
        }
        for (let c = 0; c < 11; c++) {
            invadersAliens.push({
                row: r,
                col: c,
                x: startX + c * gapX,
                y: startY + r * gapY,
                w: 24,
                h: 16,
                alive: true,
                type: type,
                points: points,
                color: color
            });
        }
    }
    invadersUpdateHud();
}

function invadersInit() {
    if (invadersInitialized) return;
    invadersInitialized = true;

    window.addEventListener('keydown', (e) => {
        if (activeGameKey !== 'invaders') return;
        if (['ArrowLeft', 'ArrowRight', 'ArrowUp', ' ', 'a', 'A', 'd', 'D', 'w', 'W', 'p', 'P'].includes(e.key)) {
            if (['ArrowLeft', 'ArrowRight', 'ArrowUp', ' '].includes(e.key)) {
                e.preventDefault();
            }
            if (e.key === 'a' || e.key === 'A') invadersKeys['ArrowLeft'] = true;
            else if (e.key === 'd' || e.key === 'D') invadersKeys['ArrowRight'] = true;
            else if (e.key === ' ' || e.key === 'ArrowUp' || e.key === 'w' || e.key === 'W') {
                invadersFireLaser();
            } else if (e.key === 'p' || e.key === 'P') {
                if (invadersRunning) {
                    invadersRunning = false;
                    invadersBannerText = '⏸️ PAUSE';
                    invadersDraw();
                } else if (invadersLives > 0) {
                    invadersRunning = true;
                    invadersBannerText = '';
                    invadersRafId = requestAnimationFrame(invadersLoop);
                }
            } else {
                invadersKeys[e.key] = true;
            }
        }
    });

    window.addEventListener('keyup', (e) => {
        if (e.key === 'a' || e.key === 'A') invadersKeys['ArrowLeft'] = false;
        else if (e.key === 'd' || e.key === 'D') invadersKeys['ArrowRight'] = false;
        else invadersKeys[e.key] = false;
    });

    invadersBuildBunkers();
    invadersBuildWave(1);
    invadersBannerText = '👾 CLOUD-INVADERS BEREIT!';
    invadersDraw();
}

function invadersStartGame() {
    if (invadersRafId) cancelAnimationFrame(invadersRafId);
    invadersScore      = 0;
    invadersWave       = 1;
    invadersLives      = 3;
    invadersStartAt    = Date.now();
    invadersTick       = 0;
    invadersBannerText = '';
    invadersPopups     = [];
    invadersPart       = [];
    invadersPlayer     = { x: 260, y: 442, w: 34, h: 16, invuln: 0 };
    invadersBuildBunkers();
    invadersBuildWave(1);
    invadersRunning    = true;
    invadersRafId      = requestAnimationFrame(invadersLoop);
}

function invadersFireLaser() {
    if (!invadersRunning) {
        invadersStartGame();
        return;
    }
    // Originalgetreu: Maximal 2 aktive Laser-Schüsse gleichzeitig auf dem Schirm
    if (invadersShots.length >= 2) return;
    invadersShots.push({
        x: invadersPlayer.x,
        y: invadersPlayer.y - 10,
        vy: -7.5
    });
}

function invadersUpdateHud() {
    const scEl = document.getElementById('invadersScore');
    const wvEl = document.getElementById('invadersWave');
    const lfEl = document.getElementById('invadersLives');
    const alEl = document.getElementById('invadersLeft');
    if (scEl) scEl.textContent = invadersScore.toLocaleString('de-DE');
    if (wvEl) wvEl.textContent = String(invadersWave);
    if (lfEl) lfEl.textContent = '❤️'.repeat(Math.max(0, invadersLives)) || '💀';
    if (alEl) {
        const aliveCnt = invadersAliens.filter((a) => a.alive).length;
        alEl.textContent = aliveCnt + ' / 55';
    }
}

function invadersSpawnExplosion(x, y, color, count = 12) {
    for (let i = 0; i < count; i++) {
        invadersPart.push({
            x: x,
            y: y,
            vx: (Math.random() - 0.5) * 4.5,
            vy: (Math.random() - 0.5) * 4.5,
            life: 16 + Math.floor(Math.random() * 10),
            color: color
        });
    }
}

function invadersLoseLife() {
    invadersLives--;
    invadersSpawnExplosion(invadersPlayer.x, invadersPlayer.y, '#34d399', 22);
    invadersUpdateHud();
    if (invadersLives <= 0) {
        invadersRunning = false;
        invadersBannerText = '💀 GAME OVER – ERDE EROBERT!';
        const durSec = Math.max(1, Math.round((Date.now() - invadersStartAt) / 1000));
        submitCloudScore('invaders', invadersScore, durSec, 'Welle ' + invadersWave);
    } else {
        invadersPlayer.x = 260;
        invadersPlayer.invuln = 85;
        invadersBombs = [];
    }
}

function invadersStep() {
    invadersTick++;
    if (invadersPlayer.invuln > 0) invadersPlayer.invuln--;

    // 1. Spieler-Kanone links/rechts
    if (invadersKeys['ArrowLeft']) {
        invadersPlayer.x = Math.max(26, invadersPlayer.x - 4.0);
    }
    if (invadersKeys['ArrowRight']) {
        invadersPlayer.x = Math.min(494, invadersPlayer.x + 4.0);
    }

    // 2. Mystery-UFO oben (erscheint alle ~420 Ticks)
    if (!invadersUfo && invadersTick % 420 === 200) {
        const fromLeft = Math.random() < 0.5;
        const bonusPool = [100, 150, 200, 300];
        invadersUfo = {
            x: fromLeft ? -24 : 544,
            y: 36,
            vx: fromLeft ? 2.2 : -2.2,
            points: bonusPool[Math.floor(Math.random() * bonusPool.length)]
        };
    }
    if (invadersUfo) {
        invadersUfo.x += invadersUfo.vx;
        if (invadersUfo.x < -40 || invadersUfo.x > 560) {
            invadersUfo = null;
        }
    }

    // 3. Alien-Formation marschiert (je weniger Aliens leben, desto kürzer das Marsch-Intervall!)
    const aliveAliens = invadersAliens.filter((a) => a.alive);
    if (aliveAliens.length === 0) {
        // Welle komplett besiegt!
        invadersScore += 500 * invadersWave;
        invadersWave++;
        invadersPopups.push({ x: 260, y: 220, text: '🎉 WELLE ' + invadersWave + '! (+500 BONUS)', color: '#fde047', life: 60 });
        if (invadersWave % 2 === 1) {
            invadersBuildBunkers(); // Alle 2 Wellen frische Bunker
        }
        invadersBuildWave(invadersWave);
        return;
    }

    // Marsch-Verzögerung: Bei 55 Aliens alle 30 Frames, bei 1 Alien alle 2 Frames!
    const stepDelay = Math.max(2, Math.floor(3 + (aliveAliens.length / 55) * 26 - Math.min(8, invadersWave)));
    invadersStepAcc++;
    if (invadersStepAcc >= stepDelay) {
        invadersStepAcc = 0;
        invadersStepFrame = 1 - invadersStepFrame;

        let hitEdge = false;
        const stepX = 8 * invadersDir;
        for (const a of aliveAliens) {
            if (a.x + stepX > 492 || a.x + stepX < 28) {
                hitEdge = true;
                break;
            }
        }

        if (hitEdge) {
            invadersDir = -invadersDir;
            for (const a of aliveAliens) {
                a.y += 14;
                // Wenn Aliens die Bunker berühren, werden Bunker-Blöcke abgetragen
                for (let b = invadersBunkers.length - 1; b >= 0; b--) {
                    const bk = invadersBunkers[b];
                    if (Math.abs(a.x - bk.x) < 16 && Math.abs(a.y - bk.y) < 14) {
                        invadersBunkers.splice(b, 1);
                    }
                }
                // Wenn Aliens die Höhe der Kanone erreichen -> sofortige Invasion!
                if (a.y + a.h / 2 >= invadersPlayer.y - 8) {
                    invadersLives = 1;
                    invadersLoseLife();
                    return;
                }
            }
        } else {
            for (const a of aliveAliens) {
                a.x += stepX;
            }
        }
    }

    // 4. Unterste Aliens jeder Spalte werfen Plasmabomben ab
    const fireChance = Math.min(0.055, 0.016 + invadersWave * 0.004 + (55 - aliveAliens.length) * 0.0004);
    if (Math.random() < fireChance && invadersBombs.length < 5 + Math.floor(invadersWave / 2)) {
        // Wähle ein zufälliges lebendes Alien, das in seiner Spalte am tiefsten steht
        const bottomByCol = {};
        for (const a of aliveAliens) {
            if (!bottomByCol[a.col] || a.row > bottomByCol[a.col].row) {
                bottomByCol[a.col] = a;
            }
        }
        const cols = Object.values(bottomByCol);
        if (cols.length > 0) {
            const shooter = cols[Math.floor(Math.random() * cols.length)];
            invadersBombs.push({
                x: shooter.x,
                y: shooter.y + 10,
                vy: 3.4 + Math.min(2.2, invadersWave * 0.25)
            });
        }
    }

    // 5. Spieler-Laser bewegen & Treffer prüfen
    for (let i = invadersShots.length - 1; i >= 0; i--) {
        const s = invadersShots[i];
        s.y += s.vy;
        if (s.y < 10) {
            invadersShots.splice(i, 1);
            continue;
        }

        // a) Treffer auf Schutzbunker?
        let hitBunker = false;
        for (let b = invadersBunkers.length - 1; b >= 0; b--) {
            const bk = invadersBunkers[b];
            if (s.x >= bk.x - 2 && s.x <= bk.x + bk.w + 2 && s.y >= bk.y && s.y <= bk.y + bk.h) {
                bk.hp--;
                if (bk.hp <= 0) invadersBunkers.splice(b, 1);
                invadersShots.splice(i, 1);
                hitBunker = true;
                break;
            }
        }
        if (hitBunker) continue;

        // b) Treffer auf Mystery-UFO?
        if (invadersUfo && Math.abs(s.x - invadersUfo.x) < 18 && Math.abs(s.y - invadersUfo.y) < 11) {
            invadersScore += invadersUfo.points;
            invadersSpawnExplosion(invadersUfo.x, invadersUfo.y, '#f43f5e', 18);
            invadersPopups.push({ x: invadersUfo.x, y: invadersUfo.y, text: '🛸 +' + invadersUfo.points + '!', color: '#fda4af', life: 45 });
            invadersUfo = null;
            invadersShots.splice(i, 1);
            invadersUpdateHud();
            continue;
        }

        // c) Treffer auf Alien?
        let hitAlien = false;
        for (const a of aliveAliens) {
            if (Math.abs(s.x - a.x) <= a.w / 2 + 2 && Math.abs(s.y - a.y) <= a.h / 2 + 2) {
                a.alive = false;
                invadersScore += a.points;
                invadersSpawnExplosion(a.x, a.y, a.color, 12);
                invadersShots.splice(i, 1);
                invadersUpdateHud();
                hitAlien = true;
                break;
            }
        }
        if (hitAlien) continue;
    }

    // 6. Alien-Plasmabomben bewegen & Treffer prüfen
    for (let i = invadersBombs.length - 1; i >= 0; i--) {
        const b = invadersBombs[i];
        b.y += b.vy;
        if (b.y > 465) {
            invadersBombs.splice(i, 1);
            continue;
        }

        // Treffer auf Schutzbunker?
        let hitBunker = false;
        for (let k = invadersBunkers.length - 1; k >= 0; k--) {
            const bk = invadersBunkers[k];
            if (b.x >= bk.x - 3 && b.x <= bk.x + bk.w + 3 && b.y >= bk.y && b.y <= bk.y + bk.h) {
                bk.hp--;
                if (bk.hp <= 0) invadersBunkers.splice(k, 1);
                invadersSpawnExplosion(b.x, b.y, '#22c55e', 4);
                invadersBombs.splice(i, 1);
                hitBunker = true;
                break;
            }
        }
        if (hitBunker) continue;

        // Treffer auf Spieler-Laserkanone?
        if (invadersPlayer.invuln <= 0 &&
            Math.abs(b.x - invadersPlayer.x) < invadersPlayer.w / 2 &&
            Math.abs(b.y - invadersPlayer.y) < invadersPlayer.h / 2 + 4) {
            invadersBombs.splice(i, 1);
            invadersLoseLife();
            return;
        }
    }
}

function invadersDrawPixelSprite(ctx, matrix, centerX, centerY, pixelSize, color) {
    const rows = matrix.length;
    const cols = matrix[0].length;
    const startX = centerX - (cols * pixelSize) / 2;
    const startY = centerY - (rows * pixelSize) / 2;
    ctx.fillStyle = color;
    for (let r = 0; r < rows; r++) {
        const rowStr = matrix[r];
        for (let c = 0; c < cols; c++) {
            if (rowStr[c] === '1') {
                ctx.fillRect(startX + c * pixelSize, startY + r * pixelSize, pixelSize, pixelSize);
            }
        }
    }
}

function invadersDraw() {
    const cvs = document.getElementById('invadersCanvas');
    if (!cvs) return;
    const ctx = cvs.getContext('2d');

    // 1. Weltraum-Hintergrund + CRT-Scanlines
    ctx.fillStyle = '#020617';
    ctx.fillRect(0, 0, cvs.width, cvs.height);

    // Sternenhimmel
    ctx.fillStyle = 'rgba(226, 232, 240, 0.45)';
    for (let i = 0; i < 36; i++) {
        const sx = (i * 97 + 19) % cvs.width;
        const sy = (i * 61 + 23) % (cvs.height - 40);
        ctx.fillRect(sx, sy, i % 3 === 0 ? 2 : 1, i % 3 === 0 ? 2 : 1);
    }

    // Grüne Bodenlinie wie beim 1978er Arcade-Automaten
    ctx.strokeStyle = '#22c55e';
    ctx.lineWidth = 2;
    ctx.beginPath();
    ctx.moveTo(12, 462);
    ctx.lineTo(cvs.width - 12, 462);
    ctx.stroke();

    // 2. Mystery-UFO oben zeichnen
    if (invadersUfo) {
        ctx.fillStyle = '#f43f5e';
        ctx.beginPath();
        ctx.ellipse(invadersUfo.x, invadersUfo.y, 16, 6, 0, 0, Math.PI * 2);
        ctx.fill();
        ctx.fillStyle = '#fda4af';
        ctx.beginPath();
        ctx.ellipse(invadersUfo.x, invadersUfo.y - 4, 8, 4, 0, 0, Math.PI * 2);
        ctx.fill();
        // Blinkende UFO-Lichter
        ctx.fillStyle = '#fde047';
        for (let lx = -10; lx <= 10; lx += 5) {
            ctx.fillRect(invadersUfo.x + lx - 1, invadersUfo.y - 1, 2, 2);
        }
    }

    // 3. 4 Schutzbunker (destructible shields) zeichnen
    for (const bk of invadersBunkers) {
        ctx.fillStyle = bk.hp === 2 ? '#22c55e' : '#15803d';
        ctx.fillRect(bk.x, bk.y, bk.w - 0.5, bk.h - 0.5);
    }

    // 4. 55 Pixel-Aliens in 2-Frame-Marsch-Animation zeichnen
    for (const a of invadersAliens) {
        if (!a.alive) continue;
        const key = a.type + invadersStepFrame;
        const sprite = INVADERS_PIXEL_SPRITES[key] || INVADERS_PIXEL_SPRITES.octo0;
        invadersDrawPixelSprite(ctx, sprite, a.x, a.y, 2, a.color);
    }

    // 5. Spieler-Laserkanone unten zeichnen
    if (invadersPlayer.invuln % 4 < 2) {
        const px = invadersPlayer.x;
        const py = invadersPlayer.y;
        ctx.fillStyle = '#34d399';
        // Kanonen-Basis
        ctx.fillRect(px - 15, py, 30, 9);
        ctx.fillRect(px - 11, py - 4, 22, 4);
        // Laser-Rohr
        ctx.fillStyle = '#a7f3d0';
        ctx.fillRect(px - 2, py - 10, 4, 6);
    }

    // 6. Spieler-Laser & Alien-Plasmabomben zeichnen
    ctx.fillStyle = '#fde047';
    for (const s of invadersShots) {
        ctx.fillRect(s.x - 1.5, s.y - 6, 3, 11);
    }
    ctx.fillStyle = '#fb7185';
    for (const b of invadersBombs) {
        // Zickzack-Plasmabombe
        const zig = (invadersTick % 6 < 3) ? -2 : 2;
        ctx.fillRect(b.x - 1.5 + zig, b.y - 5, 3, 5);
        ctx.fillRect(b.x - 1.5 - zig, b.y, 3, 5);
    }

    // 7. Explosions-Partikel & Punkte-Popups
    for (let i = invadersPart.length - 1; i >= 0; i--) {
        const pt = invadersPart[i];
        ctx.fillStyle = pt.color;
        ctx.fillRect(pt.x, pt.y, 3, 3);
        pt.x += pt.vx;
        pt.y += pt.vy;
        pt.life--;
        if (pt.life <= 0) invadersPart.splice(i, 1);
    }

    for (let i = invadersPopups.length - 1; i >= 0; i--) {
        const p = invadersPopups[i];
        ctx.fillStyle = p.color;
        ctx.font = 'bold 12px monospace';
        ctx.textAlign = 'center';
        ctx.fillText(p.text, p.x, p.y);
        p.y -= 0.5;
        p.life--;
        if (p.life <= 0) invadersPopups.splice(i, 1);
    }

    // 8. Start- / Game-Over-Banner
    if (!invadersRunning) {
        ctx.fillStyle = 'rgba(2, 6, 23, 0.80)';
        ctx.fillRect(0, cvs.height / 2 - 48, cvs.width, 96);
        ctx.textAlign = 'center';
        ctx.fillStyle = '#34d399';
        ctx.font = 'bold 21px monospace';
        ctx.fillText(invadersBannerText || '👾 CLOUD-INVADERS (1978)', cvs.width / 2, cvs.height / 2 - 10);
        ctx.fillStyle = '#e2e8f0';
        ctx.font = '12px sans-serif';
        ctx.fillText('🦑 = 30 Pkt.  |  🦀 = 20 Pkt.  |  🐙 = 10 Pkt.  |  🛸 = Mystery-Bonus', cvs.width / 2, cvs.height / 2 + 12);
        ctx.fillStyle = '#94a3b8';
        ctx.fillText('Drücke LEERTASTE oder „Neues Spiel starten“ zum Verteidigen der Erde!', cvs.width / 2, cvs.height / 2 + 32);
    }
}

function invadersLoop() {
    if (!invadersRunning) {
        invadersDraw();
        return;
    }
    invadersStep();
    invadersDraw();
    if (invadersRunning) {
        invadersRafId = requestAnimationFrame(invadersLoop);
    }
}

// =============================================================================
// 14. CLOUD-CANABALT (2009 EIN-TASTEN ROOFTOP-PARKOUR-RUNNER)
// =============================================================================
let canabaltInitialized = false;
let canabaltRunning     = false;
let canabaltRafId       = null;
let canabaltStartAt     = 0;
let canabaltTick        = 0;
let canabaltDistPx      = 0;
let canabaltMaxKmh      = 0;
let canabaltShake       = 0;
let canabaltJumpHeld    = false;
let canabaltBannerText  = '';
let canabaltDeathReason = '';

let canabaltRunner = {
    x: 135,
    y: 200,
    vx: 6.2,
    vy: 0,
    w: 16,
    h: 28,
    onGround: false,
    jumpHoldFrames: 0,
    stumbleFrames: 0,
    animPhase: 0
};

let canabaltBuildings = []; // { x, y, w, type: 'normal'|'hallway'|'collapsing'|'crane', ceilingH, collapsing, sinkVy, crates:[], pigeons:[], bomb:null, enteredLeftGlass:false, exitedRightGlass:false }
let canabaltPigeons   = []; // Fliegende Tauben { x, y, vx, vy, wing }
let canabaltShards    = []; // Glassplitter, Kistentrümmer & Rauch { x, y, vx, vy, size, color, life }
let canabaltBgTowers1 = []; // Ferne Skyline
let canabaltBgTowers2 = []; // Mittlere Skyline

function canabaltBuildParallax() {
    canabaltBgTowers1 = [];
    canabaltBgTowers2 = [];
    for (let x = 0; x < 920; x += 65) {
        canabaltBgTowers1.push({
            x: x,
            w: 48 + (x % 35),
            h: 110 + ((x * 37) % 150)
        });
    }
    for (let x = 0; x < 960; x += 85) {
        canabaltBgTowers2.push({
            x: x,
            w: 62 + (x % 40),
            h: 85 + ((x * 53) % 135)
        });
    }
}

function canabaltSpawnNextBuilding() {
    const last = canabaltBuildings[canabaltBuildings.length - 1];
    const speedFactor = Math.min(1.8, canabaltRunner.vx / 7.5);
    const gap = Math.floor(68 + Math.random() * (62 + speedFactor * 42));
    const nextX = last ? (last.x + last.w + gap) : 0;

    // Dachhöhe im gut erreichbaren Bereich variieren
    const prevY = last ? last.y : 255;
    let nextY = prevY + Math.floor((Math.random() - 0.46) * 72);
    nextY = Math.max(155, Math.min(315, nextY));

    const nextW = Math.floor(230 + Math.random() * 270);

    // Gebäudetyp bestimmen
    let type = 'normal';
    const roll = Math.random();
    if (canabaltBuildings.length > 1) {
        if (roll < 0.22) {
            type = 'hallway'; // Büro-Etage mit Glasfenstern (Decke darüber!)
            nextY = Math.max(195, Math.min(295, prevY + Math.floor((Math.random() - 0.35) * 40)));
        } else if (roll < 0.42) {
            type = 'collapsing'; // Einstürzender Wolkenkratzer
        } else if (roll < 0.54) {
            type = 'crane'; // Schmaler Kran-Stahlträger
        }
    }

    const crates = [];
    const pigeons = [];
    let bomb = null;

    if (type !== 'crane') {
        const numCrates = Math.floor(1 + Math.random() * 3);
        for (let i = 0; i < numCrates; i++) {
            const cx = 55 + Math.floor((nextW - 110) * ((i + 0.5) / numCrates) + (Math.random() - 0.5) * 30);
            crates.push({
                relX: cx,
                w: 16,
                h: 16,
                hit: false
            });
        }
    }

    // Taubenschwarm auf manchen Dächern
    if (type !== 'hallway' && Math.random() < 0.65) {
        const pCount = 4 + Math.floor(Math.random() * 6);
        const startRel = 35 + Math.random() * 80;
        for (let i = 0; i < pCount; i++) {
            pigeons.push({
                relX: startRel + i * 11 + Math.random() * 5,
                scared: false
            });
        }
    }

    // Fallender Sperrblock aus dem Himmel (auf normalen Dächern ab 250m)
    if (type === 'normal' && canabaltDistPx > 2500 && nextW > 280 && Math.random() < 0.35) {
        bomb = {
            relX: Math.floor(nextW * (0.45 + Math.random() * 0.3)),
            y: -140,
            vy: 0,
            w: 26,
            h: 26,
            landed: false,
            triggered: false
        };
    }

    canabaltBuildings.push({
        x: nextX,
        y: nextY,
        w: nextW,
        type: type,
        ceilingH: 64, // Freie Höhe im Büro-Korridor
        collapsing: false,
        sinkVy: 0,
        crates: crates,
        pigeons: pigeons,
        bomb: bomb,
        enteredLeftGlass: false,
        exitedRightGlass: false
    });
}

function canabaltSpawnGlassShards(x, y) {
    for (let i = 0; i < 18; i++) {
        canabaltShards.push({
            x: x,
            y: y + (Math.random() - 0.5) * 44,
            vx: 2 + Math.random() * 6,
            vy: (Math.random() - 0.6) * 5,
            size: 2 + Math.random() * 3.5,
            color: i % 2 === 0 ? '#e0f2fe' : '#bae6fd',
            life: 24 + Math.floor(Math.random() * 16)
        });
    }
}

function canabaltInit() {
    if (canabaltInitialized) return;
    canabaltInitialized = true;

    window.addEventListener('keydown', (e) => {
        if (activeGameKey !== 'canabalt') return;
        if ([' ', 'ArrowUp', 'w', 'W', 'x', 'X'].includes(e.key)) {
            e.preventDefault();
            if (!e.repeat) {
                canabaltPressJump(true);
            }
        }
    });

    window.addEventListener('keyup', (e) => {
        if (activeGameKey !== 'canabalt') return;
        if ([' ', 'ArrowUp', 'w', 'W', 'x', 'X'].includes(e.key)) {
            canabaltPressJump(false);
        }
    });

    canabaltBuildParallax();
    canabaltResetState();
    canabaltBannerText = '🏃‍♂️ CLOUD-CANABALT BEREIT!';
    canabaltDraw();
}

function canabaltResetState() {
    canabaltTick        = 0;
    canabaltDistPx      = 0;
    canabaltMaxKmh      = 0;
    canabaltShake       = 0;
    canabaltJumpHeld    = false;
    canabaltDeathReason = '';
    canabaltPigeons     = [];
    canabaltShards      = [];
    canabaltBuildings   = [];

    // Erstes Start-Dach lang & sicher
    canabaltBuildings.push({
        x: 0,
        y: 255,
        w: 440,
        type: 'normal',
        ceilingH: 64,
        collapsing: false,
        sinkVy: 0,
        crates: [{ relX: 280, w: 16, h: 16, hit: false }],
        pigeons: [
            { relX: 190, scared: false },
            { relX: 202, scared: false },
            { relX: 215, scared: false },
            { relX: 226, scared: false }
        ],
        bomb: null,
        enteredLeftGlass: false,
        exitedRightGlass: false
    });

    for (let i = 0; i < 4; i++) {
        canabaltSpawnNextBuilding();
    }

    canabaltRunner = {
        x: 135,
        y: 255,
        vx: 6.2,
        vy: 0,
        w: 16,
        h: 28,
        onGround: true,
        jumpHoldFrames: 0,
        stumbleFrames: 0,
        animPhase: 0
    };
    canabaltUpdateHud();
}

function canabaltStartGame() {
    if (canabaltRafId) cancelAnimationFrame(canabaltRafId);
    canabaltResetState();
    canabaltStartAt    = Date.now();
    canabaltBannerText = '';
    canabaltRunning    = true;
    canabaltRafId      = requestAnimationFrame(canabaltLoop);
}

function canabaltPressJump(pressed) {
    if (pressed && !canabaltRunning) {
        canabaltStartGame();
        return;
    }
    canabaltJumpHeld = pressed;
    if (pressed && canabaltRunner.onGround) {
        canabaltRunner.vy = -7.8;
        canabaltRunner.onGround = false;
        canabaltRunner.jumpHoldFrames = 15;
    }
    if (!pressed) {
        canabaltRunner.jumpHoldFrames = 0;
    }
}

function canabaltUpdateHud() {
    const meters = Math.floor(canabaltDistPx / 10);
    const kmh    = Math.round(canabaltRunner.vx * 5.2);
    if (kmh > canabaltMaxKmh) canabaltMaxKmh = kmh;

    const dEl = document.getElementById('canabaltDist');
    const sEl = document.getElementById('canabaltSpeed');
    const mEl = document.getElementById('canabaltMaxSpeed');
    const stEl = document.getElementById('canabaltStatus');
    if (dEl) dEl.textContent = meters.toLocaleString('de-DE') + ' m';
    if (sEl) sEl.textContent = kmh + ' km/h';
    if (mEl) mEl.textContent = canabaltMaxKmh + ' km/h';
    if (stEl) {
        if (!canabaltRunning && canabaltDeathReason) {
            stEl.textContent = canabaltDeathReason;
            stEl.className = 'text-rose-600 dark:text-rose-400 font-extrabold truncate';
        } else if (canabaltRunner.stumbleFrames > 0) {
            stEl.textContent = '⚠️ Gestolpert!';
            stEl.className = 'text-amber-600 dark:text-amber-400 font-extrabold truncate';
        } else {
            stEl.textContent = '🏃‍♂️ Im Sprint';
            stEl.className = 'text-emerald-600 dark:text-emerald-400 font-extrabold truncate';
        }
    }
}

function canabaltTriggerGameOver(reason) {
    canabaltRunning     = false;
    canabaltDeathReason = reason;
    canabaltBannerText  = '💥 ' + reason.toUpperCase();
    canabaltUpdateHud();
    const meters = Math.floor(canabaltDistPx / 10);
    const durSec = Math.max(1, Math.round((Date.now() - canabaltStartAt) / 1000));
    submitCloudScore('canabalt', meters, durSec, meters + ' m (' + canabaltMaxKmh + ' km/h)');
}

function canabaltStep() {
    canabaltTick++;
    if (canabaltShake > 0) canabaltShake *= 0.88;

    // 1. Horizontale Beschleunigung (je weiter man läuft, desto höher das Spitzentempo)
    const maxVx = Math.min(14.2, 9.8 + (canabaltDistPx / 8500));
    if (canabaltRunner.onGround) {
        canabaltRunner.vx = Math.min(maxVx, canabaltRunner.vx + 0.032);
    } else {
        canabaltRunner.vx = Math.min(maxVx, canabaltRunner.vx + 0.012);
    }

    if (canabaltRunner.stumbleFrames > 0) {
        canabaltRunner.stumbleFrames--;
    }

    const vx = canabaltRunner.vx;
    canabaltDistPx += vx;
    canabaltRunner.animPhase += vx * 0.085;

    // Parallax-Hintergrund verschieben
    for (const t of canabaltBgTowers1) {
        t.x -= vx * 0.18;
        if (t.x + t.w < 0) t.x += 920;
    }
    for (const t of canabaltBgTowers2) {
        t.x -= vx * 0.42;
        if (t.x + t.w < 0) t.x += 960;
    }

    // 2. Variable Sprunghöhe bei gedrückter Taste
    if (canabaltJumpHeld && canabaltRunner.jumpHoldFrames > 0) {
        canabaltRunner.vy -= 0.48;
        canabaltRunner.jumpHoldFrames--;
    }

    const prevFootY = canabaltRunner.y;
    canabaltRunner.vy = Math.min(13.5, canabaltRunner.vy + 0.56);
    canabaltRunner.y += canabaltRunner.vy;
    canabaltRunner.onGround = false;

    // 3. Gebäude nach links bewegen & Kollisionen prüfen
    for (const b of canabaltBuildings) {
        const prevBx = b.x;
        b.x -= vx;

        // Einstürzende Wolkenkratzer beginnen zu beben, sobald der Spieler sich nähert
        if (b.type === 'collapsing' && b.x < 440 && !b.collapsing) {
            b.collapsing = true;
        }
        if (b.collapsing) {
            b.sinkVy = Math.min(3.4, b.sinkVy + 0.045);
            b.y += b.sinkVy;
            canabaltShake = Math.max(canabaltShake, 2.8);
            if (canabaltTick % 3 === 0) {
                canabaltShards.push({
                    x: b.x + Math.random() * b.w,
                    y: 375,
                    vx: -vx * 0.4,
                    vy: -1 - Math.random() * 2,
                    size: 6 + Math.random() * 6,
                    color: 'rgba(148, 163, 184, 0.45)',
                    life: 22
                });
            }
        }

        // Tauben aufscheuchen, sobald der Läufer nah ist
        for (const p of b.pigeons) {
            const px = b.x + p.relX;
            if (!p.scared && px < canabaltRunner.x + 115) {
                p.scared = true;
                canabaltPigeons.push({
                    x: px,
                    y: b.y - 6,
                    vx: 1.5 + Math.random() * 3.5,
                    vy: -3.2 - Math.random() * 3.0,
                    wing: Math.random() * 6
                });
            }
        }

        // Fallender Sperrblock (Bombe) auf dem Dach
        if (b.bomb) {
            const bx = b.x + b.bomb.relX;
            if (!b.bomb.triggered && bx < 560) {
                b.bomb.triggered = true;
            }
            if (b.bomb.triggered && !b.bomb.landed) {
                b.bomb.vy += 0.65;
                b.bomb.y += b.bomb.vy;
                if (b.bomb.y + b.bomb.h >= b.y) {
                    b.bomb.y = b.y - b.bomb.h;
                    b.bomb.landed = true;
                    canabaltShake = 7.5;
                    canabaltSpawnGlassShards(bx, b.y - 8);
                }
            }
            // Kollision mit Sperrblock -> tödlich!
            if (Math.abs(canabaltRunner.x - (bx + b.bomb.w / 2)) < (canabaltRunner.w / 2 + b.bomb.w / 2 - 3) &&
                canabaltRunner.y > b.bomb.y + 4 &&
                canabaltRunner.y - canabaltRunner.h < b.bomb.y + b.bomb.h) {
                canabaltTriggerGameOver('Gegen Einschlag-Sperrblock geprallt!');
                return;
            }
        }

        // Prüfen, ob Läufer horizontal im Bereich dieses Gebäudes ist
        const rx = canabaltRunner.x;
        if (rx + 7 >= b.x && rx - 7 <= b.x + b.w) {
            // a) Stirnwand-Aufprall links an der Gebäudekante?
            if (prevBx > rx + 5) {
                // Bei Büro-Etagen ('hallway') ist über dem Korridor ebenfalls Betonwand!
                if (b.type === 'hallway' && canabaltRunner.y - canabaltRunner.h < b.y - b.ceilingH) {
                    canabaltShake = 8;
                    canabaltTriggerGameOver('Über dem Bürofenster gegen die Fassade geprallt!');
                    return;
                }
                if (prevFootY > b.y + 10) {
                    canabaltShake = 8;
                    canabaltTriggerGameOver('Gegen die Hochhauswand geprallt!');
                    return;
                }
            }

            // b) Glasfenster-Durchbruch bei Büro-Etagen ('hallway')
            if (b.type === 'hallway') {
                if (!b.enteredLeftGlass && rx >= b.x) {
                    b.enteredLeftGlass = true;
                    canabaltSpawnGlassShards(b.x, b.y - 24);
                }
                if (!b.exitedRightGlass && rx >= b.x + b.w - 10) {
                    b.exitedRightGlass = true;
                    canabaltSpawnGlassShards(b.x + b.w, b.y - 24);
                }
                // Decken-Kollision im Büro-Korridor
                const ceilY = b.y - b.ceilingH;
                if (canabaltRunner.y - canabaltRunner.h < ceilY && canabaltRunner.vy < 0) {
                    canabaltRunner.y = ceilY + canabaltRunner.h;
                    canabaltRunner.vy = 1.2;
                }
            }

            // c) Landung auf dem Dach / Büro-Boden
            if (canabaltRunner.vy >= 0 && prevFootY <= b.y + 12 && canabaltRunner.y >= b.y) {
                canabaltRunner.y = b.y;
                canabaltRunner.vy = b.collapsing ? b.sinkVy : 0;
                canabaltRunner.onGround = true;
            }

            // d) Kisten auf dem Dach (bremsen das Tempo beim Umwerfen!)
            for (const c of b.crates) {
                if (c.hit) continue;
                const cx = b.x + c.relX;
                if (Math.abs(rx - (cx + c.w / 2)) < 13 && canabaltRunner.y > b.y - c.h + 2) {
                    c.hit = true;
                    canabaltRunner.vx = Math.max(4.4, canabaltRunner.vx * 0.72);
                    canabaltRunner.stumbleFrames = 18;
                    for (let k = 0; k < 7; k++) {
                        canabaltShards.push({
                            x: cx,
                            y: b.y - 8,
                            vx: 2 + Math.random() * 4,
                            vy: -2 - Math.random() * 3,
                            size: 4,
                            color: '#d97706',
                            life: 20
                        });
                    }
                }
            }
        }
    }

    // 4. Alte Gebäude entfernen & neue generieren
    if (canabaltBuildings.length > 0 && canabaltBuildings[0].x + canabaltBuildings[0].w < -80) {
        canabaltBuildings.shift();
        canabaltSpawnNextBuilding();
    }

    // 5. In die Häuserschlucht gestürzt?
    if (canabaltRunner.y > 395) {
        canabaltTriggerGameOver('In die Häuserschlucht gestürzt!');
        return;
    }

    canabaltUpdateHud();
}

function canabaltDraw() {
    const cvs = document.getElementById('canabaltCanvas');
    if (!cvs) return;
    const ctx = cvs.getContext('2d');

    ctx.save();
    if (canabaltShake > 0.4) {
        ctx.translate((Math.random() - 0.5) * canabaltShake, (Math.random() - 0.5) * canabaltShake);
    }

    // 1. Dramatischer Grau-Violetter Großstadt-Himmel (Canabalt-Stil)
    const skyGrad = ctx.createLinearGradient(0, 0, 0, cvs.height);
    skyGrad.addColorStop(0, '#334155');
    skyGrad.addColorStop(0.55, '#64748b');
    skyGrad.addColorStop(1, '#cbd5e1');
    ctx.fillStyle = skyGrad;
    ctx.fillRect(0, 0, cvs.width, cvs.height);

    // 2. Parallax-Skyline Ebene 1 (ferne Silhouette)
    ctx.fillStyle = '#475569';
    for (const t of canabaltBgTowers1) {
        ctx.fillRect(t.x, cvs.height - t.h, t.w, t.h);
    }

    // 3. Parallax-Skyline Ebene 2 (mittlere Hochhäuser)
    ctx.fillStyle = '#1e293b';
    for (const t of canabaltBgTowers2) {
        ctx.fillRect(t.x, cvs.height - t.h, t.w, t.h);
    }

    // 4. Vordergrund-Gebäude, Kran-Träger & Büro-Etagen zeichnen
    for (const b of canabaltBuildings) {
        if (b.type === 'crane') {
            // Rot-dunkler Stahlträger-Kran
            ctx.fillStyle = '#0f172a';
            ctx.fillRect(b.x, b.y, b.w, 18);
            ctx.strokeStyle = '#334155';
            ctx.lineWidth = 2;
            for (let gx = b.x; gx < b.x + b.w - 16; gx += 18) {
                ctx.beginPath();
                ctx.moveTo(gx, b.y);
                ctx.lineTo(gx + 18, b.y + 18);
                ctx.lineTo(gx + 18, b.y);
                ctx.stroke();
            }
            // Kran-Stützpfeiler in der Mitte
            ctx.fillStyle = '#0f172a';
            ctx.fillRect(b.x + b.w / 2 - 14, b.y + 18, 28, cvs.height - b.y);
        } else {
            // Hauptgebäude unter dem Dach / Boden
            ctx.fillStyle = '#090d16';
            ctx.fillRect(b.x, b.y, b.w, cvs.height - b.y + 20);

            // Dachkante
            ctx.fillStyle = b.type === 'collapsing' ? '#dc2626' : '#94a3b8';
            ctx.fillRect(b.x, b.y, b.w, 4);

            // Beleuchtete Fassadenfenster unterhalb der Lauffläche
            ctx.fillStyle = '#334155';
            for (let wy = b.y + 18; wy < cvs.height - 10; wy += 22) {
                for (let wx = b.x + 14; wx < b.x + b.w - 18; wx += 20) {
                    ctx.fillRect(wx, wy, 10, 12);
                }
            }

            // Falls Büro-Etage ('hallway'): obere Decke + Glasfenster links & rechts!
            if (b.type === 'hallway') {
                const ceilY = b.y - b.ceilingH;
                // Hellerer Büro-Hintergrund im Korridor
                ctx.fillStyle = '#1e293b';
                ctx.fillRect(b.x, ceilY, b.w, b.ceilingH);
                // Deckenlampen im Büro
                ctx.fillStyle = '#fef08a';
                for (let lx = b.x + 35; lx < b.x + b.w - 30; lx += 65) {
                    ctx.fillRect(lx, ceilY + 2, 24, 3);
                }
                // Obere Beton-Etagen über dem Büro-Korridor
                ctx.fillStyle = '#090d16';
                ctx.fillRect(b.x, 0, b.w, ceilY);
                ctx.fillStyle = '#64748b';
                ctx.fillRect(b.x, ceilY - 3, b.w, 4);

                // Glasfenster-Scheiben links & rechts (sofern noch nicht durchschlagen)
                ctx.fillStyle = 'rgba(125, 211, 252, 0.55)';
                if (!b.enteredLeftGlass) {
                    ctx.fillRect(b.x, ceilY, 5, b.ceilingH);
                }
                if (!b.exitedRightGlass) {
                    ctx.fillRect(b.x + b.w - 5, ceilY, 5, b.ceilingH);
                }
            }
        }

        // Kisten / Lüftungsblöcke auf dem Dach
        for (const c of b.crates) {
            if (c.hit) continue;
            ctx.fillStyle = '#d97706';
            ctx.fillRect(b.x + c.relX, b.y - c.h, c.w, c.h);
            ctx.strokeStyle = '#fef3c7';
            ctx.lineWidth = 1;
            ctx.strokeRect(b.x + c.relX + 1, b.y - c.h + 1, c.w - 2, c.h - 2);
        }

        // Sitzende Tauben auf dem Dach
        ctx.fillStyle = '#f8fafc';
        for (const p of b.pigeons) {
            if (p.scared) continue;
            ctx.fillRect(b.x + p.relX, b.y - 5, 5, 5);
        }

        // Fallender Sperrblock (Bombe) + rote Ziel-Warnlinie
        if (b.bomb) {
            const bx = b.x + b.bomb.relX;
            if (!b.bomb.landed) {
                ctx.strokeStyle = 'rgba(244, 63, 94, 0.55)';
                ctx.setLineDash([4, 4]);
                ctx.beginPath();
                ctx.moveTo(bx + b.bomb.w / 2, 0);
                ctx.lineTo(bx + b.bomb.w / 2, b.y);
                ctx.stroke();
                ctx.setLineDash([]);
            }
            ctx.fillStyle = '#e11d48';
            ctx.fillRect(bx, b.bomb.y, b.bomb.w, b.bomb.h);
            ctx.fillStyle = '#fecdd3';
            ctx.font = 'bold 11px monospace';
            ctx.textAlign = 'center';
            ctx.fillText('⚠️', bx + b.bomb.w / 2, b.bomb.y + 17);
        }
    }

    // 5. Auffliegende Taubenschwärme zeichnen
    ctx.fillStyle = '#ffffff';
    for (let i = canabaltPigeons.length - 1; i >= 0; i--) {
        const p = canabaltPigeons[i];
        p.x += p.vx - canabaltRunner.vx * 0.35;
        p.y += p.vy;
        p.wing += 0.45;
        const wingY = Math.sin(p.wing) * 4;
        ctx.beginPath();
        ctx.moveTo(p.x, p.y);
        ctx.lineTo(p.x - 5, p.y - wingY);
        ctx.lineTo(p.x + 5, p.y - wingY);
        ctx.closePath();
        ctx.fill();
        if (p.y < -20 || p.x < -30 || p.x > cvs.width + 30) {
            canabaltPigeons.splice(i, 1);
        }
    }

    // 6. Glassplitter, Kistentrümmer & Staubwolken
    for (let i = canabaltShards.length - 1; i >= 0; i--) {
        const s = canabaltShards[i];
        ctx.fillStyle = s.color;
        ctx.fillRect(s.x, s.y, s.size, s.size);
        s.x += s.vx - canabaltRunner.vx * 0.5;
        s.y += s.vy;
        s.vy += 0.22;
        s.life--;
        if (s.life <= 0) canabaltShards.splice(i, 1);
    }

    // 7. Den sprintenden Agenten im Anzug mit flatternder roter Krawatte zeichnen!
    const rx = canabaltRunner.x;
    const ry = canabaltRunner.y;
    ctx.save();
    ctx.translate(rx, ry - 14);

    // Rote flatternde Krawatte / Schal im Fahrtwind
    ctx.strokeStyle = '#ef4444';
    ctx.lineWidth = 2.5;
    ctx.beginPath();
    ctx.moveTo(-2, -6);
    ctx.lineTo(-14 - Math.sin(canabaltRunner.animPhase) * 4, -4 + Math.cos(canabaltRunner.animPhase) * 3);
    ctx.stroke();

    // Kopf + weißes Hemd + dunkler Anzug
    ctx.fillStyle = '#f8fafc';
    ctx.beginPath();
    ctx.arc(2, -11, 4.5, 0, Math.PI * 2);
    ctx.fill();

    ctx.fillStyle = '#0f172a';
    ctx.fillRect(-4, -6, 9, 11);

    // Animierte Sprint-Beine
    const stride = canabaltRunner.onGround ? Math.sin(canabaltRunner.animPhase) * 8 : 6;
    ctx.strokeStyle = '#f8fafc';
    ctx.lineWidth = 3;
    ctx.beginPath();
    ctx.moveTo(0, 4);
    ctx.lineTo(stride, 14);
    ctx.moveTo(0, 4);
    ctx.lineTo(-stride, 14);
    ctx.stroke();
    ctx.restore();

    // 8. Live-Distanz-Anzeige oben rechts im Canvas
    const meters = Math.floor(canabaltDistPx / 10);
    ctx.fillStyle = 'rgba(15, 23, 42, 0.72)';
    ctx.fillRect(cvs.width - 148, 12, 136, 32);
    ctx.fillStyle = '#f8fafc';
    ctx.font = 'bold 16px monospace';
    ctx.textAlign = 'right';
    ctx.fillText(meters.toLocaleString('de-DE') + ' m', cvs.width - 22, 33);

    // 9. Start- / Game-Over-Banner
    if (!canabaltRunning) {
        ctx.fillStyle = 'rgba(2, 6, 23, 0.80)';
        ctx.fillRect(0, cvs.height / 2 - 48, cvs.width, 96);
        ctx.textAlign = 'center';
        ctx.fillStyle = '#f8fafc';
        ctx.font = 'bold 21px monospace';
        ctx.fillText(canabaltBannerText || '🏃‍♂️ CLOUD-CANABALT', cvs.width / 2, cvs.height / 2 - 10);
        ctx.fillStyle = '#cbd5e1';
        ctx.font = '12px sans-serif';
        ctx.fillText('Ein-Tasten-Flucht: LEERTASTE oder Klick aufs Spielfeld (kurz = flach, halten = weit)', cvs.width / 2, cvs.height / 2 + 14);
        if (meters > 0) {
            ctx.fillStyle = '#38bdf8';
            ctx.fillText('Erreichte Distanz: ' + meters.toLocaleString('de-DE') + ' m  |  Spitzentempo: ' + canabaltMaxKmh + ' km/h', cvs.width / 2, cvs.height / 2 + 34);
        }
    }

    ctx.restore();
}

function canabaltLoop() {
    if (!canabaltRunning) {
        canabaltDraw();
        return;
    }
    canabaltStep();
    canabaltDraw();
    if (canabaltRunning) {
        canabaltRafId = requestAnimationFrame(canabaltLoop);
    }
}

// =============================================================================
// 15. THE LAST NINJA (1987 C64 ISOMETRISCHES ACTION-ADVENTURE)
// =============================================================================
let ninjaInitialized = false;
let ninjaRunning     = false;
let ninjaRafId       = null;
let ninjaStartAt     = 0;
let ninjaTick        = 0;
let ninjaScore       = 0;
let ninjaLives       = 3;
let ninjaStage       = 1;
let ninjaRoomIdx     = 0;
let ninjaBannerText  = '';
let ninjaStatusMsg   = 'ARMAKUNI BETRITT DIE INSEL LIN FEN...';
const ninjaKeys      = {};

const NINJA_WEAPONS = [
    { key: 'fists',    name: '👊 Fäuste & Kick', icon: '👊', range: 44, dmg: 14, ranged: false },
    { key: 'katana',   name: '⚔️ Ninjatō-Schwert', icon: '⚔️', range: 58, dmg: 28, ranged: false },
    { key: 'shuriken', name: '✴️ Shuriken-Sterne', icon: '✴️', range: 220, dmg: 20, ranged: true },
    { key: 'nunchaku', name: '⛓️ Nunchaku', icon: '⛓️', range: 50, dmg: 22, ranged: false },
    { key: 'bostaff',  name: '🦯 Bo-Kampfstab', icon: '🦯', range: 70, dmg: 25, ranged: false }
];

let ninjaPlayer = {
    s: 45,          // Fortschritt entlang des isometrischen Pfads (20 .. 460)
    l: 0,           // Quer-Versatz auf dem Pfad (-32 .. +32)
    z: 0,           // Sprunghöhe beim Vorwärtssalto
    vz: 0,
    leapVx: 0,
    facing: 1,      // +1 = vorwärts nach Nordosten, -1 = rückwärts nach Südwesten
    hp: 100,
    maxHp: 100,
    weaponIdx: 0,
    unlockedWeapons: ['fists'],
    items: [],      // ['amulet', 'key', 'scroll']
    attackTicks: 0,
    invulnTicks: 0,
    animWalk: 0
};

let ninjaRoomsState  = [];
let ninjaProjectiles = []; // Fliegende Shuriken { s, l, vs, dmg }
let ninjaPopups      = []; // { x, y, text, color, life }

function ninjaBuildRooms(stageNum) {
    const hpScale = 1 + (stageNum - 1) * 0.35;
    ninjaRoomsState = [
        {
            id: 1,
            title: '1/6 Tor der Einöde',
            subtitle: 'THE WASTELANDS GATE',
            skyColor: '#1e1b4b',
            groundColor: '#3f6212',
            pathColor: '#a8a29e',
            hasRiver: false,
            hasDragon: false,
            lockedGate: false,
            shrine: { s: 155, l: -22, itemKey: 'katana', icon: '⚔️', label: 'Ninjatō-Schwert', collected: false },
            enemies: [
                { name: 'Samurai-Torwächter', s: 330, l: 0, hp: Math.round(65 * hpScale), maxHp: Math.round(65 * hpScale), dmg: 10, color: '#dc2626', hatColor: '#facc15', attackCd: 0, alive: true, points: 300 }
            ]
        },
        {
            id: 2,
            title: '2/6 Der Gebirgsfluss',
            subtitle: 'THE STEPPING STONES',
            skyColor: '#0f172a',
            groundColor: '#365314',
            pathColor: '#78716c',
            hasRiver: true, // Fluss zwischen s=185 und s=305 mit 3 Trittsteinen bei s=210, 245, 280!
            hasDragon: false,
            lockedGate: false,
            shrine: { s: 115, l: 20, itemKey: 'shuriken', icon: '✴️', label: 'Shuriken-Wurfsterne', collected: false },
            enemies: [
                { name: 'Fluss-Bogenschütze', s: 390, l: 4, hp: Math.round(75 * hpScale), maxHp: Math.round(75 * hpScale), dmg: 12, color: '#2563eb', hatColor: '#93c5fd', attackCd: 0, alive: true, points: 400 }
            ]
        },
        {
            id: 3,
            title: '3/6 Der Bambushain',
            subtitle: 'THE BAMBOO GROVE',
            skyColor: '#022c22',
            groundColor: '#14532d',
            pathColor: '#a16207',
            hasRiver: false,
            hasDragon: false,
            lockedGate: false,
            shrine: { s: 230, l: -24, itemKey: 'amulet', icon: '💎', label: 'Feuer-Amulett & Nunchaku', collected: false },
            enemies: [
                { name: 'Roter Schatten-Ninja', s: 200, l: 8, hp: Math.round(70 * hpScale), maxHp: Math.round(70 * hpScale), dmg: 12, color: '#991b1b', hatColor: '#ef4444', attackCd: 0, alive: true, points: 450 },
                { name: 'Koga-Attentäter', s: 370, l: -6, hp: Math.round(85 * hpScale), maxHp: Math.round(85 * hpScale), dmg: 14, color: '#7f1d1d', hatColor: '#f87171', attackCd: 0, alive: true, points: 500 }
            ]
        },
        {
            id: 4,
            title: '4/6 Der Drachenschrein',
            subtitle: 'SHRINE OF THE FIRE DRAGON',
            skyColor: '#3b0764',
            groundColor: '#451a03',
            pathColor: '#d6d3d1',
            hasRiver: false,
            hasDragon: true, // Speit bei s=240 rhythmisch Feuer!
            lockedGate: false,
            shrine: { s: 345, l: -22, itemKey: 'key', icon: '🗝️', label: 'Goldener Palastschlüssel', collected: false },
            enemies: [
                { name: 'Tempel-Wächter', s: 395, l: 0, hp: Math.round(95 * hpScale), maxHp: Math.round(95 * hpScale), dmg: 15, color: '#d97706', hatColor: '#fef08a', attackCd: 0, alive: true, points: 550 }
            ]
        },
        {
            id: 5,
            title: '5/6 Der Palasthof',
            subtitle: 'THE INNER COURTYARD',
            skyColor: '#1e293b',
            groundColor: '#334155',
            pathColor: '#cbd5e1',
            hasRiver: false,
            hasDragon: false,
            lockedGate: true, // Tor bei s=425 erfordert den Palastschlüssel!
            shrine: { s: 145, l: 22, itemKey: 'bostaff', icon: '🦯', label: 'Bo-Kampfstab & Heiltrank', collected: false },
            enemies: [
                { name: 'Palast-Schwertmeister', s: 310, l: 0, hp: Math.round(115 * hpScale), maxHp: Math.round(115 * hpScale), dmg: 16, color: '#7c2d12', hatColor: '#fdba74', attackCd: 0, alive: true, points: 650 }
            ]
        },
        {
            id: 6,
            title: '6/6 Thronsaal Kunitoki',
            subtitle: 'SANCTUM OF SHOGUN KUNITOKI',
            skyColor: '#450a0a',
            groundColor: '#1c1917',
            pathColor: '#b91c1c',
            hasRiver: false,
            hasDragon: false,
            lockedGate: false,
            shrine: { s: 430, l: 0, itemKey: 'scroll', icon: '📜', label: 'Schriftrollen der Koga', collected: false },
            enemies: [
                { name: '👹 SHOGUN KUNITOKI', s: 335, l: 0, hp: Math.round(170 * hpScale), maxHp: Math.round(170 * hpScale), dmg: 20, color: '#fbbf24', hatColor: '#dc2626', attackCd: 0, alive: true, points: 1500, isBoss: true }
            ]
        }
    ];
}

// Rechnet Pfad-Koordinaten (s: 0..480 entlang des isometrischen Wegs, l: -35..+35 quer, z: Höhe) in Canvas-Pixel um
function ninjaIsoToScreen(s, l, z = 0) {
    // Isometrische Diagonale von unten-links (82, 286) nach oben-rechts (466, 86)
    const t = s / 480;
    const baseX = 76 + t * 392 + l * 0.72;
    const baseY = 282 - t * 192 + l * 0.48 - z;
    return { x: baseX, y: baseY };
}

function ninjaInit() {
    if (ninjaInitialized) return;
    ninjaInitialized = true;

    window.addEventListener('keydown', (e) => {
        if (activeGameKey !== 'lastninja') return;
        const k = e.key;
        if (['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', ' ', 'w', 'W', 'a', 'A', 's', 'S', 'd', 'D', 'e', 'E', 'Shift', 'f', 'F', 'Enter', 'q', 'Q', '1', '2', '3', '4', '5'].includes(k)) {
            if (['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', ' '].includes(k)) {
                e.preventDefault();
            }
            if (k === 'd' || k === 'D') ninjaKeys['ArrowRight'] = true;
            else if (k === 'a' || k === 'A') ninjaKeys['ArrowLeft'] = true;
            else if (k === 'w' || k === 'W') ninjaKeys['ArrowUp'] = true;
            else if (k === 's' || k === 'S') ninjaKeys['ArrowDown'] = true;
            else if (k === ' ') ninjaTriggerAttack();
            else if (k === 'e' || k === 'E' || k === 'Shift') ninjaTriggerLeap();
            else if (k === 'f' || k === 'F' || k === 'Enter') ninjaTriggerPickup();
            else if (k === 'q' || k === 'Q') ninjaCycleWeapon();
            else if (['1', '2', '3', '4', '5'].includes(k)) {
                const idx = parseInt(k, 10) - 1;
                if (NINJA_WEAPONS[idx] && ninjaPlayer.unlockedWeapons.includes(NINJA_WEAPONS[idx].key)) {
                    ninjaPlayer.weaponIdx = idx;
                    ninjaStatusMsg = 'WAFFE GEWÄHLT: ' + NINJA_WEAPONS[idx].name.toUpperCase();
                    ninjaUpdateHud();
                }
            } else {
                ninjaKeys[k] = true;
            }
        }
    });

    window.addEventListener('keyup', (e) => {
        const k = e.key;
        if (k === 'd' || k === 'D') ninjaKeys['ArrowRight'] = false;
        else if (k === 'a' || k === 'A') ninjaKeys['ArrowLeft'] = false;
        else if (k === 'w' || k === 'W') ninjaKeys['ArrowUp'] = false;
        else if (k === 's' || k === 'S') ninjaKeys['ArrowDown'] = false;
        else ninjaKeys[k] = false;
    });

    ninjaResetState();
    ninjaBannerText = '🥷 THE LAST NINJA (1987)';
    ninjaDraw();
}

function ninjaResetState() {
    ninjaTick        = 0;
    ninjaScore       = 0;
    ninjaLives       = 3;
    ninjaStage       = 1;
    ninjaRoomIdx     = 0;
    ninjaProjectiles = [];
    ninjaPopups      = [];
    ninjaPlayer = {
        s: 45,
        l: 0,
        z: 0,
        vz: 0,
        leapVx: 0,
        facing: 1,
        hp: 100,
        maxHp: 100,
        weaponIdx: 0,
        unlockedWeapons: ['fists'],
        items: [],
        attackTicks: 0,
        invulnTicks: 0,
        animWalk: 0
    };
    ninjaBuildRooms(1);
    ninjaStatusMsg = 'FINDE DAS NINJATO-SCHWERT AM SCHREIN (TASTE F)!';
    ninjaUpdateHud();
}

function ninjaStartGame() {
    if (ninjaRafId) cancelAnimationFrame(ninjaRafId);
    ninjaResetState();
    ninjaStartAt    = Date.now();
    ninjaBannerText = '';
    ninjaRunning    = true;
    ninjaRafId      = requestAnimationFrame(ninjaLoop);
}

function ninjaUpdateHud() {
    const scEl = document.getElementById('ninjaScore');
    const rmEl = document.getElementById('ninjaRoomName');
    const wpEl = document.getElementById('ninjaWeaponName');
    const lfEl = document.getElementById('ninjaLives');
    if (scEl) scEl.textContent = ninjaScore.toLocaleString('de-DE');
    if (rmEl && ninjaRoomsState[ninjaRoomIdx]) {
        rmEl.textContent = ninjaRoomsState[ninjaRoomIdx].title;
    }
    if (wpEl && NINJA_WEAPONS[ninjaPlayer.weaponIdx]) {
        wpEl.textContent = NINJA_WEAPONS[ninjaPlayer.weaponIdx].name;
    }
    if (lfEl) {
        lfEl.textContent = '🥷'.repeat(Math.max(0, ninjaLives)) || '💀';
    }
}

function ninjaCycleWeapon() {
    if (!ninjaRunning) return;
    for (let i = 1; i <= NINJA_WEAPONS.length; i++) {
        const nextIdx = (ninjaPlayer.weaponIdx + i) % NINJA_WEAPONS.length;
        if (ninjaPlayer.unlockedWeapons.includes(NINJA_WEAPONS[nextIdx].key)) {
            ninjaPlayer.weaponIdx = nextIdx;
            ninjaStatusMsg = 'WAFFE GEWECHSELT: ' + NINJA_WEAPONS[nextIdx].name.toUpperCase();
            ninjaUpdateHud();
            break;
        }
    }
}

function ninjaTriggerLeap() {
    if (!ninjaRunning) {
        ninjaStartGame();
        return;
    }
    if (ninjaPlayer.z > 0) return;
    ninjaPlayer.vz = 6.2;
    ninjaPlayer.leapVx = 3.2 * ninjaPlayer.facing;
    ninjaStatusMsg = 'ARMAKUNI SPRINGT IM SALTO!';
}

function ninjaTriggerPickup() {
    if (!ninjaRunning) {
        ninjaStartGame();
        return;
    }
    const room = ninjaRoomsState[ninjaRoomIdx];
    if (!room || !room.shrine || room.shrine.collected) {
        ninjaStatusMsg = 'HIER GIBT ES NICHTS ZUM AUFHEBEN.';
        return;
    }
    const sh = room.shrine;
    if (Math.abs(ninjaPlayer.s - sh.s) > 48) {
        ninjaStatusMsg = 'GEHE NÄHER AN DEN SCHREIN HERAN!';
        return;
    }

    // Im Thronsaal muss zuerst Shogun Kunitoki besiegt werden
    const aliveBoss = room.enemies.some((e) => e.alive && e.isBoss);
    if (sh.itemKey === 'scroll' && aliveBoss) {
        ninjaStatusMsg = 'BESIEGE ZUERST SHOGUN KUNITOKI!';
        return;
    }

    sh.collected = true;
    ninjaScore += 250;
    const pos = ninjaIsoToScreen(sh.s, sh.l, 24);
    ninjaPopups.push({ x: pos.x, y: pos.y, text: '+' + sh.label, color: '#fde047', life: 55 });

    if (sh.itemKey === 'katana') {
        ninjaPlayer.unlockedWeapons.push('katana');
        ninjaPlayer.weaponIdx = 1;
        ninjaStatusMsg = '⚔️ NINJATO-SCHWERT ERHALTEN! (+250 PKT.)';
    } else if (sh.itemKey === 'shuriken') {
        ninjaPlayer.unlockedWeapons.push('shuriken');
        ninjaPlayer.weaponIdx = 2;
        ninjaPlayer.hp = Math.min(ninjaPlayer.maxHp, ninjaPlayer.hp + 35);
        ninjaStatusMsg = '✴️ SHURIKEN-WURFSTERNE & HEILKRAUT ERHALTEN!';
    } else if (sh.itemKey === 'amulet') {
        ninjaPlayer.items.push('amulet');
        ninjaPlayer.unlockedWeapons.push('nunchaku');
        ninjaPlayer.weaponIdx = 3;
        ninjaStatusMsg = '💎 FEUER-AMULETT & ⛓️ NUNCHAKU ERHALTEN!';
    } else if (sh.itemKey === 'key') {
        ninjaPlayer.items.push('key');
        ninjaStatusMsg = '🗝️ GOLDENER PALASTSCHLÜSSEL ERHALTEN!';
    } else if (sh.itemKey === 'bostaff') {
        ninjaPlayer.unlockedWeapons.push('bostaff');
        ninjaPlayer.weaponIdx = 4;
        ninjaPlayer.hp = ninjaPlayer.maxHp;
        ninjaStatusMsg = '🦯 BO-KAMPFSTAB & VOLLE ENERGIE ERHALTEN!';
    } else if (sh.itemKey === 'scroll') {
        ninjaScore += 3000 * ninjaStage;
        ninjaStage++;
        const durSec = Math.max(1, Math.round((Date.now() - ninjaStartAt) / 1000));
        submitCloudScore('lastninja', ninjaScore, durSec, 'Stage ' + (ninjaStage - 1) + ' gemeistert');
        // Nächster Durchgang mit höherer Schwierigkeit für Highscore-Jäger
        ninjaRoomIdx = 0;
        ninjaPlayer.s = 45;
        ninjaPlayer.hp = ninjaPlayer.maxHp;
        ninjaPlayer.items = [];
        ninjaBuildRooms(ninjaStage);
        ninjaStatusMsg = '📜 KOGA-SCHRIFTROLLEN GERETTET! STAGE ' + ninjaStage + ' STARTET!';
    }
    ninjaUpdateHud();
}

function ninjaTriggerAttack() {
    if (!ninjaRunning) {
        ninjaStartGame();
        return;
    }
    if (ninjaPlayer.attackTicks > 0) return;
    ninjaPlayer.attackTicks = 16;

    const wp = NINJA_WEAPONS[ninjaPlayer.weaponIdx] || NINJA_WEAPONS[0];
    const room = ninjaRoomsState[ninjaRoomIdx];
    if (!room) return;

    if (wp.ranged) {
        // Shuriken abfeuern
        if (ninjaProjectiles.length < 3) {
            ninjaProjectiles.push({
                s: ninjaPlayer.s + ninjaPlayer.facing * 14,
                l: ninjaPlayer.l,
                vs: ninjaPlayer.facing * 6.5,
                dmg: wp.dmg
            });
        }
        return;
    }

    // Nahkampf-Treffer prüfen
    for (const en of room.enemies) {
        if (!en.alive) continue;
        const distS = (en.s - ninjaPlayer.s) * ninjaPlayer.facing;
        const distL = Math.abs(en.l - ninjaPlayer.l);
        if (distS >= -10 && distS <= wp.range && distL <= 28) {
            en.hp -= wp.dmg;
            en.s = Math.min(445, Math.max(40, en.s + ninjaPlayer.facing * 12));
            const p = ninjaIsoToScreen(en.s, en.l, 26);
            ninjaPopups.push({ x: p.x, y: p.y, text: '-' + wp.dmg, color: '#f87171', life: 28 });
            if (en.hp <= 0) {
                en.alive = false;
                en.hp = 0;
                ninjaScore += en.points;
                ninjaPopups.push({ x: p.x, y: p.y - 12, text: '⚔️ +' + en.points + ' PKT!', color: '#fde047', life: 45 });
                ninjaStatusMsg = en.name.toUpperCase() + ' BESIEGT!';
                ninjaUpdateHud();
            }
        }
    }
}

function ninjaLoseLife(reason) {
    ninjaLives--;
    ninjaStatusMsg = reason.toUpperCase();
    ninjaUpdateHud();
    if (ninjaLives <= 0) {
        ninjaRunning = false;
        ninjaBannerText = '💀 ARMAKUNI IST GEFALLEN!';
        const durSec = Math.max(1, Math.round((Date.now() - ninjaStartAt) / 1000));
        submitCloudScore('lastninja', ninjaScore, durSec, 'Raum ' + (ninjaRoomIdx + 1) + '/6');
    } else {
        ninjaPlayer.s = 45;
        ninjaPlayer.l = 0;
        ninjaPlayer.z = 0;
        ninjaPlayer.vz = 0;
        ninjaPlayer.hp = ninjaPlayer.maxHp;
        ninjaPlayer.invulnTicks = 65;
    }
}

function ninjaStep() {
    ninjaTick++;
    if (ninjaPlayer.attackTicks > 0) ninjaPlayer.attackTicks--;
    if (ninjaPlayer.invulnTicks > 0) ninjaPlayer.invulnTicks--;

    const room = ninjaRoomsState[ninjaRoomIdx];
    if (!room) return;

    // 1. Bewegung entlang des isometrischen Pfads (s) & quer (l)
    if (ninjaPlayer.z > 0 || ninjaPlayer.vz !== 0) {
        // Während des Salto-Sprungs
        ninjaPlayer.s += ninjaPlayer.leapVx;
        ninjaPlayer.z += ninjaPlayer.vz;
        ninjaPlayer.vz -= 0.42;
        if (ninjaPlayer.z <= 0) {
            ninjaPlayer.z = 0;
            ninjaPlayer.vz = 0;
            ninjaPlayer.leapVx = 0;
        }
    } else {
        let moved = false;
        if (ninjaKeys['ArrowRight']) {
            ninjaPlayer.s += 2.3;
            ninjaPlayer.facing = 1;
            moved = true;
        }
        if (ninjaKeys['ArrowLeft']) {
            ninjaPlayer.s -= 2.3;
            ninjaPlayer.facing = -1;
            moved = true;
        }
        if (ninjaKeys['ArrowUp']) {
            ninjaPlayer.l = Math.max(-30, ninjaPlayer.l - 1.6);
            moved = true;
        }
        if (ninjaKeys['ArrowDown']) {
            ninjaPlayer.l = Math.min(30, ninjaPlayer.l + 1.6);
            moved = true;
        }
        if (moved) {
            ninjaPlayer.animWalk += 0.25;
        }
    }

    // 2. Raum-Übergänge („Flip-Screen“ wie beim C64-Original!)
    if (ninjaPlayer.s < 22) {
        if (ninjaRoomIdx > 0) {
            ninjaRoomIdx--;
            ninjaPlayer.s = 435;
            ninjaProjectiles = [];
            ninjaStatusMsg = 'ZURÜCK IN: ' + ninjaRoomsState[ninjaRoomIdx].title.toUpperCase();
            ninjaUpdateHud();
            return;
        } else {
            ninjaPlayer.s = 22;
        }
    }

    // Verschlossenes Palasttor in Raum 5 prüfen
    if (room.lockedGate && ninjaPlayer.s > 410) {
        if (ninjaPlayer.items.includes('key')) {
            room.lockedGate = false;
            ninjaScore += 400;
            ninjaStatusMsg = '🗝️ PALASTTOR MIT DEM GOLDENEN SCHLÜSSEL GEÖFFNET! (+400 PKT.)';
            ninjaUpdateHud();
        } else {
            ninjaPlayer.s = 410;
            ninjaStatusMsg = '🔒 DAS PALASTTOR IST VERSCHLOSSEN! HOLE DEN SCHLÜSSEL IN RAUM 4!';
        }
    }

    if (ninjaPlayer.s > 455) {
        if (ninjaRoomIdx < ninjaRoomsState.length - 1) {
            ninjaRoomIdx++;
            ninjaPlayer.s = 35;
            ninjaProjectiles = [];
            ninjaScore += 150;
            ninjaStatusMsg = 'BETRETE: ' + ninjaRoomsState[ninjaRoomIdx].title.toUpperCase();
            ninjaUpdateHud();
            return;
        } else {
            ninjaPlayer.s = 455;
        }
    }

    // 3. Gebirgsfluss & Trittsteine in Raum 2 prüfen (s = 190 .. 300)
    if (room.hasRiver && ninjaPlayer.z <= 1 && ninjaPlayer.s >= 192 && ninjaPlayer.s <= 298) {
        // 3 sichere Trittsteine bei s=212, s=245, s=278 (jeweils +-14 Toleranz)
        const onStone1 = Math.abs(ninjaPlayer.s - 212) <= 15 && Math.abs(ninjaPlayer.l) <= 18;
        const onStone2 = Math.abs(ninjaPlayer.s - 245) <= 15 && Math.abs(ninjaPlayer.l) <= 18;
        const onStone3 = Math.abs(ninjaPlayer.s - 278) <= 15 && Math.abs(ninjaPlayer.l) <= 18;
        if (!onStone1 && !onStone2 && !onStone3) {
            ninjaLoseLife('🌊 In den reißenden Fluss gestürzt! Nutze Taste E für Salto-Sprünge!');
            return;
        }
    }

    // 4. Feuerspeiende Drachenstatue in Raum 4 (s = 225 .. 265)
    if (room.hasDragon) {
        const fireActive = (ninjaTick % 110) < 48;
        if (fireActive && ninjaPlayer.z < 12 && Math.abs(ninjaPlayer.s - 245) < 22) {
            if (!ninjaPlayer.items.includes('amulet')) {
                if (ninjaPlayer.invulnTicks <= 0) {
                    ninjaPlayer.hp -= 35;
                    ninjaPlayer.invulnTicks = 30;
                    ninjaStatusMsg = '🔥 DRACHENFEUER! DAS AMULETT AUS RAUM 3 SCHÜTZT DICH!';
                    if (ninjaPlayer.hp <= 0) {
                        ninjaLoseLife('🔥 Vom Drachenfeuer verbrannt!');
                        return;
                    }
                }
            } else if (ninjaTick % 30 === 0) {
                ninjaStatusMsg = '💎 DEIN FEUER-AMULETT WEHRT DIE DRACHENFLAMMEN AB!';
            }
        }
    }

    // 5. Fliegende Shuriken bewegen
    for (let i = ninjaProjectiles.length - 1; i >= 0; i--) {
        const pr = ninjaProjectiles[i];
        pr.s += pr.vs;
        if (pr.s < 15 || pr.s > 465) {
            ninjaProjectiles.splice(i, 1);
            continue;
        }
        for (const en of room.enemies) {
            if (!en.alive) continue;
            if (Math.abs(pr.s - en.s) < 18 && Math.abs(pr.l - en.l) < 24) {
                en.hp -= pr.dmg;
                const p = ninjaIsoToScreen(en.s, en.l, 26);
                ninjaPopups.push({ x: p.x, y: p.y, text: '✴️ -' + pr.dmg, color: '#38bdf8', life: 28 });
                ninjaProjectiles.splice(i, 1);
                if (en.hp <= 0) {
                    en.alive = false;
                    en.hp = 0;
                    ninjaScore += en.points;
                    ninjaPopups.push({ x: p.x, y: p.y - 12, text: '⚔️ +' + en.points + ' PKT!', color: '#fde047', life: 45 });
                    ninjaStatusMsg = en.name.toUpperCase() + ' BESIEGT!';
                    ninjaUpdateHud();
                }
                break;
            }
        }
    }

    // 6. Gegner-KI (Wachen & Shogun Kunitoki)
    for (const en of room.enemies) {
        if (!en.alive) continue;
        if (en.attackCd > 0) en.attackCd--;

        const diffS = ninjaPlayer.s - en.s;
        const diffL = ninjaPlayer.l - en.l;

        // Wache bewegt sich auf Armakuni zu, sobald er in Reichweite ist
        if (Math.abs(diffS) > 30 && Math.abs(diffS) < 210) {
            const nextS = en.s + Math.sign(diffS) * (en.isBoss ? 1.35 : 1.05);
            // Wachen laufen nicht in den Fluss
            if (!room.hasRiver || nextS > 308 || nextS < 180) {
                en.s = nextS;
            }
        }
        if (Math.abs(diffL) > 4) {
            en.l += Math.sign(diffL) * 0.6;
        }

        // Gegner schlägt zu
        if (Math.abs(diffS) <= 36 && Math.abs(diffL) <= 22 && ninjaPlayer.z < 14 && en.attackCd <= 0) {
            en.attackCd = en.isBoss ? 36 : 48;
            if (ninjaPlayer.invulnTicks <= 0) {
                ninjaPlayer.hp = Math.max(0, ninjaPlayer.hp - en.dmg);
                ninjaPlayer.invulnTicks = 22;
                const p = ninjaIsoToScreen(ninjaPlayer.s, ninjaPlayer.l, 28);
                ninjaPopups.push({ x: p.x, y: p.y, text: '-' + en.dmg + ' HP', color: '#fb7185', life: 26 });
                if (ninjaPlayer.hp <= 0) {
                    ninjaLoseLife('⚔️ Im Duell gegen ' + en.name + ' besiegt!');
                    return;
                }
            }
        }
    }
}

function ninjaDrawCharacter(ctx, x, y, bodyColor, beltColor, facing, walkPhase, attackTicks, weaponKey, isBoss = false) {
    ctx.save();
    ctx.translate(x, y);
    if (isBoss) {
        ctx.scale(1.22, 1.22);
    }

    // Schatten auf dem Boden
    ctx.fillStyle = 'rgba(0, 0, 0, 0.45)';
    ctx.beginPath();
    ctx.ellipse(0, 0, 11, 5, 0, 0, Math.PI * 2);
    ctx.fill();

    // Beine in Bewegung
    const legSwing = Math.sin(walkPhase) * 5;
    ctx.strokeStyle = bodyColor;
    ctx.lineWidth = 4;
    ctx.beginPath();
    ctx.moveTo(-3, -10);
    ctx.lineTo(-4 - legSwing, 0);
    ctx.moveTo(3, -10);
    ctx.lineTo(4 + legSwing, 0);
    ctx.stroke();

    // Torso (Gi / Rüstung)
    ctx.fillStyle = bodyColor;
    ctx.fillRect(-7, -24, 14, 15);

    // Gürtel / Schärpe
    ctx.fillStyle = beltColor;
    ctx.fillRect(-7, -13, 14, 3);

    // Kopf mit Ninja-Maske & Stirnband
    ctx.fillStyle = bodyColor;
    ctx.beginPath();
    ctx.arc(0, -29, 6, 0, Math.PI * 2);
    ctx.fill();
    // Augen-Schlitz
    ctx.fillStyle = '#fde68a';
    ctx.fillRect(facing > 0 ? 0 : -5, -31, 5, 2.5);

    // Arm & Waffe
    const dirX = facing > 0 ? 1 : -1;
    const swing = attackTicks > 0 ? 10 : 0;
    ctx.strokeStyle = bodyColor;
    ctx.lineWidth = 3.5;
    ctx.beginPath();
    ctx.moveTo(0, -21);
    ctx.lineTo(dirX * (10 + swing * 0.4), -19 - swing * 0.3);
    ctx.stroke();

    if (weaponKey === 'katana' || isBoss) {
        ctx.strokeStyle = '#e2e8f0';
        ctx.lineWidth = 2.5;
        ctx.beginPath();
        ctx.moveTo(dirX * 10, -19);
        ctx.lineTo(dirX * (26 + swing), -29 + swing);
        ctx.stroke();
    } else if (weaponKey === 'bostaff') {
        ctx.strokeStyle = '#d97706';
        ctx.lineWidth = 3;
        ctx.beginPath();
        ctx.moveTo(dirX * -8, -10);
        ctx.lineTo(dirX * (26 + swing), -32 + swing);
        ctx.stroke();
    } else if (weaponKey === 'nunchaku') {
        ctx.strokeStyle = '#facc15';
        ctx.lineWidth = 2.5;
        ctx.beginPath();
        ctx.moveTo(dirX * 10, -19);
        ctx.lineTo(dirX * (20 + swing), -15);
        ctx.stroke();
    }

    ctx.restore();
}

function ninjaDraw() {
    const cvs = document.getElementById('ninjaCanvas');
    if (!cvs) return;
    const ctx = cvs.getContext('2d');

    const room = ninjaRoomsState[ninjaRoomIdx] || ninjaRoomsState[0];

    // =========================================================================
    // 1. ISOMETRISCHES 3D-SPIELFENSTER LINKS OBEN (550 x 330)
    // =========================================================================
    ctx.save();
    ctx.beginPath();
    ctx.rect(12, 12, 548, 326);
    ctx.clip();

    // Himmel + Grasboden des aktuellen Raums
    ctx.fillStyle = room.skyColor;
    ctx.fillRect(12, 12, 548, 125);
    ctx.fillStyle = room.groundColor;
    ctx.fillRect(12, 110, 548, 228);

    // Ferne japanische Berge & Mond im Hintergrund
    ctx.fillStyle = '#fef08a';
    ctx.beginPath();
    ctx.arc(105, 52, 20, 0, Math.PI * 2);
    ctx.fill();

    ctx.fillStyle = 'rgba(15, 23, 42, 0.65)';
    ctx.beginPath();
    ctx.moveTo(12, 120);
    ctx.lineTo(140, 55);
    ctx.lineTo(260, 120);
    ctx.lineTo(390, 48);
    ctx.lineTo(560, 120);
    ctx.closePath();
    ctx.fill();

    // Isometrische Straße / Steinpfad von Südwesten (s=0) nach Nordosten (s=480)
    const pSW1 = ninjaIsoToScreen(0, -38, 0);
    const pSW2 = ninjaIsoToScreen(0, 38, 0);
    const pNE2 = ninjaIsoToScreen(480, 38, 0);
    const pNE1 = ninjaIsoToScreen(480, -38, 0);

    ctx.fillStyle = room.pathColor;
    ctx.beginPath();
    ctx.moveTo(pSW1.x, pSW1.y);
    ctx.lineTo(pSW2.x, pSW2.y);
    ctx.lineTo(pNE2.x, pNE2.y);
    ctx.lineTo(pNE1.x, pNE1.y);
    ctx.closePath();
    ctx.fill();

    // Steinplatten-Fugen auf dem Pfad
    ctx.strokeStyle = 'rgba(0, 0, 0, 0.25)';
    ctx.lineWidth = 1.5;
    for (let s = 30; s < 460; s += 32) {
        const a = ninjaIsoToScreen(s, -36, 0);
        const b = ninjaIsoToScreen(s, 36, 0);
        ctx.beginPath();
        ctx.moveTo(a.x, a.y);
        ctx.lineTo(b.x, b.y);
        ctx.stroke();
    }

    // Falls Raum 2: Reißender Fluss quer über den Pfad + 3 Trittsteine!
    if (room.hasRiver) {
        const r1 = ninjaIsoToScreen(190, -140, 0);
        const r2 = ninjaIsoToScreen(190, 140, 0);
        const r3 = ninjaIsoToScreen(300, 140, 0);
        const r4 = ninjaIsoToScreen(300, -140, 0);
        ctx.fillStyle = '#0284c7';
        ctx.beginPath();
        ctx.moveTo(r1.x, r1.y);
        ctx.lineTo(r2.x, r2.y);
        ctx.lineTo(r3.x, r3.y);
        ctx.lineTo(r4.x, r4.y);
        ctx.closePath();
        ctx.fill();

        // 3 Trittsteine im Wasser
        for (const stoneS of [212, 245, 278]) {
            const st = ninjaIsoToScreen(stoneS, 0, 0);
            ctx.fillStyle = '#d6d3d1';
            ctx.beginPath();
            ctx.ellipse(st.x, st.y, 14, 8, 0, 0, Math.PI * 2);
            ctx.fill();
            ctx.strokeStyle = '#57534e';
            ctx.stroke();
        }
    }

    // Falls Raum 4: Goldene Drachenstatue + Feuerstrahl!
    if (room.hasDragon) {
        const dr = ninjaIsoToScreen(245, -48, 0);
        ctx.font = '28px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('🐉', dr.x, dr.y);
        if ((ninjaTick % 110) < 48) {
            const fEnd = ninjaIsoToScreen(245, 34, 0);
            const grad = ctx.createLinearGradient(dr.x, dr.y - 8, fEnd.x, fEnd.y);
            grad.addColorStop(0, '#fde047');
            grad.addColorStop(0.5, '#f97316');
            grad.addColorStop(1, 'rgba(239, 68, 68, 0.2)');
            ctx.strokeStyle = grad;
            ctx.lineWidth = 16;
            ctx.beginPath();
            ctx.moveTo(dr.x, dr.y - 8);
            ctx.lineTo(fEnd.x, fEnd.y);
            ctx.stroke();
        }
    }

    // Falls Raum 5: Verschlossenes Tor am oberen Ende des Pfads
    if (room.lockedGate) {
        const g1 = ninjaIsoToScreen(420, -38, 0);
        const g2 = ninjaIsoToScreen(420, 38, 0);
        ctx.strokeStyle = '#b45309';
        ctx.lineWidth = 8;
        ctx.beginPath();
        ctx.moveTo(g1.x, g1.y - 38);
        ctx.lineTo(g1.x, g1.y);
        ctx.lineTo(g2.x, g2.y);
        ctx.lineTo(g2.x, g2.y - 38);
        ctx.closePath();
        ctx.stroke();
        ctx.fillStyle = 'rgba(120, 53, 15, 0.78)';
        ctx.fill();
        ctx.font = '18px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('🔒', (g1.x + g2.x) / 2, (g1.y + g2.y) / 2 - 14);
    }

    // Schrein & blinkendes Item (wie beim C64-Original blinken aufhebbare Gegenstände!)
    if (room.shrine) {
        const shPos = ninjaIsoToScreen(room.shrine.s, room.shrine.l, 0);
        // Stein-Podest
        ctx.fillStyle = '#57534e';
        ctx.fillRect(shPos.x - 14, shPos.y - 12, 28, 12);
        ctx.fillStyle = '#a8a29e';
        ctx.fillRect(shPos.x - 16, shPos.y - 15, 32, 4);

        if (!room.shrine.collected) {
            if (ninjaTick % 24 < 18) {
                ctx.font = '20px sans-serif';
                ctx.textAlign = 'center';
                ctx.fillText(room.shrine.icon, shPos.x, shPos.y - 18);
            }
            if (Math.abs(ninjaPlayer.s - room.shrine.s) <= 48) {
                ctx.fillStyle = '#fde047';
                ctx.font = 'bold 10px monospace';
                ctx.textAlign = 'center';
                ctx.fillText('[F] ' + room.shrine.label, shPos.x, shPos.y - 36);
            }
        }
    }

    // Gegner zeichnen
    for (const en of room.enemies) {
        if (!en.alive) continue;
        const ep = ninjaIsoToScreen(en.s, en.l, 0);
        ninjaDrawCharacter(ctx, ep.x, ep.y, en.color, en.hatColor, en.s > ninjaPlayer.s ? -1 : 1, ninjaTick * 0.15, en.attackCd > 30 ? 8 : 0, 'katana', !!en.isBoss);
    }

    // Spieler Armakuni (schwarzer Ninja mit rotem Gürtel) zeichnen
    const np = ninjaIsoToScreen(ninjaPlayer.s, ninjaPlayer.l, ninjaPlayer.z);
    const curWeaponKey = (NINJA_WEAPONS[ninjaPlayer.weaponIdx] || NINJA_WEAPONS[0]).key;
    if (ninjaPlayer.invulnTicks % 4 < 2) {
        ninjaDrawCharacter(ctx, np.x, np.y, '#090d16', '#dc2626', ninjaPlayer.facing, ninjaPlayer.animWalk, ninjaPlayer.attackTicks, curWeaponKey, false);
    }

    // Fliegende Shuriken zeichnen
    for (const pr of ninjaProjectiles) {
        const pp = ninjaIsoToScreen(pr.s, pr.l, 16);
        ctx.font = '14px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('✴️', pp.x, pp.y);
    }

    // Schwebende Treffer-Popups
    for (let i = ninjaPopups.length - 1; i >= 0; i--) {
        const p = ninjaPopups[i];
        ctx.fillStyle = p.color;
        ctx.font = 'bold 12px monospace';
        ctx.textAlign = 'center';
        ctx.fillText(p.text, p.x, p.y);
        p.y -= 0.6;
        p.life--;
        if (p.life <= 0) ninjaPopups.splice(i, 1);
    }

    ctx.restore();

    // =========================================================================
    // 2. ORIGINALGETREUER C64-RAHMEN & STATUS-HUD (RECHTS & UNTEN)
    // =========================================================================
    ctx.strokeStyle = '#b45309';
    ctx.lineWidth = 3;
    ctx.strokeRect(12, 12, 548, 326);

    // Rechtes C64-Status-Panel (x=572 .. 748, y=12 .. 338)
    ctx.fillStyle = '#1c1917';
    ctx.fillRect(572, 12, 176, 326);
    ctx.strokeRect(572, 12, 176, 326);

    ctx.fillStyle = '#fbbf24';
    ctx.font = 'bold 11px monospace';
    ctx.textAlign = 'center';
    ctx.fillText('NINJA ENERGY', 660, 32);

    // Ninja-Energiebalken (10 Segmente)
    const nSegs = Math.ceil((ninjaPlayer.hp / ninjaPlayer.maxHp) * 10);
    for (let i = 0; i < 10; i++) {
        ctx.fillStyle = i < nSegs ? '#22c55e' : '#292524';
        ctx.fillRect(588 + i * 14, 40, 11, 14);
    }

    // Gegner-Energiebalken
    const activeEnemy = room.enemies.find((e) => e.alive);
    ctx.fillStyle = '#f87171';
    ctx.fillText(activeEnemy ? 'ENEMY ENERGY' : 'AREA CLEAR', 660, 76);
    const eSegs = activeEnemy ? Math.ceil((activeEnemy.hp / activeEnemy.maxHp) * 10) : 0;
    for (let i = 0; i < 10; i++) {
        ctx.fillStyle = i < eSegs ? '#ef4444' : '#292524';
        ctx.fillRect(588 + i * 14, 84, 11, 14);
    }

    // Aktive Waffe im Kasten
    ctx.fillStyle = '#fbbf24';
    ctx.fillText('WEAPON (Q)', 660, 124);
    ctx.fillStyle = '#0c0a09';
    ctx.fillRect(590, 132, 140, 62);
    ctx.strokeStyle = '#78350f';
    ctx.strokeRect(590, 132, 140, 62);
    const curWp = NINJA_WEAPONS[ninjaPlayer.weaponIdx] || NINJA_WEAPONS[0];
    ctx.font = '26px sans-serif';
    ctx.fillText(curWp.icon, 660, 165);
    ctx.fillStyle = '#fde68a';
    ctx.font = 'bold 10px monospace';
    ctx.fillText(curWp.name, 660, 186);

    // Gesammelte Artefakte / Items im Kasten
    ctx.fillStyle = '#fbbf24';
    ctx.font = 'bold 11px monospace';
    ctx.fillText('INVENTORY', 660, 218);
    ctx.fillStyle = '#0c0a09';
    ctx.fillRect(590, 226, 140, 54);
    ctx.strokeRect(590, 226, 140, 54);

    const invIcons = [];
    if (ninjaPlayer.unlockedWeapons.includes('katana')) invIcons.push('⚔️');
    if (ninjaPlayer.unlockedWeapons.includes('shuriken')) invIcons.push('✴️');
    if (ninjaPlayer.items.includes('amulet')) invIcons.push('💎');
    if (ninjaPlayer.items.includes('key')) invIcons.push('🗝️');
    if (ninjaPlayer.unlockedWeapons.includes('bostaff')) invIcons.push('🦯');
    ctx.font = '18px sans-serif';
    ctx.fillStyle = '#f8fafc';
    ctx.fillText(invIcons.length > 0 ? invIcons.join(' ') : '—', 660, 259);

    // Raum-Kompass & Stage
    ctx.fillStyle = '#a8a29e';
    ctx.font = 'bold 11px monospace';
    ctx.fillText('STAGE ' + ninjaStage + '  |  ROOM ' + (ninjaRoomIdx + 1) + '/6', 660, 312);

    // =========================================================================
    // 3. UNTERES C64-NACHRICHTEN- & HINWEIS-BANNER (y=350 .. 428)
    // =========================================================================
    ctx.fillStyle = '#1c1917';
    ctx.fillRect(12, 350, 736, 78);
    ctx.strokeStyle = '#b45309';
    ctx.strokeRect(12, 350, 736, 78);

    ctx.fillStyle = '#fbbf24';
    ctx.font = 'bold 13px monospace';
    ctx.textAlign = 'left';
    ctx.fillText('⛩️ ' + room.subtitle + ' — ' + ninjaStatusMsg, 26, 376);

    ctx.fillStyle = '#d6d3d1';
    ctx.font = '11px monospace';
    ctx.fillText('PFEILTASTEN/WASD: Pfad entlanglaufen  |  SPACE: Angriff  |  E: Salto-Sprung  |  F: Schrein aufnehmen  |  Q: Waffe', 26, 402);
    ctx.fillStyle = '#a8a29e';
    ctx.fillText('💡 Tipp: In Raum 2 mit Taste E über die 3 Fluss-Felsen springen! In Raum 4 schützt das 💎 Amulett vor dem Drachenfeuer.', 26, 419);

    // Start- / Game-Over-Overlay
    if (!ninjaRunning) {
        ctx.fillStyle = 'rgba(12, 10, 9, 0.82)';
        ctx.fillRect(12, 12, 548, 326);
        ctx.textAlign = 'center';
        ctx.fillStyle = '#fbbf24';
        ctx.font = 'bold 22px monospace';
        ctx.fillText(ninjaBannerText || '🥷 THE LAST NINJA (1987)', 286, 152);
        ctx.fillStyle = '#e7e5e4';
        ctx.font = '12px sans-serif';
        ctx.fillText('Finde Waffen & Artefakte in 6 isometrischen Räumen und besiege Shogun Kunitoki!', 286, 180);
        ctx.fillStyle = '#34d399';
        ctx.fillText('Klicke auf „Mission auf Lin Fen starten“ oder drücke LEERTASTE!', 286, 206);
    }
}

function ninjaLoop() {
    if (!ninjaRunning) {
        ninjaDraw();
        return;
    }
    ninjaStep();
    ninjaDraw();
    if (ninjaRunning) {
        ninjaRafId = requestAnimationFrame(ninjaLoop);
    }
}

// =============================================================================
// 16. CLOUD-LEMMINGS (1991 ZERSTÖRBARES PIXEL-GELÄNDE & 8 FÄHIGKEITEN)
// =============================================================================
const LEM_W = 640;
const LEM_H = 320;
// Pixel-Typen im Gelände-Puffer: 0 = Luft, 1 = Erde (grün/braun, grabbar), 2 = Ziegel/Treppe (grabbar), 3 = Stahl (unzerstörbar)
let lemTerrain     = new Uint8Array(LEM_W * LEM_H);
let lemTerrainCvs  = null;
let lemTerrainCtx  = null;

let lemInitialized = false;
let lemRunning     = false;
let lemRafId       = null;
let lemLevelIdx    = 0;
let lemFastForward = false;
let lemActiveSkill = 'digger';
let lemTick        = 0;
let lemScore       = 0;
let lemTimeLeftSec = 180;
let lemSpawned     = 0;
let lemSaved       = 0;
let lemTotal       = 20;
let lemRequiredPct = 50;
let lemBannerText  = '';
let lemHoverPos    = { x: -1, y: -1 };

let lemList        = []; // { id, x, y, dir, state, fallDist, canClimb, canFloat, bombTicks, buildStepsLeft, stepWait, anim }
let lemParticles   = []; // { x, y, vx, vy, color, life }
let lemSkillsLeft  = {};

const LEM_LEVELS = [
    {
        name: 'Level 1: Just Dig! (Grabe einfach!)',
        total: 16,
        requiredPct: 50,
        timeSec: 180,
        hatch: { x: 145, y: 42 },
        exit:  { x: 475, y: 256 },
        skills: { climber: 5, floater: 5, bomber: 5, blocker: 5, builder: 10, basher: 5, miner: 5, digger: 10 },
        buildFn: (fillRect, fillSteel) => {
            // Obere breite Erdschicht (man muss durch sie nach unten zum Ausgang graben!)
            fillRect(70, 92, 500, 74, '#65a30d', '#3f6212');
            // Untere Ziel-Ebene
            fillRect(110, 256, 440, 44, '#854d0e', '#713f12');
            // Linke & rechte Begrenzungsmauern auf der oberen Ebene, damit niemand herunterfällt
            fillSteel(70, 62, 18, 32);
            fillSteel(552, 62, 18, 32);
            // Untere linke Leitwand
            fillSteel(110, 224, 18, 34);
        }
    },
    {
        name: 'Level 2: Only Floaters Survive (Klippensprung)',
        total: 16,
        requiredPct: 50,
        timeSec: 180,
        hatch: { x: 110, y: 36 },
        exit:  { x: 520, y: 248 },
        skills: { climber: 5, floater: 16, bomber: 5, blocker: 5, builder: 12, basher: 5, miner: 5, digger: 5 },
        buildFn: (fillRect, fillSteel) => {
            // Hohe Klippe oben links -> tiefer Sturz (erfordert Floater ☂️ oder Builder!)
            fillRect(60, 78, 120, 24, '#0284c7', '#0369a1');
            fillSteel(60, 50, 16, 30);
            // Untere Hauptplattform links
            fillRect(60, 248, 250, 46, '#65a30d', '#3f6212');
            fillSteel(60, 218, 16, 32);
            // Schlucht zwischen x=310 und x=395 -> muss mit 🪜 Builder überbrückt werden!
            fillRect(395, 248, 180, 46, '#65a30d', '#3f6212');
        }
    },
    {
        name: 'Level 3: Pillars of Hercules (Steinsäulen)',
        total: 18,
        requiredPct: 60,
        timeSec: 200,
        hatch: { x: 105, y: 160 },
        exit:  { x: 545, y: 242 },
        skills: { climber: 8, floater: 8, bomber: 8, blocker: 8, builder: 14, basher: 12, miner: 8, digger: 8 },
        buildFn: (fillRect, fillSteel) => {
            // Durchgehender Boden mit 3 dicken Steinsäulen, durch die man sich mit 🔨 Basher schlagen muss
            fillRect(50, 242, 540, 52, '#78716c', '#57534e');
            fillSteel(50, 190, 18, 54);
            // 3 Säulen
            fillRect(215, 145, 36, 97, '#d97706', '#b45309');
            fillRect(325, 145, 36, 97, '#d97706', '#b45309');
            fillRect(435, 145, 36, 97, '#d97706', '#b45309');
        }
    },
    {
        name: 'Level 4: Through the Mountain (Bergmassiv)',
        total: 20,
        requiredPct: 65,
        timeSec: 220,
        hatch: { x: 95, y: 190 },
        exit:  { x: 548, y: 190 },
        skills: { climber: 10, floater: 10, bomber: 10, blocker: 10, builder: 15, basher: 12, miner: 12, digger: 10 },
        buildFn: (fillRect, fillSteel) => {
            fillRect(45, 250, 550, 46, '#15803d', '#166534');
            fillSteel(45, 195, 16, 56);
            // Massiver Berg in der Mitte
            fillRect(225, 95, 185, 155, '#9333ea', '#6b21a8');
        }
    }
];

function lemEnsureOffscreen() {
    if (!lemTerrainCvs) {
        lemTerrainCvs = document.createElement('canvas');
        lemTerrainCvs.width  = LEM_W;
        lemTerrainCvs.height = LEM_H;
        lemTerrainCtx = lemTerrainCvs.getContext('2d');
    }
}

function lemGetPixel(x, y) {
    x = Math.round(x);
    y = Math.round(y);
    if (x < 0 || x >= LEM_W || y < 0 || y >= LEM_H) return 0;
    return lemTerrain[y * LEM_W + x];
}

function lemCarveRect(x0, y0, w, h) {
    lemEnsureOffscreen();
    const sx = Math.max(0, Math.round(x0));
    const sy = Math.max(0, Math.round(y0));
    const ex = Math.min(LEM_W, Math.round(x0 + w));
    const ey = Math.min(LEM_H, Math.round(y0 + h));
    for (let y = sy; y < ey; y++) {
        for (let x = sx; x < ex; x++) {
            const idx = y * LEM_W + x;
            if (lemTerrain[idx] !== 3) { // Stahl (3) ist unzerstörbar
                lemTerrain[idx] = 0;
                lemTerrainCtx.clearRect(x, y, 1, 1);
            }
        }
    }
}

function lemCarveCircle(cx, cy, radius) {
    lemEnsureOffscreen();
    const r2 = radius * radius;
    const sx = Math.max(0, Math.floor(cx - radius));
    const sy = Math.max(0, Math.floor(cy - radius));
    const ex = Math.min(LEM_W - 1, Math.ceil(cx + radius));
    const ey = Math.min(LEM_H - 1, Math.ceil(cy + radius));
    for (let y = sy; y <= ey; y++) {
        for (let x = sx; x <= ex; x++) {
            if ((x - cx) * (x - cx) + (y - cy) * (y - cy) <= r2) {
                const idx = y * LEM_W + x;
                if (lemTerrain[idx] !== 3) {
                    lemTerrain[idx] = 0;
                    lemTerrainCtx.clearRect(x, y, 1, 1);
                }
            }
        }
    }
}

function lemAddBridgeStep(x0, y0, w, h) {
    lemEnsureOffscreen();
    const sx = Math.max(0, Math.round(x0));
    const sy = Math.max(0, Math.round(y0));
    const ex = Math.min(LEM_W, Math.round(x0 + w));
    const ey = Math.min(LEM_H, Math.round(y0 + h));
    lemTerrainCtx.fillStyle = '#fde047';
    for (let y = sy; y < ey; y++) {
        for (let x = sx; x < ex; x++) {
            const idx = y * LEM_W + x;
            if (lemTerrain[idx] === 0) {
                lemTerrain[idx] = 2;
                lemTerrainCtx.fillRect(x, y, 1, 1);
            }
        }
    }
}

function lemLoadLevel(lvlIdx) {
    lemEnsureOffscreen();
    if (lemRafId) cancelAnimationFrame(lemRafId);
    lemLevelIdx = Math.max(0, Math.min(LEM_LEVELS.length - 1, lvlIdx || 0));
    const selEl = document.getElementById('lemLevelSelect');
    if (selEl && parseInt(selEl.value, 10) !== lemLevelIdx) {
        selEl.value = String(lemLevelIdx);
    }

    const lvl = LEM_LEVELS[lemLevelIdx];
    lemTerrain.fill(0);
    lemTerrainCtx.clearRect(0, 0, LEM_W, LEM_H);

    const fillRect = (x, y, w, h, topCol, bodyCol) => {
        lemTerrainCtx.fillStyle = bodyCol;
        lemTerrainCtx.fillRect(x, y, w, h);
        lemTerrainCtx.fillStyle = topCol;
        lemTerrainCtx.fillRect(x, y, w, 6);
        for (let py = y; py < y + h; py++) {
            for (let px = x; px < x + w; px++) {
                if (px >= 0 && px < LEM_W && py >= 0 && py < LEM_H) {
                    lemTerrain[py * LEM_W + px] = 1;
                }
            }
        }
    };

    const fillSteel = (x, y, w, h) => {
        lemTerrainCtx.fillStyle = '#64748b';
        lemTerrainCtx.fillRect(x, y, w, h);
        lemTerrainCtx.strokeStyle = '#cbd5e1';
        lemTerrainCtx.strokeRect(x + 1, y + 1, w - 2, h - 2);
        for (let py = y; py < y + h; py++) {
            for (let px = x; px < x + w; px++) {
                if (px >= 0 && px < LEM_W && py >= 0 && py < LEM_H) {
                    lemTerrain[py * LEM_W + px] = 3;
                }
            }
        }
    };

    lvl.buildFn(fillRect, fillSteel);

    lemTotal       = lvl.total;
    lemRequiredPct = lvl.requiredPct;
    lemTimeLeftSec = lvl.timeSec;
    lemSpawned     = 0;
    lemSaved       = 0;
    lemTick        = 0;
    lemList        = [];
    lemParticles   = [];
    lemSkillsLeft  = Object.assign({}, lvl.skills);
    lemBannerText  = '🐹 ' + lvl.name.toUpperCase();
    lemRunning     = false;
    lemUpdateHud();
    lemDraw();
}

function lemInit() {
    if (lemInitialized) return;
    lemInitialized = true;
    lemLoadLevel(0);
    lemSelectSkill('digger');
}

function lemStartLevel() {
    lemLoadLevel(lemLevelIdx);
    lemBannerText = '';
    lemRunning    = true;
    lemRafId      = requestAnimationFrame(lemLoop);
}

function lemSelectSkill(skillKey) {
    lemActiveSkill = skillKey;
    document.querySelectorAll('.lem-skill-btn').forEach((btn) => {
        btn.className = 'lem-skill-btn flex flex-col items-center justify-center p-1.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-100 dark:bg-slate-800 text-[11px] font-extrabold cursor-pointer';
    });
    const activeBtn = document.getElementById('lemSkillBtn-' + skillKey);
    if (activeBtn) {
        activeBtn.className = 'lem-skill-btn flex flex-col items-center justify-center p-1.5 rounded-xl border-2 border-lime-500 bg-lime-100 dark:bg-lime-950/80 text-[11px] font-extrabold cursor-pointer shadow-xs';
    }
}

function lemToggleFastForward() {
    lemFastForward = !lemFastForward;
    const b = document.getElementById('lemSpeedBadge');
    if (b) b.textContent = lemFastForward ? '2×' : '1×';
}

function lemTriggerNuke() {
    if (!lemRunning) return;
    lemSpawned = lemTotal; // Keine weiteren aus der Klappe
    for (const l of lemList) {
        if (l.state !== 'dead' && l.state !== 'saved' && l.bombTicks <= 0) {
            l.bombTicks = 90 + Math.floor(Math.random() * 45);
        }
    }
}

function lemUpdateHud() {
    const outEl = document.getElementById('lemOutCount');
    const svEl  = document.getElementById('lemSavedPct');
    const tmEl  = document.getElementById('lemTimeLeft');
    const scEl  = document.getElementById('lemScore');

    const activeOut = lemList.filter((l) => l.state !== 'dead' && l.state !== 'saved').length;
    if (outEl) outEl.textContent = activeOut + ' aktiv (' + lemSpawned + '/' + lemTotal + ')';

    const pct = lemTotal > 0 ? Math.round((lemSaved / lemTotal) * 100) : 0;
    if (svEl) svEl.textContent = pct + '% (' + lemSaved + '/' + lemTotal + ' · Ziel ' + lemRequiredPct + '%)';

    if (tmEl) {
        const m = String(Math.floor(lemTimeLeftSec / 60)).padStart(2, '0');
        const s = String(lemTimeLeftSec % 60).padStart(2, '0');
        tmEl.textContent = m + ':' + s;
    }
    if (scEl) scEl.textContent = lemScore.toLocaleString('de-DE');

    ['climber', 'floater', 'bomber', 'blocker', 'builder', 'basher', 'miner', 'digger'].forEach((k) => {
        const el = document.getElementById('lemSkillCnt-' + k);
        if (el) el.textContent = String(lemSkillsLeft[k] ?? 0);
    });
}

function lemScreenToCanvasCoords(clientX, clientY) {
    const cvs = document.getElementById('lemCanvas');
    if (!cvs) return { x: 0, y: 0 };
    const rect = cvs.getBoundingClientRect();
    return {
        x: ((clientX - rect.left) / rect.width) * LEM_W,
        y: ((clientY - rect.top) / rect.height) * LEM_H
    };
}

function lemCanvasHover(e) {
    lemHoverPos = lemScreenToCanvasCoords(e.clientX, e.clientY);
}

function lemCanvasTouch(e) {
    if (!e.touches || e.touches.length === 0) return;
    e.preventDefault();
    const pos = lemScreenToCanvasCoords(e.touches[0].clientX, e.touches[0].clientY);
    lemHoverPos = pos;
    lemAssignSkillAt(pos.x, pos.y);
}

function lemCanvasClick(e) {
    const pos = lemScreenToCanvasCoords(e.clientX, e.clientY);
    lemAssignSkillAt(pos.x, pos.y);
}

function lemAssignSkillAt(cx, cy) {
    if (!lemRunning) {
        lemStartLevel();
        return;
    }
    if ((lemSkillsLeft[lemActiveSkill] || 0) <= 0) return;

    // Finde den nächsten lebenden Lemming im Umkreis von 24 Pixeln
    let bestLem = null;
    let bestDist = 24 * 24;
    for (const l of lemList) {
        if (l.state === 'dead' || l.state === 'saved') continue;
        // Wenn man nicht gerade einen Blocker wegsprengen will, bevorzuge laufende Lemminge vor Blockern
        const dx = l.x - cx;
        const dy = (l.y - 5) - cy;
        const d2 = dx * dx + dy * dy;
        if (d2 < bestDist) {
            bestDist = d2;
            bestLem = l;
        }
    }
    if (!bestLem) return;

    const sk = lemActiveSkill;
    if (sk === 'climber') {
        if (bestLem.canClimb) return;
        bestLem.canClimb = true;
    } else if (sk === 'floater') {
        if (bestLem.canFloat) return;
        bestLem.canFloat = true;
    } else if (sk === 'bomber') {
        if (bestLem.bombTicks > 0) return;
        bestLem.bombTicks = 150; // ~5 Sekunden Countdown
    } else if (sk === 'blocker') {
        if (bestLem.state === 'blocker' || bestLem.state === 'falling') return;
        bestLem.state = 'blocker';
    } else if (sk === 'builder') {
        if (bestLem.state === 'falling') return;
        bestLem.state = 'builder';
        bestLem.buildStepsLeft = 12;
        bestLem.stepWait = 0;
    } else if (sk === 'basher') {
        if (bestLem.state === 'falling') return;
        bestLem.state = 'basher';
        bestLem.stepWait = 0;
    } else if (sk === 'miner') {
        if (bestLem.state === 'falling') return;
        bestLem.state = 'miner';
        bestLem.stepWait = 0;
    } else if (sk === 'digger') {
        if (bestLem.state === 'falling') return;
        bestLem.state = 'digger';
        bestLem.stepWait = 0;
    }

    lemSkillsLeft[sk]--;
    lemUpdateHud();
}

function lemSpawnSplatter(x, y, color) {
    for (let i = 0; i < 10; i++) {
        lemParticles.push({
            x: x,
            y: y,
            vx: (Math.random() - 0.5) * 4,
            vy: (Math.random() - 0.7) * 4,
            color: color,
            life: 20 + Math.floor(Math.random() * 10)
        });
    }
}

function lemStepSingleTick() {
    lemTick++;
    const lvl = LEM_LEVELS[lemLevelIdx];

    // 1. Sekundentakt für Uhrzeit
    if (lemTick % 35 === 0 && lemTimeLeftSec > 0) {
        lemTimeLeftSec--;
        lemUpdateHud();
        if (lemTimeLeftSec <= 0) {
            lemFinishCheck();
            return;
        }
    }

    // 2. Neue Lemminge aus der Fallklappe (Hatch) spawnen
    if (lemSpawned < lemTotal && lemTick % 42 === 10) {
        lemSpawned++;
        lemList.push({
            id: lemSpawned,
            x: lvl.hatch.x,
            y: lvl.hatch.y + 12,
            dir: 1,
            state: 'falling',
            fallDist: 0,
            canClimb: false,
            canFloat: false,
            bombTicks: 0,
            buildStepsLeft: 0,
            stepWait: 0,
            anim: 0
        });
        lemUpdateHud();
    }

    // 3. Jeden Lemming bewegen
    for (const l of lemList) {
        if (l.state === 'dead' || l.state === 'saved') continue;
        l.anim++;

        // Bomber-Countdown
        if (l.bombTicks > 0) {
            l.bombTicks--;
            if (l.bombTicks <= 0) {
                lemCarveCircle(l.x, l.y - 4, 18);
                lemSpawnSplatter(l.x, l.y - 4, '#f97316');
                l.state = 'dead';
                lemUpdateHud();
                continue;
            }
        }

        // Ausgang (Exit-Portal) erreicht?
        if (Math.abs(l.x - lvl.exit.x) <= 12 && Math.abs(l.y - lvl.exit.y) <= 14) {
            l.state = 'saved';
            lemSaved++;
            lemScore += 150;
            lemSpawnSplatter(l.x, l.y - 8, '#4ade80');
            lemUpdateHud();
            continue;
        }

        // Aus dem unteren Bildschirmrand gefallen (Lava / Abgrund)?
        if (l.y >= LEM_H - 4) {
            l.state = 'dead';
            lemSpawnSplatter(l.x, LEM_H - 8, '#ef4444');
            lemUpdateHud();
            continue;
        }

        // Blocker dreht andere Lemminge um
        if (l.state !== 'blocker') {
            for (const other of lemList) {
                if (other !== l && other.state === 'blocker') {
                    if (Math.abs(l.x - other.x) <= 6 && Math.abs(l.y - other.y) <= 10) {
                        l.dir = (l.x < other.x) ? -1 : 1;
                    }
                }
            }
        }

        // A) FALLEND / FLOATER
        if (l.state === 'falling' || l.state === 'floating') {
            if (l.canFloat && l.fallDist > 16) {
                l.state = 'floating';
            }
            const fallStep = (l.state === 'floating') ? 1 : 2;
            let hitGround = false;
            for (let s = 0; s < fallStep; s++) {
                if (lemGetPixel(l.x, l.y + 1) > 0) {
                    hitGround = true;
                    break;
                }
                l.y++;
                l.fallDist++;
            }
            if (hitGround) {
                // Zu tief ohne Schirmchen gefallen? (> 95 Pixel)
                if (l.fallDist > 95 && !l.canFloat && l.state !== 'floating') {
                    l.state = 'dead';
                    lemSpawnSplatter(l.x, l.y, '#fb7185');
                    lemUpdateHud();
                } else {
                    l.state = 'walker';
                    l.fallDist = 0;
                }
            }
            continue;
        }

        // Prüfen, ob unter den Füßen der Boden weggebrochen ist
        if (l.state !== 'climbing' && lemGetPixel(l.x, l.y + 1) === 0 && lemGetPixel(l.x, l.y + 2) === 0) {
            l.state = 'falling';
            l.fallDist = 0;
            continue;
        }

        // B) BLOCKER
        if (l.state === 'blocker') {
            continue;
        }

        // C) DIGGER (Senkrecht nach unten graben)
        if (l.state === 'digger') {
            l.stepWait++;
            if (l.stepWait >= 6) {
                l.stepWait = 0;
                // Prüfen ob Stahl unter uns ist
                if (lemGetPixel(l.x, l.y + 1) === 3) {
                    l.state = 'walker';
                } else {
                    lemCarveRect(l.x - 6, l.y - 1, 13, 3);
                    l.y += 2;
                    if (lemGetPixel(l.x, l.y + 1) === 0 && lemGetPixel(l.x - 4, l.y + 1) === 0 && lemGetPixel(l.x + 4, l.y + 1) === 0) {
                        l.state = 'falling';
                        l.fallDist = 0;
                    }
                }
            }
            continue;
        }

        // D) BUILDER (12 schräge Stufen nach vorne-oben bauen)
        if (l.state === 'builder') {
            l.stepWait++;
            if (l.stepWait >= 11) {
                l.stepWait = 0;
                const bx = l.dir > 0 ? l.x : (l.x - 7);
                lemAddBridgeStep(bx, l.y - 1, 8, 2);
                l.x += l.dir * 4;
                l.y -= 2;
                l.buildStepsLeft--;
                // Gegen Decke/Wand gestoßen oder 12 Stufen fertig?
                if (lemGetPixel(l.x + l.dir * 3, l.y - 6) > 0 || l.buildStepsLeft <= 0) {
                    l.state = 'walker';
                }
            }
            continue;
        }

        // E) BASHER (Waagerecht durch Wände graben)
        if (l.state === 'basher') {
            l.stepWait++;
            if (l.stepWait >= 5) {
                l.stepWait = 0;
                const checkX = l.x + l.dir * 6;
                if (lemGetPixel(checkX, l.y - 4) === 3) {
                    // Stahl! Kehrtmachen
                    l.dir = -l.dir;
                    l.state = 'walker';
                } else {
                    const rx = l.dir > 0 ? l.x : (l.x - 8);
                    lemCarveRect(rx, l.y - 11, 8, 12);
                    l.x += l.dir * 2;
                    // Wenn vor uns auf Brusthöhe keine Wand mehr ist, wieder normal weiterlaufen
                    let hasWallAhead = false;
                    for (let d = 2; d <= 10; d += 2) {
                        if (lemGetPixel(l.x + l.dir * d, l.y - 5) > 0) {
                            hasWallAhead = true;
                            break;
                        }
                    }
                    if (!hasWallAhead) {
                        l.state = 'walker';
                    }
                }
            }
            continue;
        }

        // F) MINER (Schräg nach vorne-unten graben)
        if (l.state === 'miner') {
            l.stepWait++;
            if (l.stepWait >= 7) {
                l.stepWait = 0;
                if (lemGetPixel(l.x + l.dir * 4, l.y + 1) === 3) {
                    l.state = 'walker';
                } else {
                    const rx = l.dir > 0 ? l.x - 2 : (l.x - 8);
                    lemCarveRect(rx, l.y - 9, 10, 12);
                    l.x += l.dir * 3;
                    l.y += 2;
                }
            }
            continue;
        }

        // G) CLIMBING (Senkrechte Wand hochklettern)
        if (l.state === 'climbing') {
            l.y -= 1;
            // Oben auf der Kante angekommen?
            if (lemGetPixel(l.x + l.dir * 2, l.y - 6) === 0) {
                l.x += l.dir * 3;
                l.y -= 2;
                l.state = 'walker';
            } else if (lemGetPixel(l.x, l.y - 10) > 0) {
                // Deckenüberhang -> fällt herunter
                l.dir = -l.dir;
                l.state = 'falling';
                l.fallDist = 0;
            }
            continue;
        }

        // H) WALKER (Normales Laufen mit Stufen-Steigfähigkeit bis 5 Pixel)
        if (l.anim % 2 === 0) {
            const nx = l.x + l.dir;
            // Prüfen wie hoch die Stufe vor uns ist
            let climbedStep = false;
            for (let dy = 0; dy <= 5; dy++) {
                if (lemGetPixel(nx, l.y - dy) === 0 && lemGetPixel(nx, l.y - dy - 4) === 0) {
                    l.x = nx;
                    l.y -= dy;
                    climbedStep = true;
                    break;
                }
            }
            // Falls eine steile Wand (> 5 Pixel) vor uns steht:
            if (!climbedStep) {
                if (l.canClimb) {
                    l.state = 'climbing';
                } else {
                    l.dir = -l.dir;
                }
            } else {
                // Falls eine kleine Stufe nach unten (1..4 Pixel) folgt, sanft hinabsteigen
                for (let down = 1; down <= 4; down++) {
                    if (lemGetPixel(l.x, l.y + 1) === 0 && lemGetPixel(l.x, l.y + 2) > 0) {
                        l.y++;
                    }
                }
            }
        }
    }

    // 4. Prüfen, ob alle Lemminge im Ziel oder tot sind
    if (lemSpawned >= lemTotal) {
        const remaining = lemList.filter((l) => l.state !== 'dead' && l.state !== 'saved').length;
        if (remaining === 0) {
            lemFinishCheck();
        }
    }
}

function lemFinishCheck() {
    lemRunning = false;
    const pct = lemTotal > 0 ? Math.round((lemSaved / lemTotal) * 100) : 0;
    if (pct >= lemRequiredPct) {
        const levelBonus = pct * 10 + lemTimeLeftSec * 5 + (lemLevelIdx + 1) * 500;
        lemScore += levelBonus;
        lemUpdateHud();
        submitCloudScore('lemmings', lemScore, 180 - lemTimeLeftSec, 'Level ' + (lemLevelIdx + 1) + ' (' + pct + '% gerettet)');
        if (lemLevelIdx < LEM_LEVELS.length - 1) {
            lemBannerText = '🎉 ' + pct + '% GERETTET! WEITER ZU LEVEL ' + (lemLevelIdx + 2) + '...';
            setTimeout(() => {
                lemLoadLevel(lemLevelIdx + 1);
                lemStartLevel();
            }, 1900);
        } else {
            lemBannerText = '🏆 ALLE 4 LEMMINGS-LEVEL GEMEISTERT! (' + pct + '%)';
        }
    } else {
        lemBannerText = '💥 NUR ' + pct + '% GERETTET (ZIEL: ' + lemRequiredPct + '%) – NOCHMAL!';
    }
}

function lemDraw() {
    const cvs = document.getElementById('lemCanvas');
    if (!cvs) return;
    const ctx = cvs.getContext('2d');
    const lvl = LEM_LEVELS[lemLevelIdx] || LEM_LEVELS[0];

    // 1. Dunkler Untergrund + glühende Lava/Wasser am unteren Bildschirmrand
    ctx.fillStyle = '#090d16';
    ctx.fillRect(0, 0, LEM_W, LEM_H);

    // Sternenstaub im Hintergrund
    ctx.fillStyle = 'rgba(148, 163, 184, 0.25)';
    for (let i = 0; i < 28; i++) {
        ctx.fillRect((i * 89 + 17) % LEM_W, (i * 53 + 11) % (LEM_H - 30), 2, 2);
    }

    // Feuriger Abgrund ganz unten
    const lavaGrad = ctx.createLinearGradient(0, LEM_H - 14, 0, LEM_H);
    lavaGrad.addColorStop(0, 'rgba(239, 68, 68, 0.0)');
    lavaGrad.addColorStop(1, 'rgba(239, 68, 68, 0.75)');
    ctx.fillStyle = lavaGrad;
    ctx.fillRect(0, LEM_H - 14, LEM_W, 14);

    // 2. Zerstörbares Pixel-Gelände aus dem Offscreen-Canvas zeichnen
    if (lemTerrainCvs) {
        ctx.drawImage(lemTerrainCvs, 0, 0);
    }

    // 3. Fallklappe (Hatch) oben & Ziel-Portal (Exit) zeichnen
    const hx = lvl.hatch.x;
    const hy = lvl.hatch.y;
    ctx.fillStyle = '#b45309';
    ctx.fillRect(hx - 18, hy - 10, 36, 14);
    ctx.strokeStyle = '#fde047';
    ctx.lineWidth = 2;
    ctx.strokeRect(hx - 18, hy - 10, 36, 14);
    ctx.fillStyle = '#fef08a';
    ctx.font = 'bold 9px monospace';
    ctx.textAlign = 'center';
    ctx.fillText('IN / HATCH', hx, hy - 13);

    const ex = lvl.exit.x;
    const ey = lvl.exit.y;
    // Leuchtender Tempel-Ausgang mit Fackeln
    ctx.fillStyle = '#15803d';
    ctx.fillRect(ex - 16, ey - 24, 32, 24);
    ctx.fillStyle = '#fde047';
    ctx.beginPath();
    ctx.moveTo(ex - 20, ey - 24);
    ctx.lineTo(ex, ey - 36);
    ctx.lineTo(ex + 20, ey - 24);
    ctx.closePath();
    ctx.fill();
    ctx.fillStyle = '#020617';
    ctx.beginPath();
    ctx.arc(ex, ey - 8, 7, Math.PI, 0, false);
    ctx.lineTo(ex + 7, ey);
    ctx.lineTo(ex - 7, ey);
    ctx.closePath();
    ctx.fill();
    ctx.fillStyle = '#4ade80';
    ctx.font = 'bold 9px monospace';
    ctx.fillText('EXIT 🏠', ex, ey - 39);

    // 4. Alle Lemminge zeichnen (Blauer Anzug + grüner Haarschopf!)
    let hoveredLem = null;
    let bestHovDist = 24 * 24;

    for (const l of lemList) {
        if (l.state === 'dead' || l.state === 'saved') continue;

        const d2 = (l.x - lemHoverPos.x) ** 2 + ((l.y - 5) - lemHoverPos.y) ** 2;
        if (d2 < bestHovDist) {
            bestHovDist = d2;
            hoveredLem = l;
        }

        // Fallschirm beim Floater
        if (l.state === 'floating') {
            ctx.fillStyle = '#f43f5e';
            ctx.beginPath();
            ctx.arc(l.x, l.y - 13, 7, Math.PI, 0, false);
            ctx.fill();
            ctx.strokeStyle = '#f8fafc';
            ctx.lineWidth = 1;
            ctx.beginPath();
            ctx.moveTo(l.x - 6, l.y - 13);
            ctx.lineTo(l.x, l.y - 7);
            ctx.moveTo(l.x + 6, l.y - 13);
            ctx.lineTo(l.x, l.y - 7);
            ctx.stroke();
        }

        // Blauer Anzug
        ctx.fillStyle = l.state === 'blocker' ? '#ef4444' : '#3b82f6';
        ctx.fillRect(l.x - 2.5, l.y - 7, 5, 6);

        // Ausgestreckte Stopp-Arme beim Blocker
        if (l.state === 'blocker') {
            ctx.fillStyle = '#fca5a5';
            ctx.fillRect(l.x - 6, l.y - 6, 12, 2);
        }

        // Kopf + grüne Haare
        ctx.fillStyle = '#fde68a';
        ctx.fillRect(l.x - 2, l.y - 10, 4, 3);
        ctx.fillStyle = '#22c55e';
        ctx.fillRect(l.x - 2.5, l.y - 12, 5, 2.5);

        // Füße
        const stepOff = (l.anim % 6 < 3) ? 1 : -1;
        ctx.fillStyle = '#e2e8f0';
        ctx.fillRect(l.x - 2 + stepOff, l.y - 1, 2, 1.5);
        ctx.fillRect(l.x - stepOff, l.y - 1, 2, 1.5);

        // Bomber-Countdown über dem Kopf
        if (l.bombTicks > 0) {
            ctx.fillStyle = '#fde047';
            ctx.font = 'bold 10px monospace';
            ctx.textAlign = 'center';
            ctx.fillText(String(Math.ceil(l.bombTicks / 30)), l.x, l.y - 15);
        }
    }

    // 5. Grünes Ziel-Fadenkreuz um den anvisierten Lemming
    if (hoveredLem) {
        ctx.strokeStyle = '#4ade80';
        ctx.lineWidth = 1.5;
        ctx.strokeRect(hoveredLem.x - 8, hoveredLem.y - 15, 16, 17);
        ctx.fillStyle = '#4ade80';
        ctx.font = 'bold 10px monospace';
        ctx.textAlign = 'center';
        ctx.fillText(hoveredLem.state.toUpperCase(), hoveredLem.x, hoveredLem.y - 18);
    }

    // 6. Partikel
    for (let i = lemParticles.length - 1; i >= 0; i--) {
        const pt = lemParticles[i];
        ctx.fillStyle = pt.color;
        ctx.fillRect(pt.x, pt.y, 2.5, 2.5);
        pt.x += pt.vx;
        pt.y += pt.vy;
        pt.vy += 0.15;
        pt.life--;
        if (pt.life <= 0) lemParticles.splice(i, 1);
    }

    // 7. Start- / Level-Banner
    if (!lemRunning) {
        ctx.fillStyle = 'rgba(2, 6, 23, 0.80)';
        ctx.fillRect(0, LEM_H / 2 - 44, LEM_W, 88);
        ctx.textAlign = 'center';
        ctx.fillStyle = '#84cc16';
        ctx.font = 'bold 19px monospace';
        ctx.fillText(lemBannerText || '🐹 CLOUD-LEMMINGS (1991)', LEM_W / 2, LEM_H / 2 - 8);
        ctx.fillStyle = '#e2e8f0';
        ctx.font = '12px sans-serif';
        ctx.fillText('Klicke ins Spielfeld oder auf „▶️ Level neu starten“ – Let’s go!', LEM_W / 2, LEM_H / 2 + 18);
    }
}

function lemLoop() {
    if (!lemRunning) {
        lemDraw();
        return;
    }
    const steps = lemFastForward ? 2 : 1;
    for (let i = 0; i < steps; i++) {
        if (lemRunning) lemStepSingleTick();
    }
    lemDraw();
    if (lemRunning) {
        lemRafId = requestAnimationFrame(lemLoop);
    }
}

// =============================================================================
// 17. CLOUD-TRAFFIC-RACER (HIGHWAY-ACTION, CLOSE OVERTAKES & TUNING-GARAGE)
// =============================================================================
const TR_CARS = [
    { id: 'compact',     name: '🚗 City-Hatchback',   price: 0,     topKmh: 170, accel: 0.34, brake: 0.95, handling: 4.2, color: '#38bdf8', stripe: '#0284c7' },
    { id: 'coupe',       name: '🏎️ GT Sport-Coupé',   price: 1200,  topKmh: 215, accel: 0.46, brake: 1.15, handling: 4.8, color: '#f43f5e', stripe: '#ffffff' },
    { id: 'muscle',      name: '💪 V8 Muscle-Beast',  price: 3000,  topKmh: 250, accel: 0.56, brake: 1.25, handling: 5.1, color: '#f59e0b', stripe: '#1e293b' },
    { id: 'hyper',       name: '⚡ Cyber-Hypercar',   price: 6500,  topKmh: 295, accel: 0.72, brake: 1.45, handling: 5.8, color: '#a855f7', stripe: '#22d3ee' },
    { id: 'interceptor', name: '🚓 Polizei-Turbine',  price: 11000, topKmh: 330, accel: 0.85, brake: 1.65, handling: 6.2, color: '#0f172a', stripe: '#3b82f6', isPolice: true }
];

// 4 Fahrspuren auf dem 460px breiten Highway (Road von x=70 bis x=390, Spurbreite = 80px)
const TR_LANES = [110, 190, 270, 350];

let trInitialized = false;
let trRunning     = false;
let trRafId       = null;
let trMode        = 'twoway'; // 'twoway' | 'oneway' | 'timeattack' | 'police'
let trEnv         = 'night';  // 'night' | 'desert' | 'rain'
let trTick        = 0;
let trStartAt     = 0;
let trScore       = 0;
let trDistMeters  = 0;
let trOvertakes   = 0;
let trCombo       = 1;
let trComboTimer  = 0;
let trWrongWaySec = 0;
let trTimeLeft    = 90;
let trHornFlash   = 0;
let trBannerText  = '';
const trKeys      = {};

let trGarage = {
    cash: 500,
    selectedCar: 'compact',
    ownedCars: ['compact'],
    upgrades: { compact: 0, coupe: 0, muscle: 0, hyper: 0, interceptor: 0 }
};

let trPlayer = {
    x: 270,
    y: 440,
    w: 36,
    h: 68,
    kmh: 60,
    tilt: 0
};

let trTraffic   = []; // { lane, x, targetX, y, w, h, kmh, dir, type, color,roofColor, passed, blinker, blinkerTicks, isSuspect, hp }
let trPopups    = []; // { x, y, text, color, life }
let trScenery   = []; // Bäume, Kakteen, Laternen am Straßenrand { side, y, type }
let trRoadOffset = 0;

function trLoadGarageFromSaved() {
    const saved = SAVED_GAME_STATES && SAVED_GAME_STATES['trafficracer'];
    if (saved && typeof saved === 'object') {
        if (typeof saved.cash === 'number') trGarage.cash = Math.max(0, Math.round(saved.cash));
        if (typeof saved.selectedCar === 'string') trGarage.selectedCar = saved.selectedCar;
        if (Array.isArray(saved.ownedCars) && saved.ownedCars.length > 0) trGarage.ownedCars = saved.ownedCars;
        if (saved.upgrades && typeof saved.upgrades === 'object') {
            trGarage.upgrades = Object.assign(trGarage.upgrades, saved.upgrades);
        }
    }
}

function trSaveGarageToCloud() {
    saveCloudGameState('trafficracer', trGarage);
}

function trGetCurrentCarStats() {
    const base = TR_CARS.find((c) => c.id === trGarage.selectedCar) || TR_CARS[0];
    const lvl  = Math.min(5, trGarage.upgrades[base.id] || 0);
    return {
        ...base,
        level: lvl,
        topKmh: base.topKmh + lvl * 12,
        accel: base.accel + lvl * 0.06,
        brake: base.brake + lvl * 0.10,
        handling: base.handling + lvl * 0.25
    };
}

function trRenderGarageUi() {
    const cashEl = document.getElementById('trCashLabel');
    if (cashEl) cashEl.textContent = trGarage.cash.toLocaleString('de-DE');

    const upBtn = document.getElementById('trUpgradeBtn');
    const curStats = trGetCurrentCarStats();
    const upCost = 500 + curStats.level * 450;
    if (upBtn) {
        if (curStats.level >= 5) {
            upBtn.textContent = '🏆 Max. Tuning erreicht (Stufe 5/5)';
            upBtn.disabled = true;
            upBtn.className = 'px-3 py-1 rounded-xl bg-slate-300 dark:bg-slate-700 text-slate-600 dark:text-slate-400 font-extrabold cursor-not-allowed';
        } else {
            upBtn.textContent = '⚙️ Tuning Stufe ' + (curStats.level + 1) + '/5 (+12 km/h · ' + upCost.toLocaleString('de-DE') + ' 💰)';
            upBtn.disabled = false;
            upBtn.className = 'px-3 py-1 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-extrabold cursor-pointer';
        }
    }

    const row = document.getElementById('trCarSelectorRow');
    if (!row) return;
    row.innerHTML = TR_CARS.map((c) => {
        const owned = trGarage.ownedCars.includes(c.id);
        const sel   = trGarage.selectedCar === c.id;
        const lvl   = trGarage.upgrades[c.id] || 0;
        const top   = c.topKmh + lvl * 12;
        const cls   = sel
            ? 'p-2 rounded-xl border-2 border-orange-500 bg-orange-50 dark:bg-orange-950/50 text-left cursor-pointer transition-all'
            : 'p-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 hover:border-orange-400 text-left cursor-pointer transition-all';
        return `<button type="button" onclick="trSelectOrBuyCar('${c.id}')" class="${cls}">
            <div class="flex items-center justify-between gap-1 font-extrabold text-slate-900 dark:text-white truncate">
                <span class="truncate">${c.name}</span>
                <span class="text-[10px] px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-800">${top} km/h</span>
            </div>
            <div class="text-[10px] mt-1 font-bold ${owned ? (sel ? 'text-orange-600 dark:text-orange-400' : 'text-emerald-600 dark:text-emerald-400') : 'text-amber-600 dark:text-amber-400'}">
                ${owned ? (sel ? '✓ Ausgewählt (Stufe ' + lvl + ')' : 'In Garage (Wählen)') : '🔒 Kaufen: ' + c.price.toLocaleString('de-DE') + ' 💰'}
            </div>
        </button>`;
    }).join('');
}

function trSelectOrBuyCar(carId) {
    const car = TR_CARS.find((c) => c.id === carId);
    if (!car) return;
    if (trGarage.ownedCars.includes(carId)) {
        trGarage.selectedCar = carId;
        trSaveGarageToCloud();
        trRenderGarageUi();
        trDraw();
        return;
    }
    if (trGarage.cash < car.price) {
        alert('Noch nicht genug Credits! Dir fehlen ' + (car.price - trGarage.cash).toLocaleString('de-DE') + ' 💰.');
        return;
    }
    trGarage.cash -= car.price;
    trGarage.ownedCars.push(carId);
    trGarage.selectedCar = carId;
    trSaveGarageToCloud();
    trRenderGarageUi();
    trDraw();
}

function trUpgradeCurrentCar() {
    const cur = trGetCurrentCarStats();
    if (cur.level >= 5) return;
    const cost = 500 + cur.level * 450;
    if (trGarage.cash < cost) {
        alert('Noch nicht genug Credits für dieses Tuning (' + cost.toLocaleString('de-DE') + ' 💰 benötigt)!');
        return;
    }
    trGarage.cash -= cost;
    trGarage.upgrades[cur.id] = cur.level + 1;
    trSaveGarageToCloud();
    trRenderGarageUi();
}

function trChangeConfig() {
    const mEl = document.getElementById('trModeSelect');
    const eEl = document.getElementById('trEnvSelect');
    if (mEl) trMode = mEl.value;
    if (eEl) trEnv  = eEl.value;
    trDraw();
}

function trInit() {
    if (trInitialized) return;
    trInitialized = true;

    trLoadGarageFromSaved();
    trRenderGarageUi();

    for (let y = 0; y < 560; y += 70) {
        trScenery.push({ side: -1, y: y, type: y % 140 === 0 ? 'lamp' : 'tree' });
        trScenery.push({ side: 1,  y: y + 35, type: y % 140 === 0 ? 'lamp' : 'tree' });
    }

    window.addEventListener('keydown', (e) => {
        if (activeGameKey !== 'trafficracer') return;
        const k = e.key;
        if (['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', ' ', 'w', 'W', 'a', 'A', 's', 'S', 'd', 'D', 'h', 'H'].includes(k)) {
            if (['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', ' '].includes(k)) {
                e.preventDefault();
            }
            if (k === 'a' || k === 'A') trKeys['ArrowLeft'] = true;
            else if (k === 'd' || k === 'D') trKeys['ArrowRight'] = true;
            else if (k === 'w' || k === 'W') trKeys['ArrowUp'] = true;
            else if (k === 's' || k === 'S' || k === ' ') trKeys['ArrowDown'] = true;
            else if (k === 'h' || k === 'H') trTriggerHorn();
            else trKeys[k] = true;
        }
    });

    window.addEventListener('keyup', (e) => {
        const k = e.key;
        if (k === 'a' || k === 'A') trKeys['ArrowLeft'] = false;
        else if (k === 'd' || k === 'D') trKeys['ArrowRight'] = false;
        else if (k === 'w' || k === 'W') trKeys['ArrowUp'] = false;
        else if (k === 's' || k === 'S' || k === ' ') trKeys['ArrowDown'] = false;
        else trKeys[k] = false;
    });

    trBannerText = '🚘 CLOUD-TRAFFIC-RACER BEREIT!';
    trDraw();
}

function trStartGame() {
    if (trRafId) cancelAnimationFrame(trRafId);
    trChangeConfig();
    trTick        = 0;
    trStartAt     = Date.now();
    trScore       = 0;
    trDistMeters  = 0;
    trOvertakes   = 0;
    trCombo       = 1;
    trComboTimer  = 0;
    trWrongWaySec = 0;
    trTimeLeft    = 90;
    trHornFlash   = 0;
    trBannerText  = '';
    trTraffic     = [];
    trPopups      = [];
    trPlayer      = { x: 270, y: 440, w: 36, h: 68, kmh: 75, tilt: 0 };
    trRunning     = true;
    trUpdateHud();
    trRafId       = requestAnimationFrame(trLoop);
}

function trTriggerHorn() {
    if (!trRunning) {
        trStartGame();
        return;
    }
    trHornFlash = 26;
    // Vorausfahrende Autos in unserer Spur weichen nach Möglichkeit zur Seite aus!
    for (const v of trTraffic) {
        if (v.dir === 1 && v.y < trPlayer.y && v.y > trPlayer.y - 260 && Math.abs(v.x - trPlayer.x) < 32) {
            const altLane = v.lane === 3 ? 2 : (v.lane === 2 ? 3 : (v.lane === 0 ? 1 : 0));
            const laneBlocked = trTraffic.some((o) => o !== v && o.lane === altLane && Math.abs(o.y - v.y) < 110);
            if (!laneBlocked) {
                v.blinker = altLane > v.lane ? 1 : -1;
                v.blinkerTicks = 25;
                v.lane = altLane;
                v.targetX = TR_LANES[altLane];
            }
        }
    }
}

function trSpawnVehicle() {
    const lane = Math.floor(Math.random() * 4);
    const x    = TR_LANES[lane];
    // Mindestabstand zu bestehenden Fahrzeugen auf dieser Spur prüfen
    for (const v of trTraffic) {
        if (v.lane === lane && v.y < 140) return;
    }

    const isOncoming = (trMode === 'twoway' && lane < 2);
    const dir        = isOncoming ? -1 : 1;

    // Fahrzeugtyp bestimmen (PKW, Sportwagen, Transporter, Sattelzug-LKW, oder Raser im Polizeimodus)
    const roll = Math.random();
    let type = 'car';
    let w = 36;
    let h = 66;
    let kmh = isOncoming ? (85 + Math.random() * 35) : (70 + Math.random() * 45);
    let color = ['#3b82f6', '#10b981', '#eab308', '#ec4899', '#cbd5e1', '#6366f1'][Math.floor(Math.random() * 6)];
    let isSuspect = false;

    if (trMode === 'police' && !isOncoming && Math.random() < 0.25 && !trTraffic.some((t) => t.isSuspect)) {
        type = 'suspect';
        kmh  = 145 + Math.random() * 30;
        color = '#ef4444';
        isSuspect = true;
    } else if (roll < 0.20) {
        type = 'truck'; // Langer LKW / Sattelzug
        w = 42;
        h = 118;
        kmh = isOncoming ? 75 : 65;
        color = '#f8fafc';
    } else if (roll < 0.36) {
        type = 'van';
        w = 38;
        h = 78;
        kmh = isOncoming ? 82 : 74;
        color = '#fb923c';
    }

    trTraffic.push({
        lane: lane,
        x: x,
        targetX: x,
        y: -h - 20,
        w: w,
        h: h,
        kmh: kmh,
        dir: dir,
        type: type,
        color: color,
        passed: false,
        blinker: 0,
        blinkerTicks: 0,
        isSuspect: isSuspect,
        hp: isSuspect ? 100 : 1
    });
}

function trUpdateHud() {
    const scEl = document.getElementById('trScore');
    const spEl = document.getElementById('trSpeedKmh');
    const ovEl = document.getElementById('trOvertakes');
    const dsEl = document.getElementById('trDistInfo');

    if (scEl) scEl.textContent = Math.round(trScore).toLocaleString('de-DE');
    if (spEl) {
        const k = Math.round(trPlayer.kmh);
        spEl.textContent = k + ' km/h';
        spEl.className = k >= 100
            ? 'text-orange-600 dark:text-orange-400 font-extrabold text-sm'
            : 'text-emerald-600 dark:text-emerald-400 font-extrabold text-sm';
    }
    if (ovEl) ovEl.textContent = trOvertakes + ' (×' + trCombo + ')';
    if (dsEl) {
        const km = (trDistMeters / 1000).toFixed(2).replace('.', ',') + ' km';
        dsEl.textContent = trMode === 'timeattack' ? (km + ' · ⏱️ ' + trTimeLeft + 's') : km;
    }
}

function trEndRun(reason) {
    trRunning = false;
    const earnedCash = Math.max(25, Math.round(trScore / 18) + trOvertakes * 15);
    trGarage.cash += earnedCash;
    trSaveGarageToCloud();
    trRenderGarageUi();

    trBannerText = reason + ' (+' + earnedCash.toLocaleString('de-DE') + ' 💰)';
    const durSec = Math.max(1, Math.round((Date.now() - trStartAt) / 1000));
    const kmStr  = (trDistMeters / 1000).toFixed(1).replace('.', ',') + ' km';
    submitCloudScore('trafficracer', Math.round(trScore), durSec, kmStr + ' · ' + trOvertakes + ' Overtakes');
}

function trStep() {
    trTick++;
    if (trHornFlash > 0) trHornFlash--;
    if (trComboTimer > 0) {
        trComboTimer--;
        if (trComboTimer === 0) trCombo = 1;
    }

    const car = trGetCurrentCarStats();
    const rainBrakeFactor = trEnv === 'rain' ? 0.72 : 1.0;

    // 1. Zeitfahren-Countdown
    if (trMode === 'timeattack' && trTick % 60 === 0) {
        trTimeLeft--;
        if (trTimeLeft <= 0) {
            trEndRun('⏱️ ZEIT ABGELAUFEN!');
            return;
        }
    }

    // 2. Gas, Bremse & Lenkung
    if (trKeys['ArrowUp']) {
        trPlayer.kmh = Math.min(car.topKmh, trPlayer.kmh + car.accel);
    } else if (trKeys['ArrowDown']) {
        trPlayer.kmh = Math.max(35, trPlayer.kmh - car.brake * rainBrakeFactor);
    } else {
        // Sanftes Ausrollen auf Reisegeschwindigkeit (70 km/h)
        if (trPlayer.kmh > 70) trPlayer.kmh -= 0.14;
        else if (trPlayer.kmh < 70) trPlayer.kmh += 0.18;
    }

    trPlayer.tilt *= 0.82;
    if (trKeys['ArrowLeft']) {
        trPlayer.x = Math.max(92, trPlayer.x - car.handling);
        trPlayer.tilt = -0.09;
    }
    if (trKeys['ArrowRight']) {
        trPlayer.x = Math.min(368, trPlayer.x + car.handling);
        trPlayer.tilt = 0.09;
    }

    // 3. Gefahrene Distanz & laufende Punkte (ab 100 km/h schneller, im Gegenverkehr doppelt!)
    const meterStep = (trPlayer.kmh / 3.6) / 60;
    trDistMeters += meterStep;

    const inOncomingLane = (trMode === 'twoway' && trPlayer.x < 230);
    const speedMultiplier = trPlayer.kmh >= 100 ? (inOncomingLane ? 2.8 : 1.4) : 0.4;
    trScore += meterStep * speedMultiplier;

    // Straßenmarkierungen & Rand-Objekte scrollen
    const roadPxSpeed = trPlayer.kmh * 0.085;
    trRoadOffset = (trRoadOffset + roadPxSpeed) % 60;
    for (const sc of trScenery) {
        sc.y += roadPxSpeed;
        if (sc.y > 560) sc.y -= 560;
    }

    // 4. Neuen Verkehr spawnen
    if (trTick % Math.max(18, Math.floor(46 - trPlayer.kmh * 0.08)) === 0 && trTraffic.length < 9) {
        trSpawnVehicle();
    }

    // 5. KI-Verkehr bewegen, Spurwechsel & Close-Overtake-Prüfung
    for (let i = trTraffic.length - 1; i >= 0; i--) {
        const v = trTraffic[i];
        // Relativgeschwindigkeit zum Spieler:
        // Mitfahrender Verkehr (dir=1) kommt näher, wenn trPlayer.kmh > v.kmh
        // Gegenverkehr (dir=-1) rast mit (trPlayer.kmh + v.kmh) auf uns zu!
        const relKmh = v.dir === 1 ? (trPlayer.kmh - v.kmh) : (trPlayer.kmh + v.kmh);
        v.y += relKmh * 0.075;

        // Sanfter Spurwechsel, falls aktiv
        if (Math.abs(v.x - v.targetX) > 0.5) {
            v.x += Math.sign(v.targetX - v.x) * 2.2;
        }
        if (v.blinkerTicks > 0) v.blinkerTicks--;

        // Zufälliger KI-Spurwechsel mit Blinker
        if (v.dir === 1 && v.type !== 'truck' && v.blinkerTicks === 0 && v.y > 40 && v.y < 300 && Math.random() < 0.004) {
            const minLane = trMode === 'twoway' ? 2 : 0;
            const nextL = v.lane + (Math.random() < 0.5 ? -1 : 1);
            if (nextL >= minLane && nextL <= 3) {
                const blocked = trTraffic.some((o) => o !== v && o.lane === nextL && Math.abs(o.y - v.y) < 110);
                if (!blocked) {
                    v.blinker = nextL > v.lane ? 1 : -1;
                    v.blinkerTicks = 35;
                    v.lane = nextL;
                    v.targetX = TR_LANES[nextL];
                }
            }
        }

        // Im Polizei-Modus: Fluchtfahrzeug ("Suspect") durch dichtes Auffahren/Rammen stoppen!
        if (v.isSuspect && Math.abs(v.x - trPlayer.x) < 42 && Math.abs(v.y - trPlayer.y) < 88) {
            v.hp -= 2.2;
            if (v.hp <= 0) {
                trScore += 1200;
                trGarage.cash += 150;
                trPopups.push({ x: v.x, y: v.y, text: '🚓 RASER GESTOPPT! +1200', color: '#38bdf8', life: 50 });
                trTraffic.splice(i, 1);
                trUpdateHud();
                continue;
            }
        }

        // Close Overtake (Beinahe-Unfall-Überholmanöver ab 100 km/h!)
        if (!v.passed && v.y > trPlayer.y + 18) {
            v.passed = true;
            const lateralDist = Math.abs(v.x - trPlayer.x);
            if (trPlayer.kmh >= 100 && lateralDist < 68) {
                trOvertakes++;
                trCombo = Math.min(5, trCombo + (trComboTimer > 0 ? 1 : 0));
                trComboTimer = 155; // ~2.5 Sekunden für nächsten Overtake
                const bonus = (v.dir === -1 ? 200 : 100) * trCombo;
                trScore += bonus;
                trPopups.push({
                    x: trPlayer.x,
                    y: trPlayer.y - 38,
                    text: (v.dir === -1 ? '🔥 GEGENVERKEHR ×' : '⚡ CLOSE OVERTAKE ×') + trCombo + ' (+' + bonus + ')',
                    color: v.dir === -1 ? '#f43f5e' : '#fde047',
                    life: 44
                });
            }
        }

        // Kollisionsprüfung mit Spieler-Auto
        const hitX = Math.abs(v.x - trPlayer.x) < (v.w / 2 + trPlayer.w / 2 - 5);
        const hitY = Math.abs(v.y - trPlayer.y) < (v.h / 2 + trPlayer.h / 2 - 6);
        if (hitX && hitY && !v.isSuspect) {
            if (trMode === 'timeattack') {
                // Im Zeitfahren: starker Tempoverlust statt sofortigem Game Over
                trPlayer.kmh = 45;
                trCombo = 1;
                trPopups.push({ x: trPlayer.x, y: trPlayer.y - 30, text: '💥 CRASH! TEMPOVERLUST!', color: '#fb7185', life: 35 });
                trTraffic.splice(i, 1);
                continue;
            } else {
                trEndRun('💥 CRASH BEI ' + Math.round(trPlayer.kmh) + ' KM/H!');
                return;
            }
        }

        // Außerhalb des Bildschirms entfernen
        if (v.y > 640 || v.y < -220) {
            trTraffic.splice(i, 1);
        }
    }

    trUpdateHud();
}

function trDrawVehicle(ctx, x, y, w, h, color, stripeColor, dir, isTruck, blinker, blinkerTicks, isPolice, isPlayer, tilt = 0) {
    ctx.save();
    ctx.translate(x, y);
    if (tilt !== 0) ctx.rotate(tilt);
    if (dir === -1) ctx.scale(1, -1); // Gegenverkehr schaut nach unten

    // Schatten
    ctx.fillStyle = 'rgba(0, 0, 0, 0.45)';
    ctx.fillRect(-w / 2 + 3, -h / 2 + 4, w, h);

    // Reifen
    ctx.fillStyle = '#090d16';
    ctx.fillRect(-w / 2 - 2, -h / 2 + 8, 4, 12);
    ctx.fillRect(w / 2 - 2, -h / 2 + 8, 4, 12);
    ctx.fillRect(-w / 2 - 2, h / 2 - 20, 4, 12);
    ctx.fillRect(w / 2 - 2, h / 2 - 20, 4, 12);

    // Karosserie
    ctx.fillStyle = color;
    ctx.beginPath();
    ctx.roundRect(-w / 2, -h / 2, w, h, isTruck ? 4 : 8);
    ctx.fill();

    if (isTruck) {
        // Sattelzug-Führerhaus + heller Auflieger
        ctx.fillStyle = '#334155';
        ctx.fillRect(-w / 2 + 2, -h / 2 + 2, w - 4, 24);
        ctx.fillStyle = '#e2e8f0';
        ctx.fillRect(-w / 2 + 2, -h / 2 + 28, w - 4, h - 32);
    } else {
        // Rennstreifen
        if (stripeColor) {
            ctx.fillStyle = stripeColor;
            ctx.fillRect(-3, -h / 2 + 2, 6, h - 4);
        }
        // Windschutzscheibe & Heckscheibe
        ctx.fillStyle = '#0f172a';
        ctx.fillRect(-w / 2 + 5, -h / 2 + 14, w - 10, 14);
        ctx.fillRect(-w / 2 + 6, h / 2 - 18, w - 12, 10);
    }

    // Scheinwerfer vorn & Rücklichter hinten
    ctx.fillStyle = '#fef08a';
    ctx.fillRect(-w / 2 + 4, -h / 2, 7, 3);
    ctx.fillRect(w / 2 - 11, -h / 2, 7, 3);

    ctx.fillStyle = (isPlayer && trKeys['ArrowDown']) ? '#ff0000' : '#dc2626';
    ctx.fillRect(-w / 2 + 4, h / 2 - 3, 8, 3);
    ctx.fillRect(w / 2 - 12, h / 2 - 3, 8, 3);

    // Gelber Blinker beim Spurwechsel
    if (blinkerTicks > 0 && Math.floor(blinkerTicks / 4) % 2 === 0) {
        ctx.fillStyle = '#facc15';
        const bx = blinker < 0 ? -w / 2 - 1 : w / 2 - 4;
        ctx.fillRect(bx, -h / 2 + 2, 5, 5);
        ctx.fillRect(bx, h / 2 - 7, 5, 5);
    }

    // Polizei-Blaulichtbalken auf dem Dach
    if (isPolice) {
        const flashBlue = (trTick % 12) < 6;
        ctx.fillStyle = flashBlue ? '#3b82f6' : '#ef4444';
        ctx.fillRect(-10, -4, 10, 5);
        ctx.fillStyle = flashBlue ? '#ef4444' : '#3b82f6';
        ctx.fillRect(0, -4, 10, 5);
    }

    ctx.restore();
}

function trDraw() {
    const cvs = document.getElementById('trCanvas');
    if (!cvs) return;
    const ctx = cvs.getContext('2d');

    // 1. Umgebungs-Rand (Wüste, Nacht oder Regen)
    if (trEnv === 'desert') {
        ctx.fillStyle = '#d97706';
    } else if (trEnv === 'rain') {
        ctx.fillStyle = '#1e293b';
    } else {
        ctx.fillStyle = '#090d16';
    }
    ctx.fillRect(0, 0, cvs.width, cvs.height);

    // 2. 4-spurige Asphalt-Fahrbahn (x = 70 .. 390)
    ctx.fillStyle = trEnv === 'rain' ? '#1e293b' : '#18181b';
    ctx.fillRect(70, 0, 320, cvs.height);

    // Rot-weiße Randcurbs / Leitplanken
    for (let y = -60 + trRoadOffset; y < cvs.height; y += 30) {
        ctx.fillStyle = Math.floor((y - trRoadOffset) / 30) % 2 === 0 ? '#ef4444' : '#f8fafc';
        ctx.fillRect(62, y, 8, 30);
        ctx.fillRect(390, y, 8, 30);
    }

    // Spur-Markierungen (bei Gegenverkehr ist die Mittellinie bei x=230 doppelt durchgezogen gelb!)
    for (let laneIdx = 1; laneIdx <= 3; laneIdx++) {
        const lx = 70 + laneIdx * 80;
        if (laneIdx === 2 && trMode === 'twoway') {
            ctx.fillStyle = '#facc15';
            ctx.fillRect(lx - 4, 0, 2.5, cvs.height);
            ctx.fillRect(lx + 1.5, 0, 2.5, cvs.height);
        } else {
            ctx.fillStyle = 'rgba(248, 250, 252, 0.65)';
            for (let y = -60 + trRoadOffset; y < cvs.height; y += 60) {
                ctx.fillRect(lx - 1.5, y, 3, 32);
            }
        }
    }

    // Straßenrand-Dekoration (Palmen/Kakteen/Laternen)
    for (const sc of trScenery) {
        const sx = sc.side < 0 ? 32 : 428;
        if (sc.type === 'lamp') {
            ctx.fillStyle = '#94a3b8';
            ctx.fillRect(sx - 3, sc.y - 3, 6, 6);
            if (trEnv === 'night') {
                ctx.fillStyle = 'rgba(254, 240, 138, 0.16)';
                ctx.beginPath();
                ctx.arc(sc.side < 0 ? 68 : 392, sc.y, 28, 0, Math.PI * 2);
                ctx.fill();
            }
        } else {
            ctx.fillStyle = trEnv === 'desert' ? '#15803d' : '#065f46';
            ctx.beginPath();
            ctx.arc(sx, sc.y, 12, 0, Math.PI * 2);
            ctx.fill();
        }
    }

    // 3. Scheinwerfer-Lichtkegel bei Nacht oder Lichthupe
    if (trEnv === 'night' || trHornFlash > 0) {
        const beamGrad = ctx.createLinearGradient(trPlayer.x, trPlayer.y - 30, trPlayer.x, trPlayer.y - (trHornFlash > 0 ? 290 : 190));
        beamGrad.addColorStop(0, trHornFlash > 0 ? 'rgba(254, 249, 195, 0.55)' : 'rgba(254, 240, 138, 0.30)');
        beamGrad.addColorStop(1, 'rgba(254, 240, 138, 0)');
        ctx.fillStyle = beamGrad;
        ctx.beginPath();
        ctx.moveTo(trPlayer.x - 14, trPlayer.y - 32);
        ctx.lineTo(trPlayer.x - 52, trPlayer.y - (trHornFlash > 0 ? 290 : 190));
        ctx.lineTo(trPlayer.x + 52, trPlayer.y - (trHornFlash > 0 ? 290 : 190));
        ctx.lineTo(trPlayer.x + 14, trPlayer.y - 32);
        ctx.closePath();
        ctx.fill();
    }

    // 4. KI-Verkehr zeichnen
    for (const v of trTraffic) {
        trDrawVehicle(
            ctx,
            v.x,
            v.y,
            v.w,
            v.h,
            v.color,
            v.isSuspect ? '#fde047' : null,
            v.dir,
            v.type === 'truck',
            v.blinker,
            v.blinkerTicks,
            false,
            false,
            0
        );
        if (v.isSuspect) {
            ctx.fillStyle = '#fde047';
            ctx.font = 'bold 10px monospace';
            ctx.textAlign = 'center';
            ctx.fillText('🎯 RASER (' + Math.ceil(v.hp) + '%)', v.x, v.y - v.h / 2 - 8);
        }
    }

    // 5. Eigenes Fahrzeug zeichnen
    const myCar = trGetCurrentCarStats();
    trDrawVehicle(
        ctx,
        trPlayer.x,
        trPlayer.y,
        trPlayer.w,
        trPlayer.h,
        myCar.color,
        myCar.stripe,
        1,
        false,
        0,
        0,
        !!myCar.isPolice || trMode === 'police',
        true,
        trPlayer.tilt
    );

    // 6. Regentropfen bei Regen-Autobahn
    if (trEnv === 'rain') {
        ctx.strokeStyle = 'rgba(186, 230, 253, 0.40)';
        ctx.lineWidth = 1.5;
        for (let i = 0; i < 32; i++) {
            const rx = (i * 73 + trTick * 9) % cvs.width;
            const ry = (i * 97 + trTick * 17) % cvs.height;
            ctx.beginPath();
            ctx.moveTo(rx, ry);
            ctx.lineTo(rx - 3, ry + 14);
            ctx.stroke();
        }
    }

    // 7. Schwebende Close-Overtake-Popups
    for (let i = trPopups.length - 1; i >= 0; i--) {
        const p = trPopups[i];
        ctx.fillStyle = p.color;
        ctx.font = 'bold 13px monospace';
        ctx.textAlign = 'center';
        ctx.fillText(p.text, p.x, p.y);
        p.y -= 0.8;
        p.life--;
        if (p.life <= 0) trPopups.splice(i, 1);
    }

    // 8. Digital-Tacho oben links im Canvas (ab 100 km/h leuchtet "100+ BONUS AKTIV")
    ctx.fillStyle = 'rgba(15, 23, 42, 0.82)';
    ctx.fillRect(12, 12, 148, 42);
    ctx.fillStyle = trPlayer.kmh >= 100 ? '#fb923c' : '#34d399';
    ctx.font = 'bold 18px monospace';
    ctx.textAlign = 'left';
    ctx.fillText(Math.round(trPlayer.kmh) + ' KM/H', 20, 34);
    ctx.fillStyle = '#e2e8f0';
    ctx.font = 'bold 9px monospace';
    ctx.fillText(trPlayer.kmh >= 100 ? '⚡ OVERTAKE-BONUS AKTIV' : 'AB 100 KM/H: BONUS!', 20, 48);

    // 9. Start- / Crash-Banner
    if (!trRunning) {
        ctx.fillStyle = 'rgba(2, 6, 23, 0.82)';
        ctx.fillRect(0, cvs.height / 2 - 52, cvs.width, 104);
        ctx.textAlign = 'center';
        ctx.fillStyle = '#fb923c';
        ctx.font = 'bold 18px monospace';
        ctx.fillText(trBannerText || '🚘 CLOUD-TRAFFIC-RACER', cvs.width / 2, cvs.height / 2 - 14);
        ctx.fillStyle = '#e2e8f0';
        ctx.font = '12px sans-serif';
        ctx.fillText('Knappe Überholmanöver ab 100 km/h bringen Kombo-Punkte & 💰 Credits!', cvs.width / 2, cvs.height / 2 + 10);
        ctx.fillStyle = '#34d399';
        ctx.fillText('Klicke auf „▶️ Auf die Autobahn!“ oder drücke Taste H / Pfeiltasten', cvs.width / 2, cvs.height / 2 + 32);
    }
}

function trLoop() {
    if (!trRunning) {
        trDraw();
        return;
    }
    trStep();
    trDraw();
    if (trRunning) {
        trRafId = requestAnimationFrame(trLoop);
    }
}

// =============================================================================
// SPIEL 18: 🕵️‍♂️ IMPOSSIBLE MISSION (1984 C64-EDITION)
// =============================================================================
let imInitialized = false;
let imRunning     = false;
let imRafId       = null;
let imKeys        = {};
let imInElevator  = false;
let imRoomIdx     = 0;
let imElevatorY   = 0; // 0, 1, 2 (Ebenen) oder 3 (Kontrollraum unten)
let imTimeLeftSec = 6 * 3600; // 06:00:00 in Bunker-Sekunden (1 Echtzeit-Sek = 18 Bunker-Sek => 20 Min Echtzeit)
let imStartMs     = 0;
let imScore       = 0;
let imPiecesFound = 0;
let imSnoozeCount = 2;
let imLiftCount   = 2;
let imSnoozeTimer = 0;
let imBannerMsg   = 'ANOTHER VISITOR! STAY A WHILE… STAY FOREVER!';
let imSpeechText  = '';
let imSpeechTimer = 0;
let imDeathTimer  = 0;
let imPassword    = ['A','T','O','M','B','E','N','D','R'];
let imFoundMask   = [false,false,false,false,false,false,false,false,false];

const IM_ROOM_THEMES = [
    { name: 'Sektor 1: Cyan-Labor',         wall: '#0e7490', bg: '#082f49', plat: '#22d3ee', accent: '#67e8f9' },
    { name: 'Sektor 2: Violett-Serverhalle',wall: '#6d28d9', bg: '#2e1065', plat: '#c084fc', accent: '#e9d5ff' },
    { name: 'Sektor 3: Bernstein-Generator',wall: '#b45309', bg: '#451a03', plat: '#fbbf24', accent: '#fde68a' },
    { name: 'Sektor 4: Smaragd-Bio-Dom',    wall: '#047857', bg: '#022c22', plat: '#34d399', accent: '#a7f3d0' },
    { name: 'Sektor 5: Karmin-Reaktor',     wall: '#be123c', bg: '#4c0519', plat: '#fb7185', accent: '#fecdd3' },
    { name: 'Sektor 6: Kobalt-Tresor',      wall: '#1d4ed8', bg: '#172554', plat: '#60a5fa', accent: '#bfdbfe' }
];

let imRooms = [];
let imAgent = {
    x: 60, y: 290, vx: 0, vy: 0, dir: 1,
    onGround: true, jumping: false, somersaultAngle: 0,
    runFrame: 0, searchingObj: null, searchProgress: 0
};

function imSpeak(text) {
    imSpeechText  = text.toUpperCase();
    imSpeechTimer = 180;
    try {
        if ('speechSynthesis' in window) {
            window.speechSynthesis.cancel();
            const u = new SpeechSynthesisUtterance(text);
            u.lang  = 'en-US';
            u.pitch = 0.45;
            u.rate  = 0.82;
            window.speechSynthesis.speak(u);
        }
    } catch (e) {}
    // C64 SID Roboter-Stimmen-Synthese als authentischer Begleitklang
    try {
        const AudioCtx = window.AudioContext || window.webkitAudioContext;
        if (!window._imAudio) window._imAudio = new AudioCtx();
        const actx = window._imAudio;
        const notes = [110, 98, 123, 87, 110, 73, 65];
        notes.forEach((f, idx) => {
            const osc = actx.createOscillator();
            const g   = actx.createGain();
            osc.type = 'sawtooth';
            osc.frequency.setValueAtTime(f, actx.currentTime + idx * 0.11);
            g.gain.setValueAtTime(0.05, actx.currentTime + idx * 0.11);
            g.gain.exponentialRampToValueAtTime(0.001, actx.currentTime + idx * 0.11 + 0.1);
            osc.connect(g);
            g.connect(actx.destination);
            osc.start(actx.currentTime + idx * 0.11);
            osc.stop(actx.currentTime + idx * 0.11 + 0.1);
        });
    } catch (e) {}
}

function imPlaySfx(type) {
    try {
        const AudioCtx = window.AudioContext || window.webkitAudioContext;
        if (!window._imAudio) window._imAudio = new AudioCtx();
        const actx = window._imAudio;
        const osc  = actx.createOscillator();
        const gain = actx.createGain();
        osc.connect(gain);
        gain.connect(actx.destination);

        if (type === 'jump') {
            osc.type = 'triangle';
            osc.frequency.setValueAtTime(220, actx.currentTime);
            osc.frequency.exponentialRampToValueAtTime(520, actx.currentTime + 0.16);
            gain.gain.setValueAtTime(0.07, actx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.001, actx.currentTime + 0.17);
            osc.start();
            osc.stop(actx.currentTime + 0.17);
        } else if (type === 'zap') {
            osc.type = 'sawtooth';
            osc.frequency.setValueAtTime(160, actx.currentTime);
            osc.frequency.linearRampToValueAtTime(55, actx.currentTime + 0.35);
            gain.gain.setValueAtTime(0.12, actx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.001, actx.currentTime + 0.36);
            osc.start();
            osc.stop(actx.currentTime + 0.36);
        } else if (type === 'scream') {
            osc.type = 'sawtooth';
            osc.frequency.setValueAtTime(620, actx.currentTime);
            osc.frequency.exponentialRampToValueAtTime(110, actx.currentTime + 0.65);
            gain.gain.setValueAtTime(0.1, actx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.001, actx.currentTime + 0.66);
            osc.start();
            osc.stop(actx.currentTime + 0.66);
        } else if (type === 'found') {
            osc.type = 'square';
            osc.frequency.setValueAtTime(523, actx.currentTime);
            osc.frequency.setValueAtTime(659, actx.currentTime + 0.08);
            osc.frequency.setValueAtTime(784, actx.currentTime + 0.16);
            gain.gain.setValueAtTime(0.08, actx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.001, actx.currentTime + 0.28);
            osc.start();
            osc.stop(actx.currentTime + 0.28);
        }
    } catch (e) {}
}

function imBuildBunkerRooms() {
    // 24 Möbelstücke über 6 Räume verteilt: 9 Lochkarten-Teile, 5 Snooze, 4 Lift-Init, 6 Nieten
    const lootPool = [
        'piece_0','piece_1','piece_2','piece_3','piece_4','piece_5','piece_6','piece_7','piece_8',
        'snooze','snooze','snooze','snooze','snooze',
        'lift','lift','lift','lift',
        'empty','empty','empty','empty','empty','empty'
    ];
    for (let i = lootPool.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [lootPool[i], lootPool[j]] = [lootPool[j], lootPool[i]];
    }
    let lootCursor = 0;

    imRooms = IM_ROOM_THEMES.map((theme, idx) => {
        const platforms = [
            // Untere Hauptebene mit Abgrund-Lücke
            { x: 0,   y: 320, w: 250, isLift: false },
            { x: 330, y: 320, w: 180, isLift: false },
            { x: 580, y: 320, w: 180, isLift: false },
            // Mittlere Ebene
            { x: 70,  y: 225, w: 180, isLift: false },
            { x: 460, y: 225, w: 220, isLift: false },
            // Obere Ebene
            { x: 110, y: 130, w: 230, isLift: false },
            { x: 440, y: 130, w: 230, isLift: false },
            // 2 Vertikale Lift-Plattformen im Raum
            { x: 255, y: 225, w: 70, isLift: true, initialY: 225, stops: [130, 225, 320] },
            { x: 375, y: 130, w: 65, isLift: true, initialY: 130, stops: [130, 225, 320] }
        ];

        const furnitureTypes = [
            { kind: 'terminal', label: 'C64-Mainframe',  w: 34, h: 42, color: '#38bdf8' },
            { kind: 'tapedrive',label: 'Magnetband-Schrank', w: 32, h: 48, color: '#a855f7' },
            { kind: 'desk',     label: 'Chefschreibtisch',   w: 44, h: 32, color: '#f59e0b' },
            { kind: 'vending',  label: 'Getränkeautomat',    w: 30, h: 48, color: '#ef4444' }
        ];

        const furnPositions = [
            { x: 135, y: 225 },
            { x: 545, y: 225 },
            { x: 185, y: 130 },
            { x: 530, y: 130 }
        ];

        const furniture = furnPositions.map((pos, fIdx) => {
            const ft = furnitureTypes[(idx + fIdx) % furnitureTypes.length];
            return {
                x: pos.x,
                y: pos.y - ft.h,
                w: ft.w,
                h: ft.h,
                kind: ft.kind,
                label: ft.label,
                color: ft.color,
                loot: lootPool[lootCursor++],
                searched: false
            };
        });

        const robots = [
            { x: 380, y: 320, minX: 340, maxX: 490, vx: 1.35 + idx * 0.1, dir: 1, beamTimer: 40 },
            { x: 500, y: 225, minX: 470, maxX: 655, vx: 1.5  + idx * 0.1, dir: -1, beamTimer: 95 },
            { x: 150, y: 130, minX: 120, maxX: 315, vx: 1.45 + idx * 0.1, dir: 1, beamTimer: 70 }
        ];

        const orb = {
            x: 380, y: 180,
            vx: (idx % 2 === 0 ? 1.2 : -1.2),
            vy: 0.85
        };

        return { idx, theme, platforms, furniture, robots, orb };
    });
}

function imInit() {
    if (imInitialized) return;
    imInitialized = true;

    window.addEventListener('keydown', (e) => {
        if (activeGameKey !== 'impossible') return;
        if (['ArrowLeft','ArrowRight','ArrowUp','ArrowDown',' '].includes(e.key)) {
            e.preventDefault();
        }
        imKeys[e.key] = true;
        if (e.key === ' ') imTriggerJump();
        if (e.key.toLowerCase() === 's') imUseSnooze();
        if (e.key.toLowerCase() === 'l') imUseLiftInit();
        if (e.key.toLowerCase() === 'm') imToggleMap();
    });

    window.addEventListener('keyup', (e) => {
        imKeys[e.key] = false;
    });

    const cvs = document.getElementById('imCanvas');
    if (cvs) {
        cvs.addEventListener('click', (ev) => {
            if (!imRunning) {
                imStartGame();
                return;
            }
            if (imInElevator) {
                const rect = cvs.getBoundingClientRect();
                const mx = (ev.clientX - rect.left) * (cvs.width / rect.width);
                const my = (ev.clientY - rect.top) * (cvs.height / rect.height);
                // Klick auf einen der 6 Raum-Zugänge im Aufzugschacht
                for (let r = 0; r < 6; r++) {
                    const floor = Math.floor(r / 2);
                    const isRight = (r % 2 === 1);
                    const bx = isRight ? 470 : 70;
                    const by = 45 + floor * 92;
                    if (mx >= bx && mx <= bx + 220 && my >= by && my <= by + 72) {
                        imEnterRoom(r, isRight ? 'left' : 'right');
                        return;
                    }
                }
                // Klick unten auf den Master-Kontrollraum
                if (my >= 315 && my <= 365 && mx >= 250 && mx <= 510) {
                    imTryEnterControlRoom();
                }
            }
        });
    }

    imBuildBunkerRooms();
    imDraw();
}

function imStartGame() {
    imBuildBunkerRooms();
    imRunning     = true;
    imInElevator  = false;
    imRoomIdx     = 0;
    imElevatorY   = 0;
    imTimeLeftSec = 6 * 3600;
    imStartMs     = performance.now();
    imScore       = 0;
    imPiecesFound = 0;
    imSnoozeCount = 2;
    imLiftCount   = 2;
    imSnoozeTimer = 0;
    imDeathTimer  = 0;
    imFoundMask   = [false,false,false,false,false,false,false,false,false];

    imEnterRoom(0, 'left');
    imSpeak('Another visitor! Stay a while... stay forever!');
    imSyncHud();

    if (imRafId) cancelAnimationFrame(imRafId);
    imRafId = requestAnimationFrame(imLoop);
}

function imEnterRoom(idx, side = 'left') {
    imRoomIdx    = idx;
    imInElevator = false;
    imElevatorY  = Math.floor(idx / 2);
    imAgent.x    = side === 'left' ? 42 : 700;
    imAgent.y    = 320;
    imAgent.vx   = 0;
    imAgent.vy   = 0;
    imAgent.jumping = false;
    imAgent.onGround = true;
    imAgent.searchingObj = null;
    imAgent.searchProgress = 0;
    imSyncHud();
}

function imToggleMap() {
    if (!imRunning) return;
    imInElevator = !imInElevator;
    imSyncHud();
}

function imUseSnooze() {
    if (!imRunning || imInElevator || imSnoozeCount <= 0) return;
    imSnoozeCount--;
    imSnoozeTimer = 480; // 8 Sekunden bei 60 FPS
    imBannerMsg   = '💤 SNOOZE AKTIV: ALLE ROBOTER FÜR 8 SEK. DEAKTIVIERT!';
    imPlaySfx('found');
    imSyncHud();
}

function imUseLiftInit() {
    if (!imRunning || imInElevator || imLiftCount <= 0) return;
    imLiftCount--;
    const room = imRooms[imRoomIdx];
    room.platforms.forEach(p => {
        if (p.isLift) p.y = p.initialY;
    });
    imBannerMsg = '🛗 LIFT-INIT: PLATTFORMEN ZURÜCKGESETZT!';
    imPlaySfx('found');
    imSyncHud();
}

function imTriggerJump() {
    if (!imRunning || imInElevator || imDeathTimer > 0) return;
    if (imAgent.onGround) {
        imAgent.vy = -8.6;
        imAgent.vx = imAgent.dir * 4.5;
        imAgent.onGround = false;
        imAgent.jumping  = true;
        imAgent.somersaultAngle = 0;
        imPlaySfx('jump');
    }
}

function imFormatClock(sec) {
    sec = Math.max(0, Math.floor(sec));
    const h = Math.floor(sec / 3600);
    const m = Math.floor((sec % 3600) / 60);
    const s = sec % 60;
    return `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
}

function imSyncHud() {
    const tEl = document.getElementById('imTimeHud');
    const pEl = document.getElementById('imPiecesHud');
    const rEl = document.getElementById('imRoomHud');
    const sEl = document.getElementById('imSnoozeHud');
    const lEl = document.getElementById('imLiftHud');
    if (tEl) tEl.textContent = imFormatClock(imTimeLeftSec);
    if (pEl) pEl.textContent = `${imPiecesFound} / 9`;
    if (rEl) rEl.textContent = imInElevator ? '🛗 Haupt-Aufzug' : IM_ROOM_THEMES[imRoomIdx].name;
    if (sEl) sEl.textContent = String(imSnoozeCount);
    if (lEl) lEl.textContent = String(imLiftCount);
}

function imKillAgent(reason) {
    if (imDeathTimer > 0) return;
    imDeathTimer  = 55;
    imTimeLeftSec = Math.max(0, imTimeLeftSec - 600); // -10 Minuten Zeitstrafe
    imBannerMsg   = `⚡ ${reason} (-10 MINUTEN ZEITSTRAFE!)`;
    if (reason.includes('ABGRUND')) {
        imPlaySfx('scream');
    } else {
        imPlaySfx('zap');
        imSpeak('Destroy him, my robots!');
    }
    imSyncHud();
}

function imTryEnterControlRoom() {
    if (imPiecesFound < 9) {
        imBannerMsg = `🔒 KONTROLLRAUM GESPERRT! DU BRAUCHST ALLE 9 LOCHKARTEN (${imPiecesFound}/9).`;
        imPlaySfx('zap');
        return;
    }
    // Mission erfolgreich!
    imRunning = false;
    const bonus = Math.floor(imTimeLeftSec * 0.5);
    imScore += 5000 + bonus;
    const durSec = Math.max(1, Math.round((performance.now() - imStartMs) / 1000));
    imBannerMsg = `🏆 MISSION ERFÜLLT! ELVIN ATOMBENDER GESTOPPT! SCORE: ${imScore}`;
    imSpeak('Impossible! You have defeated Elvin Atombender!');
    submitCloudScore('impossible', imScore, durSec, `Passwort komplett · ${imFormatClock(imTimeLeftSec)} Restzeit`);
    imDraw();
}

function imStep() {
    // Zeit läuft ab (ca. 18 Bunker-Sekunden pro Echtzeit-Sekunde => 0,3 pro Frame)
    imTimeLeftSec -= 0.3;
    if (imTimeLeftSec <= 0) {
        imTimeLeftSec = 0;
        imRunning = false;
        imBannerMsg = '💥 ZEIT ABGELAUFEN! ELVIN ATOMBENDERS RAKETEN SIND GESTARTET!';
        imSpeak('Destroy him, my robots!');
        if (imScore > 0) {
            const durSec = Math.max(1, Math.round((performance.now() - imStartMs) / 1000));
            submitCloudScore('impossible', imScore, durSec, `${imPiecesFound}/9 Lochkarten`);
        }
        imSyncHud();
        return;
    }

    if (imSpeechTimer > 0) imSpeechTimer--;
    if (imSnoozeTimer > 0) imSnoozeTimer--;

    if (imDeathTimer > 0) {
        imDeathTimer--;
        if (imDeathTimer === 0) {
            imAgent.x = 42;
            imAgent.y = 320;
            imAgent.vx = 0;
            imAgent.vy = 0;
            imAgent.jumping = false;
            imAgent.onGround = true;
        }
        return;
    }

    // Modus A: Im Aufzugschacht
    if (imInElevator) {
        if (imKeys['ArrowUp'] || imKeys['w'] || imKeys['W']) {
            imKeys['ArrowUp'] = false;
            imElevatorY = Math.max(0, imElevatorY - 1);
        } else if (imKeys['ArrowDown'] || imKeys['s'] || imKeys['S']) {
            imKeys['ArrowDown'] = false;
            imElevatorY = Math.min(3, imElevatorY + 1);
        } else if (imKeys['ArrowLeft'] || imKeys['a'] || imKeys['A']) {
            imKeys['ArrowLeft'] = false;
            if (imElevatorY < 3) imEnterRoom(imElevatorY * 2, 'right');
        } else if (imKeys['ArrowRight'] || imKeys['d'] || imKeys['D']) {
            imKeys['ArrowRight'] = false;
            if (imElevatorY < 3) imEnterRoom(imElevatorY * 2 + 1, 'left');
        } else if (imKeys['Enter'] || imKeys[' ']) {
            imKeys['Enter'] = false;
            if (imElevatorY === 3) imTryEnterControlRoom();
        }
        return;
    }

    const room = imRooms[imRoomIdx];

    // 1. Agenten-Bewegung
    const moveLeft  = imKeys['ArrowLeft']  || imKeys['a'] || imKeys['A'];
    const moveRight = imKeys['ArrowRight'] || imKeys['d'] || imKeys['D'];
    const holdUp    = imKeys['ArrowUp']    || imKeys['w'] || imKeys['W'] || imKeys['e'] || imKeys['E'];
    const holdDown  = imKeys['ArrowDown'];

    if (imAgent.onGround) {
        if (moveLeft) {
            imAgent.vx = -3.4;
            imAgent.dir = -1;
            imAgent.runFrame += 0.25;
        } else if (moveRight) {
            imAgent.vx = 3.4;
            imAgent.dir = 1;
            imAgent.runFrame += 0.25;
        } else {
            imAgent.vx = 0;
        }
    } else {
        imAgent.somersaultAngle += 0.23 * imAgent.dir;
    }

    const prevY = imAgent.y;
    imAgent.x  += imAgent.vx;
    imAgent.vy += 0.42; // Gravitation
    imAgent.y  += imAgent.vy;

    // Raum-Ausgang links oder rechts -> zurück in den Aufzugschacht!
    if (imAgent.x < 10) {
        imInElevator = true;
        imSyncHud();
        return;
    }
    if (imAgent.x > 750) {
        imInElevator = true;
        imSyncHud();
        return;
    }

    // Plattform-Kollision
    imAgent.onGround = false;
    let standingLift = null;
    if (imAgent.vy >= 0) {
        for (const p of room.platforms) {
            if (imAgent.x >= p.x + 6 && imAgent.x <= p.x + p.w - 6) {
                if (prevY <= p.y + 4 && imAgent.y >= p.y) {
                    imAgent.y = p.y;
                    imAgent.vy = 0;
                    imAgent.onGround = true;
                    imAgent.jumping  = false;
                    imAgent.somersaultAngle = 0;
                    if (p.isLift) standingLift = p;
                    break;
                }
            }
        }
    }

    // Auf einer Lift-Plattform nach oben/unten fahren
    if (standingLift && imAgent.vx === 0) {
        if (holdUp && standingLift.y > standingLift.stops[0]) {
            standingLift.y -= 2.0;
            imAgent.y = standingLift.y;
        } else if (holdDown && standingLift.y < standingLift.stops[standingLift.stops.length - 1]) {
            standingLift.y += 2.0;
            imAgent.y = standingLift.y;
        }
    }

    // Sturz in den Abgrund
    if (imAgent.y > 368) {
        imKillAgent('STURZ IN DEN ABGRUND!');
        return;
    }

    // 2. Möbelstücke durchsuchen (SEARCHING...)
    let nearFurn = null;
    for (const f of room.furniture) {
        if (!f.searched && Math.abs(imAgent.x - (f.x + f.w / 2)) < 26 && Math.abs(imAgent.y - (f.y + f.h)) < 14) {
            nearFurn = f;
            break;
        }
    }

    if (nearFurn && holdUp && imAgent.onGround && imAgent.vx === 0) {
        imAgent.searchingObj = nearFurn;
        imAgent.searchProgress += 1.8;
        if (imAgent.searchProgress >= 100) {
            nearFurn.searched = true;
            imAgent.searchProgress = 0;
            imAgent.searchingObj = null;
            imPlaySfx('found');

            if (nearFurn.loot.startsWith('piece_')) {
                const pIdx = parseInt(nearFurn.loot.split('_')[1], 10);
                if (!imFoundMask[pIdx]) {
                    imFoundMask[pIdx] = true;
                    imPiecesFound++;
                }
                imScore += 500;
                imBannerMsg = `💳 LOCHKARTE GEFUNDEN! BUCHSTABE „${imPassword[pIdx]}“ (${imPiecesFound}/9)`;
                if (imPiecesFound === 9) {
                    imBannerMsg = '🔓 ALLE 9 LOCHKARTEN KOMPLETT! GEHE ZUM AUFZUG -> KONTROLLRAUM UNTEN!';
                    imSpeak('Access granted!');
                }
            } else if (nearFurn.loot === 'snooze') {
                imSnoozeCount++;
                imScore += 200;
                imBannerMsg = '💤 SNOOZE-CODE GEFUNDEN! (DRÜCKE S ZUM EINFRIEREN DER ROBOTER)';
            } else if (nearFurn.loot === 'lift') {
                imLiftCount++;
                imScore += 150;
                imBannerMsg = '🛗 LIFT-INIT GEFUNDEN! (DRÜCKE L ZUM PLATTFORM-RESET)';
            } else {
                imScore += 50;
                imBannerMsg = `🔍 ${nearFurn.label.toUpperCase()} DURCHSUCHT: NICHTS GEFUNDEN.`;
            }
            imSyncHud();
        }
    } else {
        imAgent.searchingObj   = null;
        imAgent.searchProgress = 0;
    }

    // 3. Sicherheits-Roboter & Plasma-Kugel bewegen (sofern nicht im Snooze-Modus)
    if (imSnoozeTimer <= 0) {
        for (const r of room.robots) {
            r.x += r.vx * r.dir;
            if (r.x <= r.minX) { r.x = r.minX; r.dir = 1; }
            if (r.x >= r.maxX) { r.x = r.maxX; r.dir = -1; }
            r.beamTimer = (r.beamTimer + 1) % 150;
            const firing = r.beamTimer > 105;

            // Kollision mit Roboter-Körper
            if (Math.abs(imAgent.x - r.x) < 18 && Math.abs((imAgent.y - 18) - (r.y - 18)) < 20) {
                imKillAgent('KOLLISION MIT KILLER-ROBOTER!');
                return;
            }
            // Kollision mit Hochspannungs-Elektrostrahl
            if (firing) {
                const beamX1 = r.dir > 0 ? r.x + 12 : r.x - 58;
                const beamX2 = r.dir > 0 ? r.x + 58 : r.x - 12;
                if (imAgent.x >= beamX1 && imAgent.x <= beamX2 && Math.abs(imAgent.y - r.y) < 22) {
                    imKillAgent('VOM ELEKTRO-LASER GETROFFEN!');
                    return;
                }
            }
        }

        // Schwebende Plasma-Kugel
        const orb = room.orb;
        orb.x += orb.vx;
        orb.y += orb.vy;
        if (orb.x < 45 || orb.x > 715) orb.vx *= -1;
        if (orb.y < 55 || orb.y > 305) orb.vy *= -1;

        if (Math.hypot(imAgent.x - orb.x, (imAgent.y - 18) - orb.y) < 20) {
            imKillAgent('VON SCHWARZER PLASMA-KUGEL ERFASST!');
            return;
        }
    }

    if (Math.random() < 0.05) imSyncHud();
}

function imDraw() {
    const cvs = document.getElementById('imCanvas');
    if (!cvs) return;
    const ctx = cvs.getContext('2d');

    // 1. Oberes Spielfeld (y = 0..365)
    if (imInElevator) {
        // AUFZUGSCHACHT & BUNKER-KARTE
        ctx.fillStyle = '#090d16';
        ctx.fillRect(0, 0, cvs.width, 365);

        // Zentraler Aufzugsschacht
        ctx.fillStyle = '#1e293b';
        ctx.fillRect(330, 15, 100, 345);
        ctx.strokeStyle = '#475569';
        ctx.lineWidth = 3;
        ctx.strokeRect(330, 15, 100, 345);

        // 6 Räume links & rechts (3 Etagen)
        for (let r = 0; r < 6; r++) {
            const floor   = Math.floor(r / 2);
            const isRight = (r % 2 === 1);
            const bx = isRight ? 470 : 70;
            const by = 40 + floor * 92;
            const rm = imRooms[r];
            const remainingLoot = rm ? rm.furniture.filter(f => !f.searched).length : 0;

            // Korridor zum Schacht
            ctx.fillStyle = '#334155';
            ctx.fillRect(isRight ? 430 : 290, by + 26, 40, 26);

            // Raum-Kachel
            ctx.fillStyle = rm.theme.bg;
            ctx.fillRect(bx, by, 220, 70);
            ctx.strokeStyle = rm.theme.plat;
            ctx.lineWidth = 2;
            ctx.strokeRect(bx, by, 220, 70);

            ctx.fillStyle = '#ffffff';
            ctx.font = 'bold 12px monospace';
            ctx.textAlign = 'left';
            ctx.fillText(rm.theme.name, bx + 10, by + 24);

            ctx.fillStyle = remainingLoot > 0 ? '#fde047' : '#34d399';
            ctx.font = '11px monospace';
            ctx.fillText(remainingLoot > 0 ? `🔍 Noch ${remainingLoot} Objekte offen` : '✓ Komplett durchsucht', bx + 10, by + 45);
            ctx.fillStyle = '#94a3b8';
            ctx.font = '10px sans-serif';
            ctx.fillText('Klicken oder Pfeil L/R zum Betreten', bx + 10, by + 61);
        }

        // Unten: Elvin Atombenders Kontrollraum
        const unlocked = imPiecesFound >= 9;
        ctx.fillStyle = unlocked ? '#065f46' : '#450a0a';
        ctx.fillRect(250, 316, 260, 42);
        ctx.strokeStyle = unlocked ? '#34d399' : '#f87171';
        ctx.strokeRect(250, 316, 260, 42);
        ctx.fillStyle = '#ffffff';
        ctx.font = 'bold 12px monospace';
        ctx.textAlign = 'center';
        ctx.fillText(unlocked ? '🔓 KONTROLLRAUM OFFEN (HIER KLICKEN!)' : `🔒 ELVIN ATOMBENDER (${imPiecesFound}/9 LOCHKARTEN)`, 380, 341);

        // Aufzugkabine mit Agent 4125
        const cabY = imElevatorY < 3 ? (52 + imElevatorY * 92) : 292;
        ctx.fillStyle = '#e2e8f0';
        ctx.fillRect(342, cabY, 76, 44);
        ctx.fillStyle = '#0284c7';
        ctx.fillRect(374, cabY + 12, 12, 28);
    } else {
        // RAUM-ANSICHT (C64 Bunker-Raum)
        const room = imRooms[imRoomIdx];
        ctx.fillStyle = room.theme.bg;
        ctx.fillRect(0, 0, cvs.width, 365);

        // C64 Seitenwände & Tür-Öffnungen
        ctx.fillStyle = room.theme.wall;
        ctx.fillRect(0, 0, 18, 265);
        ctx.fillRect(742, 0, 18, 265);
        ctx.fillRect(0, 0, cvs.width, 16);

        // Plattformen & Lifts
        room.platforms.forEach(p => {
            ctx.fillStyle = p.isLift ? '#f59e0b' : room.theme.plat;
            ctx.fillRect(p.x, p.y, p.w, 10);
            ctx.fillStyle = '#0f172a';
            for (let gx = p.x + 6; gx < p.x + p.w - 6; gx += 12) {
                ctx.fillRect(gx, p.y + 3, 6, 4);
            }
        });

        // Möbelstücke zum Durchsuchen
        room.furniture.forEach(f => {
            ctx.fillStyle = f.searched ? '#334155' : f.color;
            ctx.fillRect(f.x, f.y, f.w, f.h);
            ctx.strokeStyle = f.searched ? '#475569' : '#ffffff';
            ctx.lineWidth = 1.5;
            ctx.strokeRect(f.x, f.y, f.w, f.h);

            // Blinkendes Terminal-Display bei noch nicht durchsuchten Objekten
            if (!f.searched) {
                ctx.fillStyle = '#fde047';
                ctx.fillRect(f.x + 6, f.y + 6, f.w - 12, 10);
                ctx.font = 'bold 9px monospace';
                ctx.textAlign = 'center';
                ctx.fillText('🔍', f.x + f.w / 2, f.y - 4);
            }
        });

        // Dalek-artige C64 Killer-Roboter
        room.robots.forEach(r => {
            ctx.save();
            ctx.translate(r.x, r.y);
            // Metallischer Kegel-Körper
            ctx.fillStyle = imSnoozeTimer > 0 ? '#64748b' : '#cbd5e1';
            ctx.beginPath();
            ctx.moveTo(-14, 0);
            ctx.lineTo(-9, -34);
            ctx.lineTo(9, -34);
            ctx.lineTo(14, 0);
            ctx.closePath();
            ctx.fill();

            // Leuchtendes Sensor-Auge
            ctx.fillStyle = imSnoozeTimer > 0 ? '#38bdf8' : '#ef4444';
            ctx.fillRect(r.dir > 0 ? 2 : -10, -29, 8, 5);

            // Ringe am Roboter
            ctx.fillStyle = '#1e293b';
            ctx.fillRect(-11, -18, 22, 3);
            ctx.fillRect(-12, -9, 24, 3);

            // Hochspannungs-Elektroblitz beim Feuern
            if (imSnoozeTimer <= 0 && r.beamTimer > 105) {
                ctx.strokeStyle = (r.beamTimer % 4 < 2) ? '#fde047' : '#22d3ee';
                ctx.lineWidth = 2.5;
                ctx.beginPath();
                const startX = r.dir > 0 ? 12 : -12;
                const endX   = r.dir > 0 ? 58 : -58;
                ctx.moveTo(startX, -20);
                for (let s = 1; s <= 4; s++) {
                    const bx = startX + ((endX - startX) * s) / 4;
                    const by = -20 + (Math.random() * 14 - 7);
                    ctx.lineTo(bx, by);
                }
                ctx.stroke();
            }
            ctx.restore();
        });

        // Schwarze Plasma-Energiekugel
        const orb = room.orb;
        ctx.save();
        ctx.beginPath();
        ctx.arc(orb.x, orb.y, 12, 0, Math.PI * 2);
        ctx.fillStyle = '#020617';
        ctx.fill();
        ctx.lineWidth = 2.5;
        ctx.strokeStyle = imSnoozeTimer > 0 ? '#38bdf8' : '#f43f5e';
        ctx.stroke();
        ctx.restore();

        // Agent 4125 zeichnen (inkl. Salto-Rotation im Sprung!)
        ctx.save();
        ctx.translate(imAgent.x, imAgent.y - 18);
        if (imAgent.jumping) {
            ctx.rotate(imAgent.somersaultAngle);
        }
        // Kopf & Agenten-Anzug
        ctx.fillStyle = '#fde68a';
        ctx.fillRect(-5, -16, 10, 9);
        ctx.fillStyle = '#f8fafc';
        ctx.fillRect(-6, -7, 12, 14);
        // Beine (animiert beim Laufen)
        const legSwing = Math.sin(imAgent.runFrame) * 6;
        ctx.fillStyle = '#38bdf8';
        ctx.fillRect(-5 + legSwing * 0.4, 7, 4, 11);
        ctx.fillRect(1 - legSwing * 0.4, 7, 4, 11);
        ctx.restore();

        // SEARCHING... Fortschrittsbalken
        if (imAgent.searchingObj) {
            const f = imAgent.searchingObj;
            ctx.fillStyle = 'rgba(2, 6, 23, 0.9)';
            ctx.fillRect(f.x - 28, f.y - 34, 96, 24);
            ctx.strokeStyle = '#fde047';
            ctx.strokeRect(f.x - 28, f.y - 34, 96, 24);
            ctx.fillStyle = '#fde047';
            ctx.font = 'bold 9px monospace';
            ctx.textAlign = 'center';
            ctx.fillText('SEARCHING...', f.x + 20, f.y - 23);
            ctx.fillStyle = '#22c55e';
            ctx.fillRect(f.x - 24, f.y - 18, (88 * imAgent.searchProgress) / 100, 5);
        }
    }

    // 2. Unteres C64 Pocket-Computer-Cockpit (y = 365..470)
    ctx.fillStyle = '#1e293b';
    ctx.fillRect(0, 365, cvs.width, 105);
    ctx.strokeStyle = '#38bdf8';
    ctx.lineWidth = 2;
    ctx.strokeRect(2, 367, cvs.width - 4, 101);

    // 9 Lochkarten-Slots (Passwort-Puzzle)
    ctx.fillStyle = '#94a3b8';
    ctx.font = 'bold 11px monospace';
    ctx.textAlign = 'left';
    ctx.fillText('📟 C64 POCKET COMPUTER – MASTER-PASSWORT LOCHKARTEN:', 18, 388);

    for (let i = 0; i < 9; i++) {
        const sx = 18 + i * 52;
        const sy = 398;
        const found = imFoundMask[i];
        ctx.fillStyle = found ? '#065f46' : '#0f172a';
        ctx.fillRect(sx, sy, 44, 42);
        ctx.strokeStyle = found ? '#34d399' : '#475569';
        ctx.strokeRect(sx, sy, 44, 42);

        if (found) {
            ctx.fillStyle = '#fde047';
            ctx.font = 'bold 20px monospace';
            ctx.textAlign = 'center';
            ctx.fillText(imPassword[i], sx + 22, sy + 28);
        } else {
            ctx.fillStyle = '#475569';
            ctx.font = '12px monospace';
            ctx.textAlign = 'center';
            ctx.fillText('?', sx + 22, sy + 26);
        }
    }

    // Status-Meldung & Sprach-Untertitel rechts im Pocket-Computer
    ctx.fillStyle = '#0f172a';
    ctx.fillRect(495, 380, 250, 62);
    ctx.strokeStyle = '#475569';
    ctx.strokeRect(495, 380, 250, 62);

    ctx.fillStyle = '#38bdf8';
    ctx.font = 'bold 10px monospace';
    ctx.textAlign = 'left';
    ctx.fillText(imSpeechTimer > 0 ? '🎙️ "' + imSpeechText.slice(0, 30) + '"' : '📡 MISSIONS-LOG:', 505, 398);

    ctx.fillStyle = '#fde047';
    ctx.font = 'bold 10px monospace';
    ctx.fillText(imBannerMsg.slice(0, 34), 505, 416);
    if (imBannerMsg.length > 34) {
        ctx.fillText(imBannerMsg.slice(34, 68), 505, 431);
    }

    // Start-Overlay wenn noch nicht gestartet
    if (!imRunning) {
        ctx.fillStyle = 'rgba(2, 6, 23, 0.82)';
        ctx.fillRect(0, 110, cvs.width, 145);
        ctx.textAlign = 'center';
        ctx.fillStyle = '#22d3ee';
        ctx.font = 'bold 20px monospace';
        ctx.fillText('🕵️‍♂️ IMPOSSIBLE MISSION (1984 C64)', cvs.width / 2, 148);
        ctx.fillStyle = '#fde047';
        ctx.font = 'bold 13px monospace';
        ctx.fillText(imBannerMsg, cvs.width / 2, 176);
        ctx.fillStyle = '#e2e8f0';
        ctx.font = '12px sans-serif';
        ctx.fillText('Leertaste = Vorwärts-Salto über Roboter · Pfeil Hoch (E) vor Möbeln = Durchsuchen · M = Aufzugschacht', cvs.width / 2, 204);
        ctx.fillStyle = '#34d399';
        ctx.fillText('Klicke ins Spielfeld oder auf „▶️ Neue Mission starten“', cvs.width / 2, 232);
    }
}

function imLoop() {
    if (!imRunning) {
        imDraw();
        return;
    }
    imStep();
    imDraw();
    if (imRunning) {
        imRafId = requestAnimationFrame(imLoop);
    }
}

// =============================================================================
// SPIEL 19: 🎯 CLOUD-DARTS (ALLY PALLY TURNIER-EDITION)
// =============================================================================
const DARTS_SECTORS = [20, 1, 18, 4, 13, 6, 10, 15, 2, 17, 3, 19, 7, 16, 8, 11, 14, 9, 12, 5];
const DARTS_BOARD_CX = 355;
const DARTS_BOARD_CY = 245;
const DARTS_BOARD_R  = 175; // Außenradius Double-Ring

//Proportionale Radien der Turnier-Dartscheibe
const DARTS_R_BULL50 = 10;
const DARTS_R_BULL25 = 24;
const DARTS_R_TRIPLE_IN  = 96;
const DARTS_R_TRIPLE_OUT = 110;
const DARTS_R_DOUBLE_IN  = 161;
const DARTS_R_DOUBLE_OUT = 175;

let dartsInitialized = false;
let dartsRafId       = null;
let dartsMode        = '501_do';
let dartsOpp         = 'bot_pub';
let dartsStartMs     = 0;
let dartsGameOver    = false;

// Ziel-Visier & natürliches Hand-Schwanken
let dartsBaseX = DARTS_BOARD_CX;
let dartsBaseY = DARTS_BOARD_CY - 103; // Standardmäßig auf T20 gerichtet
let dartsAimX  = DARTS_BOARD_CX;
let dartsAimY  = DARTS_BOARD_CY - 103;
let dartsSwayT = 0;
let dartsPowerPhase = 0; // Präzisions-Pendel (0..2*PI)

let dartsTurnPlayer  = 0; // 0 = Spieler 1, 1 = Gegner/Spieler 2
let dartsTurnThrows  = []; // Array von { label, score, mult, base, x, y }
let dartsTurnStartScore = 501;
let dartsFlying      = null; // Aktive Fluganimation { startX, startY, targetX, targetY, t }
let dartsBannerMsg   = '🎯 WILLKOMMEN IM ALLY PALLY! ZIELE & DRÜCKE LEERTASTE ODER KLICK!';
let dartsCallerText  = '';
let dartsCallerTimer = 0;

let dartsPlayers = [
    { name: CURRENT_USERNAME, score: 501, dartsThrown: 0, totalPoints: 0, clockTarget: 1, roundsDone: 0 },
    { name: '🍺 Kneipen-KI',  score: 501, dartsThrown: 0, totalPoints: 0, clockTarget: 1, roundsDone: 0 }
];

function dartsCallerSpeak(text) {
    dartsCallerText  = text.toUpperCase();
    dartsCallerTimer = 140;
    try {
        if ('speechSynthesis' in window) {
            window.speechSynthesis.cancel();
            const u = new SpeechSynthesisUtterance(text);
            u.lang  = 'en-GB';
            u.pitch = text.includes('eighty') ? 0.8 : 0.9;
            u.rate  = 0.92;
            window.speechSynthesis.speak(u);
        }
    } catch (e) {}
}

function dartsPlaySfx(type) {
    try {
        const AudioCtx = window.AudioContext || window.webkitAudioContext;
        if (!window._dartsAudio) window._dartsAudio = new AudioCtx();
        const actx = window._dartsAudio;
        const osc  = actx.createOscillator();
        const gain = actx.createGain();
        osc.connect(gain);
        gain.connect(actx.destination);

        if (type === 'thud') {
            osc.type = 'triangle';
            osc.frequency.setValueAtTime(135, actx.currentTime);
            osc.frequency.exponentialRampToValueAtTime(42, actx.currentTime + 0.09);
            gain.gain.setValueAtTime(0.18, actx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.001, actx.currentTime + 0.095);
            osc.start();
            osc.stop(actx.currentTime + 0.095);
        } else if (type === '180') {
            [523, 659, 784, 1046].forEach((f, idx) => {
                const o = actx.createOscillator();
                const g = actx.createGain();
                o.type = 'sawtooth';
                o.frequency.setValueAtTime(f, actx.currentTime + idx * 0.09);
                g.gain.setValueAtTime(0.08, actx.currentTime + idx * 0.09);
                g.gain.exponentialRampToValueAtTime(0.001, actx.currentTime + idx * 0.09 + 0.2);
                o.connect(g);
                g.connect(actx.destination);
                o.start(actx.currentTime + idx * 0.09);
                o.stop(actx.currentTime + idx * 0.09 + 0.2);
            });
        }
    } catch (e) {}
}

function dartsEvaluatePoint(px, py) {
    const dx = px - DARTS_BOARD_CX;
    const dy = py - DARTS_BOARD_CY;
    const r  = Math.hypot(dx, dy);

    if (r <= DARTS_R_BULL50) {
        return { score: 50, base: 25, mult: 2, label: 'BULL (50)' };
    }
    if (r <= DARTS_R_BULL25) {
        return { score: 25, base: 25, mult: 1, label: '25' };
    }
    if (r > DARTS_R_DOUBLE_OUT) {
        return { score: 0, base: 0, mult: 0, label: 'MISS (0)' };
    }

    // Winkel berechnen (0 Grad = oben bei Sektor 20)
    let deg = (Math.atan2(dy, dx) * 180) / Math.PI + 90;
    if (deg < 0) deg += 360;
    const sectorIdx = Math.floor(((deg + 9) % 360) / 18);
    const num = DARTS_SECTORS[sectorIdx] || 20;

    if (r >= DARTS_R_DOUBLE_IN && r <= DARTS_R_DOUBLE_OUT) {
        return { score: num * 2, base: num, mult: 2, label: 'D' + num };
    }
    if (r >= DARTS_R_TRIPLE_IN && r <= DARTS_R_TRIPLE_OUT) {
        return { score: num * 3, base: num, mult: 3, label: 'T' + num };
    }
    return { score: num, base: num, mult: 1, label: String(num) };
}

function dartsGetSectorCoords(num, mult) {
    if (num === 25) {
        return { x: DARTS_BOARD_CX, y: DARTS_BOARD_CY + (mult === 2 ? 0 : -16) };
    }
    const idx = DARTS_SECTORS.indexOf(num);
    if (idx === -1) return { x: DARTS_BOARD_CX, y: DARTS_BOARD_CY };
    const angleRad = ((idx * 18 - 90) * Math.PI) / 180;
    let radius = 62;
    if (mult === 3) radius = (DARTS_R_TRIPLE_IN + DARTS_R_TRIPLE_OUT) / 2;
    else if (mult === 2) radius = (DARTS_R_DOUBLE_IN + DARTS_R_DOUBLE_OUT) / 2;
    return {
        x: DARTS_BOARD_CX + Math.cos(angleRad) * radius,
        y: DARTS_BOARD_CY + Math.sin(angleRad) * radius
    };
}

function dartsQuickAim(num, mult) {
    const pt = dartsGetSectorCoords(num, mult);
    dartsBaseX = pt.x;
    dartsBaseY = pt.y;
}

function dartsGetCheckoutSuggestion(rem, doubleOut = true) {
    if (rem <= 1 || rem > 170) return 'T20 • T20 • T20 (Max 180)';
    if (!doubleOut) {
        if (rem <= 20) return `Single ${rem}`;
        if (rem <= 60 && rem % 3 === 0) return `T${rem / 3}`;
        return 'T20 anvisieren';
    }
    const bogey = [169, 168, 166, 165, 163, 162, 159];
    if (bogey.includes(rem)) return 'Kein 3-Dart-Finish (Stellen!)';
    if (rem === 170) return 'T20 • T20 • Bull (50)';
    if (rem === 167) return 'T20 • T19 • Bull (50)';
    if (rem === 164) return 'T20 • T18 • Bull (50)';
    if (rem === 161) return 'T20 • T17 • Bull (50)';
    if (rem === 160) return 'T20 • T20 • D20';
    if (rem === 50)  return 'Bullseye (50)';
    if (rem <= 40 && rem % 2 === 0) return `D${rem / 2} (${rem})`;
    if (rem <= 40) {
        const s = 1;
        const d = (rem - 1) / 2;
        return `${s} • D${d}`;
    }
    if (rem <= 100) {
        const first = rem - 40 <= 20 ? (rem - 40) : 20;
        const rest  = rem - first;
        if (rest <= 40 && rest % 2 === 0) return `${first} • D${rest / 2}`;
        return `T20 oder T19 zum Stellen`;
    }
    return `T20 • Rest auf D20/D16 stellen`;
}

function dartsAimCheckoutDouble() {
    const p = dartsPlayers[dartsTurnPlayer];
    if (dartsMode === 'clock') {
        const t = p.clockTarget;
        dartsQuickAim(t === 21 ? 25 : t, t === 21 ? 2 : 1);
        return;
    }
    const rem = p.score;
    if (rem === 50) {
        dartsQuickAim(25, 2);
    } else if (rem <= 40 && rem % 2 === 0) {
        dartsQuickAim(rem / 2, 2);
    } else if (rem <= 40 && rem > 1) {
        dartsQuickAim(1, 1);
    } else {
        dartsQuickAim(20, 3);
    }
}

function dartsInit() {
    if (dartsInitialized) return;
    dartsInitialized = true;

    const cvs = document.getElementById('dartsCanvas');
    if (cvs) {
        const updateBaseFromEvent = (clientX, clientY) => {
            if (dartsTurnPlayer === 1 && dartsOpp !== 'human_2p') return;
            const rect = cvs.getBoundingClientRect();
            const mx = (clientX - rect.left) * (cvs.width / rect.width);
            const my = (clientY - rect.top) * (cvs.height / rect.height);
            if (mx >= 140 && mx <= 575) {
                dartsBaseX = mx;
                dartsBaseY = my;
            }
        };

        cvs.addEventListener('mousemove', (ev) => updateBaseFromEvent(ev.clientX, ev.clientY));
        cvs.addEventListener('touchmove', (ev) => {
            if (ev.touches && ev.touches[0]) {
                ev.preventDefault();
                updateBaseFromEvent(ev.touches[0].clientX, ev.touches[0].clientY);
            }
        }, { passive: false });

        cvs.addEventListener('click', () => {
            dartsTriggerThrow();
        });
    }

    window.addEventListener('keydown', (e) => {
        if (activeGameKey !== 'darts') return;
        const step = 6;
        if (e.key === 'ArrowLeft'  || e.key === 'a') { e.preventDefault(); dartsBaseX -= step; }
        if (e.key === 'ArrowRight' || e.key === 'd') { e.preventDefault(); dartsBaseX += step; }
        if (e.key === 'ArrowUp'    || e.key === 'w') { e.preventDefault(); dartsBaseY -= step; }
        if (e.key === 'ArrowDown'  || e.key === 's') { e.preventDefault(); dartsBaseY += step; }
        if (e.key === ' ') {
            e.preventDefault();
            dartsTriggerThrow();
        }
    });

    dartsStartMatch();
}

function dartsStartMatch() {
    dartsMode     = document.getElementById('dartsModeSelect')?.value || '501_do';
    dartsOpp      = document.getElementById('dartsOppSelect')?.value || 'bot_pub';
    dartsStartMs  = performance.now();
    dartsGameOver = false;
    dartsFlying   = null;
    dartsTurnPlayer = 0;
    dartsTurnThrows = [];

    const startPts = dartsMode === '501_do' ? 501 : (dartsMode === '301_so' ? 301 : 0);
    const oppLabel = dartsOpp === 'bot_pub' ? '🍺 Kneipen-KI' : (dartsOpp === 'bot_pro' ? '👑 Ally-Pally-Profi' : '👤 Spieler 2');

    dartsPlayers = [
        { name: CURRENT_USERNAME, score: startPts, dartsThrown: 0, totalPoints: 0, clockTarget: 1, roundsDone: 0 },
        { name: oppLabel,         score: startPts, dartsThrown: 0, totalPoints: 0, clockTarget: 1, roundsDone: 0 }
    ];
    dartsTurnStartScore = startPts;
    dartsQuickAim(dartsMode === 'clock' ? 1 : 20, dartsMode === 'clock' ? 1 : 3);
    dartsBannerMsg = `🎯 ${CURRENT_USERNAME} IST AM OCHE (WURFLINIE)!`;
    dartsSyncHud();

    if (dartsRafId) cancelAnimationFrame(dartsRafId);
    dartsRafId = requestAnimationFrame(dartsLoop);
}

function dartsSyncHud() {
    const p1 = dartsPlayers[0];
    const p2 = dartsPlayers[1];
    const p1Avg = p1.dartsThrown > 0 ? ((p1.totalPoints / p1.dartsThrown) * 3).toFixed(1) : '0.0';
    const p2Avg = p2.dartsThrown > 0 ? ((p2.totalPoints / p2.dartsThrown) * 3).toFixed(1) : '0.0';

    document.getElementById('dartsP1Score').textContent = dartsMode === 'clock'
        ? (p1.clockTarget <= 20 ? `Ziel: ${p1.clockTarget}` : 'Ziel: BULL')
        : String(p1.score);
    document.getElementById('dartsP1Avg').textContent = `Ø ${p1Avg}`;

    document.getElementById('dartsP2Name').textContent = p2.name;
    document.getElementById('dartsP2Score').textContent = dartsMode === 'clock'
        ? (p2.clockTarget <= 20 ? `Ziel: ${p2.clockTarget}` : 'Ziel: BULL')
        : String(p2.score);
    document.getElementById('dartsP2Avg').textContent = `Ø ${p2Avg}`;

    const c1 = document.getElementById('dartsP1Card');
    const c2 = document.getElementById('dartsP2Card');
    if (c1 && c2) {
        c1.className = dartsTurnPlayer === 0
            ? 'p-3 rounded-xl bg-emerald-950/90 border-2 border-emerald-400 text-white flex flex-col justify-between shadow-md'
            : 'p-3 rounded-xl bg-slate-900 border border-slate-700 text-white/75 flex flex-col justify-between';
        c2.className = dartsTurnPlayer === 1
            ? 'p-3 rounded-xl bg-amber-950/90 border-2 border-amber-400 text-white flex flex-col justify-between shadow-md'
            : 'p-3 rounded-xl bg-slate-900 border border-slate-700 text-white/75 flex flex-col justify-between';
    }

    const throwsEl = document.getElementById('dartsTurnThrows');
    if (throwsEl) {
        const slots = [0, 1, 2].map(i => dartsTurnThrows[i] ? `🎯 ${dartsTurnThrows[i].label}` : '🎯 —');
        throwsEl.textContent = slots.join(' · ');
    }

    const hintEl = document.getElementById('dartsCheckoutHint');
    if (hintEl) {
        const cur = dartsPlayers[dartsTurnPlayer];
        if (dartsMode === 'clock') {
            hintEl.textContent = cur.clockTarget <= 20 ? `Triff Sektor ${cur.clockTarget}!` : 'Triff das Bullseye (50)!';
        } else if (dartsMode === 'highscore') {
            hintEl.textContent = `Runde ${cur.roundsDone + 1} / 7 · Max Punkte sammeln!`;
        } else {
            hintEl.textContent = dartsGetCheckoutSuggestion(cur.score, dartsMode === '501_do');
        }
    }
}

function dartsTriggerThrow() {
    if (dartsGameOver || dartsFlying) return;
    if (dartsTurnPlayer === 1 && dartsOpp !== 'human_2p') return;
    if (dartsTurnThrows.length >= 3) return;

    // Präzisions-Abweichung abhängig vom Timing-Balken (sin(dartsPowerPhase) nahe 0 = perfekt!)
    const timingErr = Math.sin(dartsPowerPhase) * 9;
    const hitX = dartsAimX + (Math.random() - 0.5) * 3.5;
    const hitY = dartsAimY + timingErr;

    dartsFlying = {
        startX: 355,
        startY: 485,
        targetX: hitX,
        targetY: hitY,
        t: 0
    };
}

function dartsProcessLandedDart(hitX, hitY) {
    const res = dartsEvaluatePoint(hitX, hitY);
    res.x = hitX;
    res.y = hitY;
    dartsPlaySfx('thud');

    const p = dartsPlayers[dartsTurnPlayer];
    p.dartsThrown++;
    dartsTurnThrows.push(res);

    if (dartsMode === 'clock') {
        const needed = p.clockTarget === 21 ? 25 : p.clockTarget;
        if (res.base === needed && (p.clockTarget < 21 || res.mult === 2)) {
            p.clockTarget++;
            p.totalPoints += 50;
            dartsBannerMsg = `✅ TREFFER AUF ${res.label}! NÄCHSTES ZIEL: ${p.clockTarget <= 20 ? p.clockTarget : 'BULLSEYE'}!`;
            if (p.clockTarget > 21) {
                dartsFinishMatch(dartsTurnPlayer);
                return;
            }
        }
    } else if (dartsMode === 'highscore') {
        p.score += res.score;
        p.totalPoints += res.score;
    } else {
        // 501 Double-Out oder 301 Straight-Out
        const newRem = p.score - res.score;
        const isDoubleOut = (dartsMode === '501_do');
        const bust = (newRem < 0) || (isDoubleOut && newRem === 1) || (newRem === 0 && isDoubleOut && res.mult !== 2);

        if (bust) {
            p.score = dartsTurnStartScore;
            dartsCallerSpeak('No score! Bust!');
            dartsBannerMsg = `💥 ÜBERWORFEN (BUST)! PUNKTZAHL ZURÜCK AUF ${dartsTurnStartScore}!`;
            dartsSyncHud();
            setTimeout(dartsNextPlayerTurn, 1100);
            return;
        }

        p.score = newRem;
        p.totalPoints += res.score;

        if (newRem === 0) {
            dartsCallerSpeak('Game shot, and the match!');
            dartsFinishMatch(dartsTurnPlayer);
            return;
        }
    }

    dartsSyncHud();

    // Nach 3 geworfenen Pfeilen: Aufnahme auswerten & Spielerwechsel
    if (dartsTurnThrows.length >= 3) {
        const turnSum = dartsTurnThrows.reduce((s, d) => s + d.score, 0);
        if (turnSum === 180) {
            dartsPlaySfx('180');
            dartsCallerSpeak('One hundred and eighty!');
            dartsBannerMsg = '🔥🎙️ ONE HUNDRED AND EIGHTY (180)!!';
        } else if (turnSum >= 140) {
            dartsCallerSpeak(`${turnSum}!`);
            dartsBannerMsg = `👏 STARKE AUFNAHME: ${turnSum} PUNKTE!`;
        } else if (turnSum >= 100) {
            dartsCallerSpeak(`${turnSum}!`);
            dartsBannerMsg = `🎯 TONNE! ${turnSum} PUNKTE!`;
        } else {
            dartsBannerMsg = `Aufnahme beendet: ${turnSum} Punkte.`;
        }
        p.roundsDone++;

        if (dartsMode === 'highscore' && dartsPlayers[0].roundsDone >= 7 && dartsPlayers[1].roundsDone >= 7) {
            const winner = dartsPlayers[0].score >= dartsPlayers[1].score ? 0 : 1;
            dartsFinishMatch(winner);
            return;
        }

        setTimeout(dartsNextPlayerTurn, 1150);
    } else if (dartsTurnPlayer === 1 && dartsOpp !== 'human_2p') {
        setTimeout(dartsBotThrowNext, 650);
    }
}

function dartsNextPlayerTurn() {
    if (dartsGameOver) return;
    dartsTurnPlayer     = 1 - dartsTurnPlayer;
    dartsTurnThrows     = [];
    dartsTurnStartScore = dartsPlayers[dartsTurnPlayer].score;
    dartsBannerMsg      = `🎯 ${dartsPlayers[dartsTurnPlayer].name} IST AM WURF!`;
    dartsSyncHud();

    if (dartsTurnPlayer === 1 && dartsOpp !== 'human_2p') {
        setTimeout(dartsBotThrowNext, 700);
    }
}

function dartsBotThrowNext() {
    if (dartsGameOver || dartsTurnPlayer !== 1 || dartsTurnThrows.length >= 3) return;
    const bot = dartsPlayers[1];
    const isPro = (dartsOpp === 'bot_pro');
    const scatter = isPro ? 12 : 28;

    let targetNum = 20, targetMult = 3;
    if (dartsMode === 'clock') {
        targetNum  = bot.clockTarget === 21 ? 25 : bot.clockTarget;
        targetMult = bot.clockTarget === 21 ? 2 : 1;
    } else if (dartsMode !== 'highscore') {
        const rem = bot.score;
        if (rem === 50) { targetNum = 25; targetMult = 2; }
        else if (rem <= 40 && rem % 2 === 0) { targetNum = rem / 2; targetMult = 2; }
        else if (rem <= 40 && rem > 1) { targetNum = 1; targetMult = 1; }
        else if (rem <= 60 && dartsMode === '301_so' && rem <= 20) { targetNum = rem; targetMult = 1; }
    }

    const pt = dartsGetSectorCoords(targetNum, targetMult);
    const hitX = pt.x + (Math.random() - 0.5) * scatter * 2;
    const hitY = pt.y + (Math.random() - 0.5) * scatter * 2;

    dartsFlying = {
        startX: 355,
        startY: 485,
        targetX: hitX,
        targetY: hitY,
        t: 0
    };
}

function dartsFinishMatch(winnerIdx) {
    dartsGameOver = true;
    const p1 = dartsPlayers[0];
    const durSec = Math.max(1, Math.round((performance.now() - dartsStartMs) / 1000));
    const avg = p1.dartsThrown > 0 ? (p1.totalPoints / p1.dartsThrown) * 3 : 0;

    if (winnerIdx === 0) {
        let cloudScore = Math.round(p1.totalPoints + avg * 18 + (dartsMode === '501_do' ? 1200 : 800));
        if (dartsMode === 'highscore') cloudScore = p1.score;
        dartsBannerMsg = `🏆 SIEG FÜR ${p1.name.toUpperCase()}! (Ø ${avg.toFixed(1)} · SCORE: ${cloudScore})`;
        submitCloudScore('darts', cloudScore, durSec, `${dartsMode.toUpperCase()} · Ø ${avg.toFixed(1)} (${p1.dartsThrown} Darts)`);
    } else {
        dartsBannerMsg = `🏁 ${dartsPlayers[1].name} GEWINNT DAS LEG!`;
        if (p1.totalPoints >= 100) {
            submitCloudScore('darts', Math.round(p1.totalPoints), durSec, `Ø ${avg.toFixed(1)}`);
        }
    }
    dartsSyncHud();
}

function dartsDrawBoardAt(ctx, cx, cy, scale = 1) {
    ctx.save();
    ctx.translate(cx, cy);
    ctx.scale(scale, scale);

    // Schwarzer Außenring (Nummern-Ring)
    ctx.beginPath();
    ctx.arc(0, 0, DARTS_BOARD_R + 30, 0, Math.PI * 2);
    ctx.fillStyle = '#090d16';
    ctx.fill();
    ctx.lineWidth = 3;
    ctx.strokeStyle = '#334155';
    ctx.stroke();

    // 20 Sektoren zeichnen
    for (let i = 0; i < 20; i++) {
        const a1 = ((i * 18 - 99) * Math.PI) / 180;
        const a2 = (((i + 1) * 18 - 99) * Math.PI) / 180;
        const isDark = (i % 2 === 0);
        const singleCol = isDark ? '#0f172a' : '#f8fafc';
        const ringCol   = isDark ? '#dc2626' : '#16a34a';

        // Äußeres Single + Double-Ring
        ctx.beginPath();
        ctx.moveTo(0, 0);
        ctx.arc(0, 0, DARTS_R_DOUBLE_OUT, a1, a2);
        ctx.closePath();
        ctx.fillStyle = ringCol;
        ctx.fill();

        ctx.beginPath();
        ctx.moveTo(0, 0);
        ctx.arc(0, 0, DARTS_R_DOUBLE_IN, a1, a2);
        ctx.closePath();
        ctx.fillStyle = singleCol;
        ctx.fill();

        ctx.beginPath();
        ctx.moveTo(0, 0);
        ctx.arc(0, 0, DARTS_R_TRIPLE_OUT, a1, a2);
        ctx.closePath();
        ctx.fillStyle = ringCol;
        ctx.fill();

        ctx.beginPath();
        ctx.moveTo(0, 0);
        ctx.arc(0, 0, DARTS_R_TRIPLE_IN, a1, a2);
        ctx.closePath();
        ctx.fillStyle = singleCol;
        ctx.fill();

        // Draht-Trennlinien (Spider)
        ctx.strokeStyle = '#94a3b8';
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(0, 0);
        ctx.lineTo(Math.cos(a1) * DARTS_R_DOUBLE_OUT, Math.sin(a1) * DARTS_R_DOUBLE_OUT);
        ctx.stroke();

        // Sektor-Zahlen außen
        const midA = ((i * 18 - 90) * Math.PI) / 180;
        const nx = Math.cos(midA) * (DARTS_BOARD_R + 16);
        const ny = Math.sin(midA) * (DARTS_BOARD_R + 16);
        ctx.fillStyle = '#e2e8f0';
        ctx.font = 'bold 13px monospace';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(String(DARTS_SECTORS[i]), nx, ny);
    }

    // Drahtringe
    [DARTS_R_DOUBLE_OUT, DARTS_R_DOUBLE_IN, DARTS_R_TRIPLE_OUT, DARTS_R_TRIPLE_IN].forEach(r => {
        ctx.beginPath();
        ctx.arc(0, 0, r, 0, Math.PI * 2);
        ctx.strokeStyle = '#cbd5e1';
        ctx.lineWidth = 1;
        ctx.stroke();
    });

    // Outer Bull (25 - Grün)
    ctx.beginPath();
    ctx.arc(0, 0, DARTS_R_BULL25, 0, Math.PI * 2);
    ctx.fillStyle = '#16a34a';
    ctx.fill();
    ctx.strokeStyle = '#e2e8f0';
    ctx.stroke();

    // Inner Bullseye (50 - Rot)
    ctx.beginPath();
    ctx.arc(0, 0, DARTS_R_BULL50, 0, Math.PI * 2);
    ctx.fillStyle = '#dc2626';
    ctx.fill();
    ctx.strokeStyle = '#fde047';
    ctx.stroke();

    ctx.restore();
}

function dartsDraw() {
    const cvs = document.getElementById('dartsCanvas');
    if (!cvs) return;
    const ctx = cvs.getContext('2d');

    ctx.fillStyle = '#020617';
    ctx.fillRect(0, 0, cvs.width, cvs.height);

    // 1. Haupt-Dartscheibe zeichnen
    dartsDrawBoardAt(ctx, DARTS_BOARD_CX, DARTS_BOARD_CY, 1);

    // 2. Bereits steckende Pfeile dieser Aufnahme zeichnen
    dartsTurnThrows.forEach((d, idx) => {
        ctx.save();
        ctx.translate(d.x, d.y);
        // Schatten
        ctx.fillStyle = 'rgba(0,0,0,0.45)';
        ctx.beginPath();
        ctx.arc(3, 4, 4, 0, Math.PI * 2);
        ctx.fill();
        // Stahlspitze & Barrel
        ctx.strokeStyle = '#fde047';
        ctx.lineWidth = 2.5;
        ctx.beginPath();
        ctx.moveTo(0, 0);
        ctx.lineTo(10, 18);
        ctx.stroke();
        // Flight (Feder)
        ctx.fillStyle = idx === 0 ? '#ef4444' : (idx === 1 ? '#3b82f6' : '#10b981');
        ctx.beginPath();
        ctx.moveTo(10, 18);
        ctx.lineTo(18, 14);
        ctx.lineTo(15, 24);
        ctx.closePath();
        ctx.fill();
        ctx.restore();
    });

    // 3. Fliegender Pfeil in 3D-Perspektive
    if (dartsFlying) {
        const f = dartsFlying;
        const px = f.startX + (f.targetX - f.startX) * f.t;
        const py = f.startY + (f.targetY - f.startY) * f.t - Math.sin(f.t * Math.PI) * 55;
        const scale = 1.9 - f.t * 0.9;
        ctx.save();
        ctx.translate(px, py);
        ctx.scale(scale, scale);
        ctx.fillStyle = '#f43f5e';
        ctx.beginPath();
        ctx.moveTo(0, -14);
        ctx.lineTo(-8, 8);
        ctx.lineTo(8, 8);
        ctx.closePath();
        ctx.fill();
        ctx.restore();
    }

    // 4. Fadenkreuz (wenn Spieler am Zug)
    if (!dartsGameOver && !dartsFlying && (dartsTurnPlayer === 0 || dartsOpp === 'human_2p')) {
        ctx.save();
        ctx.translate(dartsAimX, dartsAimY);
        ctx.strokeStyle = '#38bdf8';
        ctx.lineWidth = 2;
        ctx.beginPath();
        ctx.arc(0, 0, 11, 0, Math.PI * 2);
        ctx.moveTo(-16, 0); ctx.lineTo(16, 0);
        ctx.moveTo(0, -16); ctx.lineTo(0, 16);
        ctx.stroke();
        ctx.restore();
    }

    // 5. Links oben: 2,5× Präzisions-Lupe (Zoom auf das aktuelle Zielgebiet)
    ctx.save();
    ctx.beginPath();
    ctx.arc(72, 82, 58, 0, Math.PI * 2);
    ctx.clip();
    ctx.fillStyle = '#090d16';
    ctx.fillRect(10, 20, 125, 125);
    const zoom = 2.2;
    const offX = 72 - (dartsAimX - DARTS_BOARD_CX) * zoom;
    const offY = 82 - (dartsAimY - DARTS_BOARD_CY) * zoom;
    dartsDrawBoardAt(ctx, offX, offY, zoom);
    // Fadenkreuz in der Lupe
    ctx.strokeStyle = '#38bdf8';
    ctx.lineWidth = 1.5;
    ctx.beginPath();
    ctx.moveTo(56, 82); ctx.lineTo(88, 82);
    ctx.moveTo(72, 66); ctx.lineTo(72, 98);
    ctx.stroke();
    ctx.restore();

    ctx.beginPath();
    ctx.arc(72, 82, 58, 0, Math.PI * 2);
    ctx.strokeStyle = '#10b981';
    ctx.lineWidth = 3;
    ctx.stroke();

    const aimEval = dartsEvaluatePoint(dartsAimX, dartsAimY);
    ctx.fillStyle = '#fde047';
    ctx.font = 'bold 11px monospace';
    ctx.textAlign = 'center';
    ctx.fillText('🔍 ' + aimEval.label, 72, 155);

    // 6. Rechts: Wurf-Timing- / Präzisions-Pendel
    const barX = 595, barY = 95, barH = 260;
    ctx.fillStyle = '#1e293b';
    ctx.fillRect(barX, barY, 22, barH);
    ctx.strokeStyle = '#475569';
    ctx.strokeRect(barX, barY, 22, barH);

    // Grüne Ideal-Zone in der Mitte
    ctx.fillStyle = '#10b981';
    ctx.fillRect(barX + 2, barY + barH / 2 - 16, 18, 32);

    const markerY = barY + barH / 2 + Math.sin(dartsPowerPhase) * (barH / 2 - 8);
    ctx.fillStyle = '#fde047';
    ctx.fillRect(barX - 4, markerY - 4, 30, 8);

    ctx.fillStyle = '#94a3b8';
    ctx.font = 'bold 10px monospace';
    ctx.textAlign = 'center';
    ctx.fillText('TIMING', barX + 11, barY - 10);

    // 7. Unten: Status- & Caller-Banner
    ctx.fillStyle = 'rgba(15, 23, 42, 0.92)';
    ctx.fillRect(12, 448, cvs.width - 24, 34);
    ctx.strokeStyle = '#10b981';
    ctx.strokeRect(12, 448, cvs.width - 24, 34);
    ctx.fillStyle = '#fde047';
    ctx.font = 'bold 12px monospace';
    ctx.textAlign = 'center';
    ctx.fillText(dartsBannerMsg, cvs.width / 2, 469);

    // Großes Caller-Pop-Up (z.B. 180!)
    if (dartsCallerTimer > 0) {
        dartsCallerTimer--;
        ctx.fillStyle = 'rgba(220, 38, 38, 0.92)';
        ctx.fillRect(145, 195, 420, 62);
        ctx.strokeStyle = '#fde047';
        ctx.lineWidth = 3;
        ctx.strokeRect(145, 195, 420, 62);
        ctx.fillStyle = '#ffffff';
        ctx.font = 'extrabold 24px monospace';
        ctx.textAlign = 'center';
        ctx.fillText('🎙️ ' + dartsCallerText, 355, 233);
    }
}

function dartsLoop() {
    dartsSwayT      += 0.04;
    dartsPowerPhase += 0.075;

    // Sanftes Atmen/Puls-Schwanken des Visiers um den Zielpunkt
    dartsAimX = dartsBaseX + Math.sin(dartsSwayT * 1.3) * 4.2 + Math.cos(dartsSwayT * 2.1) * 2.0;
    dartsAimY = dartsBaseY + Math.cos(dartsSwayT * 1.7) * 4.2;

    if (dartsFlying) {
        dartsFlying.t += 0.12;
        if (dartsFlying.t >= 1) {
            const tx = dartsFlying.targetX;
            const ty = dartsFlying.targetY;
            dartsFlying = null;
            dartsProcessLandedDart(tx, ty);
        }
    }

    if (activeGameKey === 'darts') {
        dartsDraw();
    }
    dartsRafId = requestAnimationFrame(dartsLoop);
}

// =============================================================================
// 20. SPIEL: CLOUD-FROGGER (1981 KONAMI / SEGA ARCADE-KLASSIKER)
// =============================================================================
let froggerInitialized = false;
let froggerRunning     = false;
let froggerRafId       = null;
let froggerSoundOn     = true;
let froggerAudioCtx    = null;

const FROGGER_COLS = 15;
const FROGGER_ROWS = 13;
const FROGGER_TILE = 40; // 15 * 40 = 600px, 13 * 40 = 520px

// 5 Ziel-Buchten (X-Zentren in Pixeln bei Row 0)
const FROGGER_BAYS_X = [60, 180, 300, 420, 540];

let froggerScore       = 0;
let froggerLevel       = 1;
let froggerLives       = 3;
let froggerHomes       = [false, false, false, false, false];
let froggerStartTime   = 0;
let froggerFrogTimer   = 45.0; // 45 Sekunden pro Frosch
let froggerLastFrameTs = 0;

let froggerFrog = {
    x: 280,
    y: 12 * FROGGER_TILE,
    row: 12,
    dir: 'up',     // 'up' | 'down' | 'left' | 'right'
    hopAnim: 0,
    minRowReached: 12,
    hasLady: false,
    deadTimer: 0,
    deadReason: '',
};

let froggerLanes = [];
let froggerFly   = { bayIdx: -1, timer: 0 };
let froggerCroc  = { bayIdx: -1, timer: 0 };
let froggerSnake = { active: false, x: 120, vx: 65 };
let froggerPopups = [];
let froggerBanner = 'Drücke ▶ Neues Spiel oder eine Pfeiltaste, um den Frosch zu starten!';

function froggerPlaySfx(type) {
    if (!froggerSoundOn) return;
    try {
        if (!froggerAudioCtx) {
            const AC = window.AudioContext || window.webkitAudioContext;
            if (!AC) return;
            froggerAudioCtx = new AC();
        }
        if (froggerAudioCtx.state === 'suspended') {
            froggerAudioCtx.resume();
        }
        const now = froggerAudioCtx.currentTime;
        const osc = froggerAudioCtx.createOscillator();
        const gain = froggerAudioCtx.createGain();
        osc.connect(gain);
        gain.connect(froggerAudioCtx.destination);

        if (type === 'hop') {
            osc.type = 'square';
            osc.frequency.setValueAtTime(320, now);
            osc.frequency.exponentialRampToValueAtTime(640, now + 0.075);
            gain.gain.setValueAtTime(0.08, now);
            gain.gain.exponentialRampToValueAtTime(0.001, now + 0.08);
            osc.start(now);
            osc.stop(now + 0.085);
        } else if (type === 'splash') {
            osc.type = 'sawtooth';
            osc.frequency.setValueAtTime(240, now);
            osc.frequency.linearRampToValueAtTime(75, now + 0.35);
            gain.gain.setValueAtTime(0.11, now);
            gain.gain.exponentialRampToValueAtTime(0.001, now + 0.36);
            osc.start(now);
            osc.stop(now + 0.37);
        } else if (type === 'squash') {
            osc.type = 'sawtooth';
            osc.frequency.setValueAtTime(150, now);
            osc.frequency.linearRampToValueAtTime(48, now + 0.32);
            gain.gain.setValueAtTime(0.12, now);
            gain.gain.exponentialRampToValueAtTime(0.001, now + 0.33);
            osc.start(now);
            osc.stop(now + 0.34);
        } else if (type === 'home') {
            osc.type = 'triangle';
            const notes = [523.25, 659.25, 783.99, 1046.5];
            notes.forEach((f, idx) => {
                osc.frequency.setValueAtTime(f, now + idx * 0.07);
            });
            gain.gain.setValueAtTime(0.11, now);
            gain.gain.exponentialRampToValueAtTime(0.001, now + 0.32);
            osc.start(now);
            osc.stop(now + 0.33);
        } else if (type === 'levelup') {
            osc.type = 'square';
            const notes = [440, 554.37, 659.25, 880, 659.25, 880];
            notes.forEach((f, idx) => {
                osc.frequency.setValueAtTime(f, now + idx * 0.08);
            });
            gain.gain.setValueAtTime(0.10, now);
            gain.gain.exponentialRampToValueAtTime(0.001, now + 0.52);
            osc.start(now);
            osc.stop(now + 0.54);
        }
    } catch (e) {}
}

function froggerToggleSound() {
    froggerSoundOn = !froggerSoundOn;
    const btn = document.getElementById('froggerSoundBtn');
    if (btn) {
        btn.textContent = froggerSoundOn ? '🔊 Ton' : '🔇 Stumm';
    }
}

function froggerInit() {
    if (froggerInitialized) return;
    froggerInitialized = true;

    const cvs = document.getElementById('froggerCanvas');
    if (cvs) {
        let touchStartX = 0;
        let touchStartY = 0;
        cvs.addEventListener('pointerdown', (e) => {
            touchStartX = e.clientX;
            touchStartY = e.clientY;
        });
        cvs.addEventListener('pointerup', (e) => {
            const dx = e.clientX - touchStartX;
            const dy = e.clientY - touchStartY;
            if (Math.hypot(dx, dy) > 22) {
                if (Math.abs(dx) > Math.abs(dy)) {
                    froggerHop(dx > 0 ? 1 : -1, 0);
                } else {
                    froggerHop(0, dy > 0 ? 1 : -1);
                }
            } else {
                // Kurzer Tipp auf Canvas -> Hüpfer nach vorn
                froggerHop(0, -1);
            }
        });
    }

    document.addEventListener('keydown', (e) => {
        if (activeGameKey !== 'frogger') return;
        if (['ArrowUp', 'KeyW'].includes(e.code)) {
            e.preventDefault();
            froggerHop(0, -1);
        } else if (['ArrowDown', 'KeyS'].includes(e.code)) {
            e.preventDefault();
            froggerHop(0, 1);
        } else if (['ArrowLeft', 'KeyA'].includes(e.code)) {
            e.preventDefault();
            froggerHop(-1, 0);
        } else if (['ArrowRight', 'KeyD'].includes(e.code)) {
            e.preventDefault();
            froggerHop(1, 0);
        }
    });

    froggerBuildLanes(1);
    froggerResetFrog();
    froggerUpdateHud();
    froggerDraw();

    if (!froggerRafId) {
        froggerLastFrameTs = performance.now();
        froggerRafId = requestAnimationFrame(froggerLoop);
    }
}

function froggerBuildLanes(lvl) {
    const mult = 1 + (lvl - 1) * 0.16;
    froggerLanes = [
        // Row 1: Fluss oben – Baumstämme + Krokodil (nach rechts)
        {
            row: 1,
            zone: 'river',
            speed: 52 * mult,
            items: [
                { x: 20,  w: 120, type: 'log' },
                { x: 230, w: 120, type: 'log' },
                { x: 440, w: 115, type: lvl >= 2 ? 'croc' : 'log' },
            ],
        },
        // Row 2: 2er-Schildkröten (nach links, 1 Tauch-Gruppe)
        {
            row: 2,
            zone: 'river',
            speed: -64 * mult,
            items: [
                { x: 30,  w: 76, type: 'turtle', count: 2, diver: true, divePhase: 0.4 },
                { x: 190, w: 76, type: 'turtle', count: 2, diver: false, divePhase: 0 },
                { x: 350, w: 76, type: 'turtle', count: 2, diver: false, divePhase: 0 },
                { x: 500, w: 76, type: 'turtle', count: 2, diver: lvl >= 3, divePhase: 2.6 },
            ],
        },
        // Row 3: Riesen-Baumstämme + Lady-Frog (nach rechts)
        {
            row: 3,
            zone: 'river',
            speed: 74 * mult,
            items: [
                { x: 10,  w: 165, type: 'log', hasLady: true, ladyOffset: 70 },
                { x: 310, w: 165, type: 'log', hasLady: false, ladyOffset: 0 },
            ],
        },
        // Row 4: Kurze Flöße (nach links)
        {
            row: 4,
            zone: 'river',
            speed: -48 * mult,
            items: [
                { x: 40,  w: 96, type: 'log' },
                { x: 240, w: 96, type: 'log' },
                { x: 440, w: 96, type: 'log' },
            ],
        },
        // Row 5: 3er-Schildkröten-Kolonnen (nach links, 1 Tauch-Gruppe)
        {
            row: 5,
            zone: 'river',
            speed: -58 * mult,
            items: [
                { x: 20,  w: 110, type: 'turtle', count: 3, diver: false, divePhase: 0 },
                { x: 220, w: 110, type: 'turtle', count: 3, diver: true,  divePhase: 1.5 },
                { x: 420, w: 110, type: 'turtle', count: 3, diver: false, divePhase: 0 },
            ],
        },
        // Row 7: Highway Spur 5 – Weiße Sattelschlepper / Trucks (nach links)
        {
            row: 7,
            zone: 'road',
            speed: -68 * mult,
            items: [
                { x: 30,  w: 84, type: 'truck', color: '#f8fafc' },
                { x: 260, w: 84, type: 'truck', color: '#e2e8f0' },
                { x: 470, w: 84, type: 'truck', color: '#f8fafc' },
            ],
        },
        // Row 8: Highway Spur 4 – Schnelle rote Turbo-Rennwagen (nach rechts)
        {
            row: 8,
            zone: 'road',
            speed: 118 * mult,
            items: [
                { x: 60,  w: 44, type: 'racecar', color: '#ef4444' },
                { x: 340, w: 44, type: 'racecar', color: '#f97316' },
            ],
        },
        // Row 9: Highway Spur 3 – Pinke Limousinen (nach links)
        {
            row: 9,
            zone: 'road',
            speed: -76 * mult,
            items: [
                { x: 40,  w: 48, type: 'sedan', color: '#ec4899' },
                { x: 230, w: 48, type: 'sedan', color: '#d946ef' },
                { x: 430, w: 48, type: 'sedan', color: '#ec4899' },
            ],
        },
        // Row 10: Highway Spur 2 – Gelbe Planierraupen / Bulldozer (nach rechts)
        {
            row: 10,
            zone: 'road',
            speed: 48 * mult,
            items: [
                { x: 50,  w: 46, type: 'dozer', color: '#eab308' },
                { x: 240, w: 46, type: 'dozer', color: '#facc15' },
                { x: 440, w: 46, type: 'dozer', color: '#eab308' },
            ],
        },
        // Row 11: Highway Spur 1 – Gelb-blaue Käfer / Taxis (nach links)
        {
            row: 11,
            zone: 'road',
            speed: -56 * mult,
            items: [
                { x: 30,  w: 44, type: 'bug', color: '#38bdf8' },
                { x: 210, w: 44, type: 'bug', color: '#fbbf24' },
                { x: 400, w: 44, type: 'bug', color: '#38bdf8' },
            ],
        },
    ];

    froggerSnake = {
        active: lvl >= 2,
        x: 120,
        vx: (52 + lvl * 8),
    };
}

function froggerResetFrog() {
    froggerFrog.x             = 7 * FROGGER_TILE; // 280
    froggerFrog.y             = 12 * FROGGER_TILE;
    froggerFrog.row           = 12;
    froggerFrog.dir           = 'up';
    froggerFrog.hopAnim       = 0;
    froggerFrog.minRowReached = 12;
    froggerFrog.hasLady       = false;
    froggerFrog.deadTimer     = 0;
    froggerFrog.deadReason    = '';
    froggerFrogTimer          = 45.0;
}

function froggerNewGame() {
    froggerScore     = 0;
    froggerLevel     = 1;
    froggerLives     = 3;
    froggerHomes     = [false, false, false, false, false];
    froggerStartTime = Date.now();
    froggerRunning   = true;
    froggerPopups    = [];
    froggerFly       = { bayIdx: 2, timer: 11.0 };
    froggerCroc      = { bayIdx: -1, timer: 6.0 };
    froggerBanner    = '🐸 Los geht’s! Hüpfe zwischen den Autos hindurch und spring auf die Baumstämme!';
    froggerBuildLanes(1);
    froggerResetFrog();
    froggerUpdateHud();
    froggerDraw();
}

function froggerUpdateHud() {
    const scEl = document.getElementById('froggerScore');
    const lvEl = document.getElementById('froggerLevel');
    const hmEl = document.getElementById('froggerHomes');
    const liEl = document.getElementById('froggerLives');
    if (scEl) scEl.textContent = froggerScore.toLocaleString('de-DE');
    if (lvEl) lvEl.textContent = String(froggerLevel);
    if (hmEl) hmEl.textContent = froggerHomes.filter(Boolean).length + '/5';
    if (liEl) liEl.textContent = froggerLives > 0 ? '🐸'.repeat(froggerLives) : '💀';
}

function froggerAddPopup(x, y, text, color = '#fde047') {
    froggerPopups.push({ x, y, text, color, life: 1.1 });
}

function froggerHop(dx, dy) {
    if (!froggerRunning) {
        froggerNewGame();
        return;
    }
    if (froggerFrog.deadTimer > 0) return;

    if (dx < 0) froggerFrog.dir = 'left';
    else if (dx > 0) froggerFrog.dir = 'right';
    else if (dy < 0) froggerFrog.dir = 'up';
    else if (dy > 0) froggerFrog.dir = 'down';

    const newRow = froggerFrog.row + dy;
    const newX   = froggerFrog.x + dx * FROGGER_TILE;

    if (newRow < 0 || newRow > 12) return;
    if (newX < 0 || newX > (FROGGER_COLS - 1) * FROGGER_TILE) return;

    froggerFrog.x       = newX;
    froggerFrog.row     = newRow;
    froggerFrog.y       = newRow * FROGGER_TILE;
    froggerFrog.hopAnim = 1.0;
    froggerPlaySfx('hop');

    // +10 Punkte für jede neu erreichte Reihe nach vorn
    if (newRow < froggerFrog.minRowReached && newRow > 0) {
        froggerFrog.minRowReached = newRow;
        froggerScore += 10;
        froggerUpdateHud();
    }

    // Prüfen, ob Reihe 0 (5 Seerosen-Buchten) erreicht wurde
    if (newRow === 0) {
        const frogCenterX = froggerFrog.x + FROGGER_TILE / 2;
        let landedBay = -1;
        for (let i = 0; i < FROGGER_BAYS_X.length; i++) {
            if (Math.abs(frogCenterX - FROGGER_BAYS_X[i]) <= 24) {
                landedBay = i;
                break;
            }
        }

        if (landedBay === -1) {
            froggerKillFrog('🌿 Im Schilf neben der Seerose gelandet!', 'splash');
            return;
        }
        if (froggerHomes[landedBay]) {
            froggerKillFrog('🐸 Diese Seerosen-Bucht ist bereits besetzt!', 'splash');
            return;
        }
        if (froggerCroc.bayIdx === landedBay) {
            froggerKillFrog('🐊 Schnapp! Krokodil in der Seerosen-Bucht!', 'squash');
            return;
        }

        // Sicher im Seerosen-Haus gelandet!
        froggerHomes[landedBay] = true;
        const timeBonus = Math.max(0, Math.round(froggerFrogTimer)) * 10;
        let pts = 50 + timeBonus;
        let msg = `+${pts}`;

        if (froggerFly.bayIdx === landedBay) {
            pts += 200;
            msg = `🪰 FLIEGE! +${pts}`;
            froggerFly.bayIdx = -1;
        }
        if (froggerFrog.hasLady) {
            pts += 200;
            msg = `🩷 LADY-BONUS! +${pts}`;
        }

        froggerScore += pts;
        froggerAddPopup(FROGGER_BAYS_X[landedBay], 34, msg, '#4ade80');
        froggerBanner = `🏡 Frosch #${froggerHomes.filter(Boolean).length} sicher im Haus! (${msg} Pkt.)`;

        // Alle 5 Häuser voll -> Level abgeschlossen!
        if (froggerHomes.every(Boolean)) {
            froggerScore += 1000;
            froggerLevel++;
            froggerHomes = [false, false, false, false, false];
            froggerPlaySfx('levelup');
            froggerAddPopup(300, 220, `🏆 LEVEL ${froggerLevel}! +1.000 BONUS!`, '#fde047');
            froggerBanner = `🎉 Alle 5 Frösche gerettet! +1.000 Bonus! Willkommen in Level ${froggerLevel}!`;
            froggerBuildLanes(froggerLevel);
        } else {
            froggerPlaySfx('home');
        }

        froggerResetFrog();
        froggerUpdateHud();
    }
}

function froggerKillFrog(reason, sfx = 'squash') {
    if (froggerFrog.deadTimer > 0) return;
    froggerPlaySfx(sfx);
    froggerFrog.deadTimer  = 1.15;
    froggerFrog.deadReason = reason;
    froggerLives--;
    froggerBanner = `${reason} (${froggerLives > 0 ? 'Noch ' + froggerLives + ' Leben' : 'GAME OVER'})`;
    froggerAddPopup(froggerFrog.x + 20, froggerFrog.y + 10, '💀 OUCH!', '#f87171');
    froggerUpdateHud();
}

function froggerUpdate(dt) {
    if (!froggerRunning) return;

    // Popups aktualisieren
    for (let i = froggerPopups.length - 1; i >= 0; i--) {
        froggerPopups[i].y -= 22 * dt;
        froggerPopups[i].life -= dt;
        if (froggerPopups[i].life <= 0) {
            froggerPopups.splice(i, 1);
        }
    }

    if (froggerFrog.hopAnim > 0) {
        froggerFrog.hopAnim = Math.max(0, froggerFrog.hopAnim - dt * 6);
    }

    // Bonus-Fliege & Krokodil in freien Seerosen-Buchten rotieren
    froggerFly.timer -= dt;
    if (froggerFly.timer <= 0) {
        if (froggerFly.bayIdx >= 0) {
            froggerFly.bayIdx = -1;
            froggerFly.timer  = 5 + Math.random() * 5;
        } else {
            const freeBays = [0, 1, 2, 3, 4].filter((i) => !froggerHomes[i] && i !== froggerCroc.bayIdx);
            if (freeBays.length > 0) {
                froggerFly.bayIdx = freeBays[Math.floor(Math.random() * freeBays.length)];
            }
            froggerFly.timer = 7 + Math.random() * 4;
        }
    }

    if (froggerLevel >= 2) {
        froggerCroc.timer -= dt;
        if (froggerCroc.timer <= 0) {
            if (froggerCroc.bayIdx >= 0) {
                froggerCroc.bayIdx = -1;
                froggerCroc.timer  = 6 + Math.random() * 5;
            } else {
                const freeBays = [0, 1, 2, 3, 4].filter((i) => !froggerHomes[i] && i !== froggerFly.bayIdx);
                if (freeBays.length > 0) {
                    froggerCroc.bayIdx = freeBays[Math.floor(Math.random() * freeBays.length)];
                }
                froggerCroc.timer = 4.5;
            }
        }
    }

    // Alle Spuren (Autos, Baumstämme, Schildkröten) bewegen
    froggerLanes.forEach((lane) => {
        lane.items.forEach((it) => {
            it.x += lane.speed * dt;
            if (lane.speed > 0 && it.x > 620) {
                it.x = -it.w - 20;
            } else if (lane.speed < 0 && it.x + it.w < -20) {
                it.x = 620;
            }
            if (it.diver) {
                it.divePhase = (it.divePhase + dt * 0.85) % 6.0;
                // 0..3.6 = Über Wasser, 3.6..4.8 = Warn-Blinken, 4.8..6.0 = Abgetaucht!
                it.diveState = it.divePhase < 3.6 ? 'surface' : (it.divePhase < 4.8 ? 'diving' : 'submerged');
            } else {
                it.diveState = 'surface';
            }
        });
    });

    // Schlange auf dem Mittelstreifen (ab Level 2)
    if (froggerSnake.active) {
        froggerSnake.x += froggerSnake.vx * dt;
        if (froggerSnake.x > 550) {
            froggerSnake.x = 550;
            froggerSnake.vx = -Math.abs(froggerSnake.vx);
        } else if (froggerSnake.x < 10) {
            froggerSnake.x = 10;
            froggerSnake.vx = Math.abs(froggerSnake.vx);
        }
    }

    // Falls Frosch gerade stirbt -> Respawn oder Game Over abwarten
    if (froggerFrog.deadTimer > 0) {
        froggerFrog.deadTimer -= dt;
        if (froggerFrog.deadTimer <= 0) {
            if (froggerLives <= 0) {
                froggerRunning = false;
                const dur = Math.max(1, Math.round((Date.now() - froggerStartTime) / 1000));
                if (froggerScore > 0) {
                    submitCloudScore('frogger', froggerScore, dur, 'Level ' + froggerLevel);
                }
                froggerBanner = `🏁 GAME OVER! Endstand: ${froggerScore.toLocaleString('de-DE')} Punkte (Level ${froggerLevel}). Klicke auf ▶ Neues Spiel!`;
            } else {
                froggerResetFrog();
            }
        }
        return;
    }

    // Zeitbalken herunterzählen
    froggerFrogTimer -= dt;
    if (froggerFrogTimer <= 0) {
        froggerKillFrog('⏱️ Zeit abgelaufen!', 'squash');
        return;
    }

    const frogLeft   = froggerFrog.x + 7;
    const frogRight  = froggerFrog.x + FROGGER_TILE - 7;
    const frogCenter = froggerFrog.x + FROGGER_TILE / 2;

    // Kollisionsprüfung Mittelstreifen-Schlange (Row 6)
    if (froggerFrog.row === 6 && froggerSnake.active) {
        if (frogRight > froggerSnake.x + 4 && frogLeft < froggerSnake.x + 44) {
            froggerKillFrog('🐍 Von der Ufer-Schlange gebissen!', 'squash');
            return;
        }
    }

    // Kollisionsprüfung Straße (Rows 7..11) & Fluss (Rows 1..5)
    const activeLane = froggerLanes.find((l) => l.row === froggerFrog.row);
    if (!activeLane) return;

    if (activeLane.zone === 'road') {
        for (const car of activeLane.items) {
            if (frogRight > car.x + 4 && frogLeft < car.x + car.w - 4) {
                froggerKillFrog('🚗 Auf dem Highway überfahren!', 'squash');
                return;
            }
        }
    } else if (activeLane.zone === 'river') {
        let onSupport = null;
        for (const plat of activeLane.items) {
            if (frogCenter >= plat.x + 4 && frogCenter <= plat.x + plat.w - 4) {
                if (plat.diveState === 'submerged') {
                    continue; // Schildkröte ist abgetaucht!
                }
                // Bei Krokodil auf dem Fluss: vorderes Maul (letzte 24px rechts) ist tödlich!
                if (plat.type === 'croc' && frogCenter > plat.x + plat.w - 24) {
                    froggerKillFrog('🐊 Ins offene Krokodil-Maul getreten!', 'squash');
                    return;
                }
                onSupport = plat;
                break;
            }
        }

        if (!onSupport) {
            froggerKillFrog('🌊 Platsch! Im reißenden Fluss versunken!', 'splash');
            return;
        }

        // Frosch reitet auf Baumstamm oder Schildkröte mit!
        froggerFrog.x += activeLane.speed * dt;

        // Lady-Frog auf dem Baumstamm einsammeln
        if (onSupport.hasLady && !froggerFrog.hasLady) {
            const ladyX = onSupport.x + onSupport.ladyOffset;
            if (Math.abs(frogCenter - ladyX) < 22) {
                onSupport.hasLady   = false;
                froggerFrog.hasLady = true;
                froggerPlaySfx('home');
                froggerAddPopup(froggerFrog.x + 20, froggerFrog.y, '🩷 LADY-FROG +200!', '#f472b6');
            }
        }

        if (froggerFrog.x < -8 || froggerFrog.x > 600 - FROGGER_TILE + 8) {
            froggerKillFrog('🌊 Vom Baumstamm über den Bildschirmrand getrieben!', 'splash');
        }
    }
}

function froggerDrawFrogSprite(ctx, cx, cy, dir, colorMain = '#22c55e', colorBelly = '#86efac', hopScale = 1.0) {
    ctx.save();
    ctx.translate(cx, cy);
    if (dir === 'right') ctx.rotate(Math.PI / 2);
    else if (dir === 'down') ctx.rotate(Math.PI);
    else if (dir === 'left') ctx.rotate(-Math.PI / 2);
    ctx.scale(hopScale, hopScale);

    // Hinterbeine & Vorderbeine
    ctx.strokeStyle = colorMain;
    ctx.lineWidth = 3.5;
    ctx.beginPath();
    // Oben links / rechts
    ctx.moveTo(-8, -4); ctx.lineTo(-14, -11); ctx.lineTo(-14, -15);
    ctx.moveTo(8, -4);  ctx.lineTo(14, -11);  ctx.lineTo(14, -15);
    // Unten links / rechts
    ctx.moveTo(-8, 6);  ctx.lineTo(-15, 11);  ctx.lineTo(-13, 16);
    ctx.moveTo(8, 6);   ctx.lineTo(15, 11);   ctx.lineTo(13, 16);
    ctx.stroke();

    // Körper
    ctx.fillStyle = colorMain;
    ctx.beginPath();
    ctx.ellipse(0, 1, 10, 12, 0, 0, Math.PI * 2);
    ctx.fill();

    // Rückenstreifen
    ctx.fillStyle = colorBelly;
    ctx.beginPath();
    ctx.ellipse(0, 1, 5, 7, 0, 0, Math.PI * 2);
    ctx.fill();

    // Glubschaugen vorn
    ctx.fillStyle = '#fef08a';
    ctx.beginPath();
    ctx.arc(-6, -9, 3.5, 0, Math.PI * 2);
    ctx.arc(6, -9, 3.5, 0, Math.PI * 2);
    ctx.fill();
    ctx.fillStyle = '#0f172a';
    ctx.beginPath();
    ctx.arc(-6, -10, 1.7, 0, Math.PI * 2);
    ctx.arc(6, -10, 1.7, 0, Math.PI * 2);
    ctx.fill();

    ctx.restore();
}

function froggerDraw() {
    const cvs = document.getElementById('froggerCanvas');
    if (!cvs) return;
    const ctx = cvs.getContext('2d');

    // 1. Hintergrund-Zonen zeichnen
    // Fluss (Rows 0..5)
    ctx.fillStyle = '#0c4a6e';
    ctx.fillRect(0, 0, 600, 6 * FROGGER_TILE);

    // Wellenlinien im Fluss
    ctx.strokeStyle = 'rgba(56, 189, 248, 0.18)';
    ctx.lineWidth = 1.5;
    const waveShift = (performance.now() * 0.025) % 40;
    for (let r = 1; r <= 5; r++) {
        const wy = r * FROGGER_TILE + 20;
        ctx.beginPath();
        for (let wx = -40; wx < 620; wx += 40) {
            ctx.moveTo(wx + (r % 2 === 0 ? waveShift : -waveShift), wy);
            ctx.lineTo(wx + 18 + (r % 2 === 0 ? waveShift : -waveShift), wy);
        }
        ctx.stroke();
    }

    // Row 0: Uferböschung & 5 Seerosen-Buchten
    ctx.fillStyle = '#14532d';
    ctx.fillRect(0, 0, 600, FROGGER_TILE);
    // Gras-Muster
    ctx.fillStyle = '#166534';
    for (let gx = 0; gx < 600; gx += 12) {
        ctx.fillRect(gx, 0, 6, 8);
    }

    for (let i = 0; i < FROGGER_BAYS_X.length; i++) {
        const bx = FROGGER_BAYS_X[i];
        // Wasser-Bucht
        ctx.fillStyle = '#075985';
        ctx.beginPath();
        ctx.roundRect(bx - 26, 4, 52, FROGGER_TILE - 4, [10, 10, 0, 0]);
        ctx.fill();

        // Seerosenblatt (Lilypad)
        ctx.fillStyle = '#15803d';
        ctx.beginPath();
        ctx.arc(bx, 22, 16, 0.2, Math.PI * 2 - 0.2);
        ctx.lineTo(bx, 22);
        ctx.closePath();
        ctx.fill();
        ctx.strokeStyle = '#4ade80';
        ctx.lineWidth = 1.5;
        ctx.stroke();

        // Bereits geretteter Frosch in dieser Bucht?
        if (froggerHomes[i]) {
            froggerDrawFrogSprite(ctx, bx, 22, 'down', '#22c55e', '#bbf7d0', 0.92);
        } else if (froggerFly.bayIdx === i) {
            // Bonus-Fliege (+200 Pkt.)
            ctx.font = '20px sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText('🪰', bx, 28);
        } else if (froggerCroc.bayIdx === i) {
            // Gefährliches Krokodil-Maul in der Bucht
            ctx.font = '21px sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText('🐊', bx, 29);
        }
    }

    // Row 6: Sicherer Mittelstreifen (Lila/Grünes Retro-Bankett)
    ctx.fillStyle = '#4c1d95';
    ctx.fillRect(0, 6 * FROGGER_TILE, 600, FROGGER_TILE);
    ctx.fillStyle = '#6d28d9';
    for (let mx = 0; mx < 600; mx += 20) {
        ctx.fillRect(mx, 6 * FROGGER_TILE + 4, 12, 6);
        ctx.fillRect(mx + 6, 6 * FROGGER_TILE + 30, 12, 6);
    }

    // Rows 7..11: 5-spuriger Asphalt-Highway
    ctx.fillStyle = '#0f172a';
    ctx.fillRect(0, 7 * FROGGER_TILE, 600, 5 * FROGGER_TILE);
    ctx.strokeStyle = 'rgba(255, 255, 255, 0.22)';
    ctx.setLineDash([16, 14]);
    for (let r = 8; r <= 11; r++) {
        ctx.beginPath();
        ctx.moveTo(0, r * FROGGER_TILE);
        ctx.lineTo(600, r * FROGGER_TILE);
        ctx.stroke();
    }
    ctx.setLineDash([]);

    // Row 12: Sicherer Start-Bürgersteig + Zeitbalken
    ctx.fillStyle = '#4c1d95';
    ctx.fillRect(0, 12 * FROGGER_TILE, 600, FROGGER_TILE);
    ctx.fillStyle = '#6d28d9';
    for (let mx = 0; mx < 600; mx += 20) {
        ctx.fillRect(mx, 12 * FROGGER_TILE + 4, 12, 6);
    }

    // 2. Fluss-Objekte & Straßen-Fahrzeuge zeichnen
    froggerLanes.forEach((lane) => {
        const y = lane.row * FROGGER_TILE;
        lane.items.forEach((it) => {
            if (lane.zone === 'river') {
                if (it.type === 'log') {
                    // Holz-Baumstamm mit Rinden-Maserung
                    ctx.fillStyle = '#78350f';
                    ctx.beginPath();
                    ctx.roundRect(it.x, y + 6, it.w, 28, 13);
                    ctx.fill();
                    ctx.strokeStyle = '#451a03';
                    ctx.lineWidth = 2;
                    ctx.stroke();

                    // Jahresringe links/rechts + Maserung
                    ctx.fillStyle = '#b45309';
                    ctx.beginPath();
                    ctx.ellipse(it.x + 10, y + 20, 5, 10, 0, 0, Math.PI * 2);
                    ctx.fill();
                    ctx.strokeStyle = '#92400e';
                    ctx.beginPath();
                    ctx.moveTo(it.x + 24, y + 14);
                    ctx.lineTo(it.x + it.w - 18, y + 14);
                    ctx.moveTo(it.x + 32, y + 25);
                    ctx.lineTo(it.x + it.w - 14, y + 25);
                    ctx.stroke();

                    // Lady-Frog auf dem Baumstamm?
                    if (it.hasLady) {
                        froggerDrawFrogSprite(ctx, it.x + it.ladyOffset, y + 20, 'left', '#ec4899', '#fbcfe8', 0.85);
                    }
                } else if (it.type === 'croc') {
                    // Schwimmendes Krokodil (Rücken betretbar, Maul rechts schnappt!)
                    ctx.fillStyle = '#15803d';
                    ctx.beginPath();
                    ctx.roundRect(it.x, y + 8, it.w - 22, 24, 10);
                    ctx.fill();
                    // Krokodil-Schuppen
                    ctx.fillStyle = '#166534';
                    for (let sx = it.x + 10; sx < it.x + it.w - 28; sx += 16) {
                        ctx.fillRect(sx, y + 12, 8, 16);
                    }
                    // Offenes Maul mit Zähnen rechts
                    ctx.fillStyle = '#dc2626';
                    ctx.beginPath();
                    ctx.moveTo(it.x + it.w - 22, y + 9);
                    ctx.lineTo(it.x + it.w, y + 5);
                    ctx.lineTo(it.x + it.w - 6, y + 20);
                    ctx.lineTo(it.x + it.w, y + 35);
                    ctx.lineTo(it.x + it.w - 22, y + 31);
                    ctx.closePath();
                    ctx.fill();
                } else if (it.type === 'turtle') {
                    if (it.diveState === 'submerged') {
                        // Nur noch Wasserblasen/Wirbel sichtbar!
                        ctx.strokeStyle = 'rgba(125, 211, 252, 0.55)';
                        ctx.lineWidth = 1.5;
                        for (let ti = 0; ti < it.count; ti++) {
                            const tx = it.x + 19 + ti * 36;
                            ctx.beginPath();
                            ctx.arc(tx, y + 20, 11, 0, Math.PI * 2);
                            ctx.stroke();
                        }
                    } else {
                        const isDivingWarn = it.diveState === 'diving';
                        for (let ti = 0; ti < it.count; ti++) {
                            const tx = it.x + 19 + ti * 36;
                            const ty = y + 20;
                            // Flossen
                            ctx.fillStyle = '#4ade80';
                            ctx.fillRect(tx - 12, ty - 13, 5, 5);
                            ctx.fillRect(tx + 7,  ty - 13, 5, 5);
                            ctx.fillRect(tx - 12, ty + 8,  5, 5);
                            ctx.fillRect(tx + 7,  ty + 8,  5, 5);
                            // Kopf links
                            ctx.beginPath();
                            ctx.arc(tx - 14, ty, 4.5, 0, Math.PI * 2);
                            ctx.fill();
                            // Panzer
                            ctx.fillStyle = isDivingWarn ? '#f97316' : '#dc2626';
                            ctx.beginPath();
                            ctx.arc(tx, ty, 12.5, 0, Math.PI * 2);
                            ctx.fill();
                            ctx.strokeStyle = isDivingWarn ? '#fde047' : '#16a34a';
                            ctx.lineWidth = 2.5;
                            ctx.stroke();
                        }
                    }
                }
            } else {
                // Straßen-Fahrzeuge (Rows 7..11)
                const goingRight = lane.speed > 0;
                ctx.fillStyle = '#020617';
                // 4 Reifen
                ctx.fillRect(it.x + 5, y + 4, 9, 4);
                ctx.fillRect(it.x + it.w - 14, y + 4, 9, 4);
                ctx.fillRect(it.x + 5, y + 32, 9, 4);
                ctx.fillRect(it.x + it.w - 14, y + 32, 9, 4);

                // Karosserie
                ctx.fillStyle = it.color;
                ctx.beginPath();
                ctx.roundRect(it.x, y + 7, it.w, 26, 6);
                ctx.fill();

                if (it.type === 'truck') {
                    // Führerhaus + langer Anhänger
                    ctx.fillStyle = '#ef4444';
                    ctx.fillRect(it.x, y + 8, 22, 24);
                    ctx.fillStyle = '#94a3b8';
                    ctx.fillRect(it.x + 25, y + 10, it.w - 29, 20);
                } else {
                    // Windschutzscheibe
                    ctx.fillStyle = '#1e293b';
                    const wx = goingRight ? it.x + it.w - 15 : it.x + 6;
                    ctx.fillRect(wx, y + 10, 9, 20);
                }

                // Scheinwerfer
                ctx.fillStyle = '#fef08a';
                const hlX = goingRight ? it.x + it.w - 2 : it.x;
                ctx.fillRect(hlX, y + 9, 2, 5);
                ctx.fillRect(hlX, y + 26, 2, 5);
            }
        });
    });

    // 3. Mittelstreifen-Schlange (Row 6, ab Level 2)
    if (froggerSnake.active) {
        const sy = 6 * FROGGER_TILE + 20;
        ctx.strokeStyle = '#a3e635';
        ctx.lineWidth = 5;
        ctx.beginPath();
        for (let i = 0; i <= 36; i += 6) {
            const wy = sy + Math.sin((performance.now() * 0.015) + i * 0.4) * 5;
            if (i === 0) ctx.moveTo(froggerSnake.x + i, wy);
            else ctx.lineTo(froggerSnake.x + i, wy);
        }
        ctx.stroke();
        // Schlangenkopf + rote Zunge
        const headX = froggerSnake.vx > 0 ? froggerSnake.x + 38 : froggerSnake.x - 2;
        ctx.fillStyle = '#65a30d';
        ctx.beginPath();
        ctx.arc(headX, sy, 5, 0, Math.PI * 2);
        ctx.fill();
    }

    // 4. Spieler-Frosch zeichnen
    const fcx = froggerFrog.x + FROGGER_TILE / 2;
    const fcy = froggerFrog.y + FROGGER_TILE / 2;
    if (froggerFrog.deadTimer > 0) {
        // Totenkopf / Kreuz bei Verlust eines Lebens
        ctx.fillStyle = 'rgba(239, 68, 68, 0.88)';
        ctx.beginPath();
        ctx.arc(fcx, fcy, 16, 0, Math.PI * 2);
        ctx.fill();
        ctx.font = '20px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('💀', fcx, fcy + 7);
    } else {
        const scale = 1.0 + froggerFrog.hopAnim * 0.22;
        froggerDrawFrogSprite(
            ctx,
            fcx,
            fcy,
            froggerFrog.dir,
            froggerFrog.hasLady ? '#10b981' : '#22c55e',
            froggerFrog.hasLady ? '#f472b6' : '#bbf7d0',
            scale
        );
    }

    // 5. Unten rechts auf Row 12: Arcade TIME-Balken + Status-Text
    const barW = 150;
    const pct  = Math.max(0, Math.min(1, froggerFrogTimer / 45.0));
    ctx.fillStyle = 'rgba(15, 23, 42, 0.88)';
    ctx.fillRect(6, 12 * FROGGER_TILE + 8, 588, 26);

    ctx.fillStyle = '#fde047';
    ctx.font = 'bold 11px monospace';
    ctx.textAlign = 'left';
    ctx.fillText(froggerBanner.slice(0, 56), 14, 12 * FROGGER_TILE + 25);

    ctx.fillStyle = '#ffffff';
    ctx.textAlign = 'right';
    ctx.fillText('TIME', 432, 12 * FROGGER_TILE + 25);
    ctx.fillStyle = '#334155';
    ctx.fillRect(438, 12 * FROGGER_TILE + 13, barW, 14);
    ctx.fillStyle = pct > 0.25 ? '#22c55e' : '#ef4444';
    ctx.fillRect(438, 12 * FROGGER_TILE + 13, barW * pct, 14);

    // 6. Schwebende Bonus-Popups
    froggerPopups.forEach((p) => {
        ctx.fillStyle = p.color;
        ctx.font = 'extrabold 14px monospace';
        ctx.textAlign = 'center';
        ctx.fillText(p.text, p.x, p.y);
    });
}

function froggerLoop(ts) {
    const dt = Math.min(0.05, Math.max(0.001, (ts - froggerLastFrameTs) / 1000));
    froggerLastFrameTs = ts;

    if (activeGameKey === 'frogger') {
        froggerUpdate(dt);
        froggerDraw();
    }
    froggerRafId = requestAnimationFrame(froggerLoop);
}

document.addEventListener('DOMContentLoaded', () => {
    renderLeaderboardRows(<?= json_encode($initialLeaderboard) ?>);
    renderFavoriteGameStars();
    gummiInit();
    trInit();
    dartsInit();
    froggerInit();
    imInit();
    lemInit();
    ninjaInit();
    canabaltInit();
    invadersInit();
    pacmanInit();
    kongInit();
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
