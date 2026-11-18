<?php
declare(strict_types=1);

/**
 * MiniCloud – SQLite PDO Adapter & MySQL-zu-SQLite Query-Translator
 * Ermöglicht den wahlweisen Betrieb der gesamten MiniCloud über eine lokale SQLite-Datenbankdatei
 * ohne separaten MySQL/MariaDB-Server.
 */

class MiniCloudSQLitePDO extends PDO
{
    public function __construct(string $sqliteFilePath)
    {
        if ($sqliteFilePath !== ':memory:') {
            $dir = dirname($sqliteFilePath);
            if (!is_dir($dir)) {
                @mkdir($dir, 0750, true);
            }
        }

        parent::__construct('sqlite:' . $sqliteFilePath, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 15,
        ]);

        // Performance- & Concurrency-Einstellungen für parallele Zugriffe (Web + Android-App)
        parent::exec('PRAGMA journal_mode = WAL;');
        parent::exec('PRAGMA synchronous = NORMAL;');
        parent::exec('PRAGMA busy_timeout = 10000;');
        parent::exec('PRAGMA foreign_keys = ON;');

        $this->registerMysqlCompatibleFunctions();
    }

    /**
     * Registriert MySQL-kompatible Skalarfunktionen direkt in SQLite.
     */
    private function registerMysqlCompatibleFunctions(): void
    {
        $this->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'), 0);
        $this->sqliteCreateFunction('CURDATE', static fn(): string => date('Y-m-d'), 0);
        $this->sqliteCreateFunction('CURTIME', static fn(): string => date('H:i:s'), 0);

        $this->sqliteCreateFunction('UNIX_TIMESTAMP', static function (?string $dt = null): int {
            if ($dt === null || $dt === '') {
                return time();
            }
            $ts = strtotime($dt);
            return $ts !== false ? $ts : 0;
        });

        $this->sqliteCreateFunction('FROM_UNIXTIME', static function ($ts): string {
            return date('Y-m-d H:i:s', (int)$ts);
        }, 1);

        $this->sqliteCreateFunction('YEAR', static function (?string $dt): ?int {
            if ($dt === null || $dt === '') {
                return null;
            }
            $ts = strtotime($dt);
            return $ts !== false ? (int)date('Y', $ts) : (int)substr($dt, 0, 4);
        }, 1);

        $this->sqliteCreateFunction('MONTH', static function (?string $dt): ?int {
            if ($dt === null || $dt === '') {
                return null;
            }
            $ts = strtotime($dt);
            return $ts !== false ? (int)date('n', $ts) : (int)substr($dt, 5, 2);
        }, 1);

        $this->sqliteCreateFunction('DAY', static function (?string $dt): ?int {
            if ($dt === null || $dt === '') {
                return null;
            }
            $ts = strtotime($dt);
            return $ts !== false ? (int)date('j', $ts) : (int)substr($dt, 8, 2);
        }, 1);

        $this->sqliteCreateFunction('DATE_FORMAT', static function (?string $dt, string $format): ?string {
            if ($dt === null || $dt === '') {
                return null;
            }
            $ts = strtotime($dt);
            if ($ts === false) {
                return $dt;
            }
            $phpFmt = strtr($format, [
                '%Y' => 'Y',
                '%y' => 'y',
                '%m' => 'm',
                '%c' => 'n',
                '%d' => 'd',
                '%e' => 'j',
                '%H' => 'H',
                '%h' => 'h',
                '%i' => 'i',
                '%s' => 's',
                '%S' => 's',
                '%W' => 'l',
                '%a' => 'D',
                '%M' => 'F',
                '%b' => 'M',
            ]);
            return date($phpFmt, $ts);
        }, 2);

        $this->sqliteCreateFunction('GREATEST', static function (...$args) {
            return !empty($args) ? max($args) : null;
        });

        $this->sqliteCreateFunction('LEAST', static function (...$args) {
            return !empty($args) ? min($args) : null;
        });

        $this->sqliteCreateFunction('IF', static function ($cond, $trueVal, $falseVal) {
            return $cond ? $trueVal : $falseVal;
        }, 3);

        $this->sqliteCreateFunction('RAND', static fn(): float => mt_rand() / mt_getrandmax(), 0);

        $this->sqliteCreateFunction('CONCAT', static function (...$args): string {
            return implode('', array_map(static fn($v): string => (string)($v ?? ''), $args));
        });

        $this->sqliteCreateFunction('CHAR_LENGTH', static function (?string $s): int {
            return mb_strlen((string)$s, 'UTF-8');
        }, 1);

        $this->sqliteCreateFunction('DATEDIFF', static function (?string $d1, ?string $d2): int {
            if (!$d1 || !$d2) {
                return 0;
            }
            return (int)round((strtotime($d1) - strtotime($d2)) / 86400);
        }, 2);

        $this->sqliteCreateFunction('VERSION', static function (): string {
            return 'SQLite ' . (SQLite3::version()['versionString'] ?? '3.x');
        }, 0);
    }

    #[\ReturnTypeWillChange]
    public function exec(string $statement): int|false
    {
        $translated = $this->translateSql($statement);
        if ($translated === '') {
            return 0;
        }
        try {
            return parent::exec($translated);
        } catch (PDOException $e) {
            // Bei ALTER TABLE ADD COLUMN: Ignorieren, falls die Spalte in SQLite bereits existiert
            if (stripos($translated, 'ALTER TABLE') !== false && stripos($e->getMessage(), 'duplicate column name') !== false) {
                return 0;
            }
            throw $e;
        }
    }

    #[\ReturnTypeWillChange]
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $translated = $this->translateSql($query);
        return parent::prepare($translated, $options);
    }

    #[\ReturnTypeWillChange]
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $translated = $this->translateSql($query);
        if ($fetchMode === null) {
            return parent::query($translated);
        }
        return parent::query($translated, $fetchMode, ...$fetchModeArgs);
    }

    /**
     * Übersetzt MySQL-spezifische SQL-Statements transparent in SQLite-kompatibles SQL.
     */
    public function translateSql(string $sql): string
    {
        $trimmed = trim($sql);
        if ($trimmed === '') {
            return '';
        }

        // 1. MySQL SET-Befehle
        if (preg_match('/^SET\s+FOREIGN_KEY_CHECKS\s*=\s*([01])/i', $trimmed, $m)) {
            return 'PRAGMA foreign_keys = ' . ($m[1] === '1' ? 'ON' : 'OFF');
        }
        if (preg_match('/^SET\s+(?:NAMES|SQL_MODE|time_zone|CHARACTER\s+SET)\b/i', $trimmed)) {
            return 'SELECT 1';
        }

        // 2. SHOW TABLES / SHOW CREATE TABLE / SHOW COLUMNS / SHOW TABLE STATUS / SHOW GLOBAL
        if (preg_match('/^SHOW\s+TABLES\b/i', $trimmed)) {
            return "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name";
        }
        if (preg_match('/^SHOW\s+TABLE\s+STATUS\b/i', $trimmed)) {
            return "SELECT name AS Name, 'SQLite' AS Engine, 0 AS Data_length, 0 AS Index_length, 0 AS Data_free, 0 AS Rows FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name";
        }
        if (preg_match('/^SHOW\s+(?:GLOBAL\s+)?(?:STATUS|VARIABLES)\b/i', $trimmed)) {
            return "SELECT '' AS Variable_name, '0' AS Value WHERE 1=0";
        }
        if (preg_match('/^SHOW\s+CREATE\s+TABLE\s+`?([a-zA-Z0-9_]+)`?/i', $trimmed, $m)) {
            return "SELECT name AS \"Table\", sql AS \"Create Table\" FROM sqlite_master WHERE type='table' AND name=" . $this->quote($m[1]);
        }
        if (preg_match('/^SHOW\s+COLUMNS\s+FROM\s+`?([a-zA-Z0-9_]+)`?(?:\s+LIKE\s+\'([^\']+)\')?/i', $trimmed, $m)) {
            $tbl = $m[1];
            $like = $m[2] ?? null;
            $where = $like !== null ? ' WHERE name LIKE ' . $this->quote($like) : '';
            return 'SELECT name AS Field, type AS Type, CASE WHEN "notnull" = 0 THEN \'YES\' ELSE \'NO\' END AS "Null", CASE WHEN pk > 0 THEN \'PRI\' ELSE \'\' END AS "Key", dflt_value AS "Default", \'\' AS Extra FROM pragma_table_info(' . $this->quote($tbl) . ')' . $where;
        }

        // 3. TRUNCATE TABLE & OPTIMIZE TABLE
        if (preg_match('/^TRUNCATE\s+(?:TABLE\s+)?`?([a-zA-Z0-9_]+)`?/i', $trimmed, $m)) {
            return 'DELETE FROM `' . $m[1] . '`';
        }
        if (preg_match('/^OPTIMIZE\s+TABLE\b/i', $trimmed)) {
            return 'PRAGMA optimize';
        }

        // 4. CREATE TABLE
        if (preg_match('/^CREATE\s+TABLE\b/i', $trimmed)) {
            return $this->translateCreateTable($trimmed);
        }

        // 5. ALTER TABLE
        if (preg_match('/^ALTER\s+TABLE\s+`?([a-zA-Z0-9_]+)`?\s+(.*)$/is', $trimmed, $m)) {
            return $this->translateAlterTable($m[1], trim($m[2]));
        }

        // 6. Allgemeine DML-Übersetzungen (SELECT, INSERT, UPDATE, DELETE)
        $out = $trimmed;

        // INSERT IGNORE INTO -> INSERT OR IGNORE INTO
        $out = (string)preg_replace('/\bINSERT\s+IGNORE\s+INTO\b/i', 'INSERT OR IGNORE INTO', $out);

        // ON DUPLICATE KEY UPDATE -> ON CONFLICT DO UPDATE SET + VALUES(col) -> excluded.col
        if (stripos($out, 'ON DUPLICATE KEY UPDATE') !== false) {
            $parts = preg_split('/\bON\s+DUPLICATE\s+KEY\s+UPDATE\b/i', $out, 2);
            if (is_array($parts) && count($parts) === 2) {
                $updatePart = (string)preg_replace('/\bVALUES\s*\(\s*(`?[a-zA-Z0-9_]+`?)\s*\)/i', 'excluded.$1', $parts[1]);
                $out = $parts[0] . 'ON CONFLICT DO UPDATE SET ' . $updatePart;
            }
        }

        // DATE_SUB / DATE_ADD / NOW() +/- INTERVAL
        $out = (string)preg_replace_callback(
            '/\bDATE_SUB\s*\(\s*(NOW\(\)|CURDATE\(\))\s*,\s*INTERVAL\s+(\d+)\s+(SECOND|MINUTE|HOUR|DAY|MONTH|YEAR)\s*\)/i',
            static function (array $m): string {
                $func = strtoupper($m[1]) === 'CURDATE()' ? 'date' : 'datetime';
                $unit = strtolower($m[3]) . 's';
                return "{$func}({$m[1]}, '-{$m[2]} {$unit}')";
            },
            $out
        );

        $out = (string)preg_replace_callback(
            '/\bDATE_ADD\s*\(\s*(NOW\(\)|CURDATE\(\))\s*,\s*INTERVAL\s+(\d+)\s+(SECOND|MINUTE|HOUR|DAY|MONTH|YEAR)\s*\)/i',
            static function (array $m): string {
                $func = strtoupper($m[1]) === 'CURDATE()' ? 'date' : 'datetime';
                $unit = strtolower($m[3]) . 's';
                return "{$func}({$m[1]}, '+{$m[2]} {$unit}')";
            },
            $out
        );

        $out = (string)preg_replace_callback(
            '/\(?\bNOW\(\)\s*([+-])\s*INTERVAL\s+(\d+)\s+(SECOND|MINUTE|HOUR|DAY|MONTH|YEAR)\)?/i',
            static function (array $m): string {
                $sign = $m[1];
                $unit = strtolower($m[3]) . 's';
                return "datetime(NOW(), '{$sign}{$m[2]} {$unit}')";
            },
            $out
        );

        // MATCH(...) AGAINST(:param IN BOOLEAN MODE)
        if (stripos($out, 'AGAINST') !== false) {
            $out = (string)preg_replace(
                '/\bMATCH\s*\([^)]+\)\s*AGAINST\s*\(\s*(:[a-zA-Z0-9_]+)\s+IN\s+BOOLEAN\s+MODE\s*\)/i',
                'CASE WHEN $1 IS NOT NULL THEN 1 ELSE 0 END',
                $out
            );
        }

        // UPDATE / DELETE mit abschließendem LIMIT
        if (preg_match('/^(?:UPDATE|DELETE)\b/i', $out) && !preg_match('/\bSELECT\b/i', $out)) {
            $out = (string)preg_replace('/\s+LIMIT\s+\d+\s*;?\s*$/i', '', $out);
        }

        return $out;
    }

    /**
     * Konvertiert MySQL CREATE TABLE Syntax nach SQLite.
     */
    private function translateCreateTable(string $sql): string
    {
        // Engine/Charset am Ende entfernen
        $sql = (string)preg_replace('/\)\s*ENGINE\s*=\s*[^;]+;?\s*$/is', ')', $sql);

        // Kommentare, Character-Set, Collate, Unsigned, ON UPDATE CURRENT_TIMESTAMP entfernen
        $sql = (string)preg_replace('/\bCOMMENT\s+\'[^\']*\'/i', '', $sql);
        $sql = (string)preg_replace('/\bCHARACTER\s+SET\s+[a-zA-Z0-9_]+/i', '', $sql);
        $sql = (string)preg_replace('/\bCOLLATE\s+[a-zA-Z0-9_]+/i', '', $sql);
        $sql = (string)preg_replace('/\bUNSIGNED\b/i', '', $sql);
        $sql = (string)preg_replace('/\bON\s+UPDATE\s+CURRENT_TIMESTAMP\b/i', '', $sql);
        $sql = (string)preg_replace('/\bENUM\s*\([^)]+\)/i', 'VARCHAR(64)', $sql);

        // AUTO_INCREMENT Spalten erkennen und in INTEGER PRIMARY KEY AUTOINCREMENT umwandeln
        $autoIncCol = null;
        $sql = (string)preg_replace_callback(
            '/(`?[a-zA-Z0-9_]+`?)\s+(?:INT|INTEGER|BIGINT|SMALLINT|TINYINT)(?:\(\d+\))?\s+(?:NOT\s+NULL\s+)?AUTO_INCREMENT(?:\s+PRIMARY\s+KEY)?/i',
            static function (array $m) use (&$autoIncCol): string {
                $autoIncCol = trim($m[1], '`');
                return $m[1] . ' INTEGER PRIMARY KEY AUTOINCREMENT';
            },
            $sql
        );

        // Zeilenweise Bereinigung innerhalb der Klammern
        $lines = preg_split('/\r?\n/', $sql) ?: [$sql];
        $cleanLines = [];
        foreach ($lines as $line) {
            $trimLine = trim($line);

            // Falls bereits ein AUTOINCREMENT PRIMARY KEY gesetzt wurde, separate PRIMARY KEY (`id`) Zeile auslassen
            if ($autoIncCol !== null && preg_match('/^PRIMARY\s+KEY\s*\(\s*`?' . preg_quote($autoIncCol, '/') . '`?\s*\)\s*,?$/i', $trimLine)) {
                continue;
            }

            // UNIQUE KEY / UNIQUE INDEX -> UNIQUE (...)
            if (preg_match('/^(?:CONSTRAINT\s+`?\w+`?\s+)?UNIQUE\s+(?:KEY|INDEX)\s+`?\w+`?\s*\((.+)\)\s*(,?)$/i', $trimLine, $um)) {
                $uCols = (string)preg_replace('/\(\d+\)/', '', $um[1]);
                $cleanLines[] = '    UNIQUE (' . $uCols . ')' . $um[2];
                continue;
            }

            // Normale KEY / INDEX / FULLTEXT KEY Zeilen innerhalb von CREATE TABLE entfernen
            if (preg_match('/^(?:FULLTEXT\s+)?(?:KEY|INDEX)\s+`?\w+`?\s*\(.+\)\s*,?$/i', $trimLine)) {
                continue;
            }

            $cleanLines[] = $line;
        }

        $result = implode("\n", $cleanLines);
        // Überflüssiges Komma vor der schließenden Klammer entfernen
        $result = (string)preg_replace('/,\s*(\)\s*;?\s*)$/s', "\n$1", $result);

        return $result;
    }

    /**
     * Konvertiert MySQL ALTER TABLE Befehle nach SQLite.
     */
    private function translateAlterTable(string $table, string $alterBody): string
    {
        // MODIFY / DROP PRIMARY KEY / ADD CONSTRAINT -> in SQLite nicht erforderlich bzw. No-Op
        if (preg_match('/^(?:MODIFY\b|CHANGE\b|DROP\s+PRIMARY\s+KEY\b|ADD\s+CONSTRAINT\b)/i', $alterBody)) {
            return 'SELECT 1';
        }

        // ADD UNIQUE KEY / INDEX
        if (preg_match('/^ADD\s+UNIQUE\s+(?:KEY|INDEX)\s+`?([a-zA-Z0-9_]+)`?\s*\(([^)]+)\)/i', $alterBody, $m)) {
            $cols = (string)preg_replace('/\(\d+\)/', '', $m[2]);
            return "CREATE UNIQUE INDEX IF NOT EXISTS `{$m[1]}` ON `{$table}` ({$cols})";
        }

        // ADD KEY / INDEX / FULLTEXT
        if (preg_match('/^ADD\s+(?:FULLTEXT\s+)?(?:KEY|INDEX)\s+`?([a-zA-Z0-9_]+)`?\s*\(([^)]+)\)/i', $alterBody, $m)) {
            $cols = (string)preg_replace('/\(\d+\)/', '', $m[2]);
            return "CREATE INDEX IF NOT EXISTS `{$m[1]}` ON `{$table}` ({$cols})";
        }

        // ADD COLUMN
        $alterBody = (string)preg_replace('/,\s*ADD\s+(?:UNIQUE\s+)?(?:INDEX|KEY)\s+`?[a-zA-Z0-9_]+`?\s*\([^)]+\)/i', '', $alterBody);
        $alterBody = (string)preg_replace('/\bUNSIGNED\b/i', '', $alterBody);
        $alterBody = (string)preg_replace('/\bON\s+UPDATE\s+CURRENT_TIMESTAMP\b/i', '', $alterBody);
        $alterBody = (string)preg_replace('/\s+AFTER\s+`?[a-zA-Z0-9_]+`?/i', '', $alterBody);
        $alterBody = (string)preg_replace('/\s+FIRST\b/i', '', $alterBody);
        $alterBody = (string)preg_replace('/\bAUTO_INCREMENT\s+PRIMARY\s+KEY\b/i', 'DEFAULT NULL', $alterBody);
        // SQLite erlaubt bei ALTER TABLE ADD COLUMN auf befüllten Tabellen keinen nicht-konstanten DEFAULT CURRENT_TIMESTAMP
        $alterBody = (string)preg_replace('/\b(?:NOT\s+NULL\s+)?DEFAULT\s+CURRENT_TIMESTAMP\b/i', 'DEFAULT NULL', $alterBody);
        if (stripos($alterBody, 'NOT NULL') !== false && stripos($alterBody, 'DEFAULT') === false) {
            $alterBody .= " DEFAULT ''";
        }

        return "ALTER TABLE `{$table}` {$alterBody}";
    }
}

/**
 * Kopiert alle Tabellenstrukturen und Datensätze vollständig von einer Quell-PDO-Verbindung
 * (MySQL oder SQLite) in eine Ziel-PDO-Verbindung (SQLite oder MySQL).
 *
 * @return array{ok: bool, tables_count: int, rows_count: int, error?: string}
 */
function migrateMiniCloudDatabase(PDO $sourcePdo, PDO $targetPdo): array
{
    try {
        if (function_exists('ensureSchemaUpdated')) {
            ensureSchemaUpdated($targetPdo, true);
        }
        if (function_exists('ensureUserSecurityColumns')) {
            ensureUserSecurityColumns($targetPdo, true);
        }

        $tablesStmt = $sourcePdo->query('SHOW TABLES');
        $sourceTables = $tablesStmt ? $tablesStmt->fetchAll(PDO::FETCH_COLUMN) : [];

        $targetTablesStmt = $targetPdo->query('SHOW TABLES');
        $targetTablesMap = array_flip(
            array_map('strtolower', $targetTablesStmt ? $targetTablesStmt->fetchAll(PDO::FETCH_COLUMN) : [])
        );

        $targetPdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        $copiedTables = 0;
        $copiedRows   = 0;

        foreach ($sourceTables as $rawTable) {
            $table = (string)$rawTable;
            if ($table === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
                continue;
            }

            // Falls eine Modul-Tabelle im Ziel noch nicht existiert, aus Quelle erzeugen
            if (!isset($targetTablesMap[strtolower($table)])) {
                $createStmt = $sourcePdo->query("SHOW CREATE TABLE `{$table}`");
                $createRow  = $createStmt ? $createStmt->fetch(PDO::FETCH_NUM) : false;
                if (is_array($createRow) && !empty($createRow[1])) {
                    $createSql = (string)$createRow[1];
                    $createSql = (string)preg_replace('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?/i', 'CREATE TABLE IF NOT EXISTS ', $createSql);
                    if (!($targetPdo instanceof MiniCloudSQLitePDO)) {
                        // Konvertierung von SQLite CREATE TABLE nach MySQL
                        $createSql = (string)preg_replace('/\bINTEGER\s+PRIMARY\s+KEY\s+AUTOINCREMENT\b/i', 'INT NOT NULL AUTO_INCREMENT PRIMARY KEY', $createSql);
                    }
                    $targetPdo->exec($createSql);
                    $targetTablesMap[strtolower($table)] = 1;
                }
            }

            // Fehlende Erweiterungsspalten im Ziel ergänzen
            $srcColsStmt = $sourcePdo->query("SHOW COLUMNS FROM `{$table}`");
            $srcCols     = $srcColsStmt ? $srcColsStmt->fetchAll(PDO::FETCH_ASSOC) : [];
            $dstColsStmt = $targetPdo->query("SHOW COLUMNS FROM `{$table}`");
            $dstColsMap  = array_flip(
                array_map('strtolower', $dstColsStmt ? $dstColsStmt->fetchAll(PDO::FETCH_COLUMN) : [])
            );

            foreach ($srcCols as $sc) {
                $colName = (string)($sc['Field'] ?? '');
                if ($colName !== '' && !isset($dstColsMap[strtolower($colName)]) && preg_match('/^[a-zA-Z0-9_]+$/', $colName)) {
                    $colType = (string)($sc['Type'] ?? 'TEXT');
                    if ($colType === '') {
                        $colType = 'TEXT';
                    }
                    try {
                        $targetPdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$colName}` {$colType} DEFAULT NULL");
                        $dstColsMap[strtolower($colName)] = 1;
                    } catch (Throwable) {
                        // Ignorieren falls Spalte nicht dynamisch ergänzt werden kann
                    }
                }
            }

            // Datensätze kopieren
            $rowsStmt = $sourcePdo->query("SELECT * FROM `{$table}`");
            $rows     = $rowsStmt ? $rowsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

            $inTx = false;
            if (!$targetPdo->inTransaction()) {
                $targetPdo->beginTransaction();
                $inTx = true;
            }

            try {
                $targetPdo->exec("DELETE FROM `{$table}`");

                if (!empty($rows)) {
                    $firstRow = $rows[0];
                    $colNames = [];
                    foreach (array_keys($firstRow) as $cn) {
                        if (isset($dstColsMap[strtolower((string)$cn)])) {
                            $colNames[] = (string)$cn;
                        }
                    }

                    if (!empty($colNames)) {
                        $quotedCols   = '`' . implode('`, `', $colNames) . '`';
                        $placeholders = implode(', ', array_fill(0, count($colNames), '?'));
                        $insStmt      = $targetPdo->prepare("INSERT INTO `{$table}` ({$quotedCols}) VALUES ({$placeholders})");

                        foreach ($rows as $r) {
                            $vals = [];
                            foreach ($colNames as $cn) {
                                $vals[] = $r[$cn] ?? null;
                            }
                            $insStmt->execute($vals);
                            $copiedRows++;
                        }
                    }
                }

                if ($inTx && $targetPdo->inTransaction()) {
                    $targetPdo->commit();
                }
                $copiedTables++;
            } catch (Throwable $txErr) {
                if ($inTx && $targetPdo->inTransaction()) {
                    $targetPdo->rollBack();
                }
                throw $txErr;
            }
        }

        $targetPdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        return [
            'ok'           => true,
            'tables_count' => $copiedTables,
            'rows_count'   => $copiedRows,
        ];
    } catch (Throwable $e) {
        try {
            $targetPdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        } catch (Throwable) {
        }
        return [
            'ok'           => false,
            'tables_count' => 0,
            'rows_count'   => 0,
            'error'        => $e->getMessage(),
        ];
    }
}

/**
 * Schreibt die Datei config/db_credentials.php unter Beibehaltung sowohl der MySQL- als auch der SQLite-Parameter.
 */
function writeMiniCloudDbCredentials(
    string $credFilePath,
    string $driver,
    string $sqlitePath,
    string $dbHost,
    int $dbPort,
    string $dbName,
    string $dbUser,
    string $dbPass
): bool {
    $driverClean = strtolower(trim($driver)) === 'sqlite' ? 'sqlite' : 'mysql';
    $content = "<?php\n"
        . "declare(strict_types=1);\n\n"
        . "if (PHP_SAPI !== 'cli' && (realpath((string)(\$_SERVER['SCRIPT_FILENAME'] ?? '')) ?: '') === realpath(__FILE__)) {\n"
        . "    http_response_code(403);\n"
        . "    exit('Zugriff verweigert.');\n"
        . "}\n\n"
        . "/**\n"
        . " * MiniCloud – Lokale Datenbank-Konfiguration (MySQL / MariaDB & SQLite)\n"
        . " * Aktualisiert am " . date('d.m.Y H:i:s') . "\n"
        . " */\n"
        . "define('DB_DRIVER', " . var_export($driverClean, true) . ");\n"
        . "define('DB_SQLITE_PATH', " . var_export($sqlitePath, true) . ");\n"
        . "define('DB_HOST', " . var_export($dbHost, true) . ");\n"
        . "define('DB_PORT', " . var_export((string)$dbPort, true) . ");\n"
        . "define('DB_NAME', " . var_export($dbName, true) . ");\n"
        . "define('DB_USER', " . var_export($dbUser, true) . ");\n"
        . "define('DB_PASS', " . var_export($dbPass, true) . ");\n";

    if (@file_put_contents($credFilePath, $content) === false) {
        return false;
    }
    @chmod($credFilePath, 0640);
    return true;
}

