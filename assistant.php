<?php
declare(strict_types=1);

/**
 * MiniCloud – Lokaler KI- & Wissens-Assistent (assistant.php)
 * 100 % Strato-, Intranet- & DSGVO-tauglich (reines PHP + MySQL ohne externe API-Abhängigkeit).
 * Beantwortet sowohl alle Fragen zur Bedienung der MiniCloud als auch Live-Fragen
 * zum eigenen Konto (Speicherplatz, anstehende Termine, offene Aufgaben, Datei-/Kontakt-/Notizsuche)
 * und kann per Schnellbefehl direkt Aufgaben oder Haftnotizen anlegen.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$pdo         = getDB();
$appSettings = getAppSettings($pdo);

if (($appSettings['enable_assistant'] ?? '1') !== '1' && !isAdmin()) {
    setFlash('error', 'Der Cloud-Assistent ist derzeit durch den Administrator deaktiviert.');
    header('Location: index.php');
    exit;
}

$userId   = (int)$_SESSION['user_id'];
$username = (string)($_SESSION['username'] ?? 'Benutzer');
$isAdmin  = isAdmin();

/**
 * Normalisiert einen deutschen Suchtext für Token- und Ähnlichkeitsvergleiche.
 */
function normalizeAssistantText(string $text): string
{
    $lower = mb_strtolower(trim($text), 'UTF-8');
    $lower = strtr($lower, [
        'ä' => 'ae',
        'ö' => 'oe',
        'ü' => 'ue',
        'ß' => 'ss',
    ]);
    $lower = preg_replace('/[^a-z0-9\s]/u', ' ', $lower) ?? $lower;
    return trim(preg_replace('/\s+/u', ' ', $lower) ?? '');
}

/**
 * Liefert die strukturierte Wissensdatenbank zu allen Funktionen der MiniCloud.
 *
 * @return array<int, array{
 *   id: string,
 *   title: string,
 *   icon: string,
 *   keywords: array<int, string>,
 *   answer: string,
 *   actions: array<int, array{label: string, url: string}>,
 *   followups: array<int, string>
 * }>
 */
function getAssistantKnowledgeBase(array $appSettings, bool $isAdmin): array
{
    $appTitle = (string)($appSettings['app_title'] ?? 'MiniCloud');

    $kb = [
        [
            'id'       => 'upload_files',
            'title'    => 'Dateien & Ordner hochladen',
            'icon'     => '📤',
            'keywords' => ['hochladen', 'upload', 'datei hochladen', 'ordner hochladen', 'drag', 'drop', 'ziehen', 'maximale dateigroesse', 'limit', 'speichern'],
            'answer'   => "So lädst du **Dateien oder ganze Ordner** in die **{$appTitle}** hoch:\n\n"
                . "1. Öffne **📂 Dateien** (`index.php`).\n"
                . "2. Ziehe Dateien oder einen kompletten Ordner einfach per **Drag & Drop** in das obere Upload-Feld – oder klicke auf **„Dateien wählen“** bzw. **„Ordner wählen“**.\n"
                . "3. Der Upload startet **sofort automatisch** im Hintergrund (inkl. Fortschrittsanzeige). Bei Ordner-Uploads bleibt deine gesamte Unterordner-Struktur 1:1 erhalten.",
            'actions'  => [
                ['label' => '📂 Zu meinen Dateien', 'url' => 'index.php'],
            ],
            'followups' => [
                'Wie viel Speicherplatz habe ich noch frei?',
                'Wie teile ich eine Datei per Link?',
                'Wie verschlüssele ich eine Datei im Tresor?',
            ],
        ],
        [
            'id'       => 'share_files',
            'title'    => 'Dateien & Ordner teilen (Links & intern)',
            'icon'     => '🔗',
            'keywords' => ['teilen', 'freigeben', 'freigabe', 'link', 'oeffentlicher link', 'passwortschutz', 'ablaufdatum', 'benutzer teilen', 'gruppe teilen', 'berechtigung', 'schreibrecht'],
            'answer'   => "Du kannst **Dateien und ganze Ordner** auf zwei Arten teilen:\n\n"
                . "- **🌐 Öffentlicher Freigabelink (auch für Gäste ohne Konto):** Klicke bei einer Datei oder einem Ordner auf das **Teilen-Symbol (🔗)**. Optional kannst du ein **Passwort**, ein **Ablaufdatum** und ein **Download-Limit** festlegen oder einen **QR-Code** anzeigen.\n"
                . "- **👥 Intern mit Cloud-Benutzern oder Gruppen:** Wähle im Teilen-Dialog einfach per **Live-Suche** den gewünschten Benutzer oder eine Gruppe aus und lege fest, ob **👁️ Nur Lesen** oder **✏️ Lesen & Schreiben** erlaubt sein soll.\n\n"
                . "*Hinweis:* Damit dich andere Benutzer in der Teilen-Auswahl finden, muss in deinen **⚙️ Einstellungen** die Option *„Teilen-Sichtbarkeit“* aktiviert sein.",
            'actions'  => [
                ['label' => '📂 Dateien & Ordner teilen', 'url' => 'index.php'],
                ['label' => '⚙️ Teilen-Sichtbarkeit prüfen', 'url' => 'settings.php'],
            ],
            'followups' => [
                'Was sind Gruppenordner?',
                'Wie funktioniert Office Light im Team?',
                'Wie erstelle ich eine Umfrage?',
            ],
        ],
        [
            'id'       => 'group_folders',
            'title'    => 'Gruppenordner & Team-Laufwerke',
            'icon'     => '👥',
            'keywords' => ['gruppenordner', 'teamordner', 'gemeinsamer ordner', 'abteilung', 'verein', 'gruppe'],
            'answer'   => "**👥 Gruppenordner** sind gemeinsame Team-Bereiche (z. B. für Familie, Projekte, Vereine oder Abteilungen):\n\n"
                . "- Sie erscheinen direkt unterhalb deiner eigenen Dateien in **📂 Dateien** sowie im **WebDAV-Netzlaufwerk** unter `/Gruppenordner/`.\n"
                . "- Je nach Berechtigung (*Nur Lesen* oder *Lesen & Schreiben*) können alle berechtigten Mitglieder dort gemeinsam Dateien ablegen, Ordner anlegen und Dokumente in **📝 Office Light** bearbeiten.\n"
                . "- Neue Gruppenordner und deren Zugriffsrechte werden vom **Administrator** unter *⚙️ Admin → Gruppen & Gruppenordner* eingerichtet.",
            'actions'  => [
                ['label' => '📂 Zu den Gruppenordnern', 'url' => 'index.php'],
            ],
            'followups' => [
                'Wie binde ich die Cloud als Netzlaufwerk (WebDAV) ein?',
                'Wie teile ich einen eigenen Ordner?',
            ],
        ],
        [
            'id'       => 'vault_encryption',
            'title'    => 'Zero-Knowledge Datei-Tresor (AES-256-GCM)',
            'icon'     => '🔒',
            'keywords' => ['tresor', 'verschluesseln', 'verschluesselung', 'entschluesseln', 'aes', 'sicher', 'geheim', 'zero knowledge', 'datei schuetzen', 'vault'],
            'answer'   => "Mit dem **🔒 Zero-Knowledge Datei-Tresor** kannst du besonders vertrauliche Dateien direkt auf dem Server mit **AES-256-GCM** (und PBKDF2-Schlüsselableitung) verschlüsseln:\n\n"
                . "1. Klicke in der Dateiliste (`index.php`) bei der gewünschten Datei auf das **Schloss-Symbol (🔒)**.\n"
                . "2. Vergib ein eigenes **Tresor-Passwort**.\n"
                . "3. **Wichtig:** Dieses Passwort wird **nirgendwo** in der Datenbank gespeichert (echtes Zero-Knowledge). Ohne das Passwort kann selbst der Serverbetreiber die Datei nicht lesen.",
            'actions'  => [
                ['label' => '📂 Dateien verwalten', 'url' => 'index.php'],
                ['label' => '🔐 Zum Passwort-Manager', 'url' => 'passwords.php'],
            ],
            'followups' => [
                'Wo speichere ich meine Passwörter und Logins?',
                'Wie aktiviere ich die Zwei-Faktor-Authentifizierung (2FA)?',
            ],
        ],
        [
            'id'       => 'office_light',
            'title'    => 'Office Light (Word, Excel, Markdown & Live-Co-Editing)',
            'icon'     => '📝',
            'keywords' => ['office', 'word', 'excel', 'tabelle', 'kalkulation', 'docx', 'xlsx', 'csv', 'markdown', 'dokument erstellen', 'vorlage', 'brief', 'protokoll', 'formel', 'gleichzeitig bearbeiten', 'co editing'],
            'answer'   => "**📝 Office Light** (`office.php`) ist deine integrierte Büro-Zentrale direkt im Browser – komplett ohne Zusatzsoftware:\n\n"
                . "- **📘 Word-Dokumente (`.docx`):** Formatierter Text, Überschriften, Tabellen, Listen, Farben & Druck-/PDF-Ansicht.\n"
                . "- **📗 Excel-Tabellen (`.xlsx` & `.csv`):** Mehrere Tabellenblätter, Formeln wie `=SUM(A1:A10)`, `=AVERAGE(...)`, `=MIN(...)`, `=MAX(...)`, Spalten-Sortierung und Diagramm-Vorschau.\n"
                . "- **✨ 6 Schnellstart-Vorlagen:** Leeres Word-Dokument, Geschäftsbrief, Besprechungsprotokoll, Leere Tabelle, Budget-/Kassenplan und Stundenzettel.\n"
                . "- **🟢 Live-Team-Zusammenarbeit:** Wenn mehrere Personen dasselbe Dokument öffnen, seht ihr oben live, wer gerade mitschreibt, und Änderungen werden automatisch synchronisiert!",
            'actions'  => [
                ['label' => '📝 Office Light Zentrale öffnen', 'url' => 'office.php'],
            ],
            'followups' => [
                'Wie funktioniert der E-Book-Reader (.epub)?',
                'Wie kann ich ein PDF bearbeiten oder ausfüllen?',
            ],
        ],
        [
            'id'       => 'ebook_reader_epub',
            'title'    => 'E-Book-Reader & Bibliothek (.epub)',
            'icon'     => '📚',
            'keywords' => ['ebook', 'e book', 'ebooks', 'epub', 'buch', 'buecher', 'lesen', 'lesezeichen', 'buecherregal', 'kapitel', 'sepia'],
            'answer'   => "Mit dem **📚 E-Book-Reader** (`ebooks.php`) liest du alle **`.epub`-Bücher** deiner Cloud bequem im Browser:\n\n"
                . "- **📚 Automatisches Bücherregal:** Findet alle `.epub`-Dateien aus deinen Ordnern, Gruppenordnern und Freigaben und zeigt automatisch **Buchcover, Titel, Autor und Klappentext** an.\n"
                . "- **🔖 Synchronisierter Lesefortschritt:** Die Cloud merkt sich automatisch dein aktuelles Kapitel und deine Scroll-Position (`% gelesen`), sodass du am Smartphone nahtlos dort weiterlesen kannst, wo du am PC aufgehört hast!\n"
                . "- **🎨 Augenkomfort & Suche:** Wähle zwischen *☀️ Papier*, *📜 Sepia*, *🌆 Schiefer* und *🌙 Nachtmodus*, passe Schriftgröße & Schriftart an, setze **Lesezeichen mit Notiz** und durchsuche das gesamte Buch im Volltext.",
            'actions'  => [
                ['label' => '📚 E-Book-Bibliothek öffnen', 'url' => 'ebooks.php'],
            ],
            'followups' => [
                'Wie funktioniert Office Light?',
                'Wie viel Speicherplatz habe ich noch frei?',
            ],
        ],
        [
            'id'       => 'pdf_tools_scan',
            'title'    => 'PDF-Werkzeugkasten & Kamera-Dokumentenscanner',
            'icon'     => '📄',
            'keywords' => ['pdf', 'scanner', 'scannen', 'kamera', 'zusammenfuegen', 'merge', 'teilen', 'split', 'komprimieren', 'verkleinern', 'seiten extrahieren', 'beleg scannen'],
            'answer'   => "Im Modul **📄 PDF & Scan** (`pdf_tools.php`) stehen dir **6 leistungsstarke PDF-Werkzeuge** zur Verfügung:\n\n"
                . "1. **📸 Kamera- & Foto-Scanner:** Fotografiere Briefe, Rechnungen oder Kassenbons direkt mit dem Smartphone ab – inkl. automatischem **S/W-Dokumentenfilter** für gestochen scharfe PDFs.\n"
                . "2. **🔗 PDFs zusammenfügen (Merge):** Kombiniere mehrere PDF-Dateien aus deiner Cloud zu einem Gesamtdokument.\n"
                . "3. **✂️ Seiten extrahieren (Split):** Löse einzelne Seiten oder Bereiche (z. B. `1-3, 5`) als neues PDF heraus.\n"
                . "4. **🗜️ PDF komprimieren:** Verkleinert große PDF-Dateien für den E-Mail-Versand.\n"
                . "5. **✏️ PDF bearbeiten, ausfüllen & schwärzen**\n"
                . "6. **🔎 Lokale OCR-Texterkennung**",
            'actions'  => [
                ['label' => '📄 PDF-Werkzeuge öffnen', 'url' => 'pdf_tools.php'],
                ['label' => '✏️ Direkt zum PDF-Editor', 'url' => 'pdf_tools.php#tab-redact'],
                ['label' => '🔎 Direkt zur OCR-Texterkennung', 'url' => 'pdf_tools.php#tab-ocr'],
            ],
            'followups' => [
                'Wie fülle ich ein PDF aus oder unterschreibe es?',
                'Wie funktioniert die lokale OCR-Texterkennung?',
            ],
        ],
        [
            'id'       => 'pdf_edit_redact',
            'title'    => 'PDF im Browser ausfüllen, unterschreiben & schwärzen',
            'icon'     => '✏️',
            'keywords' => ['pdf bearbeiten', 'pdf ausfuellen', 'formular ausfuellen', 'unterschreiben', 'unterschrift', 'signatur', 'schwaerzen', 'zensieren', 'unkenntlich', 'haekchen', 'kreuz', 'text in pdf'],
            'answer'   => "Unter **📄 PDF & Scan → ✏️ 5. PDF bearbeiten, ausfüllen & schwärzen** (`pdf_tools.php#tab-redact`) kannst du jedes PDF direkt im Browser bearbeiten:\n\n"
                . "- **🔤 Text einfügen:** Klicke an eine beliebige Stelle im Formular, um Text (in Wunschgröße und -farbe) einzutragen und frei zu verschieben.\n"
                . "- **✓ / ✕ Stempel:** Setze per Klick Häkchen oder Kreuze in Ankreuzfelder.\n"
                . "- **✍️ Unterschrift zeichnen:** Unterschreibe mit Maus, Finger oder Stift im Signatur-Pad und platziere deine Unterschrift frei skalierbar auf dem Dokument.\n"
                . "- **⬛ DSGVO-sicheres Schwärzen:** Ziehe schwarze Balken über vertrauliche Stellen (z. B. IBAN, Namen) oder übermale sie mit dem **⬜ Weiß-Radierer**.\n"
                . "- Beim Speichern wird jede Seite fest gerastert, sodass geschwärzter Originaltext **physikalisch unwiderruflich entfernt** ist!",
            'actions'  => [
                ['label' => '✏️ PDF-Editor öffnen', 'url' => 'pdf_tools.php#tab-redact'],
            ],
            'followups' => [
                'Wie lese ich Text aus einem gescannten Bild oder PDF aus (OCR)?',
                'Wie füge ich zwei PDFs zusammen?',
            ],
        ],
        [
            'id'       => 'ocr_text_recognition',
            'title'    => 'Lokale OCR-Texterkennung (12 Sprachen + Fraktur)',
            'icon'     => '🔎',
            'keywords' => ['ocr', 'texterkennung', 'text aus bild', 'text aus pdf', 'scan in text', 'tesseract', 'fraktur', 'altdeutsch', 'sprachen', 'foto in text'],
            'answer'   => "Mit der **🔎 OCR-Texterkennung** (`pdf_tools.php#tab-ocr`) wandelst du abfotografierte Briefe, Rechnungen, Buchseiten oder gescannte PDFs in **bearbeitbaren Text** um:\n\n"
                . "- **100 % lokal & Strato-/Intranet-fähig:** Die komplette OCR-Engine (`Tesseract WebAssembly`) und alle Sprachmodelle liegen lokal auf deinem Cloud-Server – es werden **keinerlei Daten ins Internet** übertragen.\n"
                . "- **12 europäische Sprachen + Fraktur:** Unterstützt Deutsch (`deu`), Englisch (`eng`), Französisch (`fra`), Spanisch (`spa`), Italienisch (`ita`), Niederländisch (`nld`), Polnisch (`pol`), Tschechisch (`ces`), Portugiesisch (`por`), Türkisch (`tur`), Ukrainisch (`ukr`) sowie **📜 Deutsche Fraktur / Altdeutsch (`frk`)**.\n"
                . "- Den erkannten Text kannst du sofort bearbeiten, kopieren oder als **`.txt`**, **📝 Office-Light-Dokument (`.html`)** oder **📌 Haftnotiz** in der Cloud speichern.",
            'actions'  => [
                ['label' => '🔎 OCR-Texterkennung starten', 'url' => 'pdf_tools.php#tab-ocr'],
            ],
            'followups' => [
                'Wie bearbeite ich ein Word- oder Excel-Dokument?',
                'Wie schwärze ich vertrauliche Stellen in einem PDF?',
            ],
        ],
        [
            'id'       => 'webdav_caldav_carddav',
            'title'    => 'WebDAV, CalDAV & CardDAV (Netzlaufwerk & Smartphone-Sync)',
            'icon'     => '📡',
            'keywords' => ['webdav', 'caldav', 'carddav', 'netzlaufwerk', 'synchronisieren', 'sync', 'windows explorer', 'mac finder', 'iphone', 'ios', 'android', 'davx5', 'thunderbird', 'app passwort'],
            'answer'   => "Deine Cloud unterstützt alle drei offenen Synchronisations-Standards – die genauen Verbindungs-URLs findest du in deinen **⚙️ Einstellungen**:\n\n"
                . "1. **📡 WebDAV (`webdav.php`):** Binde deine Cloud-Dateien als echtes **Netzlaufwerk** im Windows Explorer, macOS Finder oder unter Linux ein.\n"
                . "2. **📅 CalDAV (`caldav.php`):** Synchronisiere deinen Cloud-Kalender live mit Apple Kalender (iPhone/Mac), Thunderbird oder Android (DAVx⁵).\n"
                . "3. **📇 CardDAV (`carddav.php`):** Synchronisiere dein Cloud-Adressbuch direkt mit den Kontakten auf deinem Smartphone oder PC.\n\n"
                . "💡 **Tipp:** Erstelle dir unter **⚙️ Einstellungen → App-Passwörter** ein eigenes Geräte-Passwort für WebDAV/CalDAV/CardDAV – das funktioniert auch bei aktivierter 2FA!",
            'actions'  => [
                ['label' => '⚙️ Verbindungs-URLs & App-Passwörter', 'url' => 'settings.php'],
            ],
            'followups' => [
                'Wie erstelle ich einen Termin im Kalender?',
                'Wie importiere ich Kontakte (.vcf)?',
            ],
        ],
        [
            'id'       => 'calendar_module',
            'title'    => 'Termin-Kalender & Kalender-Freigaben',
            'icon'     => '📅',
            'keywords' => ['kalender', 'termin', 'termine', 'geburtstag', 'wiederholung', 'serie', 'ics', 'ical', 'kalender teilen'],
            'answer'   => "Im **📅 Kalender** (`calendar.php`) verwaltest du persönliche Termine, Gruppentermine und Erinnerungen:\n\n"
                . "- Unterstützt **Monats-, Wochen- und Listenansicht**, ganztägige Termine, Serien-Wiederholungen (täglich, wöchentlich, monatlich, jährlich) und **Cloud-Dateianhänge** direkt am Termin.\n"
                . "- **Geburtstage** aus deinen **📇 Kontakten** werden automatisch mit🎂-Symbol im Kalender eingeblendet.\n"
                . "- Du kannst deinen Kalender für andere Benutzer oder Gruppen freigeben (private Termine mit `🔒 Privat` bleiben dabei geschützt) sowie `.ics`-Dateien importieren und exportieren.",
            'actions'  => [
                ['label' => '📅 Kalender öffnen', 'url' => 'calendar.php'],
            ],
            'followups' => [
                'Welche Termine habe ich diese Woche?',
                'Wie synchronisiere ich den Kalender mit dem Smartphone (CalDAV)?',
            ],
        ],
        [
            'id'       => 'tasks_kanban',
            'title'    => 'Aufgaben & Kanban-Board',
            'icon'     => '📋',
            'keywords' => ['aufgaben', 'todo', 'to do', 'kanban', 'board', 'erledigen', 'checkliste', 'prioritaet', 'faelligkeit'],
            'answer'   => "Das **📋 Aufgaben- & Kanban-Board** (`tasks.php`) organisiert deine To-Dos übersichtlich in 3 Spalten (**Offen**, **In Arbeit**, **Erledigt**):\n\n"
                . "- Verschiebe Karten einfach per **Drag & Drop** zwischen den Spalten.\n"
                . "- Jede Aufgabe unterstützt **Prioritäten** (Niedrig bis Kritisch), **Fälligkeitsdaten**, **Unteraufgaben-Checklisten**, **Cloud-Dateianhänge** und die Zuweisung an Benutzer oder Gruppen.\n"
                . "- 💡 **Schnellbefehl hier im Chat:** Schreibe mir einfach `Aufgabe: Steuerunterlagen sortieren` und ich lege die Aufgabe direkt für dich an!",
            'actions'  => [
                ['label' => '📋 Aufgaben-Board öffnen', 'url' => 'tasks.php'],
            ],
            'followups' => [
                'Welche Aufgaben sind bei mir noch offen?',
                'Wie funktioniert das Projektmanagement mit Gantt-Diagramm?',
            ],
        ],
        [
            'id'       => 'projects_module',
            'title'    => 'Projektmanagement, Meilensteine & Gantt-Zeitstrahl',
            'icon'     => '🚀',
            'keywords' => ['projekt', 'projekte', 'projektmanagement', 'gantt', 'zeitstrahl', 'meilenstein', 'zeiterfassung', 'budget', 'projektbericht'],
            'answer'   => "Unter **🚀 Projekte** (`projects.php`) steuerst du größere Vorhaben im Team:\n\n"
                . "- **Visueller Gantt-Zeitstrahl** mit Start-/Enddaten und Fortschrittsbalken (`0–100 %`).\n"
                . "- **Meilensteine (🎯)**, **Budget- & Ausgabenkontrolle** sowie integrierte **⏱️ Zeiterfassung (Stechuhr & Stundeneinträge)**.\n"
                . "- Direkt verknüpft mit Aufgaben aus dem Kanban-Board und einem druckfertigen **Projektstatus-Bericht**.",
            'actions'  => [
                ['label' => '🚀 Projekt-Zentrale öffnen', 'url' => 'projects.php'],
            ],
            'followups' => [
                'Welche Aufgaben sind bei mir noch offen?',
                'Wie erstelle ich ein Besprechungsprotokoll in Office Light?',
            ],
        ],
        [
            'id'       => 'notes_pinboard',
            'title'    => 'Notizwand & Pinboard (Haftnotizen)',
            'icon'     => '📌',
            'keywords' => ['notiz', 'notizen', 'haftnotiz', 'pinboard', 'pinnwand', 'tag', 'hashtag', 'anpinnen', 'markdown export'],
            'answer'   => "Auf der **📌 Notizwand** (`notes.php`) hältst du Ideen, Checklisten und Infos auf farbigen Haftnotizen fest:\n\n"
                . "- Nutze **`#Tags`** zur schnellen Filterung, pinne wichtige Notizen oben an (erscheinen dann auch in **🏠 Mein Tag**) und teile Notizen mit Benutzern oder Gruppen.\n"
                . "- Mit einem Klick exportierst du jede Notiz als `.md`-Datei in deine Cloud.\n"
                . "- 💡 **Schnellbefehl hier im Chat:** Schreibe mir einfach `Notiz: WLAN-Gastpasswort lautet Sonnenschein` und ich erstelle die Haftnotiz sofort für dich!",
            'actions'  => [
                ['label' => '📌 Notizwand öffnen', 'url' => 'notes.php'],
            ],
            'followups' => [
                'Zeige meine letzten Notizen',
                'Wie funktioniert das Tages-Cockpit (Mein Tag)?',
            ],
        ],
        [
            'id'       => 'passwords_manager',
            'title'    => 'Passwort-Manager & Schlüsselbund',
            'icon'     => '🔐',
            'keywords' => ['passwort manager', 'schluesselbund', 'zugangsdaten', 'logins speichern', 'passwort generator', 'wlan passwort', 'lizenzschluessel', 'pin speichern'],
            'answer'   => "Der **🔐 Passwort-Manager** (`passwords.php`) speichert deine Zugangsdaten, WLAN-Passwörter, Server-Logins, Lizenzen und PINs **AES-256-GCM-verschlüsselt** in der Datenbank:\n\n"
                . "- Enthält einen **Passwort-Generator** mit Sicherheitsbewertung, Ein-Klick-Kopieren für Benutzername & Passwort, Kategorien und Favoriten.\n"
                . "- Einzelne Einträge (z. B. ein gemeinsamer Vereins- oder Team-Zugang) lassen sich gezielt mit anderen Cloud-Benutzern oder Gruppen teilen.",
            'actions'  => [
                ['label' => '🔐 Schlüsselbund öffnen', 'url' => 'passwords.php'],
            ],
            'followups' => [
                'Wie verschlüssele ich eine Datei im Tresor?',
                'Wie aktiviere ich 2FA beim Login?',
            ],
        ],
        [
            'id'       => 'polls_menti',
            'title'    => 'Umfragen (Doodle-Alternative) & Live-Mentimeter',
            'icon'     => '📊',
            'keywords' => ['umfrage', 'abstimmung', 'terminfinder', 'doodle', 'menti', 'mentimeter', 'wortwolke', 'quiz', 'publikum', 'voting', 'qr code'],
            'answer'   => "Für Abstimmungen bietet die Cloud gleich zwei spezialisierte Module:\n\n"
                . "1. **📊 Terminfinder & Umfragen (`polls.php`):** Ideal zum Finden eines gemeinsamen Termins (*Ja / Vielleicht / Nein*) oder für Entscheidungen – intern für Gruppen oder per **öffentlichem Gast-Link** ohne Login. Den Sieger-Termin übernimmst du per 1-Klick in den Kalender!\n"
                . "2. **🎤 Live-Mentimeter (`menti.php`):** Interaktive Live-Präsentationen für Meetings, Unterricht oder Events mit **Wortwolken, Live-Balken, Bewertungs-Skalen, Q&A-Fragerunde und Quiz** – Teilnehmer scannen einfach den QR-Code oder geben den 6-stelligen PIN ein.",
            'actions'  => [
                ['label' => '📊 Umfragen & Terminfinder', 'url' => 'polls.php'],
                ['label' => '🎤 Live-Mentimeter öffnen', 'url' => 'menti.php'],
            ],
            'followups' => [
                'Gibt es aktuell offene Umfragen?',
                'Wie versende ich eine Nachricht im Chat?',
            ],
        ],
        [
            'id'       => 'chat_messages',
            'title'    => 'Messenger, Chat & Rundnachrichten',
            'icon'     => '💬',
            'keywords' => ['chat', 'nachricht', 'nachrichten', 'messenger', 'schreiben', 'rundnachricht', 'broadcast'],
            'answer'   => "Über **💬 Nachrichten** (`messages.php`) tauschst du dich direkt mit anderen Cloud-Benutzern aus:\n\n"
                . "- Unterstützt **Direktnachrichten**, **Cloud-Dateianhänge** direkt im Chat sowie **📢 Admin-Rundnachrichten** an alle Benutzer.\n"
                . "- Über das **Schnell-Suchfeld** findest du auch bei vielen Benutzern sofort den gewünschten Empfänger.\n"
                . "- In den **⚙️ Einstellungen** kannst du festlegen, ob du bei neuen Nachrichten per Ton oder Browser-Meldung informiert werden möchtest.",
            'actions'  => [
                ['label' => '💬 Messenger öffnen', 'url' => 'messages.php'],
            ],
            'followups' => [
                'Habe ich ungelesene Nachrichten?',
                'Wie ändere ich meine Benachrichtigungs-Einstellungen?',
            ],
        ],
        [
            'id'       => 'trash_versions',
            'title'    => 'Papierkorb & Datei-Versionierung',
            'icon'     => '🗑️',
            'keywords' => ['papierkorb', 'geloescht', 'wiederherstellen', 'aus versehen geloescht', 'version', 'versionen', 'aeltere version', 'historie'],
            'answer'   => "Keine Sorge bei versehentlich gelöschten oder überschriebenen Dateien:\n\n"
                . "- **🗑️ Papierkorb (`trash.php`):** Gelöschte Dateien landen zuerst für **30 Tage** im Papierkorb und können dort jederzeit mit einem Klick an ihren ursprünglichen Ort wiederhergestellt werden.\n"
                . "- **🕒 Datei-Versionierung:** Wenn du eine gleichnamige Datei erneut hochlädst oder in *Office Light* überschreibst, hebt die Cloud ältere Stände automatisch in der Versionshistorie auf.",
            'actions'  => [
                ['label' => '🗑️ Papierkorb öffnen', 'url' => 'trash.php'],
                ['label' => '📂 Zu meinen Dateien', 'url' => 'index.php'],
            ],
            'followups' => [
                'Wie viel Speicherplatz habe ich noch frei?',
                'Wie lade ich Dateien hoch?',
            ],
        ],
        [
            'id'       => 'settings_2fa_themes',
            'title'    => 'Einstellungen, 2FA-Sicherheit, Themes & Sprachen',
            'icon'     => '⚙️',
            'keywords' => ['einstellungen', 'passwort aendern', 'zwei faktor', '2fa', 'sicherheitscode', 'theme', 'dark mode', 'dunkel', 'design', 'sprache', 'timeout', 'abmelden', 'anheften', 'stern'],
            'answer'   => "In deinen persönlichen **⚙️ Einstellungen** (`settings.php`) passt du die Cloud genau an deine Wünsche an:\n\n"
                . "- **🌐 11 europäische Sprachen** und **13 augenschonende Design-Themes** (Sanft Hell, Halbdunkel wie *Dämmerung/Mokka/Petrol* und Dunkel).\n"
                . "- **🔒 Zwei-Faktor-Authentifizierung (2FA per E-Mail):** Schützt dein Konto beim Login zusätzlich mit einem 6-stelligen Sicherheitscode.\n"
                . "- **⏱️ Automatisches Sitzungs-Timeout:** Wähle zwischen 5 Minuten und 8 Stunden (oder dauerhaft angemeldet).\n"
                . "- **⭐ Module oben anheften:** Öffne oben in der Navigation das Menü **„⊞ Module“** und klicke auf den Stern (`☆`/`⭐`), um deine Lieblingsmodule direkt in die obere Leiste zu holen!",
            'actions'  => [
                ['label' => '⚙️ Einstellungen öffnen', 'url' => 'settings.php'],
            ],
            'followups' => [
                'Wie installiere ich die Cloud als App auf dem Smartphone?',
                'Wie richte ich WebDAV oder CalDAV ein?',
            ],
        ],
        [
            'id'       => 'pwa_app_install',
            'title'    => 'Als App auf Smartphone, Tablet oder PC installieren (PWA)',
            'icon'     => '📱',
            'keywords' => ['app', 'installieren', 'smartphone', 'handy', 'iphone', 'ipad', 'android', 'startbildschirm', 'homescreen', 'pwa'],
            'answer'   => "Du kannst die **{$appTitle}** ohne App-Store wie eine echte App mit eigenem Symbol auf deinem Startbildschirm installieren:\n\n"
                . "- **📱 Android (Chrome) & Desktop (Chrome/Edge):** Klicke unten in der Fußzeile auf **„📲 App installieren“**.\n"
                . "- **🍏 iPhone & iPad (Safari):** Tippe unten in Safari auf das **Teilen-Symbol (⬆️)** und wähle **„Zum Home-Bildschirm“**.\n"
                . "- Die Cloud startet danach im Vollbildmodus ohne störende Browser-Adressleiste!",
            'actions'  => [
                ['label' => '🏠 Zum Tages-Cockpit', 'url' => 'dashboard.php'],
            ],
            'followups' => [
                'Wie synchronisiere ich Kalender & Kontakte mit dem Handy?',
                'Welche Design-Themes gibt es?',
            ],
        ],
    ];

    if ($isAdmin) {
        $kb[] = [
            'id'       => 'admin_features',
            'title'    => 'Administration, Modul-Statistik, Backup & GitHub-Update',
            'icon'     => '🛠️',
            'keywords' => ['admin', 'administration', 'benutzer anlegen', 'kontingent', 'quota', 'smtp', 'mail', 'impressum', 'logo', 'backup', 'datenbank', 'update', 'github', 'modul statistik', 'audit log'],
            'answer'   => "Als **Administrator** stehen dir unter **🛠️ Admin** (`admin.php`) 5 Verwaltungsbereiche zur Verfügung:\n\n"
                . "1. **👥 Benutzer & Gruppen:** Konten anlegen, Speicherkontingente (Quota) vergeben, Gruppen & Gruppenordner-Rechte verwalten (inkl. Live-Suchfilter).\n"
                . "2. **🧩 Module & Statistik:** Alle 14 Zusatzmodule einzeln ein-/ausschalten sowie die **📊 Modul-Nutzungsstatistik** (Heute / 7 Tage / 30 Tage / Gesamt + Datenbestand) einsehen.\n"
                . "3. **🎨 Darstellung & Impressum:** Cloud-Name, eigenes Logo und Impressum anpassen.\n"
                . "4. **✉️ E-Mail & SMTP:** Versand für 2FA-Codes, Einladungen und Passwort-Reset konfigurieren.\n"
                . "5. **🖥️ System, Backup & GitHub-Updater:** Server-Auslastung, Sicherheits-Audit-Log, 1-Klick-SQL-Backup und direktes Online-Update von GitHub.",
            'actions'  => [
                ['label' => '🛠️ Administration öffnen', 'url' => 'admin.php'],
                ['label' => '📊 Zur Modul-Statistik', 'url' => 'admin.php#tab-modules'],
            ],
            'followups' => [
                'Wie viel Speicherplatz habe ich noch frei?',
                'Welche Module werden am häufigsten genutzt?',
            ],
        ];
    }

    return $kb;
}

/**
 * Beantwortet eine Frage des Benutzers durch Kombination aus:
 * 1. Schnellbefehlen (Notiz / Aufgabe direkt anlegen)
 * 2. Persönlichen Live-Datenbankabfragen (Speicherplatz, Termine, Aufgaben, Nachrichten, Datei-/Kontakt-/Notizsuche)
 * 3. Intelligenter Wissensdatenbank (Token-, Synonym- & Ähnlichkeits-Matching)
 *
 * @return array{
 *   reply: string,
 *   actions: array<int, array{label: string, url: string}>,
 *   followups: array<int, string>
 * }
 */
function generateAssistantResponse(PDO $pdo, int $userId, string $username, bool $isAdmin, array $appSettings, string $rawQuestion): array
{
    $question = trim($rawQuestion);
    if ($question === '') {
        return [
            'reply'     => "Hallo **{$username}**! 👋 Wie kann ich dir heute in deiner Cloud helfen?",
            'actions'   => [],
            'followups' => [
                'Wie viel Speicherplatz habe ich noch frei?',
                'Was steht heute oder diese Woche bei mir an?',
                'Wie funktioniert die OCR-Texterkennung?',
            ],
        ];
    }

    $norm = normalizeAssistantText($question);

    // =========================================================================
    // 1. SCHNELLBEFEHL: NOTIZ ODER AUFGABE DIREKT ANLEGEN
    // =========================================================================
    if (preg_match('/^(?:neue\s+notiz|notiz\s+erstellen|notiz\s+anlegen|notiz)\s*:\s*(.+)$/isu', $question, $mNote)) {
        $noteBody = trim($mNote[1]);
        if ($noteBody !== '') {
            try {
                $firstLine = trim( strtok($noteBody, "\n") ?: 'Schnellnotiz' );
                $title = mb_substr($firstLine, 0, 80);
                $stmtIns = $pdo->prepare(
                    "INSERT INTO `notes` (`user_id`, `title`, `content`, `color`, `is_pinned`)
                     VALUES (:uid, :title, :content, 'yellow', 0)"
                );
                $stmtIns->execute([
                    ':uid'     => $userId,
                    ':title'   => $title,
                    ':content' => $noteBody,
                ]);
                return [
                    'reply'     => "✅ Ich habe deine neue Haftnotiz **„" . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . "“** sofort auf deiner 📌 Notizwand gespeichert!",
                    'actions'   => [
                        ['label' => '📌 Notizwand öffnen', 'url' => 'notes.php'],
                    ],
                    'followups' => [
                        'Zeige meine letzten Notizen',
                        'Welche Aufgaben sind noch offen?',
                    ],
                ];
            } catch (Throwable) {
                // Fallback falls Tabelle noch nicht initialisiert
            }
        }
    }

    if (preg_match('/^(?:neue\s+aufgabe|aufgabe\s+erstellen|aufgabe\s+anlegen|aufgabe|todo|to-do)\s*:\s*(.+)$/isu', $question, $mTask)) {
        $taskTitle = trim($mTask[1]);
        if ($taskTitle !== '') {
            try {
                $cleanTitle = mb_substr($taskTitle, 0, 180);
                $stmtIns = $pdo->prepare(
                    "INSERT INTO `tasks` (`user_id`, `title`, `description`, `status`, `priority`)
                     VALUES (:uid, :title, '', 'todo', 'medium')"
                );
                $stmtIns->execute([
                    ':uid'   => $userId,
                    ':title' => $cleanTitle,
                ]);
                return [
                    'reply'     => "✅ Ich habe die Aufgabe **„" . htmlspecialchars($cleanTitle, ENT_QUOTES, 'UTF-8') . "“** in deinem 📋 Aufgaben-Board (Spalte *Offen*) angelegt!",
                    'actions'   => [
                        ['label' => '📋 Aufgaben-Board öffnen', 'url' => 'tasks.php'],
                    ],
                    'followups' => [
                        'Welche Aufgaben sind bei mir noch offen?',
                        'Was steht diese Woche in meinem Kalender?',
                    ],
                ];
            } catch (Throwable) {
                // Fallback
            }
        }
    }

    // =========================================================================
    // 2. BEGRÜSSUNG / WER BIST DU / WAS KANNST DU?
    // =========================================================================
    if (preg_match('/^(hallo|hi|hey|moin|servus|guten\s+morgen|guten\s+tag|guten\s+abend|gruess\s+dich|wer\s+bist\s+du|was\s+kannst\s+du|hilfe)$/u', $norm)) {
        return [
            'reply' => "Hallo **{$username}**! 🤖 Ich bin dein **lokaler Cloud-Assistent** (100 % offline-, Intranet- & DSGVO-konform direkt auf diesem Server).\n\n"
                . "Ich kann dir auf **drei Arten** helfen:\n"
                . "1. **📊 Live-Fragen zu deinem Konto:** Frag mich z. B. nach deinem freien *Speicherplatz*, deinen *Terminen*, offenen *Aufgaben*, ungelesenen *Nachrichten* oder suche nach *Dateien, Kontakten & Notizen*.\n"
                . "2. **💡 Erklärungen zu allen Funktionen:** Frag mich z. B. zu *Office Light*, *PDF schwärzen & ausfüllen*, *lokaler OCR-Texterkennung*, *WebDAV/CalDAV*, *Datei-Tresor* oder *Teilen*.\n"
                . "3. **⚡ Schnellbefehle:** Schreib einfach `Notiz: ...` oder `Aufgabe: ...`, um sofort einen Eintrag anzulegen!",
            'actions' => [
                ['label' => '🏠 Mein Tag öffnen', 'url' => 'dashboard.php'],
                ['label' => '📂 Meine Dateien', 'url' => 'index.php'],
                ['label' => '📝 Office Light', 'url' => 'office.php'],
            ],
            'followups' => [
                'Wie viel Speicherplatz habe ich noch frei?',
                'Was steht heute oder diese Woche bei mir an?',
                'Wie funktioniert die OCR-Texterkennung?',
            ],
        ];
    }

    if (preg_match('/^(danke|vielen\s+dank|super|klasse|perfekt|top|alles\s+klar)$/u', $norm)) {
        return [
            'reply'     => "Sehr gerne, **{$username}**! 😊 Wenn du noch eine Frage zur Cloud hast oder eine Datei, einen Termin oder eine Funktion suchst, frag einfach jederzeit.",
            'actions'   => [],
            'followups' => [
                'Welche Aufgaben sind bei mir noch offen?',
                'Wie teile ich eine Datei mit Passwort?',
            ],
        ];
    }

    // =========================================================================
    // 3. LIVE-ABFRAGE: SPEICHERPLATZ & KONTINGENT
    // =========================================================================
    if (
        (str_contains($norm, 'speicher') || str_contains($norm, 'speicherplatz') || str_contains($norm, 'kontingent') || str_contains($norm, 'quota') || str_contains($norm, 'wie voll') || str_contains($norm, 'wie viel platz'))
        && !str_contains($norm, 'passwort')
    ) {
        $stats = getUserStorageStats($pdo, $userId);
        $usedStr  = formatBytes($stats['used']);
        $quotaStr = formatBytes($stats['quota']);
        $freeStr  = formatBytes($stats['free']);
        $pctStr   = number_format($stats['percent'], 1, ',', '.');

        return [
            'reply' => "📊 **Dein aktueller Cloud-Speicherstand ({$username}):**\n\n"
                . "- **Belegt:** **{$usedStr}** von **{$quotaStr}** (**{$pctStr} %**)\n"
                . "- **Noch frei verfügbar:** **{$freeStr}**\n"
                . "- **Aktive Dateien:** **{$stats['file_count']}** Dateien\n"
                . "- **Im Papierkorb:** **{$stats['trash_count']}** Dateien",
            'actions' => [
                ['label' => '📂 Zu meinen Dateien', 'url' => 'index.php'],
                ['label' => '🗑️ Papierkorb prüfen (' . $stats['trash_count'] . ')', 'url' => 'trash.php'],
            ],
            'followups' => [
                'Wie lade ich Dateien oder Ordner hoch?',
                'Was steht diese Woche in meinem Kalender?',
            ],
        ];
    }

    // =========================================================================
    // 4. LIVE-ABFRAGE: MEINE TERMINE / WAS STEHT AN?
    // =========================================================================
    if (
        str_contains($norm, 'welche termine')
        || str_contains($norm, 'meine termine')
        || str_contains($norm, 'naechster termin')
        || str_contains($norm, 'naechsten termine')
        || str_contains($norm, 'was steht heute')
        || str_contains($norm, 'was steht morgen')
        || str_contains($norm, 'was steht diese woche')
        || str_contains($norm, 'was steht bei mir an')
        || str_contains($norm, 'termine heute')
        || str_contains($norm, 'termine diese woche')
    ) {
        try {
            $stmtEv = $pdo->prepare(
                "SELECT `title`, `start_at`, `end_at`, `all_day`, `location`
                 FROM `calendar_events`
                 WHERE (`user_id` = :uid1 OR `shared_user_id` = :uid2)
                   AND `end_at` >= CURDATE()
                 ORDER BY `start_at` ASC
                 LIMIT 8"
            );
            $stmtEv->execute([':uid1' => $userId, ':uid2' => $userId]);
            $events = $stmtEv->fetchAll();

            if (empty($events)) {
                $reply = "📅 Du hast aktuell **keine anstehenden Termine** im Kalender eingetragen.";
            } else {
                $lines = ["📅 **Deine nächsten anstehenden Termine:**\n"];
                foreach ($events as $ev) {
                    $ts = strtotime((string)$ev['start_at']) ?: time();
                    $dateStr = date('d.m.Y', $ts);
                    $timeStr = ((int)$ev['all_day'] === 1) ? 'Ganztägig' : (date('H:i', $ts) . ' Uhr');
                    $locStr  = trim((string)($ev['location'] ?? '')) !== '' ? ' · 📍 ' . htmlspecialchars((string)$ev['location'], ENT_QUOTES, 'UTF-8') : '';
                    $lines[] = "- **{$dateStr} ({$timeStr}):** " . htmlspecialchars((string)$ev['title'], ENT_QUOTES, 'UTF-8') . $locStr;
                }
                $reply = implode("\n", $lines);
            }

            return [
                'reply'   => $reply,
                'actions' => [
                    ['label' => '📅 Kalender öffnen', 'url' => 'calendar.php'],
                    ['label' => '🏠 Tages-Cockpit (Mein Tag)', 'url' => 'dashboard.php'],
                ],
                'followups' => [
                    'Welche Aufgaben sind bei mir noch offen?',
                    'Wie synchronisiere ich den Kalender mit dem Smartphone?',
                ],
            ];
        } catch (Throwable) {
            // Fallback zur Wissensdatenbank
        }
    }

    // =========================================================================
    // 5. LIVE-ABFRAGE: MEINE OFFENEN AUFGABEN / TO-DOS
    // =========================================================================
    if (
        str_contains($norm, 'welche aufgaben')
        || str_contains($norm, 'meine aufgaben')
        || str_contains($norm, 'offene aufgaben')
        || str_contains($norm, 'offenen aufgaben')
        || str_contains($norm, 'meine todos')
        || str_contains($norm, 'meine to dos')
        || str_contains($norm, 'was muss ich erledigen')
        || str_contains($norm, 'was ist noch zu tun')
    ) {
        try {
            $stmtTasks = $pdo->prepare(
                "SELECT `title`, `status`, `priority`, `due_date`
                 FROM `tasks`
                 WHERE (`user_id` = :uid1 OR `assigned_to` = :uid2)
                   AND `status` IN ('todo', 'in_progress')
                 ORDER BY
                   CASE WHEN `due_date` IS NULL THEN 1 ELSE 0 END ASC,
                   `due_date` ASC,
                   `id` DESC
                 LIMIT 8"
            );
            $stmtTasks->execute([':uid1' => $userId, ':uid2' => $userId]);
            $tasks = $stmtTasks->fetchAll();

            if (empty($tasks)) {
                $reply = "🎉 **Alles erledigt!** Du hast aktuell keine offenen Aufgaben auf deinem Kanban-Board.";
            } else {
                $lines = ["📋 **Deine aktuell offenen Aufgaben (" . count($tasks) . "):**\n"];
                foreach ($tasks as $t) {
                    $statusBadge = ($t['status'] === 'in_progress') ? '🔄 *In Arbeit*' : '⏳ *Offen*';
                    $duePart = !empty($t['due_date']) ? ' · 📅 Fällig: ' . date('d.m.Y', strtotime((string)$t['due_date']) ?: time()) : '';
                    $lines[] = "- {$statusBadge}: **" . htmlspecialchars((string)$t['title'], ENT_QUOTES, 'UTF-8') . "**{$duePart}";
                }
                $lines[] = "\n💡 *Tipp:* Schreib mir `Aufgabe: ...`, um direkt hier eine neue Aufgabe anzulegen.";
                $reply = implode("\n", $lines);
            }

            return [
                'reply'   => $reply,
                'actions' => [
                    ['label' => '📋 Aufgaben-Board öffnen', 'url' => 'tasks.php'],
                ],
                'followups' => [
                    'Was steht diese Woche in meinem Kalender?',
                    'Zeige meine letzten Notizen',
                ],
            ];
        } catch (Throwable) {
            // Fallback
        }
    }

    // =========================================================================
    // 6. LIVE-ABFRAGE: UNGELESENE NACHRICHTEN & AKTIVE UMFRAGEN
    // =========================================================================
    if (
        str_contains($norm, 'ungelesene nachricht')
        || str_contains($norm, 'neue nachricht')
        || str_contains($norm, 'habe ich nachrichten')
    ) {
        $unread = getUnreadMessagesCount($pdo, $userId);
        $reply = $unread > 0
            ? "💬 Du hast aktuell **{$unread} ungelesene " . ($unread === 1 ? 'Nachricht' : 'Nachrichten') . "** in deinem Posteingang!"
            : "💬 Du hast aktuell **keine ungelesenen Nachrichten** – dein Posteingang ist auf dem neuesten Stand.";

        return [
            'reply'   => $reply,
            'actions' => [
                ['label' => '💬 Messenger öffnen', 'url' => 'messages.php'],
            ],
            'followups' => [
                'Was steht heute oder diese Woche bei mir an?',
                'Wie viel Speicherplatz habe ich noch frei?',
            ],
        ];
    }

    // =========================================================================
    // 7. LIVE-ABFRAGE: MEINE LETZTEN NOTIZEN
    // =========================================================================
    if (
        str_contains($norm, 'meine notizen')
        || str_contains($norm, 'letzten notizen')
        || str_contains($norm, 'letzte notizen')
        || str_contains($norm, 'zeige meine notizen')
    ) {
        try {
            $stmtNotes = $pdo->prepare(
                "SELECT `title`, `content`, `is_pinned`, `updated_at`
                 FROM `notes`
                 WHERE `user_id` = :uid
                 ORDER BY `is_pinned` DESC, `updated_at` DESC
                 LIMIT 6"
            );
            $stmtNotes->execute([':uid' => $userId]);
            $notes = $stmtNotes->fetchAll();

            if (empty($notes)) {
                $reply = "📌 Du hast noch keine eigenen Haftnotizen angelegt. Schreib mir einfach `Notiz: Dein Text`, um sofort eine zu erstellen!";
            } else {
                $lines = ["📌 **Deine aktuellen Haftnotizen:**\n"];
                foreach ($notes as $n) {
                    $pin = ((int)$n['is_pinned'] === 1) ? '📌 ' : '• ';
                    $snippet = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$n['content']) ?? ''), 0, 70);
                    $lines[] = "- {$pin}**" . htmlspecialchars((string)$n['title'], ENT_QUOTES, 'UTF-8') . "**: " . htmlspecialchars($snippet, ENT_QUOTES, 'UTF-8');
                }
                $reply = implode("\n", $lines);
            }

            return [
                'reply'   => $reply,
                'actions' => [
                    ['label' => '📌 Notizwand öffnen', 'url' => 'notes.php'],
                ],
                'followups' => [
                    'Welche Aufgaben sind bei mir noch offen?',
                    'Wie viel Speicherplatz habe ich noch frei?',
                ],
            ];
        } catch (Throwable) {
            // Fallback
        }
    }

    // =========================================================================
    // 8. LIVE-ABFRAGE (ADMIN): MODUL-STATISTIK PER CHAT
    // =========================================================================
    if ($isAdmin && (str_contains($norm, 'modul statistik') || str_contains($norm, 'beliebteste modul') || str_contains($norm, 'am haeufigsten genutzt') || str_contains($norm, 'welche module werden'))) {
        $mStats = getModuleUsageStatistics($pdo, $appSettings);
        $lines  = ["📊 **Top-Module nach bisherigen Aufrufen (Gesamt: {$mStats['totals']['total']}):**\n"];
        $i = 0;
        foreach ($mStats['modules'] as $mItem) {
            if ($i >= 5) {
                break;
            }
            $lines[] = ($i + 1) . ". {$mItem['icon']} **{$mItem['label']}** – **{$mItem['hits_total']}** Aufrufe ({$mItem['pct_total']} % · *{$mItem['inventory']}*)";
            $i++;
        }
        return [
            'reply'   => implode("\n", $lines),
            'actions' => [
                ['label' => '📊 Vollständige Modul-Statistik öffnen', 'url' => 'admin.php#tab-modules'],
            ],
            'followups' => [
                'Wie viel Speicherplatz habe ich noch frei?',
                'Welche Admin-Funktionen gibt es?',
            ],
        ];
    }

    // =========================================================================
    // 9. EXPLIZITE LIVE-SUCHE IN DATEIEN, KONTAKTEN & NOTIZEN
    //    (z.B. "Suche Rechnung", "Wo ist die Datei ...", "Telefonnummer von ...")
    // =========================================================================
    if (preg_match('/^(?:suche\s+nach|suche\s+datei|suche|finde\s+datei|finde|wo\s+ist\s+die\s+datei|wo\s+liegt|wo\s+finde\s+ich|telefonnummer\s+von|kontakt\s+von|adresse\s+von)\s+(.+)$/isu', $question, $mSearch)) {
        $searchTerm = trim($mSearch[1], " ?.!\"'„“");
        if (mb_strlen($searchTerm) >= 2) {
            $like = '%' . $searchTerm . '%';
            $foundLines = [];
            $actions = [];

            // A) Dateien durchsuchen
            try {
                $stmtFiles = $pdo->prepare(
                    "SELECT `id`, `original_name`, `folder_path`, `file_size`
                     FROM `files`
                     WHERE `user_id` = :uid
                       AND `deleted_at` IS NULL
                       AND (`original_name` LIKE :q1 OR `folder_path` LIKE :q2)
                     ORDER BY `uploaded_at` DESC
                     LIMIT 5"
                );
                $stmtFiles->execute([':uid' => $userId, ':q1' => $like, ':q2' => $like]);
                foreach ($stmtFiles->fetchAll() as $f) {
                    $fName = (string)$f['original_name'];
                    $fFolder = trim((string)($f['folder_path'] ?? '')) !== '' ? ' (Ordner: `' . $f['folder_path'] . '`)' : ' (Hauptverzeichnis)';
                    $foundLines[] = "- 📄 **Datei:** " . htmlspecialchars($fName, ENT_QUOTES, 'UTF-8') . $fFolder . ' · ' . formatBytes((int)$f['file_size']);
                    if (strtolower(pathinfo($fName, PATHINFO_EXTENSION)) === 'epub') {
                        $actions[] = ['label' => '📖 ' . mb_substr($fName, 0, 24) . ' im E-Book-Reader lesen', 'url' => 'ebooks.php?id=' . (int)$f['id']];
                    } elseif (isOfficeEditableFile($fName)) {
                        $actions[] = ['label' => '📝 ' . mb_substr($fName, 0, 24) . ' in Office öffnen', 'url' => 'office.php?id=' . (int)$f['id']];
                    } else {
                        $actions[] = ['label' => '⬇️ ' . mb_substr($fName, 0, 24) . ' herunterladen', 'url' => 'download.php?id=' . (int)$f['id']];
                    }
                }
            } catch (Throwable) {
            }

            // B) Kontakte durchsuchen
            try {
                $stmtContacts = $pdo->prepare(
                    "SELECT `first_name`, `last_name`, `email`, `phone`, `company`
                     FROM `contacts`
                     WHERE `user_id` = :uid
                       AND (`first_name` LIKE :q1 OR `last_name` LIKE :q2 OR `company` LIKE :q3 OR `email` LIKE :q4)
                     LIMIT 4"
                );
                $stmtContacts->execute([':uid' => $userId, ':q1' => $like, ':q2' => $like, ':q3' => $like, ':q4' => $like]);
                foreach ($stmtContacts->fetchAll() as $c) {
                    $cName = trim($c['first_name'] . ' ' . $c['last_name']);
                    $cDetails = [];
                    if (!empty($c['phone'])) {
                        $cDetails[] = '📞 ' . $c['phone'];
                    }
                    if (!empty($c['email'])) {
                        $cDetails[] = '✉️ ' . $c['email'];
                    }
                    $foundLines[] = "- 📇 **Kontakt:** **" . htmlspecialchars($cName, ENT_QUOTES, 'UTF-8') . "**" . (!empty($cDetails) ? ' (' . htmlspecialchars(implode(' · ', $cDetails), ENT_QUOTES, 'UTF-8') . ')' : '');
                }
            } catch (Throwable) {
            }

            // C) Notizen durchsuchen
            try {
                $stmtNotes = $pdo->prepare(
                    "SELECT `title`, `content`
                     FROM `notes`
                     WHERE `user_id` = :uid
                       AND (`title` LIKE :q1 OR `content` LIKE :q2)
                     LIMIT 4"
                );
                $stmtNotes->execute([':uid' => $userId, ':q1' => $like, ':q2' => $like]);
                foreach ($stmtNotes->fetchAll() as $n) {
                    $foundLines[] = "- 📌 **Notiz:** **" . htmlspecialchars((string)$n['title'], ENT_QUOTES, 'UTF-8') . "**";
                }
            } catch (Throwable) {
            }

            if (!empty($foundLines)) {
                $actions[] = ['label' => '📂 Alle Dateien durchsuchen', 'url' => 'index.php?search=' . rawurlencode($searchTerm)];
                return [
                    'reply'     => "🔎 **Suchergebnisse in deiner Cloud für „" . htmlspecialchars($searchTerm, ENT_QUOTES, 'UTF-8') . "“:**\n\n" . implode("\n", $foundLines),
                    'actions'   => array_slice($actions, 0, 4),
                    'followups' => [
                        'Wie viel Speicherplatz habe ich noch frei?',
                        'Welche Aufgaben sind bei mir noch offen?',
                    ],
                ];
            }
        }
    }

    // =========================================================================
    // 10. WISSENSDATENBANK-ABGLEICH (MIT GEWICHTUNG & FUZZY-MATCHING)
    // =========================================================================
    $kb = getAssistantKnowledgeBase($appSettings, $isAdmin);
    $queryWords = array_values(array_filter(explode(' ', $norm), static fn(string $w): bool => mb_strlen($w) >= 2));

    $bestScore = 0.0;
    $bestEntry = null;

    foreach ($kb as $entry) {
        $score = 0.0;
        $titleNorm = normalizeAssistantText($entry['title']);

        if (str_contains($norm, $titleNorm)) {
            $score += 18.0;
        }

        foreach ($entry['keywords'] as $kw) {
            $kwNorm = normalizeAssistantText($kw);
            if ($kwNorm === '') {
                continue;
            }
            if (str_contains($norm, $kwNorm)) {
                // Mehrwort-Treffer stärker gewichten
                $score += str_contains($kwNorm, ' ') ? 14.0 : 8.5;
                continue;
            }

            // Einzelwort-/Tippfehler-Abgleich
            foreach ($queryWords as $qw) {
                if ($qw === $kwNorm) {
                    $score += 7.5;
                } elseif (mb_strlen($qw) >= 4 && mb_strlen($kwNorm) >= 4) {
                    if (str_starts_with($qw, $kwNorm) || str_starts_with($kwNorm, $qw)) {
                        $score += 5.0;
                    } elseif (levenshtein(substr($qw, 0, 20), substr($kwNorm, 0, 20)) <= 1) {
                        $score += 4.0;
                    }
                }
            }
        }

        if ($score > $bestScore) {
            $bestScore = $score;
            $bestEntry = $entry;
        }
    }

    if ($bestEntry !== null && $bestScore >= 4.0) {
        return [
            'reply'     => "{$bestEntry['icon']} **{$bestEntry['title']}**\n\n" . $bestEntry['answer'],
            'actions'   => $bestEntry['actions'],
            'followups' => $bestEntry['followups'],
        ];
    }

    // =========================================================================
    // 11. AUTOMATISCHER FALLBACK: SUCHE IN EIGENEN DATEIEN & INHALTEN
    // =========================================================================
    $stopWords = ['wie', 'was', 'wo', 'wann', 'warum', 'kann', 'ich', 'man', 'in', 'der', 'die', 'das', 'den', 'dem', 'ein', 'eine', 'einen', 'mit', 'fuer', 'von', 'auf', 'ist', 'sind', 'hat', 'haben', 'cloud', 'minicloud', 'bitte', 'mir', 'mein', 'meine'];
    $meaningfulWords = array_values(array_filter(
        $queryWords,
        static fn(string $w): bool => mb_strlen($w) >= 3 && !in_array($w, $stopWords, true)
    ));

    if (!empty($meaningfulWords)) {
        $searchCandidate = $meaningfulWords[0];
        $like = '%' . $searchCandidate . '%';
        try {
            $stmtFiles = $pdo->prepare(
                "SELECT `id`, `original_name`, `folder_path`, `file_size`
                 FROM `files`
                 WHERE `user_id` = :uid AND `deleted_at` IS NULL AND `original_name` LIKE :q
                 ORDER BY `uploaded_at` DESC
                 LIMIT 4"
            );
            $stmtFiles->execute([':uid' => $userId, ':q' => $like]);
            $matchedFiles = $stmtFiles->fetchAll();

            if (!empty($matchedFiles)) {
                $lines = ["🔎 Ich habe folgende **Dateien zu „" . htmlspecialchars($searchCandidate, ENT_QUOTES, 'UTF-8') . "“** in deiner Cloud gefunden:\n"];
                foreach ($matchedFiles as $f) {
                    $lines[] = "- 📄 **" . htmlspecialchars((string)$f['original_name'], ENT_QUOTES, 'UTF-8') . "** (" . formatBytes((int)$f['file_size']) . ")";
                }
                return [
                    'reply'   => implode("\n", $lines),
                    'actions' => [
                        ['label' => '📂 Im Datei-Manager anzeigen', 'url' => 'index.php?search=' . rawurlencode($searchCandidate)],
                    ],
                    'followups' => [
                        'Wie viel Speicherplatz habe ich noch frei?',
                        'Wie funktioniert Office Light?',
                    ],
                ];
            }
        } catch (Throwable) {
        }
    }

    // Allgemeine Hilfestellung mit Themen-Auswahl
    return [
        'reply' => "Zu dieser Formulierung habe ich noch keinen direkten Treffer gefunden. 🤔\n\n"
            . "Du kannst mich aber jederzeit zu folgenden Themen fragen:\n"
            . "- **📊 Dein Konto live:** *„Wie viel Speicherplatz habe ich frei?“*, *„Welche Termine stehen an?“*, *„Welche Aufgaben sind offen?“*, *„Suche Datei …“*\n"
            . "- **📝 Dokumente & PDF:** *„Wie funktioniert Office Light?“*, *„Wie fülle ich ein PDF aus oder schwärze es?“*, *„Wie starte ich die OCR-Texterkennung?“*\n"
            . "- **🔗 Teilen & Sync:** *„Wie teile ich einen Ordner?“*, *„Wie richte ich WebDAV oder CalDAV ein?“*, *„Wie verschlüssele ich eine Datei im Tresor?“*\n"
            . "- **⚡ Schnellbefehle:** `Notiz: Dein Text` oder `Aufgabe: Dein To-Do`",
        'actions' => [
            ['label' => '📖 Vollständiges Benutzerhandbuch (Hilfe)', 'url' => 'help.php'],
            ['label' => '🏠 Zum Tages-Cockpit', 'url' => 'dashboard.php'],
        ],
        'followups' => [
            'Wie viel Speicherplatz habe ich noch frei?',
            'Wie bearbeite oder schwärze ich ein PDF?',
            'Wie richte ich WebDAV als Netzlaufwerk ein?',
        ],
    ];
}

// =============================================================================
// AJAX-ENDPUNKT FÜR CHAT-ANFRAGEN (Sowohl für assistant.php als auch für das
// schwebende Schnell-Chat-Widget in includes/footer.php)
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && $_POST['ajax'] === '1') {
    header('Content-Type: application/json; charset=UTF-8');

    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!verifyCsrfToken($csrf)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Ungültiges Sicherheits-Token. Bitte laden Sie die Seite neu.']);
        exit;
    }

    recordModuleUsage($pdo, 'assistant', true);

    $message = trim((string)($_POST['message'] ?? ''));
    $result  = generateAssistantResponse($pdo, $userId, $username, $isAdmin, $appSettings, $message);

    echo json_encode([
        'ok'        => true,
        'reply'     => $result['reply'],
        'actions'   => $result['actions'],
        'followups' => $result['followups'],
    ]);
    exit;
}

$pageTitle = '🤖 Cloud-Assistent';
require_once __DIR__ . '/includes/header.php';
$csrfToken = getCsrfToken();
?>

<div class="max-w-6xl mx-auto px-4 sm:px-6 py-6 sm:py-8 space-y-6">
    <!-- KOPFBEREICH -->
    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs flex flex-wrap items-center justify-between gap-4">
        <div class="flex items-center gap-3.5">
            <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-brand-600 to-indigo-600 text-white flex items-center justify-center text-2xl shadow-sm">
                🤖
            </div>
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-lg sm:text-xl font-extrabold text-slate-900 dark:text-white">
                        MiniCloud KI- &amp; Wissens-Assistent
                    </h1>
                    <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-emerald-100 dark:bg-emerald-950/80 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800">
                        🟢 100 % Lokal &amp; DSGVO-konform
                    </span>
                </div>
                <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-0.5">
                    Beantwortet alle Fragen zur Bedienung der Cloud, durchsucht live deine Dateien, Termine &amp; Aufgaben und legt per Befehl direkt Notizen oder To-Dos an.
                </p>
            </div>
        </div>

        <div class="flex items-center gap-2">
            <button type="button" onclick="clearAssistantHistory()"
                    class="px-3.5 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-xs font-semibold text-slate-700 dark:text-slate-200 transition-colors cursor-pointer">
                🔄 Verlauf leeren
            </button>
            <a href="help.php"
               class="px-3.5 py-2 rounded-xl bg-brand-50 dark:bg-brand-950/60 hover:bg-brand-100 text-brand-700 dark:text-brand-300 border border-brand-200 dark:border-brand-800 text-xs font-semibold transition-colors">
                📖 Handbuch öffnen
            </a>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
        <!-- LINKE SPALTE: SCHNELLFRAGEN & FÄHIGKEITEN -->
        <div class="lg:col-span-4 space-y-4">
            <!-- 1. Live-Konto-Abfragen -->
            <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-4 shadow-xs space-y-2.5">
                <h2 class="text-xs font-bold uppercase tracking-wider text-brand-600 dark:text-brand-400 flex items-center gap-1.5">
                    <span>📊</span> Live-Abfragen zu deinem Konto
                </h2>
                <div class="flex flex-col gap-1.5">
                    <button type="button" onclick="askAssistantQuick('Wie viel Speicherplatz habe ich noch frei?')"
                            class="text-left px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800/70 hover:bg-brand-50 dark:hover:bg-brand-950/40 border border-slate-200/80 dark:border-slate-700 text-xs font-medium text-slate-800 dark:text-slate-200 transition-colors cursor-pointer">
                        💾 Wie viel Speicherplatz habe ich noch frei?
                    </button>
                    <button type="button" onclick="askAssistantQuick('Was steht heute oder diese Woche bei mir an?')"
                            class="text-left px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800/70 hover:bg-brand-50 dark:hover:bg-brand-950/40 border border-slate-200/80 dark:border-slate-700 text-xs font-medium text-slate-800 dark:text-slate-200 transition-colors cursor-pointer">
                        📅 Welche Termine stehen bei mir an?
                    </button>
                    <button type="button" onclick="askAssistantQuick('Welche Aufgaben sind bei mir noch offen?')"
                            class="text-left px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800/70 hover:bg-brand-50 dark:hover:bg-brand-950/40 border border-slate-200/80 dark:border-slate-700 text-xs font-medium text-slate-800 dark:text-slate-200 transition-colors cursor-pointer">
                        📋 Welche Aufgaben sind noch offen?
                    </button>
                    <button type="button" onclick="askAssistantQuick('Zeige meine letzten Notizen')"
                            class="text-left px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800/70 hover:bg-brand-50 dark:hover:bg-brand-950/40 border border-slate-200/80 dark:border-slate-700 text-xs font-medium text-slate-800 dark:text-slate-200 transition-colors cursor-pointer">
                        📌 Zeige meine aktuellen Haftnotizen
                    </button>
                    <button type="button" onclick="askAssistantQuick('Habe ich ungelesene Nachrichten?')"
                            class="text-left px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800/70 hover:bg-brand-50 dark:hover:bg-brand-950/40 border border-slate-200/80 dark:border-slate-700 text-xs font-medium text-slate-800 dark:text-slate-200 transition-colors cursor-pointer">
                        💬 Habe ich ungelesene Nachrichten?
                    </button>
                </div>
            </div>

            <!-- 2. Häufige Fragen zur Cloud -->
            <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-4 shadow-xs space-y-2.5">
                <h2 class="text-xs font-bold uppercase tracking-wider text-indigo-600 dark:text-indigo-400 flex items-center gap-1.5">
                    <span>💡</span> Häufige Fragen zu Modulen
                </h2>
                <div class="flex flex-col gap-1.5">
                    <button type="button" onclick="askAssistantQuick('Wie funktioniert Office Light für Word und Excel?')"
                            class="text-left px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800/70 hover:bg-indigo-50 dark:hover:bg-indigo-950/40 border border-slate-200/80 dark:border-slate-700 text-xs font-medium text-slate-800 dark:text-slate-200 transition-colors cursor-pointer">
                        📝 Wie funktioniert Office Light (Word &amp; Excel)?
                    </button>
                    <button type="button" onclick="askAssistantQuick('Wie kann ich ein PDF ausfüllen, unterschreiben oder schwärzen?')"
                            class="text-left px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800/70 hover:bg-indigo-50 dark:hover:bg-indigo-950/40 border border-slate-200/80 dark:border-slate-700 text-xs font-medium text-slate-800 dark:text-slate-200 transition-colors cursor-pointer">
                        ✏️ PDF ausfüllen, unterschreiben &amp; schwärzen
                    </button>
                    <button type="button" onclick="askAssistantQuick('Wie funktioniert die lokale OCR-Texterkennung?')"
                            class="text-left px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800/70 hover:bg-indigo-50 dark:hover:bg-indigo-950/40 border border-slate-200/80 dark:border-slate-700 text-xs font-medium text-slate-800 dark:text-slate-200 transition-colors cursor-pointer">
                        🔎 Text aus Fotos &amp; Scans erkennen (OCR)
                    </button>
                    <button type="button" onclick="askAssistantQuick('Wie binde ich die Cloud per WebDAV, CalDAV oder CardDAV ein?')"
                            class="text-left px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800/70 hover:bg-indigo-50 dark:hover:bg-indigo-950/40 border border-slate-200/80 dark:border-slate-700 text-xs font-medium text-slate-800 dark:text-slate-200 transition-colors cursor-pointer">
                        📡 WebDAV, CalDAV &amp; CardDAV einrichten
                    </button>
                    <button type="button" onclick="askAssistantQuick('Wie verschlüssele ich eine Datei im Tresor?')"
                            class="text-left px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800/70 hover:bg-indigo-50 dark:hover:bg-indigo-950/40 border border-slate-200/80 dark:border-slate-700 text-xs font-medium text-slate-800 dark:text-slate-200 transition-colors cursor-pointer">
                        🔒 Zero-Knowledge Datei-Tresor nutzen
                    </button>
                </div>
            </div>

            <!-- 3. Tipp: Schnellbefehle -->
            <div class="mc-card bg-amber-50/70 dark:bg-amber-950/20 rounded-2xl border border-amber-200/80 dark:border-amber-800/60 p-4 text-xs space-y-1.5">
                <p class="font-bold text-amber-900 dark:text-amber-300">⚡ Profi-Tipp: Schnellbefehle im Chat</p>
                <p class="text-amber-800/90 dark:text-amber-200/80 leading-relaxed">
                    • <code class="font-mono bg-white/80 dark:bg-slate-900 px-1.5 py-0.5 rounded">Suche Rechnung</code> durchsucht deine Dateien, Kontakte &amp; Notizen.<br>
                    • <code class="font-mono bg-white/80 dark:bg-slate-900 px-1.5 py-0.5 rounded">Notiz: Einkaufsliste…</code> legt direkt eine Haftnotiz an.<br>
                    • <code class="font-mono bg-white/80 dark:bg-slate-900 px-1.5 py-0.5 rounded">Aufgabe: Steuererklärung</code> legt direkt ein To-Do an.
                </p>
            </div>
        </div>

        <!-- RECHTE SPALTE: INTERAKTIVES CHAT-FENSTER -->
        <div class="lg:col-span-8 mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xs flex flex-col h-[620px] overflow-hidden">
            <!-- Chat-Verlauf -->
            <div id="assistantChatMessages" class="flex-1 overflow-y-auto p-4 sm:p-6 space-y-4"></div>

            <!-- Eingabezeile -->
            <form id="assistantChatForm" onsubmit="submitAssistantQuestion(event)" class="p-3.5 sm:p-4 border-t border-slate-200 dark:border-slate-800 bg-slate-50/70 dark:bg-slate-900/90 flex items-center gap-2.5">
                <input
                    type="text"
                    id="assistantQuestionInput"
                    placeholder="Frage zur Cloud stellen oder z. B. „Suche …“ / „Aufgabe: …“ eingeben…"
                    autocomplete="off"
                    class="flex-1 px-4 py-2.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500"
                >
                <button
                    type="submit"
                    id="assistantSendBtn"
                    class="px-5 py-2.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-bold text-xs sm:text-sm shadow-xs transition-colors cursor-pointer shrink-0"
                >
                    Senden &rarr;
                </button>
            </form>
        </div>
    </div>
</div>

<script>
const ASSISTANT_CSRF = <?= json_encode($csrfToken) ?>;
const ASSISTANT_STORAGE_KEY = 'mc_assistant_chat_v1_' + <?= json_encode($userId) ?>;

function formatAssistantMarkdown(text) {
    let safe = String(text || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
    safe = safe.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    safe = safe.replace(/\*(.+?)\*/g, '<em>$1</em>');
    safe = safe.replace(/`([^`]+)`/g, '<code class="px-1.5 py-0.5 rounded bg-slate-200/80 dark:bg-slate-700 font-mono text-[11px]">$1</code>');
    safe = safe.replace(/\n/g, '<br>');
    return safe;
}

function loadAssistantHistory() {
    try {
        const raw = sessionStorage.getItem(ASSISTANT_STORAGE_KEY);
        if (raw) {
            const parsed = JSON.parse(raw);
            if (Array.isArray(parsed) && parsed.length > 0) {
                return parsed;
            }
        }
    } catch (e) {}
    return [
        {
            role: 'assistant',
            reply: <?= json_encode("Hallo **{$username}**! 👋 Ich bin dein lokaler **MiniCloud-Assistent**.\n\nFrag mich gerne alles zur Bedienung der Cloud, zu deinem freien **Speicherplatz**, deinen **Terminen & Aufgaben** oder suche direkt nach **Dateien, Kontakten und Notizen**!") ?>,
            actions: [
                { label: '🏠 Mein Tag', url: 'dashboard.php' },
                { label: '📂 Meine Dateien', url: 'index.php' },
                { label: '📝 Office Light', url: 'office.php' }
            ],
            followups: [
                'Wie viel Speicherplatz habe ich noch frei?',
                'Was steht heute oder diese Woche bei mir an?',
                'Wie funktioniert die OCR-Texterkennung?'
            ]
        }
    ];
}

function saveAssistantHistory(items) {
    try {
        sessionStorage.setItem(ASSISTANT_STORAGE_KEY, JSON.stringify(items.slice(-30)));
    } catch (e) {}
}

function clearAssistantHistory() {
    try {
        sessionStorage.removeItem(ASSISTANT_STORAGE_KEY);
    } catch (e) {}
    renderAssistantMessages();
}

function renderAssistantMessages() {
    const container = document.getElementById('assistantChatMessages');
    if (!container) return;
    const history = loadAssistantHistory();
    container.innerHTML = '';

    history.forEach((msg) => {
        const wrap = document.createElement('div');
        if (msg.role === 'user') {
            wrap.className = 'flex justify-end';
            wrap.innerHTML = `
                <div class="max-w-[82%] px-4 py-2.5 rounded-2xl rounded-br-sm bg-brand-600 text-white text-xs sm:text-sm shadow-xs">
                    ${formatAssistantMarkdown(msg.text)}
                </div>
            `;
        } else {
            wrap.className = 'flex items-start gap-2.5';
            let actionsHtml = '';
            if (Array.isArray(msg.actions) && msg.actions.length > 0) {
                actionsHtml = '<div class="flex flex-wrap gap-2 pt-2.5">' + msg.actions.map((a) =>
                    `<a href="${a.url}" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs font-bold shadow-xs transition-colors">${formatAssistantMarkdown(a.label)} &rarr;</a>`
                ).join('') + '</div>';
            }

            let followupsHtml = '';
            if (Array.isArray(msg.followups) && msg.followups.length > 0) {
                followupsHtml = '<div class="flex flex-wrap gap-1.5 pt-2.5 border-t border-slate-200/70 dark:border-slate-700/70 mt-2.5">' + msg.followups.map((f) =>
                    `<button type="button" onclick="askAssistantQuick(${JSON.stringify(f).replace(/"/g, '&quot;')})" class="px-2.5 py-1 rounded-lg bg-white dark:bg-slate-900 hover:bg-brand-50 dark:hover:bg-brand-950/50 border border-slate-200 dark:border-slate-700 text-[11px] font-medium text-slate-700 dark:text-slate-300 transition-colors cursor-pointer">💬 ${formatAssistantMarkdown(f)}</button>`
                ).join('') + '</div>';
            }

            wrap.innerHTML = `
                <div class="w-8 h-8 rounded-xl bg-gradient-to-br from-brand-600 to-indigo-600 text-white flex items-center justify-center text-sm shrink-0 mt-0.5">
                    🤖
                </div>
                <div class="max-w-[88%] px-4 py-3 rounded-2xl rounded-tl-sm bg-slate-100 dark:bg-slate-800/90 border border-slate-200/80 dark:border-slate-700/80 text-xs sm:text-sm text-slate-800 dark:text-slate-100 leading-relaxed space-y-1">
                    <div>${formatAssistantMarkdown(msg.reply)}</div>
                    ${actionsHtml}
                    ${followupsHtml}
                </div>
            `;
        }
        container.appendChild(wrap);
    });

    container.scrollTop = container.scrollHeight;
}

async function askAssistantQuick(questionText) {
    const inp = document.getElementById('assistantQuestionInput');
    if (inp) {
        inp.value = questionText;
    }
    await sendAssistantMessage(questionText);
}

async function submitAssistantQuestion(ev) {
    ev.preventDefault();
    const inp = document.getElementById('assistantQuestionInput');
    if (!inp) return;
    const text = inp.value.trim();
    if (!text) return;
    inp.value = '';
    await sendAssistantMessage(text);
}

async function sendAssistantMessage(text) {
    const history = loadAssistantHistory();
    history.push({ role: 'user', text: text });
    saveAssistantHistory(history);
    renderAssistantMessages();

    const btn = document.getElementById('assistantSendBtn');
    if (btn) btn.disabled = true;

    try {
        const fd = new FormData();
        fd.append('ajax', '1');
        fd.append('csrf_token', ASSISTANT_CSRF);
        fd.append('message', text);

        const res = await fetch('assistant.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (data && data.ok) {
            history.push({
                role: 'assistant',
                reply: data.reply,
                actions: data.actions || [],
                followups: data.followups || []
            });
        } else {
            history.push({
                role: 'assistant',
                reply: '⚠️ ' + (data && data.error ? data.error : 'Antwort konnte nicht geladen werden.'),
                actions: [],
                followups: []
            });
        }
    } catch (err) {
        history.push({
            role: 'assistant',
            reply: '⚠️ Verbindungsfehler zum lokalen Assistenten.',
            actions: [],
            followups: []
        });
    } finally {
        if (btn) btn.disabled = false;
        saveAssistantHistory(history);
        renderAssistantMessages();
    }
}

document.addEventListener('DOMContentLoaded', renderAssistantMessages);
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
