<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' && basename((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) {
    http_response_code(403);
    exit('Zugriff verweigert.');
}

/**
 * MiniCloud – Zentraler Plugin- & Modul-Katalog (App-Store über GitHub)
 *
 * Ermöglicht das dynamische Nachladen, Aktualisieren, Pausieren und Entfernen
 * einzelner Cloud-Module direkt aus dem GitHub-Repository (uWiiiDev/MiniCloud),
 * ohne dass Benutzerdaten in der Datenbank verloren gehen.
 */

/**
 * Liefert alle Kategorien des MiniCloud Plugin-Stores.
 *
 * @return array<string, array{label: string, icon: string, desc: string}>
 */
function getMiniCloudPluginCategories(): array
{
    return [
        'office_prod' => [
            'label' => 'Office, Dokumente & Produktivität',
            'icon'  => '📝',
            'desc'  => 'Office-Suite, PDF-Werkzeuge, E-Books, Mind-Maps, Whiteboard, Übersetzer & Rechner',
        ],
        'org_team' => [
            'label' => 'Kalender, Kommunikation & Team',
            'icon'  => '📅',
            'desc'  => 'Tages-Cockpit, Kalender (CalDAV), Chat, Aufgaben, Projekte, Notizen, Kontakte (CardDAV), Umfragen & Formulare',
        ],
        'finance_club' => [
            'label' => 'Finanzen, Börse & Verein',
            'icon'  => '💰',
            'desc'  => 'Haushaltsbuch, Reisekasse (Split), Börse & Krypto-Radar sowie Vereinsverwaltung',
        ],
        'world_mobility' => [
            'label' => 'Wetter, Verkehr, Karten & Warnungen',
            'icon'  => '🌍',
            'desc'  => 'Offline-Karten, Wetter & Regenradar, Staumelder, Spritpreis-Radar, ÖPNV-Abfahrtsmonitor, Bevölkerungsschutz & News',
        ],
        'media_fun' => [
            'label' => 'Medien, Audio, Video & Spiele',
            'icon'  => '🎬',
            'desc'  => 'Foto-Galerie, Video-Studio, Heimkino, Musik, Podcasts, Webradio, TV-Programm, Sport-Tippspiel, Surftagebuch & Spiele',
        ],
        'security_ai' => [
            'label' => 'KI, Sicherheit & Werkzeuge',
            'icon'  => '🔐',
            'desc'  => 'Lokaler KI-Assistent, Passwort-Tresor, 2FA-Authenticator & Alltags-Toolbox',
        ],
    ];
}

/**
 * Liefert den vollständigen Katalog aller installierbaren MiniCloud-Plugins.
 *
 * @return array<string, array{
 *   id: string,
 *   name: string,
 *   icon: string,
 *   category: string,
 *   entry_file: string,
 *   extra_files: string[],
 *   needs_map_assets: bool,
 *   setting_key: string,
 *   description: string
 * }>
 */
function getMiniCloudPluginCatalog(): array
{
    return [
        // --- 1. Office, Dokumente & Produktivität ---
        'dashboard' => [
            'id'               => 'dashboard',
            'name'             => 'Tages-Cockpit („Mein Tag“)',
            'icon'             => '🏠',
            'category'         => 'org_team',
            'entry_file'       => 'dashboard.php',
            'extra_files'      => [],
            'needs_map_assets' => false,
            'setting_key'      => 'enable_dashboard',
            'description'      => 'Persönliche Startseite mit anstehenden Terminen, offenen Aufgaben, ungelesenen Nachrichten, Notizen und Speicher-Übersicht.',
        ],
        'office' => [
            'id'               => 'office',
            'name'             => 'Office Light & E-Mail-Client',
            'icon'             => '📝',
            'category'         => 'office_prod',
            'entry_file'       => 'office.php',
            'extra_files'      => ['includes/office_functions.php', 'includes/mail_client.php'],
            'needs_map_assets' => false,
            'setting_key'      => 'enable_office',
            'description'      => 'Dokumente (.docx), Tabellen (.xlsx), Präsentationen (.pptx), Diagramme, Bild-Editor und IMAP/SMTP-Webmail direkt im Browser.',
        ],
        'pdf_tools' => [
            'id'               => 'pdf_tools',
            'name'             => 'PDF-Werkzeugkasten & Scanner',
            'icon'             => '📄',
            'category'         => 'office_prod',
            'entry_file'       => 'pdf_tools.php',
            'extra_files'      => [],
            'needs_map_assets' => false,
            'setting_key'      => 'enable_pdf_tools',
            'description'      => 'PDFs zusammenfügen, aufteilen, Seiten drehen, schwärzen, komprimieren, unterschreiben und Dokumente per Kamera scannen.',
        ],
        'ebooks' => [
            'id'               => 'ebooks',
            'name'             => 'E-Book-Reader & Bibliothek',
            'icon'             => '📚',
            'category'         => 'office_prod',
            'entry_file'       => 'ebooks.php',
            'extra_files'      => [],
            'needs_map_assets' => false,
            'setting_key'      => 'enable_ebooks',
            'description'      => 'EPUB-E-Books mit Kapitelnavigation, Lesezeichen, Lesefortschritt, Nachtmodus und anpassbarer Typografie im Browser lesen.',
        ],
        'mindmap' => [
            'id'               => 'mindmap',
            'name'             => 'Mind-Map-Editor & Ideensammler',
            'icon'             => '🧠',
            'category'         => 'office_prod',
            'entry_file'       => 'mindmap.php',
            'extra_files'      => [],
            'needs_map_assets' => false,
            'setting_key'      => 'enable_mindmap',
            'description'      => 'Interaktive Mind-Maps mit Knoten, Farben, Icons und Export als Bild oder JSON im Browser erstellen.',
        ],
        'whiteboard' => [
            'id'               => 'whiteboard',
            'name'             => 'Whiteboard & Skizzenbuch',
            'icon'             => '🎨',
            'category'         => 'office_prod',
            'entry_file'       => 'whiteboard.php',
            'extra_files'      => [],
            'needs_map_assets' => false,
            'setting_key'      => 'enable_whiteboard',
            'description'      => 'Freihand-Zeichnungen, Formen, Text-Notizen, Diagramme und kollaborative Tafeln gestalten und als PNG/Beleg speichern.',
        ],
        'translator' => [
            'id'               => 'translator',
            'name'             => 'Übersetzungs-Studio (18 Sprachen)',
            'icon'             => '🌐',
            'category'         => 'office_prod',
            'entry_file'       => 'translate.php',
            'extra_files'      => [],
            'needs_map_assets' => false,
            'setting_key'      => 'enable_translator',
            'description'      => 'Texte und ganze Cloud-Dokumente (.txt, .md, .csv, .html) in 18 Sprachen übersetzen (inkl. DeepL / LibreTranslate / KI-Anbindung).',
        ],
        'calculator' => [
            'id'               => 'calculator',
            'name'             => 'Taschenrechner & Rechen-Studio',
            'icon'             => '🧮',
            'category'         => 'office_prod',
            'entry_file'       => 'calculator.php',
            'extra_files'      => [],
            'needs_map_assets' => false,
            'setting_key'      => 'enable_calculator',
            'description'      => 'Wissenschaftlicher Rechner, Einheiten-Umrechner, Prozent-/Zins-/Kreditrechner und speicherbarer Papierstreifen.',
        ],

        // --- 2. Kalender, Kommunikation & Team ---
        'calendar' => [
            'id'               => 'calendar',
            'name'             => 'Termin-Kalender & CalDAV-Sync',
            'icon'             => '📅',
            'category'         => 'org_team',
            'entry_file'       => 'calendar.php',
            'extra_files'      => ['caldav.php'],
            'needs_map_assets' => false,
            'setting_key'      => 'enable_calendar',
            'description'      => 'Monats-, Wochen- und Tageskalender mit Serienterminen, Gruppenfreigaben, .ics-Import/Export und CalDAV-Synchronisation.',
        ],
        'chat' => [
            'id'               => 'chat',
            'name'             => 'Messenger & Gruppen-Chat',
            'icon'             => '💬',
            'category'         => 'org_team',
            'entry_file'       => 'messages.php',
            'extra_files'      => [],
            'needs_map_assets' => false,
            'setting_key'      => 'enable_chat',
            'description'      => 'Interner Echtzeit-Messenger für Direktnachrichten, Gruppen-Kanäle, Rundnachrichten und schnellen Dateiaustausch.',
        ],
        'tasks' => [
            'id'               => 'tasks',
            'name'             => 'Aufgaben & Kanban-Board',
            'icon'             => '📋',
            'category'         => 'org_team',
            'entry_file'       => 'tasks.php',
            'extra_files'      => [],
            'needs_map_assets' => false,
            'setting_key'      => 'enable_tasks',
            'description'      => 'To-Do-Listen und Kanban-Boards mit Prioritäten, Fälligkeitsdaten, Checklisten und Zuweisung an Teammitglieder.',
        ],
        'projects' => [
            'id'               => 'projects',
            'name'             => 'Projektmanagement & Gantt-Planer',
            'icon'             => '🚀',
            'category'         => 'org_team',
            'entry_file'       => 'projects.php',
            'extra_files'      => [],
            'needs_map_assets' => false,
            'setting_key'      => 'enable_projects',
            'description'      => 'Projekte mit Meilensteinen, visuellem Gantt-Zeitstrahl, Fortschrittsanzeige und integrierter Zeiterfassung steuern.',
        ],
        'notes' => [
            'id'               => 'notes',
            'name'             => 'Notizwand & Pinboard',
            'icon'             => '📌',
            'category'         => 'org_team',
            'entry_file'       => 'notes.php',
            'extra_files'      => [],
            'needs_map_assets' => false,
            'setting_key'      => 'enable_notes',
            'description'      => 'Farbige Haftnotizen (Sticky Notes), Checklisten und geteilte Pinnwände für schnelle Gedanken und Entwürfe.',
        ],
        'contacts' => [
            'id'               => 'contacts',
            'name'             => 'Adressbuch, Kontakte & CardDAV',
            'icon'             => '📇',
            'category'         => 'org_team',
            'entry_file'       => 'contacts.php',
            'extra_files'      => ['carddav.php'],
            'needs_map_assets' => false,
            'setting_key'      => 'enable_contacts',
            'description'      => 'Kontakte mit Geburtstagen, Gruppen, vCard-Import/Export (.vcf) und CardDAV-Synchronisation für Smartphone & PC.',
        ],
        'polls' => [
            'id'               => 'polls',
            'name'             => 'Terminfinder & Umfragen',
            'icon'             => '📊',
            'category'         => 'org_team',
            'entry_file'       => 'polls.php',
            'extra_files'      => [],
            'needs_map_assets' => false,
            'setting_key'      => 'enable_polls',
            'description'      => 'Doodle-ähnliche Terminabstimmungen und Meinungs-Umfragen (auch per öffentlichem Gast-Link ohne Login abstimmbar).',
        ],
        'menti' => [
            'id'               => 'menti',
            'name'             => 'Live-Mentimeter & Publikums-Voting',
            'icon'             => '🎤',
            'category'         => 'org_team',
            'entry_file'       => 'menti.php',
            'extra_files'      => [],
            'needs_map_assets' => false,
            'setting_key'      => 'enable_menti',
            'description'      => 'Interaktive Live-Abstimmungen, Wortwolken und Quiz-Runden mit QR-Code-Teilnahme für Meetings, Schule & Verein.',
        ],
        'forms' => [
            'id'               => 'forms',
            'name'             => 'Online-Formulare & Anmeldung',
            'icon'             => '📝',
            'category'         => 'org_team',
            'entry_file'       => 'forms.php',
            'extra_files'      => [],
            'needs_map_assets' => false,
            'setting_key'      => 'enable_forms',
            'description'      => 'Eigene Web-Formulare, Veranstaltungs-Anmeldungen und Rückmeldebögen mit öffentlichem Teilnahmelink und CSV-Auswertung.',
        ],

        // --- 3. Finanzen, Börse & Verein ---
        'budget' => [
            'id'               => 'budget',
            'name'             => 'Haushaltsplaner & Finanz-Cockpit',
            'icon'             => '💰',
            'category'         => 'finance_club',
            'entry_file'       => 'budget.php',
            'extra_files'      => [],
            'needs_map_assets' => false,
            'setting_key'      => 'enable_budget',
            'description'      => 'Einnahmen, Ausgaben, Fixkosten, Sparziele und Monatsbudgets mit Diagrammen und CSV-Export übersichtlich verwalten.',
        ],
        'split' => [
            'id'               => 'split',
            'name'             => 'Reisekasse & Kostenaufteilung (Split)',
            'icon'             => '🧾',
            'category'         => 'finance_club',
            'entry_file'       => 'split.php',
            'extra_files'      => [],
            'needs_map_assets' => false,
            'setting_key'      => 'enable_split',
            'description'      => 'Gemeinsame Ausgaben auf Reisen, in WGs oder Gruppen erfassen und automatisch den kürzesten Ausgleichsweg berechnen.',
        ],
        'markets' => [
            'id'               => 'markets',
            'name'             => 'Börse, Krypto & KI-Timing-Radar',
            'icon'             => '📈',
            'category'         => 'finance_club',
            'entry_file'       => 'markets.php',
            'extra_files'      => [],
            'needs_map_assets' => false,
            'setting_key'      => 'enable_markets',
            'description'      => 'Aktien-, ETF-, Edelmetall- und Krypto-Kurse, virtuelles Musterdepot, Watchlist und technische Indikatoren (RSI/SMA).',
        ],
        'club' => [
            'id'               => 'club',
            'name'             => 'Vereinsverwaltung & Vorstands-Portal',
            'icon'             => '🏛️',
            'category'         => 'finance_club',
            'entry_file'       => 'club.php',
            'extra_files'      => [],
            'needs_map_assets' => false,
            'setting_key'      => 'enable_club',
            'description'      => 'Mitgliederdatenbank, Beitragsverwaltung, Vereinskasse, Inventar, Protokolle und Arbeitseinsätze für Vereine.',
        ],

        // --- 4. Wetter, Verkehr, Karten & Warnungen ---
        'maps' => [
            'id'               => 'maps',
            'name'             => 'Karten, Offline-Atlas & Lagepläne',
            'icon'             => '🗺️',
            'category'         => 'world_mobility',
            'entry_file'       => 'maps.php',
            'extra_files'      => [],
            'needs_map_assets' => true,
            'setting_key'      => 'enable_maps',
            'description'      => 'Interaktive Deutschland- & Europakarte (auch 100 % Air-Gapped offline nutzbar), GPX-Track-Viewer, Distanzmessung und eigene Marker.',
        ],
        'weather' => [
            'id'               => 'weather',
            'name'             => 'Wetter, Regenradar & Polarlicht-Radar',
            'icon'             => '⛅',
            'category'         => 'world_mobility',
            'entry_file'       => 'weather.php',
            'extra_files'      => [],
            'needs_map_assets' => true,
            'setting_key'      => 'enable_weather',
            'description'      => '7-Tage-Prognose, Live-Niederschlagsradar (RainViewer), UV- & Luftqualität, Biowetter sowie NOAA-Polarlicht- & Kp-Index-Vorhersage.',
        ],
        'traffic' => [
            'id'               => 'traffic',
            'name'             => 'Verkehrszentrale & Autobahn-Staumelder',
            'icon'             => '🚗',
            'category'         => 'world_mobility',
            'entry_file'       => 'traffic.php',
            'extra_files'      => [],
            'needs_map_assets' => true,
            'setting_key'      => 'enable_traffic',
            'description'      => 'Echtzeit-Staus, Baustellen, Vollsperrungen, Webcams und E-Ladesäulen aller deutschen Autobahnen plus Radio-Verkehrsfunk.',
        ],
        'fuel' => [
            'id'               => 'fuel',
            'name'             => 'Tankstellen, Spritpreise & Fahrtenbuch',
            'icon'             => '⛽',
            'category'         => 'world_mobility',
            'entry_file'       => 'fuel.php',
            'extra_files'      => [],
            'needs_map_assets' => true,
            'setting_key'      => 'enable_fuel',
            'description'      => 'Live-Spritpreis-Radar (E5, E10, Diesel per Tankerkönig-API), Umkreiskarte sowie digitales Tank- & Fahrtenbuch mit Verbrauchsrechner.',
        ],
        'transit' => [
            'id'               => 'transit',
            'name'             => 'ÖPNV-Abfahrtsmonitor & Bahn-Radar',
            'icon'             => '🚆',
            'category'         => 'world_mobility',
            'entry_file'       => 'transit.php',
            'extra_files'      => [],
            'needs_map_assets' => true,
            'setting_key'      => 'enable_transit',
            'description'      => 'Digitale Echtzeit-Abfahrtstafel für Bahnhöfe & Haltestellen (ICE/IC, RE/RB, S-Bahn, U-Bahn, Tram & Bus) inkl. Verspätungs-Anzeige.',
        ],
        'alerts' => [
            'id'               => 'alerts',
            'name'             => 'Bevölkerungsschutz, Pegel & Erdbeben',
            'icon'             => '🚨',
            'category'         => 'world_mobility',
            'entry_file'       => 'alerts.php',
            'extra_files'      => [],
            'needs_map_assets' => true,
            'setting_key'      => 'enable_alerts',
            'description'      => 'Offizielle NINA-/DWD-/MoWaS-Warnmeldungen des Bundes, Hochwasser-Pegelstände (Pegelonline), weltweite USGS-Erdbeben & Notfall-Checkliste.',
        ],
        'news' => [
            'id'               => 'news',
            'name'             => 'Welt-Nachrichten & RSS-News-Ticker',
            'icon'             => '📰',
            'category'         => 'world_mobility',
            'entry_file'       => 'news.php',
            'extra_files'      => [],
            'needs_map_assets' => false,
            'setting_key'      => 'enable_news',
            'description'      => 'Schlagzeilen aus Deutschland und der Welt (Tagesschau, ZDF, Spiegel, Heise, BBC u.v.m.) sowie eigene RSS-/Atom-Feeds werbefrei lesen.',
        ],

        // --- 5. Medien, Audio, Video & Spiele ---
        'gallery' => [
            'id'               => 'gallery',
            'name'             => 'Foto-Alben & Medien-Galerie',
            'icon'             => '🖼️',
            'category'         => 'media_fun',
            'entry_file'       => 'gallery.php',
            'extra_files'      => [],
            'needs_map_assets' => false,
            'setting_key'      => 'enable_gallery',
            'description'      => 'Visuelle Foto- & Video-Galerie mit Alben, Diashow, EXIF-Kameradaten, Zeitstrahl und Teilen-Links.',
        ],
        'video_studio' => [
            'id'               => 'video_studio',
            'name'             => 'Video-Studio & Schnittplatz',
            'icon'             => '🎬',
            'category'         => 'media_fun',
            'entry_file'       => 'video_studio.php',
            'extra_files'      => [],
            'needs_map_assets' => false,
            'setting_key'      => 'enable_video_studio',
            'description'      => 'Videos direkt im Browser schneiden, trimmen, drehen, mit Text-Bauchbinden, Farbfiltern und Musik unterlegen.',
        ],
        'cinema' => [
            'id'               => 'cinema',
            'name'             => 'Heimkino & Film-Mediathek',
            'icon'             => '🍿',
            'category'         => 'media_fun',
            'entry_file'       => 'cinema.php',
            'extra_files'      => [],
            'needs_map_assets' => false,
            'setting_key'      => 'enable_cinema',
            'description'      => 'Streaming-Oberfläche für eigene Cloud-Filme & Serien mit Echtzeit-Ambilight, Merkliste, Weiterschauen-Position und Gäste-Kino-Ticket.',
        ],
        'music' => [
            'id'               => 'music',
            'name'             => 'Musik- & Hörbuch-Studio',
            'icon'             => '🎵',
            'category'         => 'media_fun',
            'entry_file'       => 'music.php',
            'extra_files'      => [],
            'needs_map_assets' => false,
            'setting_key'      => 'enable_music',
            'description'      => 'Audio-Player für MP3/FLAC/M4A mit Playlists, Equalizer, Frequenz-Visualizer, Schlaf-Timer und gemerkter Hörbuch-Position.',
        ],
        'podcasts' => [
            'id'               => 'podcasts',
            'name'             => 'Podcast-Studio & Offline-Catcher',
            'icon'             => '🎙️',
            'category'         => 'media_fun',
            'entry_file'       => 'podcasts.php',
            'extra_files'      => [],
            'needs_map_assets' => false,
            'setting_key'      => 'enable_podcasts',
            'description'      => 'Podcasts abonnieren, Episoden streamen (mit variabel einstellbarem Tempo & Sleep-Timer) oder als MP3 direkt in die Cloud herunterladen.',
        ],
        'radio' => [
            'id'               => 'radio',
            'name'             => 'Live-Webradio & Weltempfänger',
            'icon'             => '📻',
            'category'         => 'media_fun',
            'entry_file'       => 'radio.php',
            'extra_files'      => [],
            'needs_map_assets' => false,
            'setting_key'      => 'enable_radio',
            'description'      => 'Über 30.000 Radiosender weltweit live hören – inkl. Mini-Player in der Fußzeile, damit die Musik beim Ordner-Wechseln weiterläuft.',
        ],
        'tv_radio' => [
            'id'               => 'tv_radio',
            'name'             => 'Fernsehprogramm & Live-EPG',
            'icon'             => '📺',
            'category'         => 'media_fun',
            'entry_file'       => 'tv_radio.php',
            'extra_files'      => [],
            'needs_map_assets' => false,
            'setting_key'      => 'enable_tv_radio',
            'description'      => 'Elektronische Programmzeitschrift (Jetzt im TV, 20:15 Uhr Primetime, 22:00 Uhr) sowie direkte Öffentlich-Rechtliche Mediathek-Livestreams.',
        ],
        'bundesliga' => [
            'id'               => 'bundesliga',
            'name'             => 'Sportergebnisse & Cloud-Tippspiel',
            'icon'             => '🏆',
            'category'         => 'media_fun',
            'entry_file'       => 'bundesliga.php',
            'extra_files'      => ['includes/sports_api.php'],
            'needs_map_assets' => false,
            'setting_key'      => 'enable_bundesliga',
            'description'      => 'Live-Ergebnisse & Tabellen (1./2./3. Bundesliga, DFB-Pokal, Champions League, Formel 1, Tennis) inklusive gemeinsamem Tippspiel.',
        ],
        'surflog' => [
            'id'               => 'surflog',
            'name'             => 'Surftagebuch & Quiver-Log',
            'icon'             => '🏄‍♂️',
            'category'         => 'media_fun',
            'entry_file'       => 'surflog.php',
            'extra_files'      => [],
            'needs_map_assets' => false,
            'setting_key'      => 'enable_surflog',
            'description'      => 'Sessions, Spots, Windstärke (Knoten/Bft), Wellenhöhe und Board-/Segel-Quiver für Windsurfen, Kiten, Wingfoilen & Wellenreiten loggen.',
        ],
        'games' => [
            'id'               => 'games',
            'name'             => 'Browserspiele & Pausenraum',
            'icon'             => '🎮',
            'category'         => 'media_fun',
            'entry_file'       => 'games.php',
            'extra_files'      => [],
            'needs_map_assets' => false,
            'setting_key'      => 'enable_games',
            'description'      => 'Klassiker für zwischendurch inkl. Cloud-Highscore-Bestenliste (Flappy Cloud, Tetris, Snake, 2048, Sudoku, Minesweeper, Schach, Solitär u.v.m.).',
        ],

        // --- 6. KI, Sicherheit & Werkzeuge ---
        'assistant' => [
            'id'               => 'assistant',
            'name'             => 'Lokaler KI- & Wissens-Assistent',
            'icon'             => '🤖',
            'category'         => 'security_ai',
            'entry_file'       => 'assistant.php',
            'extra_files'      => [],
            'needs_map_assets' => false,
            'setting_key'      => 'enable_assistant',
            'description'      => 'Durchsucht deine Cloud-Dokumente, Notizen & Termine und antwortet wahlweise lokal per Ollama oder über Google Gemini.',
        ],
        'passwords' => [
            'id'               => 'passwords',
            'name'             => 'Passwort-Manager & Schlüsselbund',
            'icon'             => '🔐',
            'category'         => 'security_ai',
            'entry_file'       => 'passwords.php',
            'extra_files'      => [],
            'needs_map_assets' => false,
            'setting_key'      => 'enable_passwords',
            'description'      => 'AES-256-GCM verschlüsselter Passwort-Tresor mit Passwort-Generator, Sicherheits-Check und KeePass/Bitwarden-CSV-Import.',
        ],
        'authenticator' => [
            'id'               => 'authenticator',
            'name'             => '2FA-Authenticator (TOTP-Codes)',
            'icon'             => '🔢',
            'category'         => 'security_ai',
            'entry_file'       => 'authenticator.php',
            'extra_files'      => [],
            'needs_map_assets' => false,
            'setting_key'      => 'enable_authenticator',
            'description'      => 'Erzeugt alle 30 Sekunden 6-stellige 2FA-Einmalcodes (kompatibel mit Google Authenticator, GitHub, Amazon, PayPal usw.).',
        ],
        'toolbox' => [
            'id'               => 'toolbox',
            'name'             => 'Alltags-Werkzeuge & Toolbox',
            'icon'             => '🧰',
            'category'         => 'security_ai',
            'entry_file'       => 'toolbox.php',
            'extra_files'      => [],
            'needs_map_assets' => false,
            'setting_key'      => 'enable_toolbox',
            'description'      => 'WLAN-QR-Code-Generator, EPC-GiroCode (Überweisungs-QR), Glücksrad, Stoppuhr/Timer, Text-Diff-Vergleich, JSON-Formatter & Farbwähler.',
        ],
    ];
}

/**
 * Prüft, ob die Hauptdatei eines Plugins physisch auf dem Server installiert ist.
 */
function isMiniCloudPluginInstalled(string $pluginId): bool
{
    $catalog = getMiniCloudPluginCatalog();
    if (!isset($catalog[$pluginId])) {
        return false;
    }
    $rootDir = dirname(__DIR__);
    $entryPath = $rootDir . '/' . ltrim($catalog[$pluginId]['entry_file'], '/');
    return is_file($entryPath);
}

/**
 * Lädt eine einzelne Datei per HTTPS vom GitHub-Repository (Branch `main`) herunter.
 *
 * @return array{ok: bool, content?: string, error?: string}
 */
function fetchPluginFileFromGitHub(string $relativePath, string $repo = 'uWiiiDev/MiniCloud', string $branch = 'main'): array
{
    $cleanPath = ltrim(str_replace('\\', '/', $relativePath), '/');
    if ($cleanPath === '' || str_contains($cleanPath, '..')) {
        return ['ok' => false, 'error' => 'Ungültiger Dateipfad: ' . $relativePath];
    }

    $url = sprintf(
        'https://raw.githubusercontent.com/%s/%s/%s',
        trim($repo, '/'),
        rawurlencode($branch),
        implode('/', array_map('rawurlencode', explode('/', $cleanPath)))
    );

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT      => 'MiniCloud-PluginManager/1.5 (+https://github.com/uWiiiDev/MiniCloud)',
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false || $code !== 200) {
            return [
                'ok'    => false,
                'error' => sprintf('GitHub-Download fehlgeschlagen (%s, HTTP %d%s)', $cleanPath, $code, $err !== '' ? ': ' . $err : ''),
            ];
        }
        return ['ok' => true, 'content' => (string)$body];
    }

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'timeout' => 25,
            'header'  => "User-Agent: MiniCloud-PluginManager/1.5\r\n",
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false || $body === '') {
        return ['ok' => false, 'error' => 'GitHub-Download fehlgeschlagen für ' . $cleanPath];
    }

    return ['ok' => true, 'content' => (string)$body];
}

/**
 * Installiert oder aktualisiert ein Plugin direkt aus dem GitHub-Hauptzweig (`main`).
 * Alle bestehenden Datenbank-Einträge bleiben zu 100 % erhalten.
 *
 * @return array{ok: bool, message: string}
 */
function installMiniCloudPluginFromGitHub(PDO $pdo, string $pluginId): array
{
    $catalog = getMiniCloudPluginCatalog();
    if (!isset($catalog[$pluginId])) {
        return ['ok' => false, 'message' => 'Unbekanntes Plugin: ' . $pluginId];
    }

    $plugin   = $catalog[$pluginId];
    $settings = getAppSettings($pdo);
    $repo     = trim((string)($settings['github_repo'] ?? 'uWiiiDev/MiniCloud')) ?: 'uWiiiDev/MiniCloud';
    $rootDir  = dirname(__DIR__);

    $filesToDownload = array_merge([$plugin['entry_file']], $plugin['extra_files']);

    if (!empty($plugin['needs_map_assets'])) {
        $mapAssets = [
            'assets/leaflet/leaflet.css',
            'assets/leaflet/leaflet.js',
            'assets/leaflet/protomaps-leaflet.js',
            'assets/maps/de_bundeslaender.geojson',
            'assets/maps/de_landkreise.geojson',
            'assets/maps/de_places_plz.json',
            'assets/maps/europe.geojson',
        ];
        foreach ($mapAssets as $assetRel) {
            if (!is_file($rootDir . '/' . $assetRel)) {
                $filesToDownload[] = $assetRel;
            }
        }
    }

    $installedFiles = 0;
    foreach ($filesToDownload as $relFile) {
        $res = fetchPluginFileFromGitHub($relFile, $repo, 'main');
        if (!$res['ok']) {
            return ['ok' => false, 'message' => (string)($res['error'] ?? 'Download-Fehler')];
        }

        $content = (string)$res['content'];
        if (str_ends_with(strtolower($relFile), '.php')) {
            $trimmed = ltrim($content);
            if (!str_starts_with($trimmed, '<?php') && !str_starts_with($trimmed, '<!DOCTYPE')) {
                return ['ok' => false, 'message' => 'Ungültige PHP-Quelldatei von GitHub empfangen (' . $relFile . ').'];
            }
        }

        $destPath = $rootDir . '/' . ltrim($relFile, '/');
        $destDir  = dirname($destPath);
        if (!is_dir($destDir) && !@mkdir($destDir, 0755, true) && !is_dir($destDir)) {
            return ['ok' => false, 'message' => 'Zielverzeichnis konnte nicht angelegt werden: ' . $destDir];
        }

        $tmpPath = $destPath . '.tmp_' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmpPath, $content) === false) {
            return ['ok' => false, 'message' => 'Keine Schreibberechtigung im Cloud-Verzeichnis für ' . $relFile . '.'];
        }
        @chmod($tmpPath, 0644);
        if (!@rename($tmpPath, $destPath)) {
            @unlink($tmpPath);
            return ['ok' => false, 'message' => 'Datei konnte nicht aktiviert werden: ' . $relFile];
        }
        $installedFiles++;
    }

    // Modul in den Systemeinstellungen aktivieren
    if (!empty($plugin['setting_key'])) {
        saveAppSetting($pdo, $plugin['setting_key'], '1');
    }

    return [
        'ok'      => true,
        'message' => sprintf('Plugin „%s“ (%d %s) erfolgreich von GitHub installiert und aktiviert!', $plugin['name'], $installedFiles, $installedFiles === 1 ? 'Datei' : 'Dateien'),
    ];
}

/**
 * Entfernt die PHP-Hauptdatei eines Plugins vom Server und deaktiviert es im Menü.
 * WICHTIG: Sämtliche Datenbank-Tabellen und Nutzerdaten bleiben zu 100 % unangetastet!
 *
 * @return array{ok: bool, message: string}
 */
function uninstallMiniCloudPluginFile(PDO $pdo, string $pluginId): array
{
    $catalog = getMiniCloudPluginCatalog();
    if (!isset($catalog[$pluginId])) {
        return ['ok' => false, 'message' => 'Unbekanntes Plugin: ' . $pluginId];
    }

    $plugin  = $catalog[$pluginId];
    $rootDir = dirname(__DIR__);
    $entryPath = $rootDir . '/' . ltrim($plugin['entry_file'], '/');

    if (!empty($plugin['setting_key'])) {
        saveAppSetting($pdo, $plugin['setting_key'], '0');
    }

    if (is_file($entryPath)) {
        if (!@unlink($entryPath)) {
            return [
                'ok'      => false,
                'message' => sprintf('Plugin „%s“ wurde im Menü deaktiviert, aber die Datei %s konnte wegen fehlender Schreibrechte nicht gelöscht werden.', $plugin['name'], $plugin['entry_file']),
            ];
        }
    }

    return [
        'ok'      => true,
        'message' => sprintf('Plugin „%s“ (%s) wurde vom Server entfernt. Alle zugehörigen Datenbank-Einträge der Nutzer bleiben sicher erhalten.', $plugin['name'], $plugin['entry_file']),
    ];
}
