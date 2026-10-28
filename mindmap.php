<?php
declare(strict_types=1);

/**
 * MiniCloud - Mind-Map-Editor & Ideensammler (mindmap.php)
 * Interaktive Zeichenfläche für visuelle Mind-Maps mit Tastatur-Steuerung (Tab/Enter/Entf),
 * Auto-Layout (Baum & Radial), Vorlagen, Team-Freigabe, Export (PNG/SVG/JSON/Cloud-Datei)
 * und 1-Klick-Umwandlung von Knoten in Aufgaben (tasks.php) oder Notizen (notes.php).
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$pdo = getDB();
$userId = (int)$_SESSION['user_id'];
$username = (string)($_SESSION['username'] ?? 'User');
$appSettings = getAppSettings($pdo);

if (($appSettings['enable_mindmap'] ?? '1') !== '1' && !isAdmin()) {
    setFlash('error', 'Das Mind-Map-Modul ist derzeit deaktiviert.');
    header('Location: index.php');
    exit;
}

/**
 * Stellt sicher, dass die Tabelle `mindmaps` existiert.
 */
function ensureMindmapSchema(PDO $pdo): void
{
    static $done = false;
    if ($done || (!empty($_SESSION['_mc_mindmap_schema_ok']) && $_SESSION['_mc_mindmap_schema_ok'] === 1)) {
        $done = true;
        return;
    }
    $done = true;

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `mindmaps` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT NOT NULL,
            `title` VARCHAR(190) NOT NULL,
            `description` VARCHAR(500) NOT NULL DEFAULT '',
            `map_json` LONGTEXT NOT NULL,
            `shared_users` VARCHAR(500) NOT NULL DEFAULT '',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_mindmaps_user` (`user_id`),
            KEY `idx_mindmaps_updated` (`updated_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['_mc_mindmap_schema_ok'] = 1;
    }
}

ensureMindmapSchema($pdo);

/**
 * Liefert eine Vorlage als Array von Knoten zurück.
 *
 * @return array{layout: string, nodes: array<int, array<string, mixed>>}
 */
function getMindmapTemplateData(string $template, string $rootTitle): array
{
    $cleanRoot = trim($rootTitle) !== '' ? trim($rootTitle) : 'Zentrales Thema';

    if ($template === 'project') {
        return [
            'layout' => 'radial',
            'nodes'  => [
                ['id' => 'root', 'parentId' => null, 'text' => $cleanRoot, 'x' => 0, 'y' => 0, 'color' => '#2563eb', 'icon' => '🚀', 'note' => 'Hauptziel des Projekts', 'collapsed' => false],
                ['id' => 'n1', 'parentId' => 'root', 'text' => 'Ziele & Vision', 'x' => -280, 'y' => -140, 'color' => '#059669', 'icon' => '🎯', 'note' => '', 'collapsed' => false],
                ['id' => 'n1_1', 'parentId' => 'n1', 'text' => 'Kernnutzen', 'x' => -480, 'y' => -180, 'color' => '#059669', 'icon' => '💡', 'note' => '', 'collapsed' => false],
                ['id' => 'n1_2', 'parentId' => 'n1', 'text' => 'Erfolgskriterien', 'x' => -480, 'y' => -100, 'color' => '#059669', 'icon' => '✅', 'note' => '', 'collapsed' => false],
                ['id' => 'n2', 'parentId' => 'root', 'text' => 'Meilensteine', 'x' => 280, 'y' => -140, 'color' => '#7c3aed', 'icon' => '📅', 'note' => '', 'collapsed' => false],
                ['id' => 'n2_1', 'parentId' => 'n2', 'text' => 'Phase 1: Konzept', 'x' => 480, 'y' => -180, 'color' => '#7c3aed', 'icon' => '📌', 'note' => '', 'collapsed' => false],
                ['id' => 'n2_2', 'parentId' => 'n2', 'text' => 'Phase 2: Umsetzung', 'x' => 480, 'y' => -100, 'color' => '#7c3aed', 'icon' => '🔥', 'note' => '', 'collapsed' => false],
                ['id' => 'n3', 'parentId' => 'root', 'text' => 'Aufgaben & Team', 'x' => -280, 'y' => 140, 'color' => '#d97706', 'icon' => '📋', 'note' => '', 'collapsed' => false],
                ['id' => 'n3_1', 'parentId' => 'n3', 'text' => 'To-Dos verteilen', 'x' => -480, 'y' => 140, 'color' => '#d97706', 'icon' => '✅', 'note' => '', 'collapsed' => false],
                ['id' => 'n4', 'parentId' => 'root', 'text' => 'Risiken & Budget', 'x' => 280, 'y' => 140, 'color' => '#dc2626', 'icon' => '⚠️', 'note' => '', 'collapsed' => false],
                ['id' => 'n4_1', 'parentId' => 'n4', 'text' => 'Kostenrahmen', 'x' => 480, 'y' => 140, 'color' => '#dc2626', 'icon' => '💰', 'note' => '', 'collapsed' => false],
            ],
        ];
    }

    if ($template === 'travel') {
        return [
            'layout' => 'radial',
            'nodes'  => [
                ['id' => 'root', 'parentId' => null, 'text' => $cleanRoot, 'x' => 0, 'y' => 0, 'color' => '#0891b2', 'icon' => '🌴', 'note' => 'Reise- & Urlaubsplanung', 'collapsed' => false],
                ['id' => 'n1', 'parentId' => 'root', 'text' => 'Anreise & Unterkunft', 'x' => -280, 'y' => -130, 'color' => '#2563eb', 'icon' => '🏠', 'note' => '', 'collapsed' => false],
                ['id' => 'n1_1', 'parentId' => 'n1', 'text' => 'Hotel / Ferienwohnung', 'x' => -490, 'y' => -165, 'color' => '#2563eb', 'icon' => '📌', 'note' => '', 'collapsed' => false],
                ['id' => 'n1_2', 'parentId' => 'n1', 'text' => 'Tickets & Buchung', 'x' => -490, 'y' => -95, 'color' => '#2563eb', 'icon' => '✅', 'note' => '', 'collapsed' => false],
                ['id' => 'n2', 'parentId' => 'root', 'text' => 'Packliste', 'x' => 280, 'y' => -130, 'color' => '#059669', 'icon' => '🎒', 'note' => '', 'collapsed' => false],
                ['id' => 'n2_1', 'parentId' => 'n2', 'text' => 'Dokumente & Ausweise', 'x' => 490, 'y' => -165, 'color' => '#059669', 'icon' => '⚠️', 'note' => '', 'collapsed' => false],
                ['id' => 'n2_2', 'parentId' => 'n2', 'text' => 'Kleidung & Technik', 'x' => 490, 'y' => -95, 'color' => '#059669', 'icon' => '💡', 'note' => '', 'collapsed' => false],
                ['id' => 'n3', 'parentId' => 'root', 'text' => 'Ausflüge & Highlights', 'x' => -280, 'y' => 130, 'color' => '#d97706', 'icon' => '🔥', 'note' => '', 'collapsed' => false],
                ['id' => 'n4', 'parentId' => 'root', 'text' => 'Reisebudget', 'x' => 280, 'y' => 130, 'color' => '#7c3aed', 'icon' => '💰', 'note' => '', 'collapsed' => false],
            ],
        ];
    }

    if ($template === 'study') {
        return [
            'layout' => 'tree',
            'nodes'  => [
                ['id' => 'root', 'parentId' => null, 'text' => $cleanRoot, 'x' => -320, 'y' => 0, 'color' => '#7c3aed', 'icon' => '📚', 'note' => 'Wissens- & Themenübersicht', 'collapsed' => false],
                ['id' => 'n1', 'parentId' => 'root', 'text' => '1. Grundlagen', 'x' => -40, 'y' => -140, 'color' => '#2563eb', 'icon' => '💡', 'note' => '', 'collapsed' => false],
                ['id' => 'n1_1', 'parentId' => 'n1', 'text' => 'Definition & Begriffe', 'x' => 240, 'y' => -140, 'color' => '#2563eb', 'icon' => '📌', 'note' => '', 'collapsed' => false],
                ['id' => 'n2', 'parentId' => 'root', 'text' => '2. Praxisbeispiele', 'x' => -40, 'y' => 0, 'color' => '#059669', 'icon' => '🎯', 'note' => '', 'collapsed' => false],
                ['id' => 'n3', 'parentId' => 'root', 'text' => '3. Offene Fragen', 'x' => -40, 'y' => 140, 'color' => '#d97706', 'icon' => '❓', 'note' => '', 'collapsed' => false],
            ],
        ];
    }

    // Standard: Brainstorming
    return [
        'layout' => 'radial',
        'nodes'  => [
            ['id' => 'root', 'parentId' => null, 'text' => $cleanRoot, 'x' => 0, 'y' => 0, 'color' => '#2563eb', 'icon' => '🧠', 'note' => '', 'collapsed' => false],
            ['id' => 'n1', 'parentId' => 'root', 'text' => 'Idee 1', 'x' => -260, 'y' => -110, 'color' => '#059669', 'icon' => '💡', 'note' => '', 'collapsed' => false],
            ['id' => 'n2', 'parentId' => 'root', 'text' => 'Idee 2', 'x' => 260, 'y' => -110, 'color' => '#7c3aed', 'icon' => '🚀', 'note' => '', 'collapsed' => false],
            ['id' => 'n3', 'parentId' => 'root', 'text' => 'Idee 3', 'x' => -260, 'y' => 110, 'color' => '#d97706', 'icon' => '📌', 'note' => '', 'collapsed' => false],
            ['id' => 'n4', 'parentId' => 'root', 'text' => 'Idee 4', 'x' => 260, 'y' => 110, 'color' => '#db2777', 'icon' => '🔥', 'note' => '', 'collapsed' => false],
        ],
    ];
}

/**
 * Prüft, ob ein Benutzer Zugriff auf eine Mind-Map hat.
 *
 * @param array<string, mixed> $mapRow
 */
function canAccessMindmap(array $mapRow, int $userId): bool
{
    if ((int)$mapRow['user_id'] === $userId) {
        return true;
    }
    $shared = array_filter(array_map('intval', explode(',', (string)($mapRow['shared_users'] ?? ''))));
    return in_array($userId, $shared, true);
}

// --- AJAX- & POST-Aktionen ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!verifyCsrfToken($csrf)) {
        if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Ungültiges Sicherheits-Token (CSRF).']);
            exit;
        }
        setFlash('error', 'Ungültiges Sicherheits-Token.');
        header('Location: mindmap.php');
        exit;
    }

    $action = (string)($_POST['action'] ?? '');

    // 1. Neue Mind-Map anlegen
    if ($action === 'create_map') {
        $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 180);
        $desc  = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 480);
        $tpl   = (string)($_POST['template'] ?? 'brainstorm');
        if ($title === '') {
            $title = 'Neue Mind-Map';
        }

        $tplData = getMindmapTemplateData($tpl, $title);
        $stmt = $pdo->prepare(
            'INSERT INTO mindmaps (user_id, title, description, map_json, shared_users)
             VALUES (:uid, :title, :desc, :json, :shared)'
        );
        $stmt->execute([
            ':uid'    => $userId,
            ':title'  => $title,
            ':desc'   => $desc,
            ':json'   => json_encode($tplData, JSON_UNESCAPED_UNICODE),
            ':shared' => '',
        ]);
        $newId = (int)$pdo->lastInsertId();
        setFlash('success', 'Mind-Map „' . $title . '“ wurde erstellt.');
        header('Location: mindmap.php?id=' . $newId);
        exit;
    }

    // 2. Mind-Map löschen
    if ($action === 'delete_map') {
        $mapId = (int)($_POST['map_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM mindmaps WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $mapId]);
        $row = $stmt->fetch();
        if ($row && (int)$row['user_id'] === $userId) {
            $pdo->prepare('DELETE FROM mindmaps WHERE id = :id')->execute([':id' => $mapId]);
            setFlash('success', 'Mind-Map wurde gelöscht.');
        } else {
            setFlash('error', 'Mind-Map konnte nicht gelöscht werden (nur Eigentümer).');
        }
        header('Location: mindmap.php');
        exit;
    }

    // 3. Mind-Map speichern (AJAX)
    if ($action === 'save_map') {
        header('Content-Type: application/json; charset=utf-8');
        $mapId = (int)($_POST['map_id'] ?? 0);
        $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 180);
        $desc  = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 480);
        $rawJson = (string)($_POST['map_json'] ?? '');
        if ($rawJson === '' || strlen($rawJson) > 5 * 1024 * 1024) {
            echo json_encode(['ok' => false, 'error' => 'Mind-Map-Daten sind leer oder zu groß (max. 5 MB).']);
            exit;
        }
        $sharedUsersRaw = (string)($_POST['shared_users'] ?? '');

        $stmt = $pdo->prepare('SELECT * FROM mindmaps WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $mapId]);
        $row = $stmt->fetch();
        if (!$row || !canAccessMindmap($row, $userId)) {
            echo json_encode(['ok' => false, 'error' => 'Kein Zugriff auf diese Mind-Map.']);
            exit;
        }

        $decoded = json_decode($rawJson, true);
        if (!is_array($decoded) || empty($decoded['nodes']) || !is_array($decoded['nodes'])) {
            echo json_encode(['ok' => false, 'error' => 'Ungültige Mind-Map-Daten.']);
            exit;
        }

        if ($title === '') {
            $title = (string)$row['title'];
        }

        // Nur der Eigentümer darf die Freigabe-Liste ändern (Prüfung auf allow_user_sharing = 1 & is_locked = 0)
        $sharedClean = (string)$row['shared_users'];
        if ((int)$row['user_id'] === $userId && isset($_POST['shared_users'])) {
            $candIds = array_values(array_unique(array_filter(array_map('intval', explode(',', $sharedUsersRaw)), static fn(int $id): bool => $id > 0 && $id !== $userId)));
            $validIds = [];
            if (!empty($candIds)) {
                $placeholders = implode(',', array_fill(0, count($candIds), '?'));
                $stmtVis = $pdo->prepare("SELECT id FROM users WHERE id IN ($placeholders) AND allow_user_sharing = 1 AND is_locked = 0");
                $stmtVis->execute($candIds);
                $validIds = array_map('intval', $stmtVis->fetchAll(PDO::FETCH_COLUMN));
            }
            $sharedClean = implode(',', $validIds);
        }

        $stmtUpd = $pdo->prepare(
            'UPDATE mindmaps
             SET title = :title, description = :desc, map_json = :json, shared_users = :shared, updated_at = NOW()
             WHERE id = :id'
        );
        $stmtUpd->execute([
            ':title'  => $title,
            ':desc'   => $desc,
            ':json'   => json_encode($decoded, JSON_UNESCAPED_UNICODE),
            ':shared' => $sharedClean,
            ':id'     => $mapId,
        ]);

        echo json_encode([
            'ok'         => true,
            'updated_at' => date('d.m.Y H:i:s'),
            'title'      => $title,
        ]);
        exit;
    }

    // 4. Knoten als Aufgabe in tasks.php anlegen (AJAX)
    if ($action === 'node_to_task') {
        header('Content-Type: application/json; charset=utf-8');
        $nodeTitle = mb_substr(trim((string)($_POST['node_text'] ?? '')), 0, 190);
        $nodeNote  = mb_substr(trim((string)($_POST['node_note'] ?? '')), 0, 2000);
        $mapTitle  = mb_substr(trim((string)($_POST['map_title'] ?? 'Mind-Map')), 0, 100);

        if ($nodeTitle === '') {
            echo json_encode(['ok' => false, 'error' => 'Knoten-Text darf nicht leer sein.']);
            exit;
        }

        try {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS `tasks` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `user_id` INT NOT NULL,
                    `title` VARCHAR(255) NOT NULL,
                    `description` TEXT DEFAULT NULL,
                    `status` VARCHAR(32) NOT NULL DEFAULT 'todo',
                    `priority` VARCHAR(20) NOT NULL DEFAULT 'medium',
                    `due_date` DATE DEFAULT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );

            $descText = $nodeNote !== ''
                ? ($nodeNote . "\n\n(Aus Mind-Map: " . $mapTitle . ')')
                : ('Erstellt aus Mind-Map: ' . $mapTitle);

            $stmtTask = $pdo->prepare(
                "INSERT INTO tasks (user_id, title, description, status, priority)
                 VALUES (:uid, :title, :desc, 'todo', 'medium')"
            );
            $stmtTask->execute([
                ':uid'   => $userId,
                ':title' => $nodeTitle,
                ':desc'  => $descText,
            ]);

            echo json_encode([
                'ok'      => true,
                'message' => 'Aufgabe „' . $nodeTitle . '“ wurde im Aufgaben-Board angelegt!',
            ]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => 'Aufgabe konnte nicht erstellt werden: ' . $e->getMessage()]);
        }
        exit;
    }

    // 5. Knoten / Gliederung als Haftnotiz in notes.php anlegen (AJAX)
    if ($action === 'node_to_note') {
        header('Content-Type: application/json; charset=utf-8');
        $noteTitle   = mb_substr(trim((string)($_POST['note_title'] ?? '')), 0, 190);
        $noteContent = mb_substr(trim((string)($_POST['note_content'] ?? '')), 0, 5000);

        if ($noteTitle === '' && $noteContent === '') {
            echo json_encode(['ok' => false, 'error' => 'Notizinhalt darf nicht leer sein.']);
            exit;
        }

        try {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS `notes` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `user_id` INT NOT NULL,
                    `title` VARCHAR(255) NOT NULL DEFAULT '',
                    `content` TEXT NOT NULL,
                    `color` VARCHAR(30) NOT NULL DEFAULT 'yellow',
                    `is_pinned` TINYINT(1) NOT NULL DEFAULT 0,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );

            $stmtNote = $pdo->prepare(
                "INSERT INTO notes (user_id, title, content, color, is_pinned)
                 VALUES (:uid, :title, :content, 'blue', 0)"
            );
            $stmtNote->execute([
                ':uid'     => $userId,
                ':title'   => $noteTitle !== '' ? $noteTitle : 'Mind-Map-Notiz',
                ':content' => $noteContent,
            ]);

            echo json_encode([
                'ok'      => true,
                'message' => 'Haftnotiz „' . $noteTitle . '“ wurde an die Notizwand geheftet!',
            ]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => 'Notiz konnte nicht angelegt werden.']);
        }
        exit;
    }

    // 6. Mind-Map als Markdown-Gliederung oder SVG direkt in die Cloud-Dateien (index.php) speichern
    if ($action === 'export_to_cloud') {
        header('Content-Type: application/json; charset=utf-8');
        $format  = (string)($_POST['format'] ?? 'md');
        $content = (string)($_POST['content'] ?? '');
        $title   = preg_replace('/[^a-zA-Z0-9äöüÄÖÜß_\- ]/u', '', trim((string)($_POST['title'] ?? 'Mind-Map'))) ?: 'Mind-Map';

        if ($content === '' || strlen($content) > 10 * 1024 * 1024) {
            echo json_encode(['ok' => false, 'error' => 'Ungültige oder zu große Export-Daten.']);
            exit;
        }

        if ($format === 'svg') {
            // Sicherheits-Sanitisierung: keine ausführbaren Skripte oder Event-Handler in exportierten SVGs zulassen
            $content = (string)preg_replace('#<script\b[^>]*>.*?</script>#is', '', $content);
            $content = (string)preg_replace('/\bon[a-z]+\s*=\s*(["\']).*?\1/i', '', $content);
            $content = (string)preg_replace('/javascript\s*:/i', '', $content);
        }

        $ext  = $format === 'svg' ? 'svg' : ($format === 'json' ? 'json' : 'md');
        $mime = $format === 'svg' ? 'image/svg+xml' : ($format === 'json' ? 'application/json' : 'text/markdown');
        $origName = $title . '_' . date('Y-m-d') . '.' . $ext;

        $bytes = strlen($content);
        $stats = getUserStorageStats($pdo, $userId);
        if ($bytes > $stats['free']) {
            echo json_encode(['ok' => false, 'error' => 'Nicht genügend freier Cloud-Speicherplatz.']);
            exit;
        }

        if (!is_dir(UPLOAD_DIR)) {
            @mkdir(UPLOAD_DIR, 0755, true);
        }
        $storedName = generateSafeStoredFilename();
        $destPath = UPLOAD_DIR . '/' . $storedName;

        if (@file_put_contents($destPath, $content) === false) {
            echo json_encode(['ok' => false, 'error' => 'Datei konnte nicht gespeichert werden.']);
            exit;
        }

        $stmtFile = $pdo->prepare(
            "INSERT INTO files (user_id, folder_path, original_name, stored_name, file_size, mime_type)
             VALUES (:uid, 'Mind-Maps', :orig, :stored, :size, :mime)"
        );
        $stmtFile->execute([
            ':uid'    => $userId,
            ':orig'   => $origName,
            ':stored' => $storedName,
            ':size'   => $bytes,
            ':mime'   => $mime,
        ]);

        // Sicherstellen, dass der virtuelle Ordner 'Mind-Maps' existiert
        try {
            $pdo->prepare("INSERT IGNORE INTO user_folders (user_id, folder_path) VALUES (:uid, 'Mind-Maps')")
                ->execute([':uid' => $userId]);
        } catch (Throwable) {}

        echo json_encode([
            'ok'      => true,
            'message' => 'Gespeichert unter Dateien → Ordner „Mind-Maps“ als „' . $origName . '“!',
        ]);
        exit;
    }
}

// --- Alle zugänglichen Mind-Maps des Benutzers laden ---
$stmtMaps = $pdo->prepare(
    'SELECT m.*, u.username AS owner_name
     FROM mindmaps m
     LEFT JOIN users u ON u.id = m.user_id
     WHERE m.user_id = :uid
        OR FIND_IN_SET(:uid_str, m.shared_users) > 0
     ORDER BY m.updated_at DESC'
);
$stmtMaps->execute([
    ':uid'     => $userId,
    ':uid_str' => (string)$userId,
]);
$allMaps = $stmtMaps->fetchAll();

// Falls der Benutzer noch gar keine Mind-Map hat, legen wir automatisch eine Willkommens-Mind-Map an
if (empty($allMaps)) {
    $welcomeData = getMindmapTemplateData('project', 'Willkommen im Mind-Map-Editor');
    $stmtInit = $pdo->prepare(
        'INSERT INTO mindmaps (user_id, title, description, map_json, shared_users)
         VALUES (:uid, :title, :desc, :json, :shared)'
    );
    $stmtInit->execute([
        ':uid'    => $userId,
        ':title'  => 'Willkommen im Mind-Map-Editor',
        ':desc'   => 'Beispiel-Mind-Map zum Ausprobieren (Doppelklick zum Bearbeiten, Tab für Unterknoten)',
        ':json'   => json_encode($welcomeData, JSON_UNESCAPED_UNICODE),
        ':shared' => '',
    ]);
    $newMapId = (int)$pdo->lastInsertId();
    header('Location: mindmap.php?id=' . $newMapId);
    exit;
}

$activeMapId = isset($_GET['id']) ? (int)$_GET['id'] : (int)$allMaps[0]['id'];
$activeMap = null;
foreach ($allMaps as $m) {
    if ((int)$m['id'] === $activeMapId) {
        $activeMap = $m;
        break;
    }
}
if ($activeMap === null) {
    $activeMap = $allMaps[0];
    $activeMapId = (int)$activeMap['id'];
}

$isOwner = ((int)$activeMap['user_id'] === $userId);

// Andere Cloud-Benutzer für Team-Freigabe laden
$otherUsers = [];
try {
    $stmtUsers = $pdo->prepare('SELECT id, username FROM users WHERE id != :uid ORDER BY username ASC');
    $stmtUsers->execute([':uid' => $userId]);
    $otherUsers = $stmtUsers->fetchAll();
} catch (Throwable) {
    $otherUsers = [];
}

$sharedUserIds = array_filter(array_map('intval', explode(',', (string)($activeMap['shared_users'] ?? ''))));

$pageTitle = 'Mind-Map-Editor';
require_once __DIR__ . '/includes/header.php';
?>

<div class="space-y-4">
    <!-- Obere Aktions- und Auswahlleiste -->
    <div class="mc-card rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-xs p-4 sm:p-5">
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-3">
            <!-- Links: Titel & Auswahl der Mind-Map -->
            <div class="flex flex-wrap items-center gap-3">
                <div class="w-11 h-11 rounded-2xl bg-brand-50 dark:bg-brand-950/70 border border-brand-200/70 dark:border-brand-800/70 text-brand-600 dark:text-brand-400 flex items-center justify-center text-2xl shrink-0">🧠</div>
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="text-xl sm:text-2xl font-bold text-slate-900 dark:text-white">Mind-Map-Editor</h1>
                        <span id="saveStatusBadge" class="px-2 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-50 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-300 border border-emerald-200/70 dark:border-emerald-800/70">
                            ✓ Gespeichert
                        </span>
                    </div>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400">
                        <kbd class="px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-[10px]">Tab</kbd> Unterknoten ·
                        <kbd class="px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-[10px]">Enter</kbd> Nachbarknoten ·
                        <kbd class="px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-[10px]">Doppelklick</kbd> Text ändern ·
                        <kbd class="px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-[10px]">Entf</kbd> Löschen
                    </p>
                </div>

                <!-- Map-Umschalter -->
                <div class="flex items-center gap-1.5 ml-0 sm:ml-3">
                    <select
                        onchange="if(this.value) window.location.href='mindmap.php?id='+this.value"
                        class="px-3 py-1.5 rounded-xl text-xs sm:text-sm font-semibold bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-brand-500"
                    >
                        <?php foreach ($allMaps as $m): ?>
                            <option value="<?= (int)$m['id'] ?>" <?= (int)$m['id'] === $activeMapId ? 'selected' : '' ?>>
                                <?= e((string)$m['title']) ?><?= (int)$m['user_id'] !== $userId ? ' (geteilt von ' . e((string)$m['owner_name']) . ')' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <button
                        type="button"
                        onclick="document.getElementById('newMindmapModal').classList.remove('hidden')"
                        class="inline-flex items-center gap-1 px-3 py-1.5 rounded-xl text-xs font-semibold bg-brand-600 hover:bg-brand-700 text-white shadow-2xs transition-colors cursor-pointer"
                    >
                        <span>＋</span>
                        <span>Neue Map</span>
                    </button>
                </div>
            </div>

            <!-- Rechts: Auto-Layout, Export & Speichern -->
            <div class="flex flex-wrap items-center gap-1.5">
                <button
                    type="button"
                    onclick="applyAutoLayout('radial')"
                    class="px-2.5 py-1.5 rounded-xl text-xs font-semibold bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 border border-slate-200/80 dark:border-slate-700 transition-colors cursor-pointer"
                    title="Knoten sternförmig um die Mitte anordnen"
                >
                    ✨ Radial-Layout
                </button>
                <button
                    type="button"
                    onclick="applyAutoLayout('tree')"
                    class="px-2.5 py-1.5 rounded-xl text-xs font-semibold bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 border border-slate-200/80 dark:border-slate-700 transition-colors cursor-pointer"
                    title="Knoten als Baumstruktur von links nach rechts anordnen"
                >
                    🌿 Baum-Layout
                </button>
                <button
                    type="button"
                    onclick="exportMindmapToCloud('md')"
                    class="px-2.5 py-1.5 rounded-xl text-xs font-semibold bg-cyan-50 dark:bg-cyan-950/50 hover:bg-cyan-100 dark:hover:bg-cyan-900/60 text-cyan-800 dark:text-cyan-300 border border-cyan-200/80 dark:border-cyan-800/70 transition-colors cursor-pointer"
                    title="Als Markdown-Gliederung direkt im Cloud-Ordner 'Mind-Maps' speichern"
                >
                    ☁️ In Cloud-Dateien
                </button>
                <button
                    type="button"
                    onclick="downloadMindmapPng()"
                    class="px-2.5 py-1.5 rounded-xl text-xs font-semibold bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 border border-slate-200/80 dark:border-slate-700 transition-colors cursor-pointer"
                    title="Als PNG-Bild herunterladen"
                >
                    🖼️ PNG
                </button>
                <button
                    type="button"
                    onclick="downloadMindmapJson()"
                    class="px-2.5 py-1.5 rounded-xl text-xs font-semibold bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 border border-slate-200/80 dark:border-slate-700 transition-colors cursor-pointer"
                    title="Als JSON-Datei exportieren"
                >
                    📥 JSON
                </button>
                <?php if ($isOwner && count($allMaps) > 1): ?>
                    <form method="post" onsubmit="return confirm('Möchtest du diese Mind-Map wirklich unwiderruflich löschen?');" class="inline m-0">
                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                        <input type="hidden" name="action" value="delete_map">
                        <input type="hidden" name="map_id" value="<?= (int)$activeMapId ?>">
                        <button
                            type="submit"
                            class="px-2.5 py-1.5 rounded-xl text-xs font-semibold bg-red-50 dark:bg-red-950/40 hover:bg-red-100 dark:hover:bg-red-900/60 text-red-600 dark:text-red-400 border border-red-200/80 dark:border-red-800/60 transition-colors cursor-pointer"
                            title="Mind-Map löschen"
                        >
                            🗑️
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Hauptbereich: Links Zeichenfläche, Rechts Knoten-Inspektor & Eigenschaften -->
    <div class="grid grid-cols-1 lg:grid-cols-4 gap-4">
        <!-- Interaktive Mind-Map-Zeichenfläche (3 Spalten) -->
        <div class="lg:col-span-3 relative rounded-2xl bg-slate-50 dark:bg-slate-950 border border-slate-200/90 dark:border-slate-800 shadow-xs overflow-hidden h-[560px] sm:h-[640px] select-none" id="mindmapViewport">
            <!-- Dezentes Punktraster im Hintergrund -->
            <div class="absolute inset-0 pointer-events-none opacity-40 dark:opacity-20" style="background-image: radial-gradient(#64748b 1px, transparent 1px); background-size: 24px 24px;"></div>

            <!-- Schnell-Werkzeugleiste oben links auf der Zeichenfläche -->
            <div class="absolute top-3 left-3 z-20 flex flex-wrap items-center gap-1.5 bg-white/95 dark:bg-slate-900/95 backdrop-blur-xs p-1.5 rounded-xl border border-slate-200/80 dark:border-slate-700 shadow-sm">
                <button type="button" onclick="addChildNode()" class="px-2.5 py-1.5 rounded-lg text-xs font-semibold bg-brand-600 hover:bg-brand-700 text-white transition-colors cursor-pointer" title="Unterknoten hinzufügen (Tab)">
                    ＋ Unterknoten
                </button>
                <button type="button" onclick="addSiblingNode()" class="px-2.5 py-1.5 rounded-lg text-xs font-semibold bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 transition-colors cursor-pointer" title="Nachbarknoten hinzufügen (Enter)">
                    ＋ Nachbar
                </button>
                <button type="button" onclick="toggleCollapseSelected()" class="px-2.5 py-1.5 rounded-lg text-xs font-semibold bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 transition-colors cursor-pointer" title="Unteräste ein-/ausklappen">
                    📂 Äste +/-
                </button>
                <button type="button" onclick="deleteSelectedNode()" class="px-2.5 py-1.5 rounded-lg text-xs font-semibold text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-950/40 transition-colors cursor-pointer" title="Ausgewählten Knoten löschen (Entf)">
                    🗑️
                </button>
            </div>

            <!-- Zoom-Steuerung unten rechts auf der Zeichenfläche -->
            <div class="absolute bottom-3 right-3 z-20 flex items-center gap-1 bg-white/95 dark:bg-slate-900/95 backdrop-blur-xs p-1.5 rounded-xl border border-slate-200/80 dark:border-slate-700 shadow-sm">
                <button type="button" onclick="zoomCanvas(-0.15)" class="w-7 h-7 rounded-lg text-xs font-bold bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 cursor-pointer" title="Verkleinern">－</button>
                <span id="zoomLevelLabel" class="px-2 text-[11px] font-semibold text-slate-600 dark:text-slate-300">100%</span>
                <button type="button" onclick="zoomCanvas(0.15)" class="w-7 h-7 rounded-lg text-xs font-bold bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 cursor-pointer" title="Vergrößern">＋</button>
                <button type="button" onclick="resetViewport()" class="px-2 h-7 rounded-lg text-[11px] font-semibold bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 cursor-pointer" title="Ansicht zentrieren">🎯 Zentrieren</button>
            </div>

            <!-- Transformierbare Ebene für SVG-Verbindungslinien und HTML-Knoten -->
            <div id="mindmapWorld" class="absolute top-0 left-0 w-full h-full origin-top-left">
                <svg id="mindmapEdgesSvg" class="overflow-visible pointer-events-none absolute top-0 left-0" width="1" height="1"></svg>
                <div id="mindmapNodesLayer" class="absolute top-0 left-0"></div>
            </div>
        </div>

        <!-- Rechte Seitenleiste: Knoten-Editor & Cloud-Verknüpfungen (1 Spalte) -->
        <div class="space-y-4">
            <!-- Ausgewählter Knoten -->
            <div class="rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 shadow-xs p-4 space-y-3.5">
                <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-800 pb-2.5">
                    <h2 class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                        🎯 Ausgewählter Knoten
                    </h2>
                    <span id="selectedNodeBadge" class="text-[11px] font-semibold text-brand-600 dark:text-brand-400">Zentralknoten</span>
                </div>

                <div class="space-y-2.5">
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-300 mb-1">Knoten-Text</label>
                        <input
                            type="text"
                            id="inspectorNodeText"
                            oninput="updateSelectedNodeFromInspector()"
                            class="w-full px-3 py-2 rounded-xl text-xs sm:text-sm bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500"
                            placeholder="Begriff oder Idee..."
                        >
                    </div>

                    <!-- Icon / Emoji-Schnellwahl -->
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-300 mb-1">Symbol / Icon</label>
                        <div class="flex flex-wrap gap-1" id="inspectorIconPicker">
                            <?php foreach (['', '💡', '🚀', '🎯', '✅', '⚠️', '📌', '🔥', '📅', '💰', '❓', '📚', '🌴', '🏠'] as $ico): ?>
                                <button
                                    type="button"
                                    onclick="setSelectedNodeIcon('<?= $ico ?>')"
                                    class="w-7 h-7 rounded-lg text-sm bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-slate-700 border border-slate-200/70 dark:border-slate-700 flex items-center justify-center cursor-pointer"
                                    title="<?= $ico === '' ? 'Kein Icon' : $ico ?>"
                                >
                                    <?= $ico === '' ? '∅' : $ico ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Farb-Schnellwahl -->
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-300 mb-1">Ast- &amp; Knotenfarbe</label>
                        <div class="flex flex-wrap gap-1.5">
                            <?php
                            $palette = [
                                '#2563eb' => 'Blau',
                                '#059669' => 'Smaragd',
                                '#7c3aed' => 'Violett',
                                '#d97706' => 'Amber',
                                '#dc2626' => 'Rot',
                                '#db2777' => 'Pink',
                                '#0891b2' => 'Cyan',
                                '#475569' => 'Schiefer',
                            ];
                            foreach ($palette as $hex => $label):
                            ?>
                                <button
                                    type="button"
                                    onclick="setSelectedNodeColor('<?= $hex ?>')"
                                    style="background-color: <?= $hex ?>"
                                    class="w-6 h-6 rounded-full border-2 border-white dark:border-slate-900 shadow-xs hover:scale-110 transition-transform cursor-pointer"
                                    title="<?= e($label) ?>"
                                ></button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Detail-Notiz zum Knoten -->
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-300 mb-1">📝 Zusatz-Notiz zum Knoten</label>
                        <textarea
                            id="inspectorNodeNote"
                            rows="3"
                            oninput="updateSelectedNodeFromInspector()"
                            class="w-full px-3 py-2 rounded-xl text-xs bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-brand-500"
                            placeholder="Details, Links oder Gedanken zu diesem Ast..."
                        ></textarea>
                    </div>

                    <!-- MiniCloud-Aktionen für den Knoten -->
                    <div class="pt-2 border-t border-slate-100 dark:border-slate-800 space-y-1.5">
                        <p class="text-[11px] font-semibold text-slate-500 dark:text-slate-400">⚡ Weiterverarbeiten in der Cloud:</p>
                        <button
                            type="button"
                            onclick="convertSelectedNodeToTask()"
                            class="w-full flex items-center justify-center gap-1.5 px-3 py-2 rounded-xl text-xs font-semibold bg-emerald-50 dark:bg-emerald-950/50 hover:bg-emerald-100 dark:hover:bg-emerald-900/60 text-emerald-800 dark:text-emerald-300 border border-emerald-200/80 dark:border-emerald-800/70 transition-colors cursor-pointer"
                        >
                            <span>📋</span>
                            <span>Als Aufgabe ins Kanban-Board</span>
                        </button>
                        <button
                            type="button"
                            onclick="convertSelectedNodeToNote()"
                            class="w-full flex items-center justify-center gap-1.5 px-3 py-2 rounded-xl text-xs font-semibold bg-amber-50 dark:bg-amber-950/50 hover:bg-amber-100 dark:hover:bg-amber-900/60 text-amber-800 dark:text-amber-300 border border-amber-200/80 dark:border-amber-800/70 transition-colors cursor-pointer"
                        >
                            <span>📌</span>
                            <span>Als Haftnotiz an die Pinnwand</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Map-Eigenschaften & Team-Freigabe -->
            <div class="rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 shadow-xs p-4 space-y-3">
                <h2 class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 border-b border-slate-100 dark:border-slate-800 pb-2">
                    ⚙️ Map-Einstellungen
                </h2>
                <div>
                    <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-300 mb-1">Titel der Mind-Map</label>
                    <input
                        type="text"
                        id="mapTitleInput"
                        value="<?= e((string)$activeMap['title']) ?>"
                        oninput="scheduleAutoSave()"
                        class="w-full px-3 py-1.5 rounded-xl text-xs font-semibold bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white"
                    >
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-300 mb-1">Kurzbeschreibung</label>
                    <input
                        type="text"
                        id="mapDescInput"
                        value="<?= e((string)$activeMap['description']) ?>"
                        oninput="scheduleAutoSave()"
                        placeholder="Optional..."
                        class="w-full px-3 py-1.5 rounded-xl text-xs bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-200"
                    >
                </div>

                <?php if ($isOwner && !empty($otherUsers)): ?>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-300 mb-1">👥 Mit Cloud-Nutzern teilen</label>
                        <div class="max-h-28 overflow-y-auto space-y-1 p-2 rounded-xl bg-slate-50 dark:bg-slate-800/70 border border-slate-200/70 dark:border-slate-700">
                            <?php foreach ($otherUsers as $u): ?>
                                <?php $uidItem = (int)$u['id']; ?>
                                <label class="flex items-center gap-2 text-xs text-slate-700 dark:text-slate-200 cursor-pointer">
                                    <input
                                        type="checkbox"
                                        class="shared-user-cb rounded border-slate-300 text-brand-600"
                                        value="<?= $uidItem ?>"
                                        <?= in_array($uidItem, $sharedUserIds, true) ? 'checked' : '' ?>
                                        onchange="scheduleAutoSave()"
                                    >
                                    <span><?= e((string)$u['username']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Neue Mind-Map erstellen -->
<div id="newMindmapModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 backdrop-blur-xs p-4">
    <div class="w-full max-w-md rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 shadow-xl p-5 space-y-4">
        <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-800 pb-3">
            <h3 class="text-base font-bold text-slate-900 dark:text-white">🧠 Neue Mind-Map anlegen</h3>
            <button type="button" onclick="document.getElementById('newMindmapModal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 cursor-pointer">✕</button>
        </div>
        <form method="post" class="space-y-3.5">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="create_map">
            <div>
                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-200 mb-1">Titel / Zentrales Thema</label>
                <input
                    type="text"
                    name="title"
                    required
                    placeholder="z.B. Sommerurlaub 2027, Hausumbau, Projekt-Ideen..."
                    class="w-full px-3.5 py-2 rounded-xl text-sm bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500"
                >
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-200 mb-1">Start-Vorlage wählen</label>
                <select
                    name="template"
                    class="w-full px-3.5 py-2 rounded-xl text-sm bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white"
                >
                    <option value="brainstorm">💡 Freies Brainstorming (4 Hauptäste)</option>
                    <option value="project">🚀 Projekt-Planung (Ziele, Meilensteine, Aufgaben, Budget)</option>
                    <option value="travel">🌴 Urlaubs- &amp; Reiseplanung (Unterkunft, Packliste, Ausflüge)</option>
                    <option value="study">📚 Wissens- &amp; Lernstruktur (Baum-Layout)</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-200 mb-1">Kurzbeschreibung (optional)</label>
                <input
                    type="text"
                    name="description"
                    placeholder="Kurze Notiz zum Zweck der Mind-Map..."
                    class="w-full px-3.5 py-2 rounded-xl text-sm bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white"
                >
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" onclick="document.getElementById('newMindmapModal').classList.add('hidden')" class="px-4 py-2 rounded-xl text-xs font-semibold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 cursor-pointer">
                    Abbrechen
                </button>
                <button type="submit" class="px-4 py-2 rounded-xl text-xs font-semibold bg-brand-600 hover:bg-brand-700 text-white cursor-pointer">
                    Mind-Map erstellen
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Toast-Benachrichtigung -->
<div id="mindmapToast" class="hidden fixed bottom-5 right-5 z-50 px-4 py-2.5 rounded-xl bg-slate-900 dark:bg-slate-100 text-white dark:text-slate-900 text-xs font-semibold shadow-lg"></div>

<script>
const CSRF_TOKEN = <?= json_encode(getCsrfToken()) ?>;
const ACTIVE_MAP_ID = <?= (int)$activeMapId ?>;
let mapData = <?= $activeMap['map_json'] ?: '{"layout":"radial","nodes":[]}' ?>;

if (!mapData || !Array.isArray(mapData.nodes) || mapData.nodes.length === 0) {
    mapData = {
        layout: 'radial',
        nodes: [{ id: 'root', parentId: null, text: 'Zentrales Thema', x: 0, y: 0, color: '#2563eb', icon: '🧠', note: '', collapsed: false }]
    };
}

let selectedNodeId = mapData.nodes[0].id;
let panX = 0;
let panY = 0;
let scale = 1;
let isPanning = false;
let panStartX = 0;
let panStartY = 0;
let draggingNodeId = null;
let dragStartMouseX = 0;
let dragStartMouseY = 0;
let dragNodeOrigX = 0;
let dragNodeOrigY = 0;
let autoSaveTimer = null;

const viewport = document.getElementById('mindmapViewport');
const world = document.getElementById('mindmapWorld');
const edgesSvg = document.getElementById('mindmapEdgesSvg');
const nodesLayer = document.getElementById('mindmapNodesLayer');

function showToast(msg) {
    const t = document.getElementById('mindmapToast');
    if (!t) return;
    t.textContent = msg;
    t.classList.remove('hidden');
    clearTimeout(t._timer);
    t._timer = setTimeout(() => t.classList.add('hidden'), 3200);
}

function getNodeById(id) {
    return mapData.nodes.find(n => n.id === id) || null;
}

function isNodeVisible(node) {
    let curr = node;
    const visited = new Set();
    while (curr && curr.parentId) {
        if (visited.has(curr.id)) break;
        visited.add(curr.id);
        const parent = getNodeById(curr.parentId);
        if (!parent) break;
        if (parent.collapsed) return false;
        curr = parent;
    }
    return true;
}

function getChildren(parentId) {
    return mapData.nodes.filter(n => n.parentId === parentId);
}

function resetViewport() {
    const rect = viewport.getBoundingClientRect();
    panX = Math.round(rect.width / 2);
    panY = Math.round(rect.height / 2);
    scale = 1;
    applyTransform();
}

function zoomCanvas(delta) {
    scale = Math.min(2.0, Math.max(0.4, Math.round((scale + delta) * 100) / 100));
    document.getElementById('zoomLevelLabel').textContent = Math.round(scale * 100) + '%';
    applyTransform();
}

function applyTransform() {
    world.style.transform = `translate(${panX}px, ${panY}px) scale(${scale})`;
}

function renderMindmap() {
    nodesLayer.innerHTML = '';
    edgesSvg.innerHTML = '';

    // 1. Verbindungslinien (Bezier-Kurven) zeichnen
    mapData.nodes.forEach(node => {
        if (!node.parentId || !isNodeVisible(node)) return;
        const parent = getNodeById(node.parentId);
        if (!parent) return;

        const x1 = Number(parent.x) || 0;
        const y1 = Number(parent.y) || 0;
        const x2 = Number(node.x) || 0;
        const y2 = Number(node.y) || 0;
        const dx = (x2 - x1) * 0.45;

        const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        path.setAttribute('d', `M ${x1} ${y1} C ${x1 + dx} ${y1}, ${x2 - dx} ${y2}, ${x2} ${y2}`);
        path.setAttribute('fill', 'none');
        path.setAttribute('stroke', node.color || '#64748b');
        path.setAttribute('stroke-width', parent.parentId === null ? '3.5' : '2.5');
        path.setAttribute('stroke-linecap', 'round');
        path.setAttribute('opacity', '0.85');
        edgesSvg.appendChild(path);
    });

    // 2. Knoten als interaktive Karten rendern
    mapData.nodes.forEach(node => {
        if (!isNodeVisible(node)) return;

        const isRoot = (node.parentId === null);
        const isSelected = (node.id === selectedNodeId);
        const children = getChildren(node.id);

        const el = document.createElement('div');
        el.setAttribute('data-node-id', node.id);
        el.style.position = 'absolute';
        el.style.left = `${Number(node.x) || 0}px`;
        el.style.top = `${Number(node.y) || 0}px`;
        el.style.transform = 'translate(-50%, -50%)';
        el.style.borderColor = node.color || '#2563eb';

        const baseCls = isRoot
            ? 'px-4 py-2.5 rounded-2xl font-bold text-sm sm:text-base text-white shadow-md cursor-grab active:cursor-grabbing transition-shadow whitespace-nowrap flex items-center gap-2'
            : 'px-3.5 py-2 rounded-xl font-semibold text-xs sm:text-sm bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100 border-2 shadow-xs cursor-grab active:cursor-grabbing transition-shadow whitespace-nowrap flex items-center gap-1.5';

        el.className = baseCls + (isSelected ? ' ring-4 ring-brand-500/40 scale-105 z-10' : '');
        if (isRoot) {
            el.style.backgroundColor = node.color || '#2563eb';
        }

        if (node.icon) {
            const icoSpan = document.createElement('span');
            icoSpan.textContent = node.icon;
            el.appendChild(icoSpan);
        }

        const txtSpan = document.createElement('span');
        txtSpan.textContent = node.text || 'Knoten';
        el.appendChild(txtSpan);

        if (node.note && node.note.trim() !== '') {
            const noteBadge = document.createElement('span');
            noteBadge.textContent = '📝';
            noteBadge.className = 'text-[10px] opacity-80';
            noteBadge.title = node.note;
            el.appendChild(noteBadge);
        }

        if (children.length > 0 && node.collapsed) {
            const colBadge = document.createElement('span');
            colBadge.textContent = `+${children.length}`;
            colBadge.className = 'ml-1 px-1.5 py-0.2 rounded-full text-[10px] font-bold bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-200';
            el.appendChild(colBadge);
        }

        // Klick & Ziehen eines Knotens
        el.addEventListener('mousedown', (ev) => {
            ev.stopPropagation();
            selectNode(node.id);
            draggingNodeId = node.id;
            dragStartMouseX = ev.clientX;
            dragStartMouseY = ev.clientY;
            dragNodeOrigX = Number(node.x) || 0;
            dragNodeOrigY = Number(node.y) || 0;
        });

        // Doppelklick zum schnellen Umbenennen
        el.addEventListener('dblclick', (ev) => {
            ev.stopPropagation();
            const inp = document.getElementById('inspectorNodeText');
            if (inp) {
                inp.focus();
                inp.select();
            }
        });

        nodesLayer.appendChild(el);
    });

    syncInspector();
}

function selectNode(id) {
    selectedNodeId = id;
    renderMindmap();
}

function syncInspector() {
    const node = getNodeById(selectedNodeId);
    const txtInput = document.getElementById('inspectorNodeText');
    const noteInput = document.getElementById('inspectorNodeNote');
    const badge = document.getElementById('selectedNodeBadge');
    if (!node) return;

    if (document.activeElement !== txtInput) {
        txtInput.value = node.text || '';
    }
    if (document.activeElement !== noteInput) {
        noteInput.value = node.note || '';
    }
    badge.textContent = node.parentId === null ? '🌟 Zentralknoten' : '🌿 Unterknoten';
}

function updateSelectedNodeFromInspector() {
    const node = getNodeById(selectedNodeId);
    if (!node) return;
    node.text = document.getElementById('inspectorNodeText').value;
    node.note = document.getElementById('inspectorNodeNote').value;
    renderMindmap();
    scheduleAutoSave();
}

function setSelectedNodeColor(color) {
    const node = getNodeById(selectedNodeId);
    if (!node) return;
    node.color = color;
    renderMindmap();
    scheduleAutoSave();
}

function setSelectedNodeIcon(icon) {
    const node = getNodeById(selectedNodeId);
    if (!node) return;
    node.icon = icon;
    renderMindmap();
    scheduleAutoSave();
}

function addChildNode() {
    const parent = getNodeById(selectedNodeId) || mapData.nodes[0];
    if (!parent) return;
    parent.collapsed = false;

    const siblings = getChildren(parent.id);
    const angle = (siblings.length * 0.65) - 0.5;
    const dir = (parent.x >= 0) ? 1 : -1;
    const offsetX = parent.parentId === null ? (siblings.length % 2 === 0 ? 240 : -240) : (dir * 200);
    const offsetY = Math.round(Math.sin(angle) * 90) + (siblings.length * 25 - 30);

    const newId = 'n_' + Date.now() + '_' + Math.floor(Math.random() * 1000);
    const palette = ['#2563eb', '#059669', '#7c3aed', '#d97706', '#db2777', '#0891b2'];
    const color = parent.parentId === null ? palette[siblings.length % palette.length] : (parent.color || '#2563eb');

    mapData.nodes.push({
        id: newId,
        parentId: parent.id,
        text: 'Neuer Punkt',
        x: Math.round((Number(parent.x) || 0) + offsetX),
        y: Math.round((Number(parent.y) || 0) + offsetY),
        color: color,
        icon: '💡',
        note: '',
        collapsed: false
    });

    selectedNodeId = newId;
    renderMindmap();
    scheduleAutoSave();
    setTimeout(() => {
        const inp = document.getElementById('inspectorNodeText');
        if (inp) { inp.focus(); inp.select(); }
    }, 30);
}

function addSiblingNode() {
    const curr = getNodeById(selectedNodeId);
    if (!curr || curr.parentId === null) {
        addChildNode();
        return;
    }
    selectedNodeId = curr.parentId;
    addChildNode();
}

function toggleCollapseSelected() {
    const curr = getNodeById(selectedNodeId);
    if (!curr) return;
    const children = getChildren(curr.id);
    if (children.length === 0) {
        showToast('Dieser Knoten hat noch keine Unteräste.');
        return;
    }
    curr.collapsed = !curr.collapsed;
    renderMindmap();
    scheduleAutoSave();
}

function deleteSelectedNode() {
    const curr = getNodeById(selectedNodeId);
    if (!curr) return;
    if (curr.parentId === null) {
        showToast('Der zentrale Hauptknoten kann nicht gelöscht werden.');
        return;
    }

    const toDelete = new Set([curr.id]);
    let added = true;
    while (added) {
        added = false;
        mapData.nodes.forEach(n => {
            if (n.parentId && toDelete.has(n.parentId) && !toDelete.has(n.id)) {
                toDelete.add(n.id);
                added = true;
            }
        });
    }

    const parentId = curr.parentId;
    mapData.nodes = mapData.nodes.filter(n => !toDelete.has(n.id));
    selectedNodeId = parentId || mapData.nodes[0].id;
    renderMindmap();
    scheduleAutoSave();
}

// Auto-Layout: Radial oder Baum
function applyAutoLayout(mode) {
    mapData.layout = mode;
    const root = mapData.nodes.find(n => n.parentId === null) || mapData.nodes[0];
    if (!root) return;

    if (mode === 'tree') {
        root.x = -340;
        root.y = 0;
        let currentLeafY = -220;

        function layoutTree(nodeId, depth) {
            const kids = getChildren(nodeId);
            if (kids.length === 0) {
                const n = getNodeById(nodeId);
                if (n && n.id !== root.id) {
                    n.x = -340 + depth * 230;
                    n.y = currentLeafY;
                    currentLeafY += 75;
                }
                return;
            }
            kids.forEach(k => layoutTree(k.id, depth + 1));
            const n = getNodeById(nodeId);
            if (n && n.id !== root.id) {
                n.x = -340 + depth * 230;
                const avgY = kids.reduce((acc, k) => acc + k.y, 0) / kids.length;
                n.y = Math.round(avgY);
            }
        }
        layoutTree(root.id, 0);
    } else {
        // Radial
        root.x = 0;
        root.y = 0;
        const firstLevel = getChildren(root.id);
        firstLevel.forEach((child, idx) => {
            const side = (idx % 2 === 0) ? -1 : 1;
            const row = Math.floor(idx / 2);
            const totalRows = Math.ceil(firstLevel.length / 2);
            const baseY = (row - (totalRows - 1) / 2) * 180;
            child.x = side * 260;
            child.y = Math.round(baseY);

            const subKids = getChildren(child.id);
            subKids.forEach((sub, sIdx) => {
                const subY = child.y + (sIdx - (subKids.length - 1) / 2) * 65;
                sub.x = side * 480;
                sub.y = Math.round(subY);

                const deepKids = getChildren(sub.id);
                deepKids.forEach((d, dIdx) => {
                    d.x = side * 680;
                    d.y = Math.round(sub.y + (dIdx - (deepKids.length - 1) / 2) * 55);
                });
            });
        });
    }

    resetViewport();
    renderMindmap();
    scheduleAutoSave();
    showToast(mode === 'tree' ? '🌿 Baum-Layout angewendet' : '✨ Radial-Layout angewendet');
}

// Maus-Panning & Node-Dragging
viewport.addEventListener('mousedown', (ev) => {
    if (ev.target.closest('button')) return;
    isPanning = true;
    panStartX = ev.clientX - panX;
    panStartY = ev.clientY - panY;
});

window.addEventListener('mousemove', (ev) => {
    if (draggingNodeId) {
        const node = getNodeById(draggingNodeId);
        if (node) {
            const dx = (ev.clientX - dragStartMouseX) / scale;
            const dy = (ev.clientY - dragStartMouseY) / scale;
            node.x = Math.round(dragNodeOrigX + dx);
            node.y = Math.round(dragNodeOrigY + dy);
            renderMindmap();
        }
        return;
    }
    if (isPanning) {
        panX = ev.clientX - panStartX;
        panY = ev.clientY - panStartY;
        applyTransform();
    }
});

window.addEventListener('mouseup', () => {
    if (draggingNodeId) {
        draggingNodeId = null;
        scheduleAutoSave();
    }
    isPanning = false;
});

viewport.addEventListener('wheel', (ev) => {
    ev.preventDefault();
    zoomCanvas(ev.deltaY < 0 ? 0.1 : -0.1);
}, { passive: false });

// Tastatur-Kurzbefehle (Tab, Enter, Entf)
window.addEventListener('keydown', (ev) => {
    const tag = (document.activeElement && document.activeElement.tagName) ? document.activeElement.tagName.toLowerCase() : '';
    if (tag === 'input' || tag === 'textarea' || tag === 'select') {
        if (ev.key === 'Enter' && document.activeElement.id === 'inspectorNodeText') {
            ev.preventDefault();
            document.activeElement.blur();
        }
        return;
    }

    if (ev.key === 'Tab') {
        ev.preventDefault();
        addChildNode();
    } else if (ev.key === 'Enter') {
        ev.preventDefault();
        addSiblingNode();
    } else if (ev.key === 'Delete' || ev.key === 'Backspace') {
        ev.preventDefault();
        deleteSelectedNode();
    }
});

// Auto-Save in die Datenbank
function scheduleAutoSave() {
    const badge = document.getElementById('saveStatusBadge');
    if (badge) {
        badge.textContent = '⏳ Speichert...';
        badge.className = 'px-2 py-0.5 rounded-full text-[11px] font-semibold bg-amber-50 dark:bg-amber-950/60 text-amber-700 dark:text-amber-300 border border-amber-200/70 dark:border-amber-800/70';
    }
    clearTimeout(autoSaveTimer);
    autoSaveTimer = setTimeout(saveMindmapNow, 650);
}

async function saveMindmapNow() {
    const title = document.getElementById('mapTitleInput').value.trim() || 'Mind-Map';
    const desc = document.getElementById('mapDescInput').value.trim();
    const sharedIds = Array.from(document.querySelectorAll('.shared-user-cb:checked')).map(cb => cb.value).join(',');

    const fd = new FormData();
    fd.append('csrf_token', CSRF_TOKEN);
    fd.append('ajax', '1');
    fd.append('action', 'save_map');
    fd.append('map_id', String(ACTIVE_MAP_ID));
    fd.append('title', title);
    fd.append('description', desc);
    fd.append('map_json', JSON.stringify(mapData));
    fd.append('shared_users', sharedIds);

    try {
        const res = await fetch('mindmap.php', { method: 'POST', body: fd });
        const data = await res.json();
        const badge = document.getElementById('saveStatusBadge');
        if (data.ok && badge) {
            badge.textContent = '✓ Gespeichert';
            badge.className = 'px-2 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-50 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-300 border border-emerald-200/70 dark:border-emerald-800/70';
        }
    } catch (e) {}
}

// 1-Klick: Knoten -> Aufgabe in tasks.php
async function convertSelectedNodeToTask() {
    const node = getNodeById(selectedNodeId);
    if (!node) return;
    const fd = new FormData();
    fd.append('csrf_token', CSRF_TOKEN);
    fd.append('ajax', '1');
    fd.append('action', 'node_to_task');
    fd.append('node_text', (node.icon ? node.icon + ' ' : '') + node.text);
    fd.append('node_note', node.note || '');
    fd.append('map_title', document.getElementById('mapTitleInput').value.trim());

    const res = await fetch('mindmap.php', { method: 'POST', body: fd });
    const data = await res.json();
    showToast(data.ok ? ('✅ ' + data.message) : ('❌ ' + (data.error || 'Fehler')));
}

// 1-Klick: Knoten -> Haftnotiz in notes.php
async function convertSelectedNodeToNote() {
    const node = getNodeById(selectedNodeId);
    if (!node) return;
    const kids = getChildren(node.id);
    let content = node.note ? (node.note + '\n\n') : '';
    if (kids.length > 0) {
        content += 'Unterpunkte:\n' + kids.map(k => '• ' + (k.icon ? k.icon + ' ' : '') + k.text).join('\n');
    } else if (!content) {
        content = node.text;
    }

    const fd = new FormData();
    fd.append('csrf_token', CSRF_TOKEN);
    fd.append('ajax', '1');
    fd.append('action', 'node_to_note');
    fd.append('note_title', (node.icon ? node.icon + ' ' : '') + node.text);
    fd.append('note_content', content);

    const res = await fetch('mindmap.php', { method: 'POST', body: fd });
    const data = await res.json();
    showToast(data.ok ? ('📌 ' + data.message) : ('❌ ' + (data.error || 'Fehler')));
}

// Export als Markdown-Gliederung in die Cloud-Dateien
function buildMarkdownOutline() {
    const title = document.getElementById('mapTitleInput').value.trim() || 'Mind-Map';
    const root = mapData.nodes.find(n => n.parentId === null) || mapData.nodes[0];
    let lines = [`# 🧠 ${title}`, ''];

    function walk(node, depth) {
        const indent = '  '.repeat(depth);
        const prefix = depth === 0 ? '##' : '-';
        lines.push(`${indent}${prefix} ${node.icon ? node.icon + ' ' : ''}${node.text}`);
        if (node.note && node.note.trim() !== '') {
            lines.push(`${indent}  > ${node.note.trim()}`);
        }
        getChildren(node.id).forEach(c => walk(c, depth + 1));
    }
    if (root) walk(root, 0);
    return lines.join('\n');
}

async function exportMindmapToCloud(format) {
    const title = document.getElementById('mapTitleInput').value.trim() || 'Mind-Map';
    const content = format === 'json' ? JSON.stringify(mapData, null, 2) : buildMarkdownOutline();

    const fd = new FormData();
    fd.append('csrf_token', CSRF_TOKEN);
    fd.append('ajax', '1');
    fd.append('action', 'export_to_cloud');
    fd.append('format', format);
    fd.append('title', title);
    fd.append('content', content);

    const res = await fetch('mindmap.php', { method: 'POST', body: fd });
    const data = await res.json();
    showToast(data.ok ? ('☁️ ' + data.message) : ('❌ ' + (data.error || 'Fehler')));
}

function downloadMindmapJson() {
    const title = document.getElementById('mapTitleInput').value.trim() || 'Mind-Map';
    const blob = new Blob([JSON.stringify(mapData, null, 2)], { type: 'application/json' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = title.replace(/\s+/g, '_') + '.json';
    a.click();
}

function downloadMindmapPng() {
    const title = document.getElementById('mapTitleInput').value.trim() || 'Mind-Map';
    let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
    mapData.nodes.forEach(n => {
        if (!isNodeVisible(n)) return;
        minX = Math.min(minX, n.x - 140);
        maxX = Math.max(maxX, n.x + 140);
        minY = Math.min(minY, n.y - 50);
        maxY = Math.max(maxY, n.y + 50);
    });
    const pad = 60;
    const width = Math.max(600, (maxX - minX) + pad * 2);
    const height = Math.max(400, (maxY - minY) + pad * 2);
    const offsetX = -minX + pad;
    const offsetY = -minY + pad;

    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#f8fafc';
    ctx.fillRect(0, 0, width, height);

    // Linien
    mapData.nodes.forEach(node => {
        if (!node.parentId || !isNodeVisible(node)) return;
        const parent = getNodeById(node.parentId);
        if (!parent) return;
        const x1 = parent.x + offsetX, y1 = parent.y + offsetY;
        const x2 = node.x + offsetX, y2 = node.y + offsetY;
        const dx = (x2 - x1) * 0.45;
        ctx.beginPath();
        ctx.moveTo(x1, y1);
        ctx.bezierCurveTo(x1 + dx, y1, x2 - dx, y2, x2, y2);
        ctx.strokeStyle = node.color || '#2563eb';
        ctx.lineWidth = 3;
        ctx.stroke();
    });

    // Knoten
    ctx.font = 'bold 14px sans-serif';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    mapData.nodes.forEach(node => {
        if (!isNodeVisible(node)) return;
        const x = node.x + offsetX, y = node.y + offsetY;
        const label = (node.icon ? node.icon + ' ' : '') + (node.text || '');
        const w = Math.max(110, ctx.measureText(label).width + 32);
        const h = 38;
        ctx.fillStyle = node.parentId === null ? (node.color || '#2563eb') : '#ffffff';
        ctx.strokeStyle = node.color || '#2563eb';
        ctx.lineWidth = 2.5;
        ctx.beginPath();
        ctx.roundRect(x - w / 2, y - h / 2, w, h, 10);
        ctx.fill();
        ctx.stroke();
        ctx.fillStyle = node.parentId === null ? '#ffffff' : '#0f172a';
        ctx.fillText(label, x, y);
    });

    const a = document.createElement('a');
    a.href = canvas.toDataURL('image/png');
    a.download = title.replace(/\s+/g, '_') + '.png';
    a.click();
}

window.addEventListener('DOMContentLoaded', () => {
    resetViewport();
    renderMindmap();
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
