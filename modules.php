<?php
declare(strict_types=1);

/**
 * MiniCloud - Modul-Übersicht & Schnellstart-Zentrale (modules.php)
 * Kompakte, nach Themen gruppierte Modul-Zentrale mit 3 Ansichtsmodi
 * (📂 Themen-Gruppen aufklappbar, ⚡ Kompakt-Grid, 📋 Detail-Karten),
 * ⭐ Favoriten-Pin für die Kopfzeile, ⠿ Drag-&-Drop-Sortierung und Live-Suche.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$pdo = getDB();
$userId = (int)$_SESSION['user_id'];
$appSettings = getAppSettings();

// Live-Zähler für Badges (Ungelesene Chat-Nachrichten & Papierkorb)
$unreadChatCount = 0;
try {
    $stmtUnread = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE receiver_id = :uid AND is_read = 0 AND deleted_by_receiver = 0');
    $stmtUnread->execute([':uid' => $userId]);
    $unreadChatCount = (int)$stmtUnread->fetchColumn();
} catch (Throwable $e) {
    $unreadChatCount = 0;
}

$trashItemCount = 0;
try {
    $trashItemCount = getTrashCount($pdo, $userId, isAdmin());
} catch (Throwable $e) {
    $trashItemCount = 0;
}

$categoriesMeta = [
    'files_office' => [
        'label' => 'Dateien & Office',
        'icon'  => '📁',
        'desc'  => 'Cloud-Speicher, Dokumente, Tabellen, PDFs & Werkzeuge',
    ],
    'org_team' => [
        'label' => 'Planung & Team',
        'icon'  => '📅',
        'desc'  => 'Termine, Chat, Aufgaben, Projekte, Notizen & Umfragen',
    ],
    'finance_club' => [
        'label' => 'Finanzen & Verein',
        'icon'  => '💰',
        'desc'  => 'Haushaltsbuch, Gruppen-Reisekasse (Split) & Vereinsverwaltung',
    ],
    'media' => [
        'label' => 'Medien & Kreativ',
        'icon'  => '🎬',
        'desc'  => 'Foto-Alben, Video-Schnitt, Heimkino & Musik-Studio',
    ],
    'tools_extra' => [
        'label' => 'KI, Schutz & Freizeit',
        'icon'  => '🤖',
        'desc'  => 'KI-Assistent, Passwort-Tresor, Toolbox, Surftagebuch & Spiele',
    ],
];

// Alle verfügbaren Module mit kurzem Namen, Kurz-Subtitel & ausführlicher Beschreibung
$allModules = [
    // --- 1. Dateien & Office ---
    [
        'key'      => 'files',
        'enabled'  => true,
        'title'    => 'Dateien & Ordner',
        'shortSub' => 'Cloud-Speicher & Freigaben',
        'icon'     => '📁',
        'url'      => 'index.php',
        'category' => 'files_office',
        'desc'     => 'Dateien verwalten, Ordner strukturieren, Vorschau öffnen und Freigabe-Links erstellen.',
        'badge'    => '',
        'badgeCls' => '',
    ],
    [
        'key'      => 'office',
        'enabled'  => ($appSettings['enable_office'] ?? '1') === '1',
        'title'    => 'Office & Bild-Studio',
        'shortSub' => 'Word, Excel, Präsentationen & Fotos',
        'icon'     => '📝',
        'url'      => 'office.php',
        'category' => 'files_office',
        'desc'     => 'Word (.docx), Excel (.xlsx), PowerPoint (.pptx), Visio (.drawio), Mind-Maps sowie Foto- & Bild-Studio im Browser.',
        'badge'    => '',
        'badgeCls' => '',
    ],
    [
        'key'      => 'pdf_tools',
        'enabled'  => ($appSettings['enable_pdf_tools'] ?? '1') === '1',
        'title'    => 'PDF & Scanner',
        'shortSub' => 'Zusammenfügen, Teilen & OCR',
        'icon'     => '📄',
        'url'      => 'pdf_tools.php',
        'category' => 'files_office',
        'desc'     => 'PDF-Dateien zusammenfügen, teilen, Seiten drehen, schwärzen oder Dokumente direkt per Kamera scannen.',
        'badge'    => '',
        'badgeCls' => '',
    ],
    [
        'key'      => 'translator',
        'enabled'  => ($appSettings['enable_translator'] ?? '1') === '1',
        'title'    => 'Übersetzer',
        'shortSub' => 'Texte & Dokumente (18 Sprachen)',
        'icon'     => '🌐',
        'url'      => 'translate.php',
        'category' => 'files_office',
        'desc'     => 'Satz- & Kontext-Übersetzung für 18 Sprachen inkl. Direkt-Übersetzung von Cloud-Dokumenten (PDF, Word, Text).',
        'badge'    => '',
        'badgeCls' => '',
    ],
    [
        'key'      => 'whiteboard',
        'enabled'  => ($appSettings['enable_whiteboard'] ?? '1') === '1',
        'title'    => 'Whiteboard',
        'shortSub' => 'Skizzen, Diagramme & Sticky Notes',
        'icon'     => '🎨',
        'url'      => 'whiteboard.php',
        'category' => 'files_office',
        'desc'     => 'Zeichenfläche für Freihand-Skizzen, Flussdiagramme, Klebezettel (Sticky Notes) & PNG-/Cloud-Export.',
        'badge'    => '',
        'badgeCls' => '',
    ],
    [
        'key'      => 'ebooks',
        'enabled'  => ($appSettings['enable_ebooks'] ?? '1') === '1',
        'title'    => 'E-Book-Reader',
        'shortSub' => 'EPUB-Bibliothek & Lesefortschritt',
        'icon'     => '📚',
        'url'      => 'ebooks.php',
        'category' => 'files_office',
        'desc'     => 'EPUB-Bücher in der Cloud-Bibliothek sammeln, mit Lesezeichen, Kapitelwahl und Lesefortschritt lesen.',
        'badge'    => '',
        'badgeCls' => '',
    ],
    [
        'key'      => 'calculator',
        'enabled'  => ($appSettings['enable_calculator'] ?? '1') === '1',
        'title'    => 'Rechner-Studio',
        'shortSub' => 'Bon-Streifen, MwSt. & Einheiten',
        'icon'     => '🧮',
        'url'      => 'calculator.php',
        'category' => 'files_office',
        'desc'     => 'Wissenschaftsrechner mit beschreibbarem Rechenstreifen, MwSt.-/Rabatt-Rechner, Einheiten- & Zinsrechner.',
        'badge'    => '',
        'badgeCls' => '',
    ],
    [
        'key'      => 'trash',
        'enabled'  => true,
        'title'    => 'Papierkorb',
        'shortSub' => 'Gelöschte Dateien wiederherstellen',
        'icon'     => '🗑️',
        'url'      => 'trash.php',
        'category' => 'files_office',
        'desc'     => 'Gelöschte Dateien einsehen, bei Bedarf mit einem Klick wiederherstellen oder endgültig bereinigen.',
        'badge'    => $trashItemCount > 0 ? (string)$trashItemCount : '',
        'badgeCls' => 'bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-200',
    ],

    // --- 2. Planung & Team ---
    [
        'key'      => 'dashboard',
        'enabled'  => ($appSettings['enable_dashboard'] ?? '1') === '1',
        'title'    => 'Mein Tag',
        'shortSub' => 'Tages-Cockpit & Übersicht',
        'icon'     => '🏠',
        'url'      => 'dashboard.php',
        'category' => 'org_team',
        'desc'     => 'Persönliches Tages-Cockpit mit anstehenden Terminen, offenen Aufgaben, Schnellnotizen und Speicherstatus.',
        'badge'    => '',
        'badgeCls' => '',
    ],
    [
        'key'      => 'calendar',
        'enabled'  => ($appSettings['enable_calendar'] ?? '1') === '1',
        'title'    => 'Kalender',
        'shortSub' => 'Termine, Geburtstage & ICS',
        'icon'     => '📅',
        'url'      => 'calendar.php',
        'category' => 'org_team',
        'desc'     => 'Termine, Geburtstage, Feiertage und wiederkehrende Ereignisse inklusive ICS-Import & Export planen.',
        'badge'    => '',
        'badgeCls' => '',
    ],
    [
        'key'      => 'chat',
        'enabled'  => ($appSettings['enable_chat'] ?? '1') === '1',
        'title'    => 'Team-Chat',
        'shortSub' => 'Nachrichten & Dateianhänge',
        'icon'     => '💬',
        'url'      => 'messages.php',
        'category' => 'org_team',
        'desc'     => 'Direktnachrichten und Dateianhänge mit anderen Cloud-Mitgliedern in Echtzeit austauschen.',
        'badge'    => $unreadChatCount > 0 ? ($unreadChatCount . ' neu') : '',
        'badgeCls' => 'bg-red-600 text-white',
    ],
    [
        'key'      => 'tasks',
        'enabled'  => ($appSettings['enable_tasks'] ?? '1') === '1',
        'title'    => 'Aufgaben',
        'shortSub' => 'To-Do-Listen & Fälligkeiten',
        'icon'     => '📋',
        'url'      => 'tasks.php',
        'category' => 'org_team',
        'desc'     => 'To-Do-Listen mit Fälligkeiten, Prioritäten und Kategorien strukturieren und abhaken.',
        'badge'    => '',
        'badgeCls' => '',
    ],
    [
        'key'      => 'projects',
        'enabled'  => ($appSettings['enable_projects'] ?? '1') === '1',
        'title'    => 'Projekte & Kanban',
        'shortSub' => 'Boards, Spalten & Meilensteine',
        'icon'     => '🚀',
        'url'      => 'projects.php',
        'category' => 'org_team',
        'desc'     => 'Gemeinsame Projekte mit Kanban-Board, Meilensteinen und Team-Zuständigkeiten organisieren.',
        'badge'    => '',
        'badgeCls' => '',
    ],
    [
        'key'      => 'notes',
        'enabled'  => ($appSettings['enable_notes'] ?? '1') === '1',
        'title'    => 'Notizen & Pinnwand',
        'shortSub' => 'Haftnotizen & Checklisten',
        'icon'     => '📌',
        'url'      => 'notes.php',
        'category' => 'org_team',
        'desc'     => 'Farbige Haftnotizen, Ideen und Checklisten festhalten, anheften und durchsuchen.',
        'badge'    => '',
        'badgeCls' => '',
    ],
    [
        'key'      => 'contacts',
        'enabled'  => ($appSettings['enable_contacts'] ?? '1') === '1',
        'title'    => 'Kontakte',
        'shortSub' => 'Adressbuch & vCard-Sync',
        'icon'     => '📇',
        'url'      => 'contacts.php',
        'category' => 'org_team',
        'desc'     => 'Adressbuch mit Telefonnummern, E-Mail-Adressen, Geburtstagen und vCard-Import/Export pflegen.',
        'badge'    => '',
        'badgeCls' => '',
    ],
    [
        'key'      => 'polls',
        'enabled'  => ($appSettings['enable_polls'] ?? '1') === '1',
        'title'    => 'Umfragen & Termine',
        'shortSub' => 'Terminfindung im Doodle-Stil',
        'icon'     => '📊',
        'url'      => 'polls.php',
        'category' => 'org_team',
        'desc'     => 'Abstimmungen und Terminfindungen im Doodle-Stil für Cloud-Nutzer oder externe Gäste starten.',
        'badge'    => '',
        'badgeCls' => '',
    ],
    [
        'key'      => 'menti',
        'enabled'  => ($appSettings['enable_menti'] ?? '1') === '1',
        'title'    => 'Live-Mentimeter',
        'shortSub' => 'Wortwolken & Live-Votings',
        'icon'     => '🎤',
        'url'      => 'menti.php',
        'category' => 'org_team',
        'desc'     => 'Interaktive Live-Abstimmungen, Wortwolken und Fragerunden mit QR-Code & Zugangs-PIN durchführen.',
        'badge'    => '',
        'badgeCls' => '',
    ],
    [
        'key'      => 'forms',
        'enabled'  => ($appSettings['enable_forms'] ?? '1') === '1',
        'title'    => 'Online-Formulare',
        'shortSub' => 'Anmeldungen & Helferlisten',
        'icon'     => '🗳️',
        'url'      => 'forms.php',
        'category' => 'org_team',
        'desc'     => 'Event-Anmeldungen, Helfer-/Mitbringlisten, Online-Mitgliedsanträge und Feedback-Bögen mit Gäste-QR-Code.',
        'badge'    => '',
        'badgeCls' => '',
    ],

    // --- 3. Finanzen & Verein ---
    [
        'key'      => 'split',
        'enabled'  => ($appSettings['enable_split'] ?? '1') === '1',
        'title'    => 'Reisekasse & Split',
        'shortSub' => 'Wer schuldet wem wie viel?',
        'icon'     => '🧾',
        'url'      => 'split.php',
        'category' => 'finance_club',
        'desc'     => 'Gruppen-Kostenabrechnung für Reisen & Projekte: Auslagen mit beliebigen Profiteuren (Cloud-Nutzer & Freitext-Gäste) aufteilen.',
        'badge'    => '',
        'badgeCls' => '',
    ],
    [
        'key'      => 'budget',
        'enabled'  => ($appSettings['enable_budget'] ?? '1') === '1',
        'title'    => 'Haushaltsplaner',
        'shortSub' => 'Einnahmen, Fixkosten & Budgets',
        'icon'     => '💰',
        'url'      => 'budget.php',
        'category' => 'finance_club',
        'desc'     => 'Haushaltsbuch für Einnahmen, Ausgaben, Fixkosten/Abos, Monats-Budgets und Sparziele.',
        'badge'    => '',
        'badgeCls' => '',
    ],
    [
        'key'      => 'club',
        'enabled'  => ($appSettings['enable_club'] ?? '1') === '1',
        'title'    => 'Vereinsverwaltung',
        'shortSub' => 'Mitglieder, Beiträge & Kasse',
        'icon'     => '🏛️',
        'url'      => 'club.php',
        'category' => 'finance_club',
        'desc'     => 'Mitgliederdatei, Beitragstarife, Jahresbeiträge, Kassenbuch, Arbeitsstunden, Inventar & Vorstands-Rollen.',
        'badge'    => '',
        'badgeCls' => '',
    ],

    // --- 4. Medien & Kreativ ---
    [
        'key'      => 'gallery',
        'enabled'  => ($appSettings['enable_gallery'] ?? '1') === '1',
        'title'    => 'Foto-Galerie',
        'shortSub' => 'Alben, Diashow & Gäste-Upload',
        'icon'     => '🖼️',
        'url'      => 'gallery.php',
        'category' => 'media',
        'desc'     => 'Fotowand aller Cloud-Bilder, virtuelle Foto-Alben, Vollbild-Diashow, EXIF-Inspektor und Gäste-Sammelalben.',
        'badge'    => '',
        'badgeCls' => '',
    ],
    [
        'key'      => 'video_studio',
        'enabled'  => ($appSettings['enable_video_studio'] ?? '1') === '1',
        'title'    => 'Video-Studio',
        'shortSub' => 'Videos kürzen, drehen & vertonen',
        'icon'     => '🎬',
        'url'      => 'video_studio.php',
        'category' => 'media',
        'desc'     => 'Videos im Browser sekundengenau kürzen, drehen, auf 16:9 / 9:16 anpassen, Bauchbinden & Musik hinterlegen.',
        'badge'    => '',
        'badgeCls' => '',
    ],
    [
        'key'      => 'cinema',
        'enabled'  => ($appSettings['enable_cinema'] ?? '1') === '1',
        'title'    => 'Heimkino',
        'shortSub' => 'Streaming, Ambilight & Untertitel',
        'icon'     => '🍿',
        'url'      => 'cinema.php',
        'category' => 'media',
        'desc'     => 'Streaming-Kino für alle Cloud-Videos mit Ambilight, „Weiterschauen“-Automatik, Sound-Booster & Gäste-Ticket.',
        'badge'    => '',
        'badgeCls' => '',
    ],
    [
        'key'      => 'music',
        'enabled'  => ($appSettings['enable_music'] ?? '1') === '1',
        'title'    => 'Musik & Hörbuch',
        'shortSub' => 'Audio-Player, Playlists & Equalizer',
        'icon'     => '🎵',
        'url'      => 'music.php',
        'category' => 'media',
        'desc'     => 'MP3/Audio-Player & Hörbuch-Bibliothek mit ID3-Tags, Visualizer, Equalizer, Lesezeichen & teilbaren Playlists.',
        'badge'    => '',
        'badgeCls' => '',
    ],

    // --- 5. KI, Schutz & Freizeit ---
    [
        'key'      => 'assistant',
        'enabled'  => ($appSettings['enable_assistant'] ?? '1') === '1',
        'title'    => 'KI-Assistent',
        'shortSub' => 'Cloud-Suche & Dokumenten-Fragen',
        'icon'     => '🤖',
        'url'      => 'assistant.php',
        'category' => 'tools_extra',
        'desc'     => 'Lokaler, mehrsprachiger Cloud-Assistent für Dateisuche, Termine, Aufgaben und Fragen zur MiniCloud.',
        'badge'    => '',
        'badgeCls' => '',
    ],
    [
        'key'      => 'passwords',
        'enabled'  => ($appSettings['enable_passwords'] ?? '1') === '1',
        'title'    => 'Schlüsselbund',
        'shortSub' => 'Verschlüsselter Passwort-Tresor',
        'icon'     => '🔐',
        'url'      => 'passwords.php',
        'category' => 'tools_extra',
        'desc'     => 'Passwörter, Zugangsdaten und sichere Notizen Ende-zu-Ende verschlüsselt im Tresor aufbewahren.',
        'badge'    => '',
        'badgeCls' => '',
    ],
    [
        'key'      => 'toolbox',
        'enabled'  => ($appSettings['enable_toolbox'] ?? '1') === '1',
        'title'    => 'Alltags-Toolbox',
        'shortSub' => 'QR-Codes, Glücksrad & Stoppuhr',
        'icon'     => '🧰',
        'url'      => 'toolbox.php',
        'category' => 'tools_extra',
        'desc'     => 'WLAN- & GiroCode-QR-Codes, Glücksrad & Team-Auslosung, Stoppuhr, Text-Diff-Checker & Passphrasen-Generator.',
        'badge'    => '',
        'badgeCls' => '',
    ],
    [
        'key'      => 'surflog',
        'enabled'  => ($appSettings['enable_surflog'] ?? '1') === '1',
        'title'    => 'Surftagebuch',
        'shortSub' => 'Sessions, Windrose & Material',
        'icon'     => '🏄‍♂️',
        'url'      => 'surflog.php',
        'category' => 'tools_extra',
        'desc'     => 'Session-Logbuch für Windsurfen, Kiten, Wingfoilen & SUP mit Windrechner, Quiver-Material & GPX-Heatmap.',
        'badge'    => '',
        'badgeCls' => '',
    ],
    [
        'key'      => 'games',
        'enabled'  => ($appSettings['enable_games'] ?? '1') === '1',
        'title'    => 'Spiele-Zentrale',
        'shortSub' => '20 Browser-Spiele & Highscores',
        'icon'     => '🎮',
        'url'      => 'games.php',
        'category' => 'tools_extra',
        'desc'     => '20 werbefreie Browserspiele im Vollbildmodus mit Cloud-Highscores und Live-Duellen.',
        'badge'    => '',
        'badgeCls' => '',
    ],
];

$activeModules = array_values(array_filter($allModules, static fn(array $m): bool => !empty($m['enabled'])));
foreach ($activeModules as &$mRef) {
    $catKey = (string)$mRef['category'];
    $mRef['catLabel'] = $categoriesMeta[$catKey]['label'] ?? 'Sonstiges';
}
unset($mRef);

$pageTitle = 'Alle Module';
require_once __DIR__ . '/includes/header.php';
?>

<div class="space-y-4">
    <!-- Schlanke Steuer- & Filterleiste -->
    <div class="mc-card rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-xs p-3.5 sm:p-4">
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-3">
            <!-- Links: Titel + Anzahl -->
            <div class="flex items-center justify-between sm:justify-start gap-3">
                <div class="flex items-center gap-2.5">
                    <span class="w-9 h-9 rounded-xl bg-brand-50 dark:bg-brand-950/70 border border-brand-200/70 dark:border-brand-800/70 flex items-center justify-center text-lg shrink-0">
                        🧩
                    </span>
                    <div>
                        <div class="flex items-center gap-2">
                            <h1 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white leading-tight">
                                Cloud-Module
                            </h1>
                            <span class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300">
                                <?= count($activeModules) ?>
                            </span>
                        </div>
                        <p class="text-[11px] text-slate-400 dark:text-slate-500 hidden sm:block">
                            ⭐ heftet Module oben in die Kopfzeile an &bull; ⠿ sortiert per Ziehen
                        </p>
                    </div>
                </div>
            </div>

            <!-- Rechts: Schnellsuche + Ansichts-Umschalter + Angeheftet-Status -->
            <div class="flex flex-wrap items-center gap-2">
                <!-- Schnellsuche -->
                <div class="relative flex-1 sm:flex-initial sm:w-60">
                    <span class="absolute inset-y-0 left-0 pl-2.5 flex items-center text-slate-400 pointer-events-none text-xs">🔍</span>
                    <input
                        type="search"
                        id="moduleSearchInput"
                        oninput="applyModulePageFilters()"
                        placeholder="Modul schnell suchen…"
                        class="w-full pl-8 pr-3 py-1.5 rounded-xl text-xs bg-slate-50 dark:bg-slate-800/90 border border-slate-200 dark:border-slate-700 text-slate-800 dark:text-slate-100 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-brand-500"
                    >
                </div>

                <!-- Ansichts-Umschalter: Gruppiert | Kompakt | Details -->
                <div class="inline-flex items-center p-0.5 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-200/80 dark:border-slate-700/80" role="group" aria-label="Darstellungsmodus">
                    <button
                        type="button"
                        data-mod-view-btn="grouped"
                        onclick="setModuleViewMode('grouped')"
                        class="px-2.5 py-1 rounded-lg text-xs font-semibold transition-all cursor-pointer"
                        title="Nach Themen gruppiert (Sektionen auf- und zuklappbar)"
                    >
                        📂 Gruppiert
                    </button>
                    <button
                        type="button"
                        data-mod-view-btn="compact"
                        onclick="setModuleViewMode('compact')"
                        class="px-2.5 py-1 rounded-lg text-xs font-semibold transition-all cursor-pointer"
                        title="Alle Module als kompaktes App-Dock auf einen Blick"
                    >
                        ⚡ Kompakt
                    </button>
                    <button
                        type="button"
                        data-mod-view-btn="details"
                        onclick="setModuleViewMode('details')"
                        class="px-2.5 py-1 rounded-lg text-xs font-semibold transition-all cursor-pointer"
                        title="Ausführliche Karten mit Beschreibungstext"
                    >
                        📋 Details
                    </button>
                </div>

                <!-- Angeheftet-Badge & Reset -->
                <div class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-xl bg-amber-50/80 dark:bg-amber-950/30 border border-amber-200/80 dark:border-amber-800/60 text-xs font-medium text-amber-800 dark:text-amber-300" title="Anzahl der oben in der Kopfzeile angehefteten Module">
                    <span>⭐</span>
                    <strong id="pinnedModuleCounter" class="font-bold">0</strong>
                </div>

                <button
                    type="button"
                    onclick="resetHeaderNavAndOrder(event)"
                    class="p-1.5 rounded-xl text-xs font-semibold text-slate-500 dark:text-slate-400 hover:text-slate-800 dark:hover:text-white bg-slate-100 dark:bg-slate-800 hover:bg-slate-200/80 dark:hover:bg-slate-700 border border-slate-200/80 dark:border-slate-700 transition-colors cursor-pointer"
                    title="Angeheftete Favoriten und Kachel-Reihenfolge auf Standard zurücksetzen"
                >
                    🔄
                </button>
            </div>
        </div>

        <!-- Kategorie-Filter-Pills -->
        <div class="mt-3 pt-2.5 border-t border-slate-100 dark:border-slate-800/80 flex items-center justify-between gap-2 overflow-x-auto" id="moduleCategoryTabs">
            <div class="flex items-center gap-1.5">
                <button type="button" data-mod-filter="all" onclick="filterModulesByCategory('all')" class="mod-filter-btn px-2.5 py-1 rounded-lg text-xs font-semibold bg-brand-600 text-white shadow-2xs transition-colors cursor-pointer shrink-0">
                    Alle (<?= count($activeModules) ?>)
                </button>
                <button type="button" data-mod-filter="pinned" onclick="filterModulesByCategory('pinned')" class="mod-filter-btn px-2.5 py-1 rounded-lg text-xs font-semibold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-200/70 dark:hover:bg-slate-700 transition-colors cursor-pointer shrink-0">
                    ⭐ Favoriten
                </button>
                <?php foreach ($categoriesMeta as $catKey => $catInfo): ?>
                    <button type="button" data-mod-filter="<?= e($catKey) ?>" onclick="filterModulesByCategory('<?= e($catKey) ?>')" class="mod-filter-btn px-2.5 py-1 rounded-lg text-xs font-semibold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-200/70 dark:hover:bg-slate-700 transition-colors cursor-pointer shrink-0">
                        <?= $catInfo['icon'] ?> <?= e($catInfo['label']) ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <button
                type="button"
                id="btnToggleAllModSections"
                onclick="toggleAllModuleSections()"
                class="hidden text-[11px] font-semibold text-brand-600 dark:text-brand-400 hover:underline shrink-0 cursor-pointer px-1"
            >
                Alle Gruppen auf-/zuklappen
            </button>
        </div>
    </div>

    <!-- ⭐ Schnellzugriffs-Leiste: Meine angehefteten Favoriten -->
    <div id="pinnedQuickDock" class="hidden mc-card rounded-2xl bg-gradient-to-r from-amber-50/70 via-white to-white dark:from-amber-950/20 dark:via-slate-900 dark:to-slate-900 border border-amber-200/70 dark:border-amber-800/40 p-3">
        <div class="flex items-center justify-between gap-2 mb-2 px-1">
            <span class="text-[11px] font-bold uppercase tracking-wider text-amber-700 dark:text-amber-400 flex items-center gap-1.5">
                <span>⭐</span>
                <span>Angeheftete Direktzugriffe (Kopfzeile)</span>
            </span>
            <span class="text-[11px] text-slate-400 dark:text-slate-500 hidden sm:inline">Klicke auf ⭐ bei einem Modul zum An-/Abheften</span>
        </div>
        <div id="pinnedQuickDockList" class="flex flex-wrap items-center gap-1.5"></div>
    </div>

    <!-- 📂 ANSICHT 1: Gruppierte Themen-Sektionen (Aufklappbar) -->
    <div id="groupedModulesContainer" class="hidden space-y-3">
        <?php foreach ($categoriesMeta as $catKey => $catInfo): ?>
            <section
                data-mod-group-section="<?= e($catKey) ?>"
                class="mc-card rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 shadow-2xs overflow-hidden"
            >
                <button
                    type="button"
                    onclick="toggleModuleCategorySection('<?= e($catKey) ?>')"
                    class="w-full px-4 py-2.5 flex items-center justify-between gap-3 bg-slate-50/70 dark:bg-slate-800/40 hover:bg-slate-100/80 dark:hover:bg-slate-800/80 transition-colors text-left cursor-pointer"
                >
                    <div class="flex items-center gap-2.5 min-w-0">
                        <span
                            data-mod-group-chevron="<?= e($catKey) ?>"
                            class="w-5 h-5 rounded-md flex items-center justify-center text-slate-400 transition-transform duration-150 rotate-90 shrink-0"
                        >
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                        </span>
                        <span class="text-base leading-none"><?= $catInfo['icon'] ?></span>
                        <span class="text-xs sm:text-sm font-bold text-slate-900 dark:text-white truncate">
                            <?= e($catInfo['label']) ?>
                        </span>
                        <span class="text-[11px] text-slate-400 dark:text-slate-500 hidden md:inline truncate">
                            &mdash; <?= e($catInfo['desc']) ?>
                        </span>
                    </div>
                    <span
                        data-mod-group-count="<?= e($catKey) ?>"
                        class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-slate-200/70 dark:bg-slate-700/80 text-slate-600 dark:text-slate-300 shrink-0"
                    >0</span>
                </button>
                <div
                    data-mod-group-grid="<?= e($catKey) ?>"
                    class="p-3 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-2.5 border-t border-slate-100 dark:border-slate-800/80"
                ></div>
            </section>
        <?php endforeach; ?>
    </div>

    <!-- ⚡ / 📋 ANSICHT 2 & 3: Gemeinsames Modul-Kacheln-Raster (#appLauncherGrid) -->
    <div id="appLauncherGrid" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-2.5">
        <?php foreach ($activeModules as $mod): ?>
            <?php
            $mKey      = (string)$mod['key'];
            $mTitle    = (string)$mod['title'];
            $mShortSub = (string)$mod['shortSub'];
            $mIcon     = (string)$mod['icon'];
            $mUrl      = (string)$mod['url'];
            $mCat      = (string)$mod['category'];
            $mCatLabel = (string)$mod['catLabel'];
            $mDesc     = (string)$mod['desc'];
            $mBadge    = (string)$mod['badge'];
            $mBadgeCls = (string)$mod['badgeCls'];
            ?>
            <div
                data-launcher-item="<?= e($mKey) ?>"
                data-module-category="<?= e($mCat) ?>"
                data-module-title="<?= e($mTitle) ?>"
                data-module-icon="<?= e($mIcon) ?>"
                data-module-url="<?= e($mUrl) ?>"
                data-module-search="<?= e(mb_strtolower($mTitle . ' ' . $mShortSub . ' ' . $mDesc . ' ' . $mCatLabel)) ?>"
                draggable="true"
                title="<?= e($mTitle . ' – ' . $mDesc) ?>"
                class="mod-card group relative flex flex-col justify-between rounded-xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 hover:border-brand-400 dark:hover:border-brand-500/80 shadow-2xs hover:shadow-md p-2.5 transition-all cursor-grab active:cursor-grabbing"
            >
                <div class="flex items-start justify-between gap-1.5">
                    <a href="<?= e($mUrl) ?>" draggable="false" class="flex items-center gap-2.5 min-w-0 flex-1 group/link">
                        <div class="mod-card-icon w-9 h-9 rounded-xl bg-slate-100 dark:bg-slate-800 group-hover/link:bg-brand-50 dark:group-hover/link:bg-brand-950/60 border border-slate-200/70 dark:border-slate-700/70 flex items-center justify-center text-lg shrink-0 transition-colors">
                            <?= $mIcon ?>
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-1.5">
                                <h2 class="text-xs sm:text-[13px] font-bold text-slate-900 dark:text-white group-hover/link:text-brand-600 dark:group-hover/link:text-brand-400 transition-colors truncate">
                                    <?= e($mTitle) ?>
                                </h2>
                                <?php if ($mBadge !== ''): ?>
                                    <span class="px-1.5 py-0.2 rounded-full text-[10px] font-bold shrink-0 <?= e($mBadgeCls) ?>">
                                        <?= e($mBadge) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <p class="mod-card-sub text-[11px] text-slate-400 dark:text-slate-500 truncate">
                                <?= e($mShortSub) ?>
                            </p>
                        </div>
                    </a>

                    <!-- Stern-Button zum Anheften -->
                    <button
                        type="button"
                        data-nav-star="<?= e($mKey) ?>"
                        onclick="toggleHeaderNavFavorite('<?= e($mKey) ?>', event)"
                        class="w-6 h-6 rounded-lg text-slate-300 dark:text-slate-600 hover:text-amber-500 hover:bg-amber-50 dark:hover:bg-amber-950/50 flex items-center justify-center text-xs transition-all cursor-pointer shrink-0"
                        title="In Kopfzeile anheften / lösen"
                    >☆</button>
                </div>

                <!-- Ausführliche Beschreibung (nur im Modus „📋 Details“ sichtbar) -->
                <div class="mod-card-details hidden mt-2.5 pt-2 border-t border-slate-100 dark:border-slate-800/80">
                    <a href="<?= e($mUrl) ?>" draggable="false" class="block">
                        <p class="text-xs text-slate-600 dark:text-slate-300 leading-relaxed line-clamp-2 mb-2">
                            <?= e($mDesc) ?>
                        </p>
                    </a>
                    <div class="flex items-center justify-between gap-2 text-[11px]">
                        <span
                            data-pin-status="<?= e($mKey) ?>"
                            onclick="toggleHeaderNavFavorite('<?= e($mKey) ?>', event)"
                            class="inline-flex items-center gap-1 text-[11px] font-medium text-slate-400 dark:text-slate-500 hover:text-slate-700 dark:hover:text-slate-200 cursor-pointer select-none"
                        >
                            ☆ Oben anheften
                        </span>
                        <a href="<?= e($mUrl) ?>" draggable="false" class="font-semibold text-brand-600 dark:text-brand-400 hover:underline">
                            Öffnen &rarr;
                        </a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Leerzustand bei Suche / Filter -->
    <div id="moduleEmptyState" class="hidden rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 p-8 text-center space-y-2">
        <div class="text-3xl">🔍</div>
        <h3 class="text-sm font-bold text-slate-800 dark:text-slate-100">Keine passenden Module gefunden</h3>
        <p class="text-xs text-slate-500 dark:text-slate-400">Bitte passe deinen Suchbegriff an oder wähle die Kategorie „Alle“.</p>
        <button
            type="button"
            onclick="document.getElementById('moduleSearchInput').value = ''; filterModulesByCategory('all');"
            class="mt-2 inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-xl text-xs font-semibold bg-brand-600 hover:bg-brand-700 text-white transition-colors cursor-pointer"
        >
            Alle Module anzeigen
        </button>
    </div>
</div>

<script>
const modViewStorageKey = 'mc_modules_view_mode_u<?= (int)$userId ?>';
const modCollapsedCatsKey = 'mc_modules_collapsed_cats_u<?= (int)$userId ?>';

let currentModuleCatFilter = 'all';
let currentModuleViewMode = (function() {
    try {
        const saved = localStorage.getItem(modViewStorageKey);
        if (saved === 'grouped' || saved === 'compact' || saved === 'details') return saved;
    } catch (e) {}
    return 'grouped';
})();

let collapsedCategorySet = (function() {
    try {
        const raw = localStorage.getItem(modCollapsedCatsKey);
        if (raw) {
            const arr = JSON.parse(raw);
            if (Array.isArray(arr)) return new Set(arr);
        }
    } catch (e) {}
    return new Set();
})();

function saveCollapsedCategories() {
    try {
        localStorage.setItem(modCollapsedCatsKey, JSON.stringify(Array.from(collapsedCategorySet)));
    } catch (e) {}
}

function toggleModuleCategorySection(catKey) {
    if (collapsedCategorySet.has(catKey)) {
        collapsedCategorySet.delete(catKey);
    } else {
        collapsedCategorySet.add(catKey);
    }
    saveCollapsedCategories();
    applyModulePageFilters();
}

function toggleAllModuleSections() {
    const allCatKeys = Array.from(document.querySelectorAll('[data-mod-group-section]')).map(el => el.getAttribute('data-mod-group-section'));
    const anyOpen = allCatKeys.some(k => !collapsedCategorySet.has(k));
    if (anyOpen) {
        allCatKeys.forEach(k => collapsedCategorySet.add(k));
    } else {
        collapsedCategorySet.clear();
    }
    saveCollapsedCategories();
    applyModulePageFilters();
}

function setModuleViewMode(mode) {
    if (mode !== 'grouped' && mode !== 'compact' && mode !== 'details') {
        mode = 'grouped';
    }
    currentModuleViewMode = mode;
    try {
        localStorage.setItem(modViewStorageKey, mode);
    } catch (e) {}

    document.querySelectorAll('[data-mod-view-btn]').forEach(btn => {
        const isAct = btn.getAttribute('data-mod-view-btn') === mode;
        btn.className = isAct
            ? 'px-2.5 py-1 rounded-lg text-xs font-bold bg-white dark:bg-slate-900 text-brand-600 dark:text-brand-400 shadow-2xs transition-all cursor-pointer'
            : 'px-2.5 py-1 rounded-lg text-xs font-semibold text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white transition-all cursor-pointer';
    });

    const toggleAllBtn = document.getElementById('btnToggleAllModSections');
    if (toggleAllBtn) {
        toggleAllBtn.classList.toggle('hidden', mode !== 'grouped');
    }

    applyModulePageFilters();
}

function filterModulesByCategory(cat) {
    currentModuleCatFilter = cat;
    document.querySelectorAll('#moduleCategoryTabs .mod-filter-btn').forEach(btn => {
        const isCurrent = btn.getAttribute('data-mod-filter') === cat;
        if (isCurrent) {
            btn.className = 'mod-filter-btn px-2.5 py-1 rounded-lg text-xs font-semibold bg-brand-600 text-white shadow-2xs transition-colors cursor-pointer shrink-0';
        } else {
            btn.className = 'mod-filter-btn px-2.5 py-1 rounded-lg text-xs font-semibold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-200/70 dark:hover:bg-slate-700 transition-colors cursor-pointer shrink-0';
        }
    });
    applyModulePageFilters();
}

function renderPinnedQuickDock(allCards) {
    const dock = document.getElementById('pinnedQuickDock');
    const list = document.getElementById('pinnedQuickDockList');
    if (!dock || !list) return;

    list.innerHTML = '';
    const pinnedCards = allCards.filter(card => {
        const star = card.querySelector('[data-nav-star]');
        return star && star.textContent.trim() === '⭐';
    });

    if (pinnedCards.length === 0 || currentModuleCatFilter === 'pinned') {
        dock.classList.add('hidden');
        return;
    }

    pinnedCards.forEach(card => {
        const url = card.getAttribute('data-module-url') || '#';
        const icon = card.getAttribute('data-module-icon') || '📁';
        const title = card.getAttribute('data-module-title') || '';
        const chip = document.createElement('a');
        chip.href = url;
        chip.className = 'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-xl bg-white dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 border border-amber-200/80 dark:border-slate-700 text-xs font-semibold text-slate-800 dark:text-slate-100 shadow-2xs transition-colors';
        chip.innerHTML = `<span>${icon}</span><span></span>`;
        chip.querySelector('span:last-child').textContent = title;
        list.appendChild(chip);
    });

    dock.classList.remove('hidden');
}

function applyModulePageFilters() {
    const searchInput = document.getElementById('moduleSearchInput');
    const query = searchInput ? searchInput.value.trim().toLowerCase() : '';
    const mainGrid = document.getElementById('appLauncherGrid');
    const groupedContainer = document.getElementById('groupedModulesContainer');
    const allCards = Array.from(document.querySelectorAll('[data-launcher-item]'));

    renderPinnedQuickDock(allCards);

    // Kachel-Details je nach Ansichtsmodus ein-/ausblenden
    const isDetails = currentModuleViewMode === 'details';
    const isGrouped = currentModuleViewMode === 'grouped' && currentModuleCatFilter === 'all';

    allCards.forEach(card => {
        const detailBox = card.querySelector('.mod-card-details');
        if (detailBox) {
            detailBox.classList.toggle('hidden', !isDetails);
        }
    });

    let visibleCount = 0;

    if (isGrouped && groupedContainer) {
        mainGrid.classList.add('hidden');
        groupedContainer.classList.remove('hidden');

        // Karten in ihre jeweilige Kategorie-Sektion einsortieren
        const countsByCat = {};
        allCards.forEach(card => {
            const cat = card.getAttribute('data-module-category') || 'files_office';
            const text = card.getAttribute('data-module-search') || '';
            const matchSearch = query === '' || text.includes(query);
            card.style.display = matchSearch ? '' : 'none';

            const targetCatGrid = groupedContainer.querySelector(`[data-mod-group-grid="${cat}"]`);
            if (targetCatGrid && card.parentElement !== targetCatGrid) {
                targetCatGrid.appendChild(card);
            }

            if (matchSearch) {
                countsByCat[cat] = (countsByCat[cat] || 0) + 1;
                visibleCount++;
            }
        });

        groupedContainer.querySelectorAll('[data-mod-group-section]').forEach(sec => {
            const catKey = sec.getAttribute('data-mod-group-section');
            const cnt = countsByCat[catKey] || 0;
            sec.classList.toggle('hidden', cnt === 0);

            const cntBadge = sec.querySelector(`[data-mod-group-count="${catKey}"]`);
            if (cntBadge) cntBadge.textContent = String(cnt);

            const gridEl = sec.querySelector(`[data-mod-group-grid="${catKey}"]`);
            const chevEl = sec.querySelector(`[data-mod-group-chevron="${catKey}"]`);
            const isCollapsed = query === '' && collapsedCategorySet.has(catKey);

            if (gridEl) gridEl.classList.toggle('hidden', isCollapsed);
            if (chevEl) chevEl.classList.toggle('rotate-90', !isCollapsed);
        });
    } else {
        if (groupedContainer) groupedContainer.classList.add('hidden');
        mainGrid.classList.remove('hidden');

        mainGrid.className = isDetails
            ? 'grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3.5'
            : 'grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-2.5';

        allCards.forEach(card => {
            if (card.parentElement !== mainGrid) {
                mainGrid.appendChild(card);
            }
            const cat = card.getAttribute('data-module-category') || '';
            const text = card.getAttribute('data-module-search') || '';
            const starBtn = card.querySelector('[data-nav-star]');
            const isPinned = starBtn && starBtn.textContent.trim() === '⭐';

            let matchCat = true;
            if (currentModuleCatFilter === 'pinned') {
                matchCat = isPinned;
            } else if (currentModuleCatFilter !== 'all') {
                matchCat = (cat === currentModuleCatFilter);
            }

            const matchSearch = query === '' || text.includes(query);
            const show = matchCat && matchSearch;
            card.style.display = show ? '' : 'none';
            if (show) visibleCount++;
        });
    }

    const emptyEl = document.getElementById('moduleEmptyState');
    if (emptyEl) {
        emptyEl.classList.toggle('hidden', visibleCount > 0);
    }
}

document.addEventListener('DOMContentLoaded', function() {
    setModuleViewMode(currentModuleViewMode);
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
