<?php
declare(strict_types=1);

/**
 * MiniCloud – 1-Datei Web-Installer (install.php)
 *
 * Diese einzelne Datei kann auf jeden leeren Webspace (z. B. STRATO, IONOS, All-Inkl,
 * Netcup oder eigenen Apache/Nginx-Server) hochgeladen und im Browser aufgerufen werden.
 *
 * Standardmäßig lädt der Installer von GitHub NUR das schlanke Kernsystem
 * (Dateimanager + App-Store & Plugin-Manager) herunter, ohne die 40+ Zusatzmodule.
 * Alle weiteren Module können anschließend bei Bedarf per 1-Klick im Hintergrund
 * über den integrierten App-Store (plugins.php) nachgeladen werden.
 */

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

if (empty($_SESSION['mc_web_installer_csrf'])) {
    $_SESSION['mc_web_installer_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['mc_web_installer_csrf'];

$githubRepo = 'uWiiiDev/MiniCloud';
$rootDir    = __DIR__;
$credFile   = $rootDir . '/config/db_credentials.php';
$isAlreadyConfigured = is_file($credFile);

/**
 * Liste aller optionalen Modul-Dateien, die beim schlanken „Nur Datei-Cloud“-Start
 * NICHT mitentpackt bzw. beim Minimal-Start ausgelassen werden.
 * Sie können später jederzeit einzeln im App-Store (plugins.php) nachgeladen werden.
 *
 * @return array<int, string>
 */
function getOptionalModuleFilesList(): array
{
    return [
        'alerts.php',
        'assistant.php',
        'authenticator.php',
        'banking.php',
        'budget.php',
        'bundesliga.php',
        'calculator.php',
        'caldav.php',
        'calendar.php',
        'carddav.php',
        'cinema.php',
        'club.php',
        'contacts.php',
        'dashboard.php',
        'ebooks.php',
        'forms.php',
        'fuel.php',
        'gallery.php',
        'games.php',
        'maps.php',
        'markets.php',
        'menti.php',
        'messages.php',
        'mindmap.php',
        'music.php',
        'news.php',
        'notes.php',
        'office.php',
        'passwords.php',
        'pdf_tools.php',
        'podcasts.php',
        'polls.php',
        'projects.php',
        'radio.php',
        'split.php',
        'surflog.php',
        'tasks.php',
        'toolbox.php',
        'traffic.php',
        'transit.php',
        'translate.php',
        'tv_radio.php',
        'video_studio.php',
        'weather.php',
        'whiteboard.php',
    ];
}

/**
 * Prüft, ob ein relativer Pfad aus dem GitHub-Archiv zu den optionalen Modulen
 * oder schweren Offline-Karten-Assets gehört.
 */
function isOptionalModuleOrAssetPath(string $relPath): bool
{
    $clean = ltrim(str_replace('\\', '/', $relPath), '/');
    if (in_array($clean, getOptionalModuleFilesList(), true)) {
        return true;
    }
    if (str_starts_with($clean, 'assets/leaflet/') || str_starts_with($clean, 'assets/maps/')) {
        return true;
    }
    return false;
}

/**
 * Lädt binäre Daten per HTTPS (cURL oder Stream-Context) von GitHub herunter.
 */
function fetchInstallerUrl(string $url, ?string &$errOut = null): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch !== false) {
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_TIMEOUT        => 60,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT      => 'MiniCloud-WebInstaller/2.0 (+https://github.com/uWiiiDev/MiniCloud)',
                CURLOPT_HTTPHEADER     => [
                    'Accept: application/vnd.github+json',
                    'Cache-Control: no-cache, no-store, must-revalidate',
                ],
            ]);
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);

            if (is_string($body) && $code >= 200 && $code < 300) {
                return $body;
            }
            $errOut = $err !== '' ? $err : sprintf('HTTP-Status %d beim Abruf von GitHub.', $code);
            return null;
        }
    }

    $ctx = stream_context_create([
        'http' => [
            'method'          => 'GET',
            'timeout'         => 60,
            'follow_location' => 1,
            'header'          => "User-Agent: MiniCloud-WebInstaller/2.0\r\nCache-Control: no-cache\r\n",
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        $errOut = 'Verbindung zu GitHub fehlgeschlagen (cURL / allow_url_fopen prüfen).';
        return null;
    }
    return $body;
}

/**
 * HTML-Escaping
 */
function instEsc(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Systemvoraussetzungen prüfen
$phpOk      = version_compare(PHP_VERSION, '8.1.0', '>=');
$zipOk      = class_exists('ZipArchive');
$pdoOk      = extension_loaded('pdo_mysql');
$writableOk = is_writable($rootDir);
$curlOrUrl  = function_exists('curl_init') || filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN);
$allReady   = $phpOk && $zipOk && $pdoOk && $writableOk && $curlOrUrl;

$coreAlreadyPresent = is_file($rootDir . '/setup.php') && is_file($rootDir . '/index.php') && is_file($rootDir . '/plugins.php');
$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'download_and_prepare') {
    $postedCsrf = (string)($_POST['csrf_token'] ?? '');
    $installMode = ($_POST['install_mode'] ?? 'minimal') === 'full' ? 'full' : 'minimal';

    if (!hash_equals($csrfToken, $postedCsrf)) {
        $errorMsg = 'Ungültiges Sicherheits-Token (CSRF). Bitte laden Sie die Seite neu.';
    } elseif (!$allReady) {
        $errorMsg = 'Bitte stellen Sie sicher, dass alle Systemvoraussetzungen (insb. ZipArchive und Schreibrechte) erfüllt sind.';
    } else {
        // Falls das Kernsystem noch nicht lokal liegt (1-Datei-Installation) oder explizit von GitHub geladen werden soll:
        $needDownload = !$coreAlreadyPresent || !empty($_POST['force_github_download']);

        if ($needDownload) {
            // 1. Neuesten Commit-SHA von GitHub ermitteln (verhindert veralteten CDN-Cache)
            $apiErr = null;
            $latestSha = '';
            $apiJson = fetchInstallerUrl(sprintf('https://api.github.com/repos/%s/commits?sha=main&per_page=1&_cb=%d', $githubRepo, time()), $apiErr);
            if ($apiJson !== null) {
                $decoded = json_decode($apiJson, true);
                if (is_array($decoded) && !empty($decoded[0]['sha'])) {
                    $latestSha = strtolower((string)$decoded[0]['sha']);
                }
            }

            $zipUrl = $latestSha !== ''
                ? sprintf('https://github.com/%s/archive/%s.zip', $githubRepo, $latestSha)
                : sprintf('https://github.com/%s/archive/refs/heads/main.zip?_cb=%d', $githubRepo, time());

            $dlErr = null;
            $zipBytes = fetchInstallerUrl($zipUrl, $dlErr);
            if ($zipBytes === null || strlen($zipBytes) < 1000) {
                $errorMsg = 'Download des MiniCloud-Pakets von GitHub fehlgeschlagen: ' . ($dlErr ?? 'Leeres Archiv.');
            } else {
                $tmpZip = tempnam(sys_get_temp_dir(), 'mc_inst_');
                if ($tmpZip === false || @file_put_contents($tmpZip, $zipBytes) === false) {
                    $errorMsg = 'Temporäre Archivdatei konnte nicht gespeichert werden.';
                } else {
                    $zip = new ZipArchive();
                    if ($zip->open($tmpZip) !== true) {
                        @unlink($tmpZip);
                        $errorMsg = 'Das heruntergeladene GitHub-Archiv konnte nicht geöffnet werden.';
                    } else {
                        $extractedCount = 0;
                        for ($i = 0; $i < $zip->numFiles; $i++) {
                            $entryName = (string)$zip->getNameIndex($i);
                            if ($entryName === '' || str_ends_with($entryName, '/')) {
                                continue;
                            }
                            $slashPos = strpos($entryName, '/');
                            if ($slashPos === false) {
                                continue;
                            }
                            $relPath = substr($entryName, $slashPos + 1);
                            if ($relPath === '' || str_contains($relPath, '..')) {
                                continue;
                            }

                            // Niemals bestehende Datenbank-Konfiguration oder install.php selbst während des Laufs überschreiben
                            if (
                                $relPath === 'config/db_credentials.php' ||
                                $relPath === 'config/database.local.php' ||
                                $relPath === 'install.php' ||
                                str_starts_with($relPath, '.git/') ||
                                str_starts_with($relPath, '.github/')
                            ) {
                                continue;
                            }

                            // Im Modus „Nur Datei-Cloud (Minimal-Start)“ alle optionalen Module weglassen!
                            if ($installMode === 'minimal' && isOptionalModuleOrAssetPath($relPath)) {
                                continue;
                            }

                            $content = $zip->getFromIndex($i);
                            if ($content === false) {
                                continue;
                            }

                            $destPath = $rootDir . '/' . $relPath;
                            $destDir  = dirname($destPath);
                            if (!is_dir($destDir) && !@mkdir($destDir, 0755, true) && !is_dir($destDir)) {
                                continue;
                            }

                            if (@file_put_contents($destPath, $content) !== false) {
                                @chmod($destPath, 0644);
                                if (function_exists('opcache_invalidate') && str_ends_with(strtolower($destPath), '.php')) {
                                    @opcache_invalidate($destPath, true);
                                }
                                $extractedCount++;
                            }
                        }
                        $zip->close();
                        @unlink($tmpZip);

                        if ($extractedCount === 0) {
                            $errorMsg = 'Es konnten keine Dateien im Zielverzeichnis entpackt werden. Bitte Schreibrechte prüfen.';
                        }
                    }
                }
            }
        }

        // Falls der Minimal-Modus gewählt wurde und aus einem Komplett-Upload noch optionale Moduldateien herumliegen:
        // sauber entfernen, damit wirklich nur das Dateimodul + App-Store vorhanden sind.
        if ($errorMsg === '' && $installMode === 'minimal') {
            foreach (getOptionalModuleFilesList() as $optFile) {
                $p = $rootDir . '/' . $optFile;
                if (is_file($p)) {
                    @unlink($p);
                }
            }
        }

        if ($errorMsg === '') {
            if (!is_dir($rootDir . '/uploads')) {
                @mkdir($rootDir . '/uploads', 0750, true);
            }
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }
            header('Location: setup.php?mode=' . rawurlencode($installMode));
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MiniCloud – Web-Installer</title>
    <?php if (is_file($rootDir . '/assets/tailwind.js')): ?>
        <script src="assets/tailwind.js"></script>
    <?php endif; ?>
    <style>
        :root {
            color-scheme: light;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f1f5f9;
            color: #1e293b;
            line-height: 1.5;
            padding: 2rem 1rem;
        }
        .mc-wrap { max-width: 680px; margin: 0 auto; }
        .mc-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 1rem;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06);
            margin-bottom: 1.25rem;
        }
        .mc-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.15rem 0.6rem;
            border-radius: 9999px;
            font-size: 0.72rem;
            font-weight: 700;
            background: #dbeafe;
            color: #1d4ed8;
        }
        .mc-option {
            display: flex;
            align-items: flex-start;
            gap: 0.85rem;
            padding: 1rem;
            border: 2px solid #cbd5e1;
            border-radius: 0.85rem;
            cursor: pointer;
            transition: all 0.15s ease;
            margin-bottom: 0.75rem;
        }
        .mc-option:hover { border-color: #3b82f6; background: #f8fafc; }
        .mc-option input[type="radio"] { margin-top: 0.25rem; transform: scale(1.15); accent-color: #2563eb; }
        .mc-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            width: 100%;
            padding: 0.85rem 1.25rem;
            border: none;
            border-radius: 0.85rem;
            background: #2563eb;
            color: #ffffff;
            font-weight: 700;
            font-size: 0.95rem;
            cursor: pointer;
            transition: background 0.15s ease;
            text-decoration: none;
        }
        .mc-btn:hover { background: #1d4ed8; }
        .mc-btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .mc-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 0.6rem; }
        .mc-check {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.6rem 0.85rem;
            border-radius: 0.65rem;
            font-size: 0.8rem;
            border: 1px solid #e2e8f0;
            background: #f8fafc;
        }
        .mc-check.ok { background: #ecfdf5; border-color: #a7f3d0; color: #065f46; }
        .mc-check.err { background: #fef2f2; border-color: #fecaca; color: #991b1b; }
    </style>
</head>
<body>
    <div class="mc-wrap">
        <div style="text-align: center; margin-bottom: 1.5rem;">
            <div style="display: inline-flex; width: 3.75rem; height: 3.75rem; border-radius: 1rem; background: #2563eb; color: #fff; align-items: center; justify-content: center; font-size: 1.85rem; margin-bottom: 0.6rem; box-shadow: 0 4px 12px rgba(37,99,235,0.25);">
                ☁️
            </div>
            <h1 style="margin: 0; font-size: 1.65rem; font-weight: 800; color: #0f172a;">
                MiniCloud – Web-Installer
            </h1>
            <p style="margin: 0.35rem 0 0; font-size: 0.9rem; color: #475569;">
                Schlanke Installation direkt von GitHub – starten Sie nur mit dem Dateimodul und laden Sie weitere Module bei Bedarf nach.
            </p>
        </div>

        <?php if ($isAlreadyConfigured): ?>
            <div class="mc-card" style="text-align: center;">
                <div style="font-size: 2.2rem; margin-bottom: 0.5rem;">✅</div>
                <h2 style="margin: 0 0 0.5rem; font-size: 1.2rem;">MiniCloud ist bereits installiert</h2>
                <p style="font-size: 0.88rem; color: #475569; margin-bottom: 1.25rem;">
                    Die Datenbank-Konfiguration (<code style="background:#f1f5f9;padding:2px 6px;border-radius:4px;">config/db_credentials.php</code>) ist bereits eingerichtet.
                    Über den <strong>App-Store &amp; Plugin-Manager</strong> können Sie jederzeit einzelne Module nachladen oder entfernen.
                </p>
                <div style="display: flex; gap: 0.75rem; justify-content: center; flex-wrap: wrap;">
                    <a href="index.php" class="mc-btn" style="width: auto; padding: 0.65rem 1.25rem;">📁 Zur Datei-Cloud</a>
                    <a href="plugins.php" class="mc-btn" style="width: auto; padding: 0.65rem 1.25rem; background: #0f172a;">🧩 Zum App-Store &amp; Plugin-Manager</a>
                </div>
            </div>
        <?php else: ?>

            <?php if ($errorMsg !== ''): ?>
                <div class="mc-card" style="background: #fef2f2; border-color: #fecaca; color: #991b1b; font-size: 0.88rem; font-weight: 600;">
                    ⚠️ <?= instEsc($errorMsg) ?>
                </div>
            <?php endif; ?>

            <div class="mc-card">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.85rem;">
                    <strong style="font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b;">
                        1. Server-Voraussetzungen
                    </strong>
                    <span class="mc-badge"><?= $allReady ? '✅ Bereit zur Installation' : '⚠️ Prüfung erforderlich' ?></span>
                </div>
                <div class="mc-grid">
                    <div class="mc-check <?= $phpOk ? 'ok' : 'err' ?>">
                        <span>PHP-Version (mind. 8.1)</span>
                        <strong><?= $phpOk ? '✅ ' . instEsc(PHP_VERSION) : '❌ ' . instEsc(PHP_VERSION) ?></strong>
                    </div>
                    <div class="mc-check <?= $pdoOk ? 'ok' : 'err' ?>">
                        <span>PDO MySQL-Erweiterung</span>
                        <strong><?= $pdoOk ? '✅ Aktiv' : '❌ Fehlt' ?></strong>
                    </div>
                    <div class="mc-check <?= $zipOk ? 'ok' : 'err' ?>">
                        <span>PHP ZipArchive</span>
                        <strong><?= $zipOk ? '✅ Aktiv' : '❌ Fehlt' ?></strong>
                    </div>
                    <div class="mc-check <?= $writableOk ? 'ok' : 'err' ?>">
                        <span>Verzeichnis beschreibbar</span>
                        <strong><?= $writableOk ? '✅ Ja' : '❌ Nein (CHMOD)' ?></strong>
                    </div>
                </div>
            </div>

            <form method="post" action="install.php" class="mc-card" onsubmit="const b=document.getElementById('instSubmitBtn');if(b){b.disabled=true;b.textContent='⏳ Lade MiniCloud-Kernsystem von GitHub & bereite Setup vor...';}">
                <input type="hidden" name="csrf_token" value="<?= instEsc($csrfToken) ?>">
                <input type="hidden" name="action" value="download_and_prepare">

                <div style="margin-bottom: 1rem;">
                    <strong style="font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; display: block; margin-bottom: 0.65rem;">
                        2. Gewünschten Installations-Umfang wählen
                    </strong>

                    <label class="mc-option">
                        <input type="radio" name="install_mode" value="minimal" checked>
                        <div>
                            <div style="font-weight: 800; font-size: 0.95rem; color: #0f172a; display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                                <span>📁 Schlanker Start: Nur Datei-Cloud &amp; App-Store</span>
                                <span class="mc-badge" style="background: #dcfce7; color: #166534;">Empfohlen</span>
                            </div>
                            <p style="margin: 0.25rem 0 0; font-size: 0.82rem; color: #475569;">
                                Installiert zunächst <strong>ausschließlich das Dateimodul</strong> (Ordner, Freigaben, Tresor, Volltextsuche) sowie den
                                <strong>App-Store &amp; Plugin-Manager</strong>. Alle über 40 weiteren Module (z.&nbsp;B. Kontoauszüge, Kalender, Podcasts, Office, Spiele)
                                bleiben außen vor und können später jederzeit einzeln per 1-Klick im Hintergrund nachgeladen werden.
                            </p>
                        </div>
                    </label>

                    <label class="mc-option">
                        <input type="radio" name="install_mode" value="full">
                        <div>
                            <div style="font-weight: 800; font-size: 0.95rem; color: #0f172a;">
                                🚀 Vollinstallation: Alle 40+ Module sofort mitinstallieren
                            </div>
                            <p style="margin: 0.25rem 0 0; font-size: 0.82rem; color: #475569;">
                                Lädt und aktiviert neben der Datei-Cloud sofort sämtliche Zusatzmodule (Finanzen, Kontoauszüge, Office, Kalender, Medien, Karten uvm.).
                                Nicht benötigte Module können auch später im App-Store pausiert oder entfernt werden.
                            </p>
                        </div>
                    </label>
                </div>

                <?php if ($coreAlreadyPresent): ?>
                    <div style="margin-bottom: 1rem; padding: 0.75rem 1rem; border-radius: 0.75rem; background: #f8fafc; border: 1px solid #e2e8f0; font-size: 0.8rem; color: #475569;">
                        <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                            <input type="checkbox" name="force_github_download" value="1">
                            <span>Programmdateien vor dem Start noch einmal frisch von GitHub (<code style="font-family:monospace;">uWiiiDev/MiniCloud</code>) aktualisieren</span>
                        </label>
                    </div>
                <?php endif; ?>

                <button type="submit" id="instSubmitBtn" class="mc-btn" <?= !$allReady ? 'disabled' : '' ?>>
                    <span>🚀 Weiter zur Datenbank- &amp; Admin-Einrichtung</span>
                </button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
