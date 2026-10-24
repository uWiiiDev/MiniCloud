<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$pdo         = getDB();
$appSettings = getAppSettings($pdo);
$appName     = $appSettings['app_title'] ?: APP_NAME;
$isAdminUser = isAdmin();

$pageTitle = 'Hilfe & Dokumentation';
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-6xl mx-auto">
    <!-- Kopfzeile -->
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div>
            <h1 class="text-xl sm:text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2.5">
                <svg class="w-6 h-6 text-brand-600 dark:text-brand-400 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span>Hilfe &amp; Benutzerhandbuch – <?= e($appName) ?></span>
            </h1>
            <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-0.5">
                Alle Funktionen, Tastenkürzel, Sicherheitsinformationen und das Datenverzeichnis im Überblick.
            </p>
        </div>

        <a
            href="index.php"
            class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-slate-200/80 dark:bg-slate-800 hover:bg-slate-300/80 dark:hover:bg-slate-700 text-slate-800 dark:text-slate-200 text-xs sm:text-sm font-semibold transition-colors self-start sm:self-auto"
        >
            &larr; Zurück zu meinen Dateien
        </a>
    </div>

    <!-- Schnellnavigation -->
    <nav aria-label="Inhaltsverzeichnis" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-3.5 sm:p-4 shadow-xs mb-6">
        <div class="flex flex-wrap items-center gap-2 text-xs sm:text-sm font-semibold">
            <span class="text-slate-400 dark:text-slate-500 mr-1">Direkt zu:</span>
            <a href="#upload-ordner" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">
                📤 Upload &amp; Ordner
            </a>
            <a href="#vorschau-sortierung" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">
                👁️ Vorschau &amp; Sortierung
            </a>
            <a href="#verschieben-kopieren" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">
                ⇄ Verschieben &amp; Kopieren
            </a>
            <a href="#teilen-gruppen" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">
                🔗 Teilen &amp; Gruppenordner
            </a>
            <a href="#tresor-modus" class="px-3 py-1.5 rounded-xl bg-amber-50 dark:bg-amber-950/60 hover:bg-amber-100 text-amber-800 dark:text-amber-300 border border-amber-200/70 dark:border-amber-800/60 transition-colors">
                🔒 Tresor-Modus
            </a>
            <a href="#papierkorb-themes" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">
                🗑️ Papierkorb &amp; Designs
            </a>
            <?php if ($isAdminUser): ?>
                <a href="#admin-hilfe" class="px-3 py-1.5 rounded-xl bg-amber-50 dark:bg-amber-950/60 hover:bg-amber-100 text-amber-800 dark:text-amber-300 border border-amber-200/70 dark:border-amber-800/60 transition-colors">
                    ⚙️ Administration
                </a>
            <?php endif; ?>
            <a href="#it-sicherheit" class="px-3 py-1.5 rounded-xl bg-emerald-50 dark:bg-emerald-950/60 hover:bg-emerald-100 text-emerald-800 dark:text-emerald-300 border border-emerald-200/70 dark:border-emerald-800/60 transition-colors">
                🛡️ IT-Sicherheitskonzept
            </a>
            <a href="#datenverzeichnis" class="px-3 py-1.5 rounded-xl bg-indigo-50 dark:bg-indigo-950/60 hover:bg-indigo-100 text-indigo-800 dark:text-indigo-300 border border-indigo-200/70 dark:border-indigo-800/60 transition-colors">
                🗂️ Datenverzeichnis
            </a>
        </div>
    </nav>

    <!-- 1. Dateien & Ordner hochladen und verwalten -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
        <section id="upload-ordner" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-brand-100 dark:bg-brand-950 text-brand-600 dark:text-brand-400 font-bold">📤</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Dateien &amp; Ordner hochladen</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>Sofort-Upload per Drag &amp; Drop:</strong> Ziehen Sie einzelne Dateien oder komplette Ordner aus Ihrem Dateimanager direkt auf die gestrichelte Upload-Fläche. Der Upload startet automatisch.</li>
                <li><strong>Auswahl-Buttons:</strong> Über <em>„+ Dateien“</em> wählen Sie mehrere Einzeldateien aus, über <em>„Ordner“</em> laden Sie eine komplette Ordnerstruktur samt Unterordnern hoch.</li>
                <li><strong>Zielordner festlegen:</strong> Im Feld <em>„Zielordner (optional)“</em> ist automatisch Ihr aktuell geöffneter Ordner voreingestellt. Sie können dort auch direkt einen neuen Pfad (z. B. <code>Projekte/2026</code>) eintragen.</li>
                <li><strong>Unterordner anlegen:</strong> Über die Ordnerleiste erstellen Sie jederzeit neue Ordner oder Unterordner im aktuell geöffneten Verzeichnis.</li>
            </ul>
        </section>

        <!-- 2. Dateivorschau, Durchblättern & Sortierung -->
        <section id="vorschau-sortierung" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-violet-100 dark:bg-violet-950 text-violet-600 dark:text-violet-300 font-bold">👁️</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Vorschau, Durchblättern &amp; Sortierung</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>Direktvorschau:</strong> Ein Klick auf das Augen-Symbol (<span class="text-violet-600 dark:text-violet-400 font-semibold">Vorschau</span>), das Dateityp-Badge oder den Dateinamen öffnet das Vorschau-Fenster für Bilder, PDF-Dokumente, Videos, Audiodateien sowie Text- und Quellcodedateien.</li>
                <li><strong>Durchblättern im Ordner:</strong> Befinden sich mehrere Dateien im aktuellen Ordner, können Sie über die Vor-/Zurück-Buttons oder mit den Pfeiltasten <kbd class="px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-800 text-xs font-mono">&larr;</kbd> und <kbd class="px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-800 text-xs font-mono">&rarr;</kbd> nahtlos durch alle Dateien blättern (<kbd class="px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-800 text-xs font-mono">Esc</kbd> schließt die Vorschau).</li>
                <li><strong>Tabellensortierung:</strong> Klicken Sie auf die Spaltenüberschriften <em>Name</em>, <em>Ordner / Verschieben</em>, <em>Größe</em> oder <em>Typ / Datum</em>, um auf- oder absteigend (`↑` / `↓`) zu sortieren.</li>
            </ul>
        </section>

        <!-- 3. Verschieben & Kopieren -->
        <section id="verschieben-kopieren" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300 font-bold">⇄</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Dateien &amp; Ordner verschieben oder kopieren</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>Einzelne Dateien:</strong> Wählen Sie in der Spalte <em>„Ordner / Verschieben“</em> im Auswahlmenü entweder unter <strong>⇄ Verschieben</strong> oder <strong>📋 Kopieren</strong> den gewünschten Zielordner aus.</li>
                <li><strong>Ganze Ordner samt Inhalt:</strong> Sowohl in der oberen Ordnerleiste als auch direkt in jeder Unterordner-Zeile der Tabelle können komplette Ordner inklusive aller Unterordner und Dateien verschoben oder kopiert werden.</li>
                <li><strong>Zwischen persönlicher Cloud &amp; Gruppenordnern:</strong> Sie können Dateien und komplette Ordner in beiden Richtungen zwischen Ihren persönlichen Ordnern und allen Gruppenordnern (mit Schreibrecht) verschieben oder kopieren.</li>
            </ul>
        </section>

        <!-- 4. Teilen & Gruppenordner -->
        <section id="teilen-gruppen" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300 font-bold">🔗</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Freigaben &amp; Gruppenordner</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>Öffentliche Freigabelinks:</strong> Über das Teilen-Symbol erstellen Sie für Dateien oder ganze Ordner einen kryptografisch gesicherten Link – optional mit <strong>Passwortschutz</strong> und <strong>Ablaufdatum</strong>.</li>
                <li><strong>Direktes Teilen mit Cloud-Benutzern:</strong> Im selben Teilen-Dialog können Sie Dateien oder Ordner für andere registrierte Benutzer freigeben. Diese erscheinen dort unter <em>„Für mich freigegeben“</em>.</li>
                <li><strong>Gruppenordner:</strong> Gemeinsame Team-Ordner finden Sie im Bereich <em>„Gruppenordner“</em>. Je nach Berechtigung haben Sie dort Lesezugriff (👁️ Nur Lesen) oder vollen Schreibzugriff (✏️ Lesen &amp; Schreiben).</li>
            </ul>
        </section>
    </div>

    <!-- 4b. TRESOR-MODUS (Einzeldatei-Verschlüsselung AES-256 Zero-Knowledge) -->
    <section id="tresor-modus" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-amber-200 dark:border-amber-800/70 p-5 sm:p-6 shadow-xs mb-6 space-y-4">
        <div class="flex items-center gap-2.5">
            <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300 font-bold">🔒</span>
            <div>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Tresor-Modus – Einzeldatei-Verschlüsselung (AES-256)</h2>
                <p class="text-xs text-slate-500 dark:text-slate-400">Sensible Dokumente mit einem nur Ihnen bekannten Passwort schützen (Zero-Knowledge-Prinzip)</p>
            </div>
        </div>

        <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
            <li><strong>Beim Upload oder nachträglich verschlüsseln:</strong> Sie können sensible Dateien entweder direkt beim Hochladen über das Feld <em>„🔒 Tresor-Passwort (optional)“</em> oder jederzeit nachträglich über das <strong>🔒 Schloss-Symbol</strong> in der Dateiliste mit <strong>AES-256-GCM (PBKDF2-HMAC-SHA256)</strong> verschlüsseln.</li>
            <li><strong>Entschlüsseln &amp; Teilen:</strong> Tresor-Dateien sind mit dem Abzeichen <strong>🔒 Tresor</strong> markiert. Beim Herunterladen (auch über geteilte Links oder Freigaben an andere Benutzer) wird das Tresor-Passwort abgefragt. Zudem können Sie den Tresor-Schutz über das Schloss-Symbol jederzeit wieder dauerhaft aufheben.</li>
        </ul>

        <div class="p-4 rounded-xl bg-amber-50 dark:bg-amber-950/50 border border-amber-300/80 dark:border-amber-800 text-xs sm:text-sm text-amber-950 dark:text-amber-200 leading-relaxed">
            <strong>⚠️ Wichtiger Hinweis zum Tresor-Passwort (Zero-Knowledge):</strong><br>
            Das von Ihnen gewählte Tresor-Passwort wird <strong>nirgendwo auf dem Server gespeichert</strong> – weder in der Datenbank noch in einer Konfigurationsdatei. Nach dem Verschlüsseln wird der Schlüssel sofort aus dem Arbeitsspeicher verworfen. <strong>Wenn Sie das Tresor-Passwort für eine Datei vergessen, kann niemand – auch nicht der Administrator – die Datei wiederherstellen!</strong>
        </div>
    </section>

    <!-- 5. Papierkorb, Designs & Admin-Hilfe -->
    <div class="grid grid-cols-1 <?= $isAdminUser ? 'md:grid-cols-2' : '' ?> gap-6 mb-8">
        <section id="papierkorb-themes" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-bold">🗑️</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Papierkorb &amp; Farbdesigns</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>30 Tage Papierkorb-Aufbewahrung:</strong> Gelöschte Dateien und Ordner wandern zunächst in den <a href="trash.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Papierkorb</a>. Dort können sie 30 Tage lang mit einem Klick wiederhergestellt oder sofort endgültig gelöscht werden.</li>
                <li><strong>Augenfreundliche Farbschemata:</strong> Oben rechts in der Kopfzeile wählen Sie zwischen 5 angenehm abgedunkelten hellen Designs (<em>Sand, Salbei, Nordisch, Lavendel, Klassisch Hell</em>) und 3 dunklen Designs (<em>Dunkel, Ozean, Wald</em>). Ihre Auswahl wird automatisch in Ihrem Benutzerprofil gespeichert.</li>
            </ul>
        </section>

        <?php if ($isAdminUser): ?>
        <section id="admin-hilfe" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300 font-bold">⚙️</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Hinweise für Administratoren</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>Branding &amp; Impressum:</strong> Unter <a href="admin.php#branding" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Administration</a> passen Sie Anwendungstitel, Untertitel, Logo und den Impressumstext an.</li>
                <li><strong>Benutzer &amp; Speicher-Quotas:</strong> Legen Sie neue Konten an, vergeben Sie individuelles Speicherkontingent (in MB) und setzen Sie bei Bedarf Passwörter zurück.</li>
                <li><strong>Benutzergruppen &amp; Gruppenordner:</strong> Erstellen Sie zuerst unter <em>„Benutzergruppen verwalten“</em> Gruppen und weisen Sie anschließend unter <em>„Gruppenordner &amp; Zugriffsrechte“</em> Lese- oder Schreibrechte an ganze Gruppen oder einzelne Benutzer zu.</li>
            </ul>
        </section>
        <?php endif; ?>
    </div>

    <!-- 6. IT-SICHERHEITSKONZEPT (inkl. Ergebnis des Sicherheitsaudits) -->
    <section id="it-sicherheit" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-7 shadow-xs mb-8 space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-2 pb-4 border-b border-slate-200 dark:border-slate-800">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300 font-bold text-lg">🛡️</span>
                <div>
                    <h2 class="text-lg sm:text-xl font-bold text-slate-900 dark:text-white">IT-Sicherheitskonzept</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Technische und organisatorische Schutzmaßnahmen (TOM) sowie Ergebnisse der Sicherheitsprüfung</p>
                </div>
            </div>
            <span class="text-xs font-semibold px-3 py-1 rounded-full bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300">
                Stand: <?= date('d.m.Y') ?> &bull; Geprüft &amp; Gehärtet
            </span>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs sm:text-sm">
            <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 space-y-1.5">
                <h3 class="font-bold text-slate-900 dark:text-white">1. Authentifizierung &amp; Brute-Force-Schutz</h3>
                <p class="text-slate-600 dark:text-slate-300 leading-relaxed">
                    Geschlossenes Benutzersystem ohne öffentliche Selbstregistrierung. Passwörter werden ausschließlich mittels <strong>BCRYPT (Cost-Faktor 12)</strong> gehasht. Gegen Timing-Angriffe wird bei unbekannten Benutzernamen ein Dummy-Hash geprüft. Nach <strong>5 Fehlversuchen innerhalb von 5 Minuten</strong> greift eine automatische temporäre Sperre (sowohl beim Login als auch bei passwortgeschützten Freigabelinks).
                </p>
            </div>

            <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 space-y-1.5">
                <h3 class="font-bold text-slate-900 dark:text-white">2. Session- &amp; Zugriffsschutz (RBAC)</h3>
                <p class="text-slate-600 dark:text-slate-300 leading-relaxed">
                    Sessions nutzen <code>session.use_strict_mode</code>, <code>HttpOnly</code>, <code>SameSite=Lax</code> und bei HTTPS <code>Secure</code>. Nach erfolgreichem Login erfolgt <code>session_regenerate_id(true)</code> gegen Session-Fixation. Bei jedem Seitenaufruf gleicht das System Benutzerexistenz und Administrator-Status in Echtzeit mit der Datenbank ab, sodass gelöschte Konten oder entzogene Rechte sofort wirksam sind.
                </p>
            </div>

            <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 space-y-1.5">
                <h3 class="font-bold text-slate-900 dark:text-white">3. Dateispeicher- &amp; Upload-Isolierung</h3>
                <p class="text-slate-600 dark:text-slate-300 leading-relaxed">
                    Hochgeladene Dateien werden niemals unter ihrem Originalnamen im Dateisystem abgelegt, sondern erhalten einen kryptografisch zufälligen Dateinamen mit der nicht ausführbaren Endung <code>.bin</code> (z. B. <code>mc_..._a1b2c3d4.bin</code>) und restriktiven Dateirechten (<code>0640</code>). Das Verzeichnis <code>uploads/</code> ist zusätzlich per <code>.htaccess</code> (<code>Require all denied</code>, <code>Options -Indexes -ExecCGI</code>) gegen direkten Web-Zugriff gesperrt.
                </p>
            </div>

            <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 space-y-1.5">
                <h3 class="font-bold text-slate-900 dark:text-white">4. Schutz vor SQLi, XSS, CSRF &amp; Path-Traversal</h3>
                <p class="text-slate-600 dark:text-slate-300 leading-relaxed">
                    Alle Datenbankabfragen nutzen echte <strong>PDO Prepared Statements</strong> (<code>ATTR_EMULATE_PREPARES = false</code>). Sämtliche Ausgaben werden per <code>htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')</code> bzw. <code>JSON_HEX_TAG</code> maskiert. Jede zustandsändernde Aktion erfordert einen <code>POST</code>-Request mit kryptografischem 256-Bit-CSRF-Token (<code>hash_equals</code>). Pfadangaben werden strikt gegen <code>..</code> und SQL-Wildcards (<code>%</code>) bereinigt.
                </p>
            </div>
        </div>

        <!-- Ergebnisse des Sicherheitsaudits -->
        <div class="p-4 sm:p-5 rounded-xl bg-emerald-50/70 dark:bg-emerald-950/30 border border-emerald-200/80 dark:border-emerald-800/60">
            <h3 class="text-sm sm:text-base font-bold text-emerald-950 dark:text-emerald-200 mb-2">
                Ergebnisse der durchgeführten Sicherheitsüberprüfung &amp; Härtung
            </h3>
            <ul class="text-xs sm:text-sm text-emerald-900 dark:text-emerald-200/90 space-y-1.5 list-disc pl-5">
                <li><strong>Script-Kontext-Härtung (XSS-Prävention):</strong> JSON-Serialisierung für die Dateivorschau und CSRF-Tokens mit <code>JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT</code> abgesichert.</li>
                <li><strong>DOM-XSS-Schutz im Teilen-Dialog:</strong> Vollständige HTML-Maskierung (<code>escapeHtml</code>) aller dynamisch geladenen Freigabe-URLs, Ablaufdaten und Benutzernamen.</li>
                <li><strong>Host-Header- &amp; Open-Redirect-Schutz:</strong> Strikte Regex-Validierung von <code>HTTP_HOST</code> bei der Link-Generierung sowie Whitelist-Prüfung lokaler Zielseiten beim Theme-Wechsel.</li>
                <li><strong>Forced-Logout-Schutz:</strong> Abmeldung (<code>logout.php</code>) akzeptiert ausschließlich CSRF-geschützte <code>POST</code>-Anfragen.</li>
                <li><strong>Brute-Force-Sperre:</strong> Ratenbegrenzung (max. 5 Fehlversuche pro 5 Minuten) für Benutzer-Login und passwortgeschützte Freigabelinks.</li>
                <li><strong>SVG-Sanitisierung &amp; Verzeichnisrechte:</strong> Abwehr aktiver Skriptelemente in hochgeladenen SVG-Logos sowie Härtung der Verzeichnisberechtigungen auf <code>0750</code>.</li>
            </ul>
        </div>
    </section>

    <!-- 7. DATENVERZEICHNIS -->
    <section id="datenverzeichnis" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-7 shadow-xs mb-8 space-y-4">
        <div class="flex items-center gap-2.5 pb-4 border-b border-slate-200 dark:border-slate-800">
            <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-indigo-100 dark:bg-indigo-950 text-indigo-700 dark:text-indigo-300 font-bold text-lg">🗂️</span>
            <div>
                <h2 class="text-lg sm:text-xl font-bold text-slate-900 dark:text-white">Datenverzeichnis (Data Dictionary &amp; Bestandsübersicht)</h2>
                <p class="text-xs text-slate-500 dark:text-slate-400">Übersicht aller verarbeiteten Datenbestände, Datenbanktabellen, Speicherorte und Löschfristen</p>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-xs sm:text-sm">
                <thead>
                    <tr class="bg-slate-50/80 dark:bg-slate-800/60 border-b border-slate-200 dark:border-slate-800 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                        <th class="py-3 px-4">Tabelle / Speicherort</th>
                        <th class="py-3 px-4">Wesentliche Felder / Inhalte</th>
                        <th class="py-3 px-4">Zweck &amp; Personenbezug</th>
                        <th class="py-3 px-4">Aufbewahrung / Löschung</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200/80 dark:divide-slate-800 text-slate-700 dark:text-slate-300">
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">users</td>
                        <td class="py-3 px-4"><code>id, username, email, password_hash, two_factor_enabled, two_factor_code_hash, two_factor_expires_at, is_admin, theme, storage_quota, created_at</code></td>
                        <td class="py-3 px-4">Benutzerauthentifizierung, optionale 2FA per E-Mail, Rollensteuerung, Kontingent &amp; UI-Theme (personenbezogen)</td>
                        <td class="py-3 px-4">Bis zur Löschung des Benutzerkontos durch den Administrator (Kaskadenlöschung)</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">files</td>
                        <td class="py-3 px-4"><code>id, user_id, group_folder_id, filename, original_name, folder_path, file_size, mime_type, is_encrypted, upload_date, deleted_at</code></td>
                        <td class="py-3 px-4">Metadaten hochgeladener Dateien &amp; Zuordnung zu Benutzern/Gruppenordnern</td>
                        <td class="py-3 px-4">Aktiv bis zum Löschen; im Papierkorb (<code>deleted_at</code>) automatische Endlöschung nach 30 Tagen</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">user_folders</td>
                        <td class="py-3 px-4"><code>id, user_id, group_folder_id, folder_path, created_at</code></td>
                        <td class="py-3 px-4">Verzeichnisstruktur (inkl. leerer Ordner) in der persönlichen Cloud &amp; in Gruppenordnern</td>
                        <td class="py-3 px-4">Bis zum Löschen/Verschieben des Ordners oder Löschung des Benutzers/Gruppenordners</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">groups &amp; group_members</td>
                        <td class="py-3 px-4"><code>id, name, description</code> / <code>group_id, user_id</code></td>
                        <td class="py-3 px-4">Benutzergruppen und Mitgliedschaftszuordnung für Team-Berechtigungen</td>
                        <td class="py-3 px-4">Bis zum Entfernen der Mitgliedschaft oder Löschen der Gruppe / des Benutzers</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">group_folders &amp; group_folder_permissions</td>
                        <td class="py-3 px-4"><code>id, name, description, created_by</code> / <code>group_folder_id, user_id, group_id, can_write</code></td>
                        <td class="py-3 px-4">Gemeinsame Gruppenordner sowie Lese-/Schreibberechtigungen für Benutzer &amp; Gruppen</td>
                        <td class="py-3 px-4">Bis zum Widerruf der Berechtigung oder Löschung des Gruppenordners durch den Admin</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">shares</td>
                        <td class="py-3 px-4"><code>id, file_id, folder_path, user_id, share_token, password_hash, expires_at, created_at</code></td>
                        <td class="py-3 px-4">Öffentliche Freigabelinks (Einzeldatei oder Ordner) inkl. optionalem BCRYPT-Passwort &amp; Ablaufdatum</td>
                        <td class="py-3 px-4">Bis zum manuellen Widerruf, Ablauf (<code>expires_at</code>) oder Löschen der Datei / des Ordners</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">user_shares</td>
                        <td class="py-3 px-4"><code>id, file_id, folder_path, owner_id, shared_with_user_id, created_at</code></td>
                        <td class="py-3 px-4">Interne Direktfreigaben von Dateien oder Ordnern zwischen Cloud-Benutzern</td>
                        <td class="py-3 px-4">Bis zum Widerruf durch den Eigentümer oder Löschung der Datei / des Benutzers</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">settings</td>
                        <td class="py-3 px-4"><code>setting_key, setting_value, updated_at</code></td>
                        <td class="py-3 px-4">Systemeinstellungen (<code>app_title, app_subtitle, logo_data_uri, imprint_text</code>)</td>
                        <td class="py-3 px-4">Dauerhaft (durch Administrator jederzeit änderbar)</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">Dateisystem: uploads/</td>
                        <td class="py-3 px-4"><code>mc_&lt;uniqid&gt;_&lt;hex16&gt;.bin</code>, <code>.htaccess</code></td>
                        <td class="py-3 px-4">Physische Binärdaten der hochgeladenen Dateien (pseudonymisierter Dateiname)</td>
                        <td class="py-3 px-4">Physische Löschung (<code>unlink</code>) bei Leerung des Papierkorbs, nach 30 Tagen im Papierkorb oder bei Kontolöschung</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
