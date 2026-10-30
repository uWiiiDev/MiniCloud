<?php
declare(strict_types=1);

/**
 * MiniCloud – Lokaler mehrsprachiger KI- & Wissens-Assistent (assistant.php)
 * 100 % Strato-, Intranet- & DSGVO-tauglich (reines PHP + MySQL ohne externe API-Abhängigkeit).
 * Unterstützt alle 11 angebotenen europäischen Sprachen:
 * de, en, fr, es, it, nl, pl, pt, cs, tr, uk (inkl. automatischer Spracherkennung im Chat).
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/office_functions.php';
require_once __DIR__ . '/includes/i18n.php';

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
$uiLang   = getCurrentLang();

/**
 * Normalisiert Suchtext über alle 11 europäischen Sprachen (Latein + Kyrillisch).
 */
function normalizeAssistantText(string $text): string
{
    $lower = mb_strtolower(trim($text), 'UTF-8');
    $lower = strtr($lower, [
        'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
        'á' => 'a',  'à' => 'a',  'â' => 'a',  'ã' => 'a',  'ą' => 'a',
        'é' => 'e',  'è' => 'e',  'ê' => 'e',  'ë' => 'e',  'ę' => 'e',  'ě' => 'e',
        'í' => 'i',  'ì' => 'i',  'î' => 'i',  'ï' => 'i',  'ı' => 'i',
        'ó' => 'o',  'ò' => 'o',  'ô' => 'o',  'õ' => 'o',
        'ú' => 'u',  'ù' => 'u',  'û' => 'u',  'ů' => 'u',
        'ý' => 'y',  'ñ' => 'n',  'ń' => 'n',  'ň' => 'n',
        'ç' => 'c',  'ć' => 'c',  'č' => 'c',
        'ł' => 'l',  'ś' => 's',  'š' => 's',  'ş' => 's',
        'ź' => 'z',  'ż' => 'z',  'ž' => 'z',
        'ř' => 'r',  'ď' => 'd',  'ť' => 't',  'ğ' => 'g',
    ]);
    $lower = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $lower) ?? $lower;
    return trim(preg_replace('/\s+/u', ' ', $lower) ?? '');
}

/**
 * Prüft, ob mindestens eines der übergebenen Schlüsselwörter im normalisierten Text vorkommt.
 *
 * @param array<int, string> $keywords
 */
function hasAnyKeyword(string $norm, array $keywords): bool
{
    foreach ($keywords as $kw) {
        if ($kw !== '' && str_contains($norm, $kw)) {
            return true;
        }
    }
    return false;
}

/**
 * Erkennt automatisch die Sprache einer eingegebenen Frage anhand charakteristischer
 * Wörter/Schriftzeichen oder fällt auf die gewählte UI-/Antwortsprache zurück.
 */
function detectAssistantLanguage(string $question, string $fallbackLang = 'de'): string
{
    $allowed = array_keys(getAllowedLanguages());
    if (!in_array($fallbackLang, $allowed, true)) {
        $fallbackLang = 'de';
    }

    $qTrim = trim($question);
    if ($qTrim === '') {
        return $fallbackLang;
    }

    // Kyrillische Zeichen -> Ukrainisch (uk)
    if (preg_match('/[\x{0400}-\x{04FF}]/u', $qTrim)) {
        return 'uk';
    }

    $lower = ' ' . mb_strtolower($qTrim, 'UTF-8') . ' ';

    $markers = [
        'de' => [' wie ', ' was ', ' welche ', ' mein ', ' meine ', ' speicherplatz ', ' aufgabe:', ' notiz:', ' suche ', ' datei ', ' ordner ', ' passwort ', ' noch frei', ' bei mir '],
        'en' => [' how ', ' what ', ' which ', ' where ', ' my ', ' storage ', ' space ', ' left', ' free ', ' task:', ' note:', ' search ', ' find ', ' file ', ' files ', ' folder ', ' unread ', ' appointments ', ' calendar '],
        'fr' => [' comment ', ' quel ', ' quelle ', ' quels ', ' mes ', ' mon ', ' espace ', ' stockage ', ' tâche:', ' tache:', ' chercher ', ' rechercher ', ' fichier ', ' dossier ', ' rendez-vous ', ' bonjour ', ' salut '],
        'es' => [' cómo ', ' como ', ' qué ', ' que ', ' cuál ', ' cuales ', ' mis ', ' almacenamiento ', ' espacio ', ' tarea:', ' nota:', ' buscar ', ' busca ', ' archivo ', ' archivos ', ' carpeta ', ' hola ', ' citas '],
        'it' => [' come ', ' quale ', ' quali ', ' mio ', ' miei ', ' mie ', ' spazio ', ' archiviazione ', ' compito:', ' cerca ', ' trova ', ' cartella ', ' ciao ', ' appuntamenti '],
        'nl' => [' hoe ', 'hoeveel ', ' wat ', ' welke ', ' mijn ', ' opslag ', ' opslagruimte ', ' vrij ', ' taak:', ' notitie:', ' zoek ', ' bestand ', ' bestanden ', ' map ', ' afspraken ', ' hallo '],
        'pl' => [' jak ', ' co ', ' jakie ', ' ile ', ' mam ', ' jeszcze ', ' wolnego ', ' miejsca', ' moje ', ' miejsce ', ' pamięć ', ' pamiec ', ' zadanie:', ' notatka:', ' szukaj ', ' znajdź ', ' znajdz ', ' plik ', ' pliki ', ' katalog ', ' cześć ', ' czesc ', ' czy '],
        'pt' => [' como ', ' qual ', ' quais ', ' quanto ', ' meu ', ' meus ', ' minha ', ' minhas ', ' armazenamento ', ' espaço ', ' espaco ', ' tarefa:', ' procurar ', ' ficheiro ', ' arquivo ', ' pasta ', ' olá ', ' ola '],
        'cs' => [' jak ', ' co ', ' jaké ', ' jake ', ' kolik ', ' které ', ' ktere ', ' moje ', ' můj ', ' muj ', ' úložiště ', ' uloziste ', ' místo ', ' misto ', ' úkol:', ' ukol:', ' poznámka:', ' poznamka:', ' hledat ', ' najít ', ' soubor ', ' složka ', ' ahoj '],
        'tr' => [' nasıl ', ' nasil ', ' ne ', ' kadar ', ' hangi ', ' benim ', ' depolama ', ' alan ', ' alanım ', ' görev:', ' gorev:', ' not:', ' ara ', ' bul ', ' dosya ', ' klasör ', ' klasor ', ' merhaba ', ' selam '],
    ];

    $scores = [];
    foreach ($markers as $code => $words) {
        $scores[$code] = 0;
        foreach ($words as $w) {
            if (str_contains($lower, $w)) {
                $scores[$code] += 2;
            }
        }
    }

    // Spezifische Sonderzeichen-Indikatoren
    if (preg_match('/[ğışçöü]/u', $lower) && (str_contains($lower, 'nasıl') || str_contains($lower, 'dosya') || str_contains($lower, 'kaç') || str_contains($lower, 'alan'))) {
        $scores['tr'] += 4;
    }
    if (preg_match('/[ąćęłńśźż]/u', $lower)) {
        $scores['pl'] += 4;
    }
    if (preg_match('/[ěščřžůďťň]/u', $lower)) {
        $scores['cs'] += 4;
    }
    if (preg_match('/[¿¡ñ]/u', $lower)) {
        $scores['es'] += 4;
    }
    if (preg_match('/[ãõ]/u', $lower)) {
        $scores['pt'] += 4;
    }

    arsort($scores);
    $topLang  = (string)array_key_first($scores);
    $topScore = $scores[$topLang] ?? 0;

    if ($topScore >= 2 && ($scores[$fallbackLang] ?? 0) < $topScore) {
        return $topLang;
    }

    return $fallbackLang;
}

/**
 * Liefert das Sprachpaket für alle UI-Elemente, Begrüßungen, Live-Antworten und Vorschläge
 * in allen 11 unterstützten europäischen Sprachen.
 *
 * @return array<string, mixed>
 */
function getAssistantLangPack(string $lang, string $username, string $appTitle): array
{
    $packs = [
        'de' => [
            'page_title'       => '🤖 Cloud-Assistent',
            'header_title'     => 'MiniCloud KI- & Wissens-Assistent',
            'header_badge'     => '🟢 100 % Lokal & 11 Sprachen',
            'header_desc'      => '2-Stufen-Architektur: Durchsucht live deine Dateien, Termine, Aufgaben, Finanzen & Lesestände, merkt sich den Gesprächskontext und nutzt optional ein lokales Sprachmodell (Ollama).',
            'btn_clear'        => '🔄 Verlauf leeren',
            'btn_help'         => '📖 Handbuch öffnen',
            'lang_auto'        => '🌐 Automatisch (Sprache erkennen)',
            'sec_live'         => 'Live-Abfragen zu deinem Konto',
            'sec_modules'      => 'Häufige Fragen zu Modulen',
            'tip_title'        => '⚡ Profi-Tipp: Schnellbefehle im Chat',
            'tip_body'         => '• <code>Suche Rechnung</code> durchsucht Dateien, Kontakte & Notizen.<br>• <code>Notiz: Einkaufsliste…</code> legt direkt eine Haftnotiz an.<br>• <code>Aufgabe: Steuererklärung</code> legt direkt ein To-Do an.<br>• <code>Termin: Morgen 15:00 Zahnarzt</code> trägt direkt einen Kalendertermin ein.',
            'input_placeholder'=> 'Frage stellen (mit Folgefragen-Gedächtnis) oder „Termin: Morgen 15:00 …“ eingeben…',
            'btn_send'         => 'Senden →',
            'welcome'          => "Hallo **{$username}**! 👋 Ich bin dein lokaler **{$appTitle}-Assistent**.\n\nIch spreche alle **11 Sprachen** deiner Cloud, merke mir unseren **Gesprächskontext für Rückfragen** und kenne live deinen **Speicherplatz**, deine **Termine & Aufgaben**, dein **Haushaltsbuch**, deine **Hörbuch-/E-Book-Lesestände** sowie **Kontakte & Geburtstage**.",
            'quick_welcome'    => "Hallo **{$username}**! 👋 Wie kann ich dir auf dieser Seite helfen?",
            'hello_reply'      => "Hallo **{$username}**! 🤖 Ich bin dein **lokaler 2-Stufen-Cloud-Assistent** (100 % offline-, Intranet- & DSGVO-konform direkt auf diesem Server).\n\nIch kann dir in **11 Sprachen** helfen:\n1. **📊 Live-Fragen zu deinem Konto:** Frag mich nach freiem *Speicherplatz*, *Terminen*, offenen *Aufgaben*, *Haushaltsbuch-Ausgaben*, deinem letzten *Hörbuch / E-Book*, *Vereinsdaten* oder *Geburtstagen*.\n2. **🧠 Gesprächsgedächtnis:** Du kannst jederzeit kurze Anschlussfragen stellen (*„Und wie teile ich das?“*, *„Geht das auch mit Passwort?“*).\n3. **⚡ Schnellbefehle:** Schreib `Notiz: ...`, `Aufgabe: ...` oder `Termin: Morgen 15:00 ...`, um sofort einen Eintrag anzulegen!",
            'thanks_reply'     => "Sehr gerne, **{$username}**! 😊 Wenn du noch eine Frage zur Cloud hast oder eine Datei, einen Termin oder eine Funktion suchst, frag einfach jederzeit.",
            'q_storage'        => 'Wie viel Speicherplatz habe ich noch frei?',
            'q_events'         => 'Welche Termine stehen bei mir an?',
            'q_tasks'          => 'Welche Aufgaben sind bei mir noch offen?',
            'q_notes'          => 'Zeige meine letzten Notizen',
            'q_messages'       => 'Habe ich ungelesene Nachrichten?',
            'q_office'         => 'Wie funktioniert Office Light (Word & Excel)?',
            'q_ebooks'         => 'Wie funktioniert der E-Book-Reader (.epub)?',
            'q_pdf'            => 'Wie kann ich ein PDF ausfüllen, unterschreiben oder schwärzen?',
            'q_ocr'            => 'Wie funktioniert die lokale OCR-Texterkennung?',
            'q_webdav'         => 'Wie binde ich die Cloud per WebDAV, CalDAV oder CardDAV ein?',
            'q_vault'          => 'Wie verschlüssele ich eine Datei im Tresor?',
            'act_dashboard'    => '🏠 Mein Tag',
            'act_files'        => '📂 Meine Dateien',
            'act_office'       => '📝 Office Light',
            'act_ebooks'       => '📚 E-Book-Bibliothek',
            'act_calendar'     => '📅 Kalender öffnen',
            'act_tasks'        => '📋 Aufgaben-Board öffnen',
            'act_notes'        => '📌 Notizwand öffnen',
            'act_messages'     => '💬 Messenger öffnen',
            'act_trash'        => '🗑️ Papierkorb prüfen',
            'act_settings'     => '⚙️ Einstellungen öffnen',
            'act_help'         => '📖 Benutzerhandbuch (Hilfe)',
            'note_created'     => '✅ Ich habe deine neue Haftnotiz **„%s“** sofort auf deiner 📌 Notizwand gespeichert!',
            'task_created'     => '✅ Ich habe die Aufgabe **„%s“** in deinem 📋 Aufgaben-Board angelegt!',
            'storage_title'    => '📊 **Dein aktueller Cloud-Speicherstand (%s):**',
            'storage_used'     => 'Belegt',
            'storage_of'       => 'von',
            'storage_free'     => 'Noch frei verfügbar',
            'storage_files'    => 'Aktive Dateien',
            'storage_trash'    => 'Im Papierkorb',
            'events_empty'     => '📅 Du hast aktuell **keine anstehenden Termine** im Kalender eingetragen.',
            'events_title'     => '📅 **Deine nächsten anstehenden Termine:**',
            'events_allday'    => 'Ganztägig',
            'tasks_empty'      => '🎉 **Alles erledigt!** Du hast aktuell keine offenen Aufgaben auf deinem Kanban-Board.',
            'tasks_title'      => '📋 **Deine aktuell offenen Aufgaben (%d):**',
            'tasks_in_prog'    => '🔄 *In Arbeit*',
            'tasks_todo'       => '⏳ *Offen*',
            'tasks_due'        => '📅 Fällig',
            'tasks_tip'        => '💡 *Tipp:* Schreib mir `Aufgabe: ...`, um direkt hier eine neue Aufgabe anzulegen.',
            'msg_unread'       => '💬 Du hast aktuell **%d ungelesene Nachricht(en)** in deinem Posteingang!',
            'msg_empty'        => '💬 Du hast aktuell **keine ungelesenen Nachrichten** – dein Posteingang ist auf dem neuesten Stand.',
            'notes_empty'      => '📌 Du hast noch keine eigenen Haftnotizen angelegt. Schreib mir einfach `Notiz: Dein Text`, um sofort eine zu erstellen!',
            'notes_title'      => '📌 **Deine aktuellen Haftnotizen:**',
            'search_title'     => '🔎 **Suchergebnisse in deiner Cloud für „%s“:**',
            'search_files_lbl' => '📄 **Datei:**',
            'search_cont_lbl'  => '📇 **Kontakt:**',
            'search_note_lbl'  => '📌 **Notiz:**',
            'search_all_btn'   => '📂 Alle Dateien durchsuchen',
            'fallback_files'   => '🔎 Ich habe folgende **Dateien zu „%s“** in deiner Cloud gefunden:',
            'fallback_reply'   => "Zu dieser Formulierung habe ich noch keinen direkten Treffer gefunden. 🤔\n\nDu kannst mich in **allen 11 Cloud-Sprachen** zu folgenden Themen fragen:\n- **📊 Dein Konto live:** *„Wie viel Speicherplatz habe ich frei?“*, *„Welche Termine stehen an?“*, *„Welche Aufgaben sind offen?“*, *„Suche …“*\n- **📝 Dokumente, E-Books & PDF:** *„Wie funktioniert Office Light?“*, *„Wie lese ich E-Books (.epub)?“*, *„Wie schwärze ich ein PDF?“*, *„Wie starte ich OCR?“*\n- **🔗 Teilen & Sync:** *„Wie teile ich einen Ordner?“*, *„Wie richte ich WebDAV oder CalDAV ein?“*\n- **⚡ Schnellbefehle:** `Notiz: Dein Text` oder `Aufgabe: Dein To-Do`",
        ],
        'en' => [
            'page_title'       => '🤖 Cloud Assistant',
            'header_title'     => 'MiniCloud AI & Knowledge Assistant',
            'header_badge'     => '🟢 100% Local & 11 Languages',
            'header_desc'      => 'Answers questions about your cloud in 11 languages, searches your live files, events & tasks, and creates notes or to-dos via quick commands.',
            'btn_clear'        => '🔄 Clear history',
            'btn_help'         => '📖 Open manual',
            'lang_auto'        => '🌐 Automatic (detect language)',
            'sec_live'         => 'Live queries for your account',
            'sec_modules'      => 'Frequently asked module questions',
            'tip_title'        => '⚡ Pro tip: Quick chat commands',
            'tip_body'         => '• <code>Search invoice</code> searches your files, contacts & notes.<br>• <code>Note: Shopping list…</code> instantly creates a sticky note.<br>• <code>Task: Tax return</code> instantly creates a to-do.',
            'input_placeholder'=> 'Ask a question or type "Search …" / "Task: …" / "Note: …"',
            'btn_send'         => 'Send →',
            'welcome'          => "Hello **{$username}**! 👋 I am your local **{$appTitle} Assistant**.\n\nAsk me anything about using the cloud, your available **storage space**, upcoming **appointments & tasks**, or search directly for **files, contacts, and notes**!",
            'quick_welcome'    => "Hello **{$username}**! 👋 How can I help you on this page?",
            'hello_reply'      => "Hello **{$username}**! 🤖 I am your **local Cloud Assistant** (running 100% locally and privately on this server).\n\nI can help you in three ways:\n1. **📊 Live account queries:** Ask about your free *storage*, upcoming *appointments*, open *tasks*, unread *messages*, or search *files, contacts & notes*.\n2. **💡 Module guides:** Ask about *Office Light*, *E-Book Reader (.epub)*, *PDF editing & redaction*, *local OCR*, *WebDAV/CalDAV*, or the *Zero-Knowledge Vault*.\n3. **⚡ Quick commands:** Type `Note: ...` or `Task: ...` to create an item right away!",
            'thanks_reply'     => "You're very welcome, **{$username}**! 😊 Let me know anytime if you need help with a file, appointment, or cloud feature.",
            'q_storage'        => 'How much free storage space do I have left?',
            'q_events'         => 'What appointments do I have coming up?',
            'q_tasks'          => 'Which tasks are still open for me?',
            'q_notes'          => 'Show my latest sticky notes',
            'q_messages'       => 'Do I have unread messages?',
            'q_office'         => 'How does Office Light (Word & Excel) work?',
            'q_ebooks'         => 'How does the E-Book Reader (.epub) work?',
            'q_pdf'            => 'How can I fill out, sign, or redact a PDF?',
            'q_ocr'            => 'How does local OCR text recognition work?',
            'q_webdav'         => 'How do I connect WebDAV, CalDAV, or CardDAV?',
            'q_vault'          => 'How do I encrypt a file in the Vault?',
            'act_dashboard'    => '🏠 My Day',
            'act_files'        => '📂 My Files',
            'act_office'       => '📝 Office Light',
            'act_ebooks'       => '📚 E-Book Library',
            'act_calendar'     => '📅 Open Calendar',
            'act_tasks'        => '📋 Open Tasks Board',
            'act_notes'        => '📌 Open Notes Board',
            'act_messages'     => '💬 Open Messenger',
            'act_trash'        => '🗑️ Check Trash',
            'act_settings'     => '⚙️ Open Settings',
            'act_help'         => '📖 User Manual (Help)',
            'note_created'     => '✅ I immediately saved your new sticky note **"%s"** to your 📌 Notes board!',
            'task_created'     => '✅ I created the task **"%s"** on your 📋 Tasks board!',
            'storage_title'    => '📊 **Your current cloud storage status (%s):**',
            'storage_used'     => 'Used',
            'storage_of'       => 'of',
            'storage_free'     => 'Available free space',
            'storage_files'    => 'Active files',
            'storage_trash'    => 'In trash',
            'events_empty'     => '📅 You currently have **no upcoming appointments** scheduled in your calendar.',
            'events_title'     => '📅 **Your next upcoming appointments:**',
            'events_allday'    => 'All day',
            'tasks_empty'      => '🎉 **All done!** You currently have no open tasks on your Kanban board.',
            'tasks_title'      => '📋 **Your currently open tasks (%d):**',
            'tasks_in_prog'    => '🔄 *In progress*',
            'tasks_todo'       => '⏳ *Open*',
            'tasks_due'        => '📅 Due',
            'tasks_tip'        => '💡 *Tip:* Type `Task: ...` to create a new task right from this chat.',
            'msg_unread'       => '💬 You currently have **%d unread message(s)** in your inbox!',
            'msg_empty'        => '💬 You have **no unread messages** right now – your inbox is up to date.',
            'notes_empty'      => '📌 You have not created any sticky notes yet. Just type `Note: Your text` to create one!',
            'notes_title'      => '📌 **Your latest sticky notes:**',
            'search_title'     => '🔎 **Search results in your cloud for "%s":**',
            'search_files_lbl' => '📄 **File:**',
            'search_cont_lbl'  => '📇 **Contact:**',
            'search_note_lbl'  => '📌 **Note:**',
            'search_all_btn'   => '📂 Search all files',
            'fallback_files'   => '🔎 I found the following **files matching "%s"** in your cloud:',
            'fallback_reply'   => "I couldn't find a direct match for that phrasing yet. 🤔\n\nYou can ask me about:\n- **📊 Live account info:** *\"How much storage space do I have left?\"*, *\"What appointments do I have?\"*, *\"Which tasks are open?\"*, *\"Search …\"*\n- **📝 Documents, E-Books & PDF:** *\"How does Office Light work?\"*, *\"How do I read E-Books (.epub)?\"*, *\"How do I redact a PDF?\"*, *\"How does OCR work?\"*\n- **🔗 Sharing & Sync:** *\"How do I share a folder?\"*, *\"How do I set up WebDAV or CalDAV?\"*\n- **⚡ Quick commands:** `Note: Your text` or `Task: Your to-do`",
        ],
        'fr' => [
            'page_title'       => '🤖 Assistant Cloud',
            'header_title'     => 'Assistant IA & Connaissances MiniCloud',
            'header_badge'     => '🟢 100 % Local & 11 Langues',
            'header_desc'      => 'Répond à vos questions sur le cloud en 11 langues, recherche en direct vos fichiers, rendez-vous & tâches et crée des notes ou tâches.',
            'btn_clear'        => '🔄 Effacer l’historique',
            'btn_help'         => '📖 Ouvrir le manuel',
            'lang_auto'        => '🌐 Automatique (détecter la langue)',
            'sec_live'         => 'Questions en direct sur votre compte',
            'sec_modules'      => 'Questions fréquentes sur les modules',
            'tip_title'        => '⚡ Astuce : Commandes rapides',
            'tip_body'         => '• <code>Chercher facture</code> recherche dans vos fichiers, contacts & notes.<br>• <code>Note: Liste…</code> crée immédiatement une note.<br>• <code>Tâche: Impôts</code> crée immédiatement une tâche.',
            'input_placeholder'=> 'Posez une question ou tapez « Chercher … » / « Tâche: … »',
            'btn_send'         => 'Envoyer →',
            'welcome'          => "Bonjour **{$username}** ! 👋 Je suis votre **Assistant {$appTitle}** local.\n\nPosez-moi vos questions sur le cloud, votre **espace de stockage**, vos **rendez-vous & tâches** ou recherchez vos **fichiers, contacts et notes** !",
            'quick_welcome'    => "Bonjour **{$username}** ! 👋 Comment puis-je vous aider sur cette page ?",
            'hello_reply'      => "Bonjour **{$username}** ! 🤖 Je suis votre **Assistant Cloud local** (100 % privé sur ce serveur).\n\nJe peux vous aider pour :\n1. **📊 Votre compte en direct :** *espace de stockage*, *rendez-vous*, *tâches ouvertes*, *messages non lus* ou recherche de *fichiers*.\n2. **💡 Tous les modules :** *Office Light*, *Lecteur E-Book (.epub)*, *PDF & Scan*, *OCR*, *WebDAV/CalDAV*, *Coffre-fort AES-256*.\n3. **⚡ Commandes rapides :** Tapez `Note: ...` ou `Tâche: ...` !",
            'thanks_reply'     => "Avec plaisir, **{$username}** ! 😊 N'hésitez pas si vous avez d'autres questions.",
            'q_storage'        => 'Combien d’espace de stockage me reste-t-il ?',
            'q_events'         => 'Quels sont mes prochains rendez-vous ?',
            'q_tasks'          => 'Quelles sont mes tâches ouvertes ?',
            'q_notes'          => 'Afficher mes dernières notes',
            'q_messages'       => 'Ai-je des messages non lus ?',
            'q_office'         => 'Comment fonctionne Office Light (Word & Excel) ?',
            'q_ebooks'         => 'Comment fonctionne le lecteur E-Book (.epub) ?',
            'q_pdf'            => 'Comment remplir, signer ou caviarder un PDF ?',
            'q_ocr'            => 'Comment fonctionne la reconnaissance de texte OCR ?',
            'q_webdav'         => 'Comment configurer WebDAV, CalDAV ou CardDAV ?',
            'q_vault'          => 'Comment chiffrer un fichier dans le coffre-fort ?',
            'act_dashboard'    => '🏠 Ma Journée',
            'act_files'        => '📂 Mes Fichiers',
            'act_office'       => '📝 Office Light',
            'act_ebooks'       => '📚 Bibliothèque E-Books',
            'act_calendar'     => '📅 Ouvrir le calendrier',
            'act_tasks'        => '📋 Ouvrir les tâches',
            'act_notes'        => '📌 Ouvrir les notes',
            'act_messages'     => '💬 Ouvrir la messagerie',
            'act_trash'        => '🗑️ Voir la corbeille',
            'act_settings'     => '⚙️ Paramètres',
            'act_help'         => '📖 Manuel d’aide',
            'note_created'     => '✅ J’ai enregistré votre nouvelle note **« %s »** sur votre 📌 tableau de notes !',
            'task_created'     => '✅ J’ai créé la tâche **« %s »** dans votre 📋 tableau de tâches !',
            'storage_title'    => '📊 **État actuel de votre espace de stockage (%s) :**',
            'storage_used'     => 'Utilisé',
            'storage_of'       => 'sur',
            'storage_free'     => 'Encore disponible',
            'storage_files'    => 'Fichiers actifs',
            'storage_trash'    => 'Dans la corbeille',
            'events_empty'     => '📅 Vous n’avez **aucun rendez-vous à venir** dans votre calendrier.',
            'events_title'     => '📅 **Vos prochains rendez-vous :**',
            'events_allday'    => 'Toute la journée',
            'tasks_empty'      => '🎉 **Tout est terminé !** Vous n’avez aucune tâche ouverte.',
            'tasks_title'      => '📋 **Vos tâches actuellement ouvertes (%d) :**',
            'tasks_in_prog'    => '🔄 *En cours*',
            'tasks_todo'       => '⏳ *Ouvert*',
            'tasks_due'        => '📅 Échéance',
            'tasks_tip'        => '💡 *Astuce :* Tapez `Tâche: ...` pour créer une tâche directement ici.',
            'msg_unread'       => '💬 Vous avez **%d message(s) non lu(s)** dans votre boîte de réception !',
            'msg_empty'        => '💬 Vous n’avez **aucun message non lu** pour le moment.',
            'notes_empty'      => '📌 Vous n’avez pas encore de notes. Tapez `Note: Votre texte` pour en créer une !',
            'notes_title'      => '📌 **Vos dernières notes :**',
            'search_title'     => '🔎 **Résultats de recherche pour « %s » :**',
            'search_files_lbl' => '📄 **Fichier :**',
            'search_cont_lbl'  => '📇 **Contact :**',
            'search_note_lbl'  => '📌 **Note :**',
            'search_all_btn'   => '📂 Rechercher dans tous les fichiers',
            'fallback_files'   => '🔎 J’ai trouvé les **fichiers suivants pour « %s »** :',
            'fallback_reply'   => "Je n'ai pas trouvé de réponse directe à cette formulation. 🤔\n\nVous pouvez me demander :\n- **📊 Votre compte :** *« Combien d'espace de stockage me reste-t-il ? »*, *« Quels sont mes rendez-vous ? »*, *« Quelles sont mes tâches ouvertes ? »*\n- **📝 Modules :** *Office Light*, *E-Books (.epub)*, *PDF & Scan*, *OCR*, *WebDAV / CalDAV*\n- **⚡ Commandes rapides :** `Note: ...` ou `Tâche: ...`",
        ],
        'es' => [
            'page_title'       => '🤖 Asistente Cloud',
            'header_title'     => 'Asistente de IA y Conocimiento MiniCloud',
            'header_badge'     => '🟢 100 % Local y 11 Idiomas',
            'header_desc'      => 'Responde preguntas sobre la nube en 11 idiomas, busca en tus archivos, citas y tareas en vivo y crea notas o tareas con comandos rápidos.',
            'btn_clear'        => '🔄 Borrar historial',
            'btn_help'         => '📖 Abrir manual',
            'lang_auto'        => '🌐 Automático (detectar idioma)',
            'sec_live'         => 'Consultas en vivo de tu cuenta',
            'sec_modules'      => 'Preguntas frecuentes sobre módulos',
            'tip_title'        => '⚡ Consejo: Comandos rápidos',
            'tip_body'         => '• <code>Buscar factura</code> busca en archivos, contactos y notas.<br>• <code>Nota: Lista…</code> crea una nota adhesiva.<br>• <code>Tarea: Impuestos</code> crea una tarea pendiente.',
            'input_placeholder'=> 'Haz una pregunta o escribe «Buscar …» / «Tarea: …»',
            'btn_send'         => 'Enviar →',
            'welcome'          => "¡Hola **{$username}**! 👋 Soy tu **Asistente {$appTitle}** local.\n\n¡Pregúntame sobre el uso de la nube, tu **espacio de almacenamiento**, tus **citas y tareas** o busca directamente **archivos, contactos y notas**!",
            'quick_welcome'    => "¡Hola **{$username}**! 👋 ¿Cómo puedo ayudarte en esta página?",
            'hello_reply'      => "¡Hola **{$username}**! 🤖 Soy tu **Asistente Cloud local** (100 % privado en este servidor).\n\nPuedo ayudarte con:\n1. **📊 Consultas en vivo:** *espacio libre*, *próximas citas*, *tareas abiertas*, *mensajes no leídos* o búsqueda de *archivos*.\n2. **💡 Guías de módulos:** *Office Light*, *Lector de E-Books (.epub)*, *PDF y escáner*, *OCR*, *WebDAV/CalDAV*, *Bóveda AES-256*.\n3. **⚡ Comandos rápidos:** Escribe `Nota: ...` o `Tarea: ...`.",
            'thanks_reply'     => "¡De nada, **{$username}**! 😊 Si necesitas algo más en tu nube, pregúntame cuando quieras.",
            'q_storage'        => '¿Cuánto espacio de almacenamiento libre me queda?',
            'q_events'         => '¿Qué citas tengo próximamente?',
            'q_tasks'          => '¿Qué tareas tengo pendientes?',
            'q_notes'          => 'Mostrar mis últimas notas',
            'q_messages'       => '¿Tengo mensajes sin leer?',
            'q_office'         => '¿Cómo funciona Office Light (Word y Excel)?',
            'q_ebooks'         => '¿Cómo funciona el lector de E-Books (.epub)?',
            'q_pdf'            => '¿Cómo puedo rellenar, firmar o censurar un PDF?',
            'q_ocr'            => '¿Cómo funciona el reconocimiento de texto OCR?',
            'q_webdav'         => '¿Cómo conecto WebDAV, CalDAV o CardDAV?',
            'q_vault'          => '¿Cómo cifro un archivo en la bóveda?',
            'act_dashboard'    => '🏠 Mi Día',
            'act_files'        => '📂 Mis Archivos',
            'act_office'       => '📝 Office Light',
            'act_ebooks'       => '📚 Biblioteca E-Books',
            'act_calendar'     => '📅 Abrir calendario',
            'act_tasks'        => '📋 Abrir tareas',
            'act_notes'        => '📌 Abrir notas',
            'act_messages'     => '💬 Abrir mensajería',
            'act_trash'        => '🗑️ Ver papelera',
            'act_settings'     => '⚙️ Ajustes',
            'act_help'         => '📖 Manual de ayuda',
            'note_created'     => '✅ ¡He guardado tu nueva nota **«%s»** en tu 📌 tablón de notas!',
            'task_created'     => '✅ ¡He creado la tarea **«%s»** en tu 📋 tablero de tareas!',
            'storage_title'    => '📊 **Estado actual de tu almacenamiento (%s):**',
            'storage_used'     => 'Ocupado',
            'storage_of'       => 'de',
            'storage_free'     => 'Disponible libre',
            'storage_files'    => 'Archivos activos',
            'storage_trash'    => 'En la papelera',
            'events_empty'     => '📅 Actualmente **no tienes próximas citas** en tu calendario.',
            'events_title'     => '📅 **Tus próximas citas:**',
            'events_allday'    => 'Todo el día',
            'tasks_empty'      => '🎉 **¡Todo al día!** No tienes tareas abiertas actualmente.',
            'tasks_title'      => '📋 **Tus tareas abiertas (%d):**',
            'tasks_in_prog'    => '🔄 *En curso*',
            'tasks_todo'       => '⏳ *Abierta*',
            'tasks_due'        => '📅 Vence',
            'tasks_tip'        => '💡 *Consejo:* Escribe `Tarea: ...` para crear una tarea directamente.',
            'msg_unread'       => '💬 ¡Tienes **%d mensaje(s) sin leer** en tu bandeja de entrada!',
            'msg_empty'        => '💬 No tienes **mensajes sin leer** en este momento.',
            'notes_empty'      => '📌 Aún no tienes notas adhesivas. ¡Escribe `Nota: Tu texto` para crear una!',
            'notes_title'      => '📌 **Tus notas actuales:**',
            'search_title'     => '🔎 **Resultados de búsqueda para «%s»:**',
            'search_files_lbl' => '📄 **Archivo:**',
            'search_cont_lbl'  => '📇 **Contacto:**',
            'search_note_lbl'  => '📌 **Nota:**',
            'search_all_btn'   => '📂 Buscar en todos los archivos',
            'fallback_files'   => '🔎 He encontrado los siguientes **archivos sobre «%s»**:',
            'fallback_reply'   => "No encontré una coincidencia exacta para esa pregunta. 🤔\n\nPuedes preguntarme sobre:\n- **📊 Tu cuenta en vivo:** *«¿Cuánto espacio libre tengo?»*, *«¿Qué citas tengo?»*, *«¿Qué tareas tengo pendientes?»*\n- **📝 Módulos:** *Office Light*, *E-Books (.epub)*, *PDF y escáner*, *OCR*, *WebDAV / CalDAV*\n- **⚡ Comandos rápidos:** `Nota: ...` o `Tarea: ...`",
        ],
        'it' => [
            'page_title'       => '🤖 Assistente Cloud',
            'header_title'     => 'Assistente IA & Conoscenza MiniCloud',
            'header_badge'     => '🟢 100 % Locale & 11 Lingue',
            'header_desc'      => 'Risponde alle domande sul cloud in 11 lingue, cerca dal vivo file, appuntamenti e attività e crea note o to-do con comandi rapidi.',
            'btn_clear'        => '🔄 Cancella cronologia',
            'btn_help'         => '📖 Apri manuale',
            'lang_auto'        => '🌐 Automatico (rileva lingua)',
            'sec_live'         => 'Domande dal vivo sul tuo account',
            'sec_modules'      => 'Domande frequenti sui moduli',
            'tip_title'        => '⚡ Consiglio: Comandi rapidi',
            'tip_body'         => '• <code>Cerca fattura</code> cerca in file, contatti e note.<br>• <code>Nota: Spesa…</code> crea subito una nota.<br>• <code>Compito: Tasse</code> crea subito un’attività.',
            'input_placeholder'=> 'Fai una domanda o scrivi «Cerca …» / «Compito: …»',
            'btn_send'         => 'Invia →',
            'welcome'          => "Ciao **{$username}**! 👋 Sono il tuo **Assistente {$appTitle}** locale.\n\nChiedimi tutto sull'uso del cloud, sullo **spazio di archiviazione**, sui tuoi **appuntamenti e compiti** o cerca **file, contatti e note**!",
            'quick_welcome'    => "Ciao **{$username}**! 👋 Come posso aiutarti in questa pagina?",
            'hello_reply'      => "Ciao **{$username}**! 🤖 Sono il tuo **Assistente Cloud locale**.\n\nPosso aiutarti con:\n1. **📊 Il tuo account dal vivo:** *spazio libero*, *appuntamenti*, *compiti aperti*, *messaggi non letti* o ricerca *file*.\n2. **💡 Guide ai moduli:** *Office Light*, *Lettore E-Book (.epub)*, *PDF & Scan*, *OCR*, *WebDAV/CalDAV*, *Cassaforte AES-256*.\n3. **⚡ Comandi rapidi:** Scrivi `Nota: ...` o `Compito: ...`!",
            'thanks_reply'     => "Con piacere, **{$username}**! 😊 Chiedimi pure se hai bisogno di altro.",
            'q_storage'        => 'Quanto spazio di archiviazione libero mi rimane?',
            'q_events'         => 'Quali sono i miei prossimi appuntamenti?',
            'q_tasks'          => 'Quali compiti sono ancora aperti?',
            'q_notes'          => 'Mostra le mie ultime note',
            'q_messages'       => 'Ho messaggi non letti?',
            'q_office'         => 'Come funziona Office Light (Word & Excel)?',
            'q_ebooks'         => 'Come funziona il lettore E-Book (.epub)?',
            'q_pdf'            => 'Come posso compilare, firmare o oscurare un PDF?',
            'q_ocr'            => 'Come funziona il riconoscimento testo OCR?',
            'q_webdav'         => 'Come configuro WebDAV, CalDAV o CardDAV?',
            'q_vault'          => 'Come crittografo un file nella cassaforte?',
            'act_dashboard'    => '🏠 La mia giornata',
            'act_files'        => '📂 I miei file',
            'act_office'       => '📝 Office Light',
            'act_ebooks'       => '📚 Libreria E-Book',
            'act_calendar'     => '📅 Apri calendario',
            'act_tasks'        => '📋 Apri compiti',
            'act_notes'        => '📌 Apri note',
            'act_messages'     => '💬 Apri messaggi',
            'act_trash'        => '🗑️ Controlla cestino',
            'act_settings'     => '⚙️ Impostazioni',
            'act_help'         => '📖 Manuale guida',
            'note_created'     => '✅ Ho salvato la tua nuova nota **«%s»** sulla 📌 bacheca!',
            'task_created'     => '✅ Ho creato il compito **«%s»** nella tua 📋 bacheca attività!',
            'storage_title'    => '📊 **Stato attuale del tuo spazio cloud (%s):**',
            'storage_used'     => 'Occupato',
            'storage_of'       => 'di',
            'storage_free'     => 'Spazio libero disponibile',
            'storage_files'    => 'File attivi',
            'storage_trash'    => 'Nel cestino',
            'events_empty'     => '📅 Al momento **non hai appuntamenti in programma**.',
            'events_title'     => '📅 **I tuoi prossimi appuntamenti:**',
            'events_allday'    => 'Tutto il giorno',
            'tasks_empty'      => '🎉 **Tutto completato!** Non hai compiti aperti.',
            'tasks_title'      => '📋 **I tuoi compiti aperti (%d):**',
            'tasks_in_prog'    => '🔄 *In corso*',
            'tasks_todo'       => '⏳ *Aperto*',
            'tasks_due'        => '📅 Scadenza',
            'tasks_tip'        => '💡 *Consiglio:* Scrivi `Compito: ...` per crearne uno subito.',
            'msg_unread'       => '💬 Hai **%d messaggio/i non letto/i** nella posta in arrivo!',
            'msg_empty'        => '💬 Non hai **messaggi non letti** al momento.',
            'notes_empty'      => '📌 Non hai ancora note. Scrivi `Nota: Il tuo testo` per crearne una!',
            'notes_title'      => '📌 **Le tue ultime note:**',
            'search_title'     => '🔎 **Risultati di ricerca per «%s»:**',
            'search_files_lbl' => '📄 **File:**',
            'search_cont_lbl'  => '📇 **Contatto:**',
            'search_note_lbl'  => '📌 **Nota:**',
            'search_all_btn'   => '📂 Cerca in tutti i file',
            'fallback_files'   => '🔎 Ho trovato i seguenti **file per «%s»**:',
            'fallback_reply'   => "Non ho trovato una risposta diretta a questa domanda. 🤔\n\nPuoi chiedermi:\n- **📊 Account dal vivo:** *«Quanto spazio libero ho?»*, *«Quali appuntamenti ho?»*, *«Quali compiti sono aperti?»*\n- **📝 Moduli:** *Office Light*, *E-Book (.epub)*, *PDF & Scan*, *OCR*, *WebDAV / CalDAV*\n- **⚡ Comandi rapidi:** `Nota: ...` o `Compito: ...`",
        ],
        'nl' => [
            'page_title'       => '🤖 Cloud-Assistent',
            'header_title'     => 'MiniCloud AI- & Kennisassistent',
            'header_badge'     => '🟢 100 % Lokaal & 11 Talen',
            'header_desc'      => 'Beantwoordt vragen over de cloud in 11 talen, doorzoekt live je bestanden, afspraken & taken en maakt direct notities of taken aan.',
            'btn_clear'        => '🔄 Geschiedenis wissen',
            'btn_help'         => '📖 Handleiding openen',
            'lang_auto'        => '🌐 Automatisch (taal herkennen)',
            'sec_live'         => 'Live vragen over je account',
            'sec_modules'      => 'Veelgestelde vragen over modules',
            'tip_title'        => '⚡ Tip: Snelcommando’s in de chat',
            'tip_body'         => '• <code>Zoek factuur</code> doorzoekt bestanden, contacten & notities.<br>• <code>Notitie: Boodschappen…</code> maakt direct een notitie.<br>• <code>Taak: Belasting</code> maakt direct een taak aan.',
            'input_placeholder'=> 'Stel een vraag of typ "Zoek …" / "Taak: …"',
            'btn_send'         => 'Verzenden →',
            'welcome'          => "Hallo **{$username}**! 👋 Ik ben je lokale **{$appTitle}-Assistent**.\n\nStel me vragen over de cloud, je vrije **opslagruimte**, je **afspraken & taken** of zoek direct naar **bestanden, contacten en notities**!",
            'quick_welcome'    => "Hallo **{$username}**! 👋 Hoe kan ik je op deze pagina helpen?",
            'hello_reply'      => "Hallo **{$username}**! 🤖 Ik ben je **lokale Cloud-Assistent**.\n\nIk kan je helpen met:\n1. **📊 Live accountvragen:** *opslagruimte*, *afspraken*, *open taken*, *ongelezen berichten* of *bestanden zoeken*.\n2. **💡 Uitleg over modules:** *Office Light*, *E-Book-lezer (.epub)*, *PDF bewerken*, *OCR*, *WebDAV/CalDAV*, *Bestandskluis*.\n3. **⚡ Snelcommando’s:** Typ `Notitie: ...` of `Taak: ...`!",
            'thanks_reply'     => "Graag gedaan, **{$username}**! 😊 Vraag gerust als je nog iets wilt weten.",
            'q_storage'        => 'Hoeveel vrije opslagruimte heb ik nog?',
            'q_events'         => 'Welke afspraken staan er op mijn agenda?',
            'q_tasks'          => 'Welke taken staan er nog open?',
            'q_notes'          => 'Toon mijn laatste notities',
            'q_messages'       => 'Heb ik ongelezen berichten?',
            'q_office'         => 'Hoe werkt Office Light (Word & Excel)?',
            'q_ebooks'         => 'Hoe werkt de E-Book-lezer (.epub)?',
            'q_pdf'            => 'Hoe kan ik een PDF invullen, ondertekenen of zwartmaken?',
            'q_ocr'            => 'Hoe werkt lokale OCR-tekstherkenning?',
            'q_webdav'         => 'Hoe stel ik WebDAV, CalDAV of CardDAV in?',
            'q_vault'          => 'Hoe versleutel ik een bestand in de kluis?',
            'act_dashboard'    => '🏠 Mijn Dag',
            'act_files'        => '📂 Mijn Bestanden',
            'act_office'       => '📝 Office Light',
            'act_ebooks'       => '📚 E-Book-bibliotheek',
            'act_calendar'     => '📅 Agenda openen',
            'act_tasks'        => '📋 Takenbord openen',
            'act_notes'        => '📌 Notitiebord openen',
            'act_messages'     => '💬 Berichten openen',
            'act_trash'        => '🗑️ Prullenbak bekijken',
            'act_settings'     => '⚙️ Instellingen',
            'act_help'         => '📖 Handleiding',
            'note_created'     => '✅ Ik heb je nieuwe notitie **"%s"** direct op je 📌 notitiebord opgeslagen!',
            'task_created'     => '✅ Ik heb de taak **"%s"** aangemaakt op je 📋 takenbord!',
            'storage_title'    => '📊 **Je huidige cloud-opslagstatus (%s):**',
            'storage_used'     => 'Gebruikt',
            'storage_of'       => 'van',
            'storage_free'     => 'Nog vrij beschikbaar',
            'storage_files'    => 'Actieve bestanden',
            'storage_trash'    => 'In prullenbak',
            'events_empty'     => '📅 Je hebt momenteel **geen geplande afspraken** in je agenda.',
            'events_title'     => '📅 **Je eerstvolgende afspraken:**',
            'events_allday'    => 'Hele dag',
            'tasks_empty'      => '🎉 **Alles afgerond!** Je hebt momenteel geen openstaande taken.',
            'tasks_title'      => '📋 **Je openstaande taken (%d):**',
            'tasks_in_prog'    => '🔄 *Bezig*',
            'tasks_todo'       => '⏳ *Open*',
            'tasks_due'        => '📅 Einddatum',
            'tasks_tip'        => '💡 *Tip:* Typ `Taak: ...` om direct een taak aan te maken.',
            'msg_unread'       => '💬 Je hebt momenteel **%d ongelezen bericht(en)**!',
            'msg_empty'        => '💬 Je hebt momenteel **geen ongelezen berichten**.',
            'notes_empty'      => '📌 Je hebt nog geen notities. Typ `Notitie: Tekst` om er een te maken!',
            'notes_title'      => '📌 **Je huidige notities:**',
            'search_title'     => '🔎 **Zoekresultaten in je cloud voor "%s":**',
            'search_files_lbl' => '📄 **Bestand:**',
            'search_cont_lbl'  => '📇 **Contact:**',
            'search_note_lbl'  => '📌 **Notitie:**',
            'search_all_btn'   => '📂 Alle bestanden doorzoeken',
            'fallback_files'   => '🔎 Ik heb de volgende **bestanden voor "%s"** gevonden:',
            'fallback_reply'   => "Ik heb geen directe treffer gevonden voor deze vraag. 🤔\n\nJe kunt me vragen stellen over:\n- **📊 Je account live:** *\"Hoeveel vrije opslagruimte heb ik?\"*, *\"Welke afspraken heb ik?\"*, *\"Welke taken staan open?\"*\n- **📝 Modules:** *Office Light*, *E-Books (.epub)*, *PDF & Scan*, *OCR*, *WebDAV / CalDAV*\n- **⚡ Snelcommando’s:** `Notitie: ...` of `Taak: ...`",
        ],
        'pl' => [
            'page_title'       => '🤖 Asystent Chmury',
            'header_title'     => 'Asystent AI i Wiedzy MiniCloud',
            'header_badge'     => '🟢 100 % Lokalny i 11 Języków',
            'header_desc'      => 'Odpowiada na pytania o chmurę w 11 językach, przeszukuje na żywo pliki, terminy i zadania oraz tworzy notatki i zadania.',
            'btn_clear'        => '🔄 Wyczyść historię',
            'btn_help'         => '📖 Otwórz podręcznik',
            'lang_auto'        => '🌐 Automatycznie (wykryj język)',
            'sec_live'         => 'Zapytania na żywo o Twoje konto',
            'sec_modules'      => 'Częste pytania o moduły',
            'tip_title'        => '⚡ Wskazówka: Szybkie polecenia',
            'tip_body'         => '• <code>Szukaj faktura</code> przeszukuje pliki, kontakty i notatki.<br>• <code>Notatka: Zakupy…</code> tworzy od razu notatkę.<br>• <code>Zadanie: Podatki</code> tworzy nowe zadanie.',
            'input_placeholder'=> 'Zadaj pytanie lub wpisz „Szukaj …” / „Zadanie: …”',
            'btn_send'         => 'Wyślij →',
            'welcome'          => "Cześć **{$username}**! 👋 Jestem Twoim lokalnym **Asystentem {$appTitle}**.\n\nZapytaj mnie o obsługę chmury, wolne **miejsce na dysku**, nadchodzące **terminy i zadania** lub wyszukaj **pliki, kontakty i notatki**!",
            'quick_welcome'    => "Cześć **{$username}**! 👋 Jak mogę Ci pomóc na tej stronie?",
            'hello_reply'      => "Cześć **{$username}**! 🤖 Jestem Twoim **lokalnym Asystentem Chmury**.\n\nMogę Ci pomóc w:\n1. **📊 Informacjach na żywo:** *wolne miejsce*, *terminy w kalendarzu*, *otwarte zadania*, *nieprzeczytane wiadomości* oraz *wyszukiwanie plików*.\n2. **💡 Obsłudze modułów:** *Office Light*, *Czytnik E-Booków (.epub)*, *PDF i skaner*, *OCR*, *WebDAV/CalDAV*, *Sejf plików*.\n3. **⚡ Szybkich poleceniach:** Wpisz `Notatka: ...` lub `Zadanie: ...`!",
            'thanks_reply'     => "Proszę bardzo, **{$username}**! 😊 Jeśli masz jeszcze jakieś pytania, śmiało pisz.",
            'q_storage'        => 'Ile mam jeszcze wolnego miejsca?',
            'q_events'         => 'Jakie mam nadchodzące terminy?',
            'q_tasks'          => 'Jakie zadania są jeszcze otwarte?',
            'q_notes'          => 'Pokaż moje ostatnie notatki',
            'q_messages'       => 'Czy mam nieprzeczytane wiadomości?',
            'q_office'         => 'Jak działa Office Light (Word i Excel)?',
            'q_ebooks'         => 'Jak działa czytnik E-Booków (.epub)?',
            'q_pdf'            => 'Jak wypełnić, podpisać lub zamazać PDF?',
            'q_ocr'            => 'Jak działa lokalne rozpoznawanie tekstu OCR?',
            'q_webdav'         => 'Jak skonfigurować WebDAV, CalDAV lub CardDAV?',
            'q_vault'          => 'Jak zaszyfrować plik w sejfie?',
            'act_dashboard'    => '🏠 Mój Dzień',
            'act_files'        => '📂 Moje Pliki',
            'act_office'       => '📝 Office Light',
            'act_ebooks'       => '📚 Biblioteka E-Booków',
            'act_calendar'     => '📅 Otwórz kalendarz',
            'act_tasks'        => '📋 Otwórz zadania',
            'act_notes'        => '📌 Otwórz notatki',
            'act_messages'     => '💬 Otwórz wiadomości',
            'act_trash'        => '🗑️ Sprawdź kosz',
            'act_settings'     => '⚙️ Ustawienia',
            'act_help'         => '📖 Podręcznik pomocy',
            'note_created'     => '✅ Zapisałem Twoją nową notatkę **„%s”** na 📌 tablicy notatek!',
            'task_created'     => '✅ Utworzyłem zadanie **„%s”** na Twojej 📋 tablicy zadań!',
            'storage_title'    => '📊 **Aktualny stan pamięci w chmurze (%s):**',
            'storage_used'     => 'Zajęte',
            'storage_of'       => 'z',
            'storage_free'     => 'Wolne miejsce',
            'storage_files'    => 'Aktywne pliki',
            'storage_trash'    => 'W koszu',
            'events_empty'     => '📅 Obecnie **nie masz żadnych nadchodzących terminów** w kalendarzu.',
            'events_title'     => '📅 **Twoje najbliższe terminy:**',
            'events_allday'    => 'Cały dzień',
            'tasks_empty'      => '🎉 **Wszystko zrobione!** Nie masz obecnie otwartych zadań.',
            'tasks_title'      => '📋 **Twoje otwarte zadania (%d):**',
            'tasks_in_prog'    => '🔄 *W toku*',
            'tasks_todo'       => '⏳ *Otwarte*',
            'tasks_due'        => '📅 Termin',
            'tasks_tip'        => '💡 *Wskazówka:* Wpisz `Zadanie: ...`, aby od razu dodać zadanie.',
            'msg_unread'       => '💬 Masz obecnie **%d nieprzeczytaną(-e) wiadomość(-ci)**!',
            'msg_empty'        => '💬 Nie masz obecnie **żadnych nieprzeczytanych wiadomości**.',
            'notes_empty'      => '📌 Nie masz jeszcze notatek. Wpisz `Notatka: Twój tekst`, aby ją utworzyć!',
            'notes_title'      => '📌 **Twoje ostatnie notatki:**',
            'search_title'     => '🔎 **Wyniki wyszukiwania dla „%s”:**',
            'search_files_lbl' => '📄 **Plik:**',
            'search_cont_lbl'  => '📇 **Kontakt:**',
            'search_note_lbl'  => '📌 **Notatka:**',
            'search_all_btn'   => '📂 Przeszukaj wszystkie pliki',
            'fallback_files'   => '🔎 Znalazłem następujące **pliki dla „%s”**:',
            'fallback_reply'   => "Nie znalazłem bezpośredniej odpowiedzi na to pytanie. 🤔\n\nMożesz zapytać mnie o:\n- **📊 Twoje konto na żywo:** *„Ile mam wolnego miejsca?”*, *„Jakie mam terminy?”*, *„Jakie zadania są otwarte?”*\n- **📝 Moduły:** *Office Light*, *E-Booki (.epub)*, *PDF i skaner*, *OCR*, *WebDAV / CalDAV*\n- **⚡ Szybkie polecenia:** `Notatka: ...` lub `Zadanie: ...`",
        ],
        'pt' => [
            'page_title'       => '🤖 Assistente Cloud',
            'header_title'     => 'Assistente de IA e Conhecimento MiniCloud',
            'header_badge'     => '🟢 100 % Local e 11 Idiomas',
            'header_desc'      => 'Responde a perguntas sobre a nuvem em 11 idiomas, pesquisa ficheiros, compromissos e tarefas em tempo real e cria notas ou tarefas.',
            'btn_clear'        => '🔄 Limpar histórico',
            'btn_help'         => '📖 Abrir manual',
            'lang_auto'        => '🌐 Automático (detetar idioma)',
            'sec_live'         => 'Consultas em tempo real da sua conta',
            'sec_modules'      => 'Perguntas frequentes sobre módulos',
            'tip_title'        => '⚡ Dica: Comandos rápidos',
            'tip_body'         => '• <code>Procurar fatura</code> pesquisa ficheiros, contactos e notas.<br>• <code>Nota: Compras…</code> cria imediatamente uma nota.<br>• <code>Tarefa: Impostos</code> cria uma tarefa.',
            'input_placeholder'=> 'Faça uma pergunta ou digite «Procurar …» / «Tarefa: …»',
            'btn_send'         => 'Enviar →',
            'welcome'          => "Olá **{$username}**! 👋 Sou o seu **Assistente {$appTitle}** local.\n\nPergunte-me tudo sobre a nuvem, o seu **espaço de armazenamento**, **compromissos e tarefas** ou pesquise **ficheiros, contactos e notas**!",
            'quick_welcome'    => "Olá **{$username}**! 👋 Como posso ajudar nesta página?",
            'hello_reply'      => "Olá **{$username}**! 🤖 Sou o seu **Assistente Cloud local**.\n\nPosso ajudar com:\n1. **📊 Consultas ao vivo:** *espaço livre*, *compromissos*, *tarefas abertas*, *mensagens não lidas* ou *pesquisa de ficheiros*.\n2. **💡 Guias de módulos:** *Office Light*, *Leitor de E-Books (.epub)*, *PDF e Scanner*, *OCR*, *WebDAV/CalDAV*, *Cofre AES-256*.\n3. **⚡ Comandos rápidos:** Digite `Nota: ...` ou `Tarefa: ...`!",
            'thanks_reply'     => "De nada, **{$username}**! 😊 Sempre que precisar de ajuda, basta perguntar.",
            'q_storage'        => 'Quanto espaço de armazenamento livre ainda tenho?',
            'q_events'         => 'Quais são os meus próximos compromissos?',
            'q_tasks'          => 'Que tarefas estão abertas?',
            'q_notes'          => 'Mostrar as minhas últimas notas',
            'q_messages'       => 'Tenho mensagens não lidas?',
            'q_office'         => 'Como funciona o Office Light (Word e Excel)?',
            'q_ebooks'         => 'Como funciona o leitor de E-Books (.epub)?',
            'q_pdf'            => 'Como preencher, assinar ou ocultar texto num PDF?',
            'q_ocr'            => 'Como funciona o reconhecimento de texto OCR?',
            'q_webdav'         => 'Como configurar WebDAV, CalDAV ou CardDAV?',
            'q_vault'          => 'Como encriptar um ficheiro no cofre?',
            'act_dashboard'    => '🏠 Meu Dia',
            'act_files'        => '📂 Meus Ficheiros',
            'act_office'       => '📝 Office Light',
            'act_ebooks'       => '📚 Biblioteca E-Books',
            'act_calendar'     => '📅 Abrir calendário',
            'act_tasks'        => '📋 Abrir tarefas',
            'act_notes'        => '📌 Abrir notas',
            'act_messages'     => '💬 Abrir mensagens',
            'act_trash'        => '🗑️ Ver reciclagem',
            'act_settings'     => '⚙️ Definições',
            'act_help'         => '📖 Manual de ajuda',
            'note_created'     => '✅ Guardei a sua nova nota **«%s»** no seu 📌 quadro de notas!',
            'task_created'     => '✅ Criei a tarefa **«%s»** no seu 📋 quadro de tarefas!',
            'storage_title'    => '📊 **Estado atual do seu armazenamento (%s):**',
            'storage_used'     => 'Utilizado',
            'storage_of'       => 'de',
            'storage_free'     => 'Livre disponível',
            'storage_files'    => 'Ficheiros ativos',
            'storage_trash'    => 'Na reciclagem',
            'events_empty'     => '📅 Não tem **próximos compromissos** agendados.',
            'events_title'     => '📅 **Os seus próximos compromissos:**',
            'events_allday'    => 'Dia inteiro',
            'tasks_empty'      => '🎉 **Tudo concluído!** Não tem tarefas abertas.',
            'tasks_title'      => '📋 **As suas tarefas abertas (%d):**',
            'tasks_in_prog'    => '🔄 *Em curso*',
            'tasks_todo'       => '⏳ *Aberta*',
            'tasks_due'        => '📅 Prazo',
            'tasks_tip'        => '💡 *Dica:* Digite `Tarefa: ...` para criar uma tarefa.',
            'msg_unread'       => '💬 Tem **%d mensagem(ns) não lida(s)** na caixa de entrada!',
            'msg_empty'        => '💬 Não tem **mensagens não lidas** de momento.',
            'notes_empty'      => '📌 Ainda não tem notas. Digite `Nota: O seu texto` para criar uma!',
            'notes_title'      => '📌 **As suas últimas notas:**',
            'search_title'     => '🔎 **Resultados da pesquisa por «%s»:**',
            'search_files_lbl' => '📄 **Ficheiro:**',
            'search_cont_lbl'  => '📇 **Contacto:**',
            'search_note_lbl'  => '📌 **Nota:**',
            'search_all_btn'   => '📂 Pesquisar em todos os ficheiros',
            'fallback_files'   => '🔎 Encontrei os seguintes **ficheiros para «%s»**:',
            'fallback_reply'   => "Não encontrei uma correspondência direta. 🤔\n\nPode perguntar-me sobre:\n- **📊 Conta ao vivo:** *«Quanto espaço livre tenho?»*, *«Que compromissos tenho?»*, *«Que tarefas estão abertas?»*\n- **📝 Módulos:** *Office Light*, *E-Books (.epub)*, *PDF e Scanner*, *OCR*, *WebDAV / CalDAV*\n- **⚡ Comandos rápidos:** `Nota: ...` ou `Tarefa: ...`",
        ],
        'cs' => [
            'page_title'       => '🤖 Cloudový asistent',
            'header_title'     => 'AI a znalostní asistent MiniCloud',
            'header_badge'     => '🟢 100 % Lokální a 11 Jazyků',
            'header_desc'      => 'Odpovídá na dotazy ke cloudu v 11 jazycích, živě prohledává soubory, události a úkoly a vytváří poznámky či úkoly.',
            'btn_clear'        => '🔄 Vymazat historii',
            'btn_help'         => '📖 Otevřít příručku',
            'lang_auto'        => '🌐 Automaticky (rozpoznat jazyk)',
            'sec_live'         => 'Živé dotazy k vašemu účtu',
            'sec_modules'      => 'Časté dotazy k modulům',
            'tip_title'        => '⚡ Tip: Rychlé příkazy v chatu',
            'tip_body'         => '• <code>Hledat faktura</code> prohledá soubory, kontakty a poznámky.<br>• <code>Poznámka: Nákup…</code> ihned vytvoří poznámku.<br>• <code>Úkol: Daně</code> ihned vytvoří úkol.',
            'input_placeholder'=> 'Položte dotaz nebo napište „Hledat …“ / „Úkol: …“',
            'btn_send'         => 'Odeslat →',
            'welcome'          => "Ahoj **{$username}**! 👋 Jsem tvůj lokální **asistent {$appTitle}**.\n\nZeptej se mě na cokoliv ohledně cloudu, volného **úložiště**, **událostí a úkolů** nebo vyhledej **soubory, kontakty a poznámky**!",
            'quick_welcome'    => "Ahoj **{$username}**! 👋 Jak ti mohu na této stránce pomoci?",
            'hello_reply'      => "Ahoj **{$username}**! 🤖 Jsem tvůj **lokální cloudový asistent**.\n\nMohu ti pomoci třemi způsoby:\n1. **📊 Živé dotazy k účtu:** *volné místo*, *události v kalendáři*, *otevřené úkoly*, *nepřečtené zprávy* nebo *hledání souborů*.\n2. **💡 Nápověda k modulům:** *Office Light*, *Čtečka E-knih (.epub)*, *PDF a skener*, *OCR*, *WebDAV/CalDAV*, *Trezor souborů*.\n3. **⚡ Rychlé příkazy:** Napiš `Poznámka: ...` nebo `Úkol: ...`!",
            'thanks_reply'     => "Rádo se stalo, **{$username}**! 😊 Kdykoliv se znovu zeptej.",
            'q_storage'        => 'Kolik volného místa mi ještě zbývá?',
            'q_events'         => 'Jaké události mě čekají?',
            'q_tasks'          => 'Které úkoly mám ještě otevřené?',
            'q_notes'          => 'Ukaž moje poslední poznámky',
            'q_messages'       => 'Mám nepřečtené zprávy?',
            'q_office'         => 'Jak funguje Office Light (Word a Excel)?',
            'q_ebooks'         => 'Jak funguje čtečka E-knih (.epub)?',
            'q_pdf'            => 'Jak vyplnit, podepsat nebo začernit PDF?',
            'q_ocr'            => 'Jak funguje lokální rozpoznávání textu OCR?',
            'q_webdav'         => 'Jak nastavit WebDAV, CalDAV nebo CardDAV?',
            'q_vault'          => 'Jak zašifrovat soubor v trezoru?',
            'act_dashboard'    => '🏠 Můj den',
            'act_files'        => '📂 Moje soubory',
            'act_office'       => '📝 Office Light',
            'act_ebooks'       => '📚 Knihovna E-knih',
            'act_calendar'     => '📅 Otevřít kalendář',
            'act_tasks'        => '📋 Otevřít úkoly',
            'act_notes'        => '📌 Otevřít poznámky',
            'act_messages'     => '💬 Otevřít zprávy',
            'act_trash'        => '🗑️ Zkontrolovat koš',
            'act_settings'     => '⚙️ Nastavení',
            'act_help'         => '📖 Uživatelská příručka',
            'note_created'     => '✅ Uložil jsem tvou novou poznámku **„%s“** na 📌 nástěnku!',
            'task_created'     => '✅ Vytvořil jsem úkol **„%s“** na tvé 📋 nástěnce úkolů!',
            'storage_title'    => '📊 **Aktuální stav tvého úložiště (%s):**',
            'storage_used'     => 'Využito',
            'storage_of'       => 'z',
            'storage_free'     => 'Volné místo',
            'storage_files'    => 'Aktivní soubory',
            'storage_trash'    => 'V koši',
            'events_empty'     => '📅 Aktuálně **nemáš žádné nadcházející události** v kalendáři.',
            'events_title'     => '📅 **Tvoje nejbližší události:**',
            'events_allday'    => 'Celý den',
            'tasks_empty'      => '🎉 **Vše hotovo!** Nemáš žádné otevřené úkoly.',
            'tasks_title'      => '📋 **Tvoje otevřené úkoly (%d):**',
            'tasks_in_prog'    => '🔄 *Probíhá*',
            'tasks_todo'       => '⏳ *Otevřeno*',
            'tasks_due'        => '📅 Termín',
            'tasks_tip'        => '💡 *Tip:* Napiš `Úkol: ...` pro okamžité vytvoření úkolu.',
            'msg_unread'       => '💬 Máš **%d nepřečtených zpráv**!',
            'msg_empty'        => '💬 Momentálně **nemáš žádné nepřečtené zprávy**.',
            'notes_empty'      => '📌 Zatím nemáš žádné poznámky. Napiš `Poznámka: Text`!',
            'notes_title'      => '📌 **Tvoje aktuální poznámky:**',
            'search_title'     => '🔎 **Výsledky vyhledávání pro „%s“:**',
            'search_files_lbl' => '📄 **Soubor:**',
            'search_cont_lbl'  => '📇 **Kontakt:**',
            'search_note_lbl'  => '📌 **Poznámka:**',
            'search_all_btn'   => '📂 Prohledat všechny soubory',
            'fallback_files'   => '🔎 Našel jsem následující **soubory pro „%s“**:',
            'fallback_reply'   => "Pro tuto formulaci jsem nenašel přímou odpověď. 🤔\n\nMůžeš se mě zeptat na:\n- **📊 Tvůj účet živě:** *„Kolik volného místa mi zbývá?“*, *„Jaké události mě čekají?“*, *„Které úkoly jsou otevřené?“*\n- **📝 Moduly:** *Office Light*, *E-knihy (.epub)*, *PDF a skener*, *OCR*, *WebDAV / CalDAV*\n- **⚡ Rychlé příkazy:** `Poznámka: ...` nebo `Úkol: ...`",
        ],
        'tr' => [
            'page_title'       => '🤖 Bulut Asistanı',
            'header_title'     => 'MiniCloud Yapay Zeka ve Bilgi Asistanı',
            'header_badge'     => '🟢 %100 Yerel ve 11 Dil',
            'header_desc'      => 'Bulut hakkındaki soruları 11 dilde yanıtlar, dosyalarınızı, randevularınızı ve görevlerinizi canlı arar ve hızlı komutlarla not veya görev oluşturur.',
            'btn_clear'        => '🔄 Geçmişi temizle',
            'btn_help'         => '📖 Kılavuzu aç',
            'lang_auto'        => '🌐 Otomatik (dili algıla)',
            'sec_live'         => 'Hesabınızla ilgili canlı sorgular',
            'sec_modules'      => 'Modüller hakkında sık sorulanlar',
            'tip_title'        => '⚡ İpucu: Hızlı sohbet komutları',
            'tip_body'         => '• <code>Ara fatura</code> dosyaları, kişileri ve notları tarar.<br>• <code>Not: Alışveriş…</code> hemen yapışkan not ekler.<br>• <code>Görev: Vergi</code> hemen görev oluşturur.',
            'input_placeholder'=> 'Soru sorun veya "Ara …" / "Görev: …" yazın…',
            'btn_send'         => 'Gönder →',
            'welcome'          => "Merhaba **{$username}**! 👋 Ben yerel **{$appTitle} Asistanınızım**.\n\nBana bulut kullanımı, boş **depolama alanınız**, yaklaşan **randevu ve görevleriniz** hakkında soru sorabilir veya **dosya, kişi ve not** arayabilirsiniz!",
            'quick_welcome'    => "Merhaba **{$username}**! 👋 Bu sayfada size nasıl yardımcı olabilirim?",
            'hello_reply'      => "Merhaba **{$username}**! 🤖 Ben **yerel Bulut Asistanınızım**.\n\nSize 3 şekilde yardımcı olabilirim:\n1. **📊 Canlı hesap bilgileri:** *boş depolama alanı*, *randevular*, *açık görevler*, *okunmamış mesajlar* veya *dosya arama*.\n2. **💡 Modül rehberleri:** *Office Light*, *E-Kitap Okuyucu (.epub)*, *PDF düzenleme ve karartma*, *OCR*, *WebDAV/CalDAV*, *Şifreli Kasa*.\n3. **⚡ Hızlı komutlar:** `Not: ...` veya `Görev: ...` yazın!",
            'thanks_reply'     => "Rica ederim, **{$username}**! 😊 Başka bir sorunuz olursa her zaman yazabilirsiniz.",
            'q_storage'        => 'Ne kadar boş depolama alanım kaldı?',
            'q_events'         => 'Yaklaşan hangi randevularım var?',
            'q_tasks'          => 'Hangi görevlerim hâlâ açık?',
            'q_notes'          => 'Son notlarımı göster',
            'q_messages'       => 'Okunmamış mesajım var mı?',
            'q_office'         => 'Office Light (Word ve Excel) nasıl çalışır?',
            'q_ebooks'         => 'E-Kitap Okuyucu (.epub) nasıl çalışır?',
            'q_pdf'            => 'Bir PDF dosyasını nasıl doldurur, imzalar veya karartırım?',
            'q_ocr'            => 'Yerel OCR metin tanıma nasıl çalışır?',
            'q_webdav'         => 'WebDAV, CalDAV veya CardDAV nasıl kurulur?',
            'q_vault'          => 'Kasada bir dosyayı nasıl şifrelerim?',
            'act_dashboard'    => '🏠 Günüm',
            'act_files'        => '📂 Dosyalarım',
            'act_office'       => '📝 Office Light',
            'act_ebooks'       => '📚 E-Kitaplığı Aç',
            'act_calendar'     => '📅 Takvimi aç',
            'act_tasks'        => '📋 Görevleri aç',
            'act_notes'        => '📌 Notları aç',
            'act_messages'     => '💬 Mesajları aç',
            'act_trash'        => '🗑️ Çöp kutusu',
            'act_settings'     => '⚙️ Ayarlar',
            'act_help'         => '📖 Yardım Kılavuzu',
            'note_created'     => '✅ Yeni notunuz **"%s"** 📌 not panonuza kaydedildi!',
            'task_created'     => '✅ **"%s"** görevi 📋 görev panonuza eklendi!',
            'storage_title'    => '📊 **Mevcut bulut depolama durumunuz (%s):**',
            'storage_used'     => 'Kullanılan',
            'storage_of'       => '/',
            'storage_free'     => 'Boş kullanılabilir alan',
            'storage_files'    => 'Aktif dosyalar',
            'storage_trash'    => 'Çöp kutusunda',
            'events_empty'     => '📅 Takviminizde şu anda **yaklaşan bir randevu yok**.',
            'events_title'     => '📅 **Yaklaşan randevularınız:**',
            'events_allday'    => 'Tüm gün',
            'tasks_empty'      => '🎉 **Hepsi tamamlandı!** Şu anda açık göreviniz yok.',
            'tasks_title'      => '📋 **Açık görevleriniz (%d):**',
            'tasks_in_prog'    => '🔄 *Devam ediyor*',
            'tasks_todo'       => '⏳ *Açık*',
            'tasks_due'        => '📅 Son tarih',
            'tasks_tip'        => '💡 *İpucu:* Doğrudan görev eklemek için `Görev: ...` yazın.',
            'msg_unread'       => '💬 Gelen kutunuzda **%d okunmamış mesaj** var!',
            'msg_empty'        => '💬 Şu anda **okunmamış mesajınız yok**.',
            'notes_empty'      => '📌 Henüz notunuz yok. Oluşturmak için `Not: Metniniz` yazın!',
            'notes_title'      => '📌 **Güncel yapışkan notlarınız:**',
            'search_title'     => '🔎 **"%s" için bulut arama sonuçları:**',
            'search_files_lbl' => '📄 **Dosya:**',
            'search_cont_lbl'  => '📇 **Kişi:**',
            'search_note_lbl'  => '📌 **Not:**',
            'search_all_btn'   => '📂 Tüm dosyalarda ara',
            'fallback_files'   => '🔎 **"%s"** ile eşleşen şu dosyaları buldum:',
            'fallback_reply'   => "Bu ifadeyle doğrudan bir eşleşme bulamadım. 🤔\n\nBana şunları sorabilirsiniz:\n- **📊 Canlı hesabınız:** *\"Ne kadar boş alanım var?\"*, *\"Hangi randevularım var?\"*, *\"Hangi görevler açık?\"*\n- **📝 Modüller:** *Office Light*, *E-Kitap (.epub)*, *PDF ve Tarayıcı*, *OCR*, *WebDAV / CalDAV*\n- **⚡ Hızlı komutlar:** `Not: ...` veya `Görev: ...`",
        ],
        'uk' => [
            'page_title'       => '🤖 Хмарний асистент',
            'header_title'     => 'ШІ- та довідковий асистент MiniCloud',
            'header_badge'     => '🟢 100 % Локальний та 11 мов',
            'header_desc'      => 'Відповідає на запитання про хмару 11 мовами, миттєво шукає ваші файли, події та завдання і створює нотатки або завдання.',
            'btn_clear'        => '🔄 Очистити історію',
            'btn_help'         => '📖 Відкрити посібник',
            'lang_auto'        => '🌐 Автоматично (визначати мову)',
            'sec_live'         => 'Живі запити до вашого облікового запису',
            'sec_modules'      => 'Часті запитання про модулі',
            'tip_title'        => '⚡ Порада: Швидкі команди в чаті',
            'tip_body'         => '• <code>Пошук рахунок</code> шукає у файлах, контактах і нотатках.<br>• <code>Нотатка: Покупки…</code> одразу створює стікер.<br>• <code>Завдання: Звіт</code> одразу створює завдання.',
            'input_placeholder'=> 'Поставте запитання або введіть «Пошук …» / «Завдання: …»',
            'btn_send'         => 'Надіслати →',
            'welcome'          => "Привіт, **{$username}**! 👋 Я твій локальний **асистент {$appTitle}**.\n\nЗапитуй мене про користування хмарою, вільне **місце у сховищі**, **події та завдання** або шукай **файли, контакти та нотатки**!",
            'quick_welcome'    => "Привіт, **{$username}**! 👋 Як я можу допомогти на цій сторінці?",
            'hello_reply'      => "Привіт, **{$username}**! 🤖 Я твій **локальний хмарний асистент**.\n\nЯ можу допомогти у 3 напрямках:\n1. **📊 Живі дані облікового запису:** *вільне місце*, *події календаря*, *відкриті завдання*, *непрочитані повідомлення* або *пошук файлів*.\n2. **💡 Довідка по модулях:** *Office Light*, *Електронні книги (.epub)*, *PDF та сканер*, *OCR-розпізнавання*, *WebDAV/CalDAV*, *Сейф файлів*.\n3. **⚡ Швидкі команди:** Напиши `Нотатка: ...` або `Завдання: ...`!",
            'thanks_reply'     => "Будь ласка, **{$username}**! 😊 Звертайся будь-коли, якщо потрібна допомога.",
            'q_storage'        => 'Скільки вільного місця у мене залишилося?',
            'q_events'         => 'Які події у мене заплановані?',
            'q_tasks'          => 'Які завдання у мене ще відкриті?',
            'q_notes'          => 'Покажи мої останні нотатки',
            'q_messages'       => 'Чи є у мене непрочитані повідомлення?',
            'q_office'         => 'Як працює Office Light (Word та Excel)?',
            'q_ebooks'         => 'Як працює рідер електронних книг (.epub)?',
            'q_pdf'            => 'Як заповнити, підписати або замалювати PDF?',
            'q_ocr'            => 'Як працює локальне OCR-розпізнавання тексту?',
            'q_webdav'         => 'Як налаштувати WebDAV, CalDAV або CardDAV?',
            'q_vault'          => 'Як зашифрувати файл у сейфі?',
            'act_dashboard'    => '🏠 Мій день',
            'act_files'        => '📂 Мої файли',
            'act_office'       => '📝 Office Light',
            'act_ebooks'       => '📚 Бібліотека E-Books',
            'act_calendar'     => '📅 Відкрити календар',
            'act_tasks'        => '📋 Відкрити завдання',
            'act_notes'        => '📌 Відкрити нотатки',
            'act_messages'     => '💬 Відкрити чат',
            'act_trash'        => '🗑️ Перевірити кошик',
            'act_settings'     => '⚙️ Налаштування',
            'act_help'         => '📖 Довідник користувача',
            'note_created'     => '✅ Я зберіг твою нову нотатку **«%s»** на 📌 дошці нотаток!',
            'task_created'     => '✅ Я створив завдання **«%s»** на твоїй 📋 дошці завдань!',
            'storage_title'    => '📊 **Поточний стан твого хмарного сховища (%s):**',
            'storage_used'     => 'Використано',
            'storage_of'       => 'з',
            'storage_free'     => 'Вільно доступно',
            'storage_files'    => 'Активні файли',
            'storage_trash'    => 'У кошику',
            'events_empty'     => '📅 Наразі у твоєму календарі **немає запланованих подій**.',
            'events_title'     => '📅 **Твої найближчі події:**',
            'events_allday'    => 'Весь день',
            'tasks_empty'      => '🎉 **Усе виконано!** У тебе немає відкритих завдань.',
            'tasks_title'      => '📋 **Твої відкриті завдання (%d):**',
            'tasks_in_prog'    => '🔄 *В роботі*',
            'tasks_todo'       => '⏳ *Відкрито*',
            'tasks_due'        => '📅 Термін',
            'tasks_tip'        => '💡 *Порада:* Напиши `Завдання: ...`, щоб одразу створити завдання.',
            'msg_unread'       => '💬 У тебе **%d непрочитаних повідомлень** у вхідних!',
            'msg_empty'        => '💬 Наразі у тебе **немає непрочитаних повідомлень**.',
            'notes_empty'      => '📌 У тебе ще немає нотаток. Напиши `Нотатка: Твій текст`, щоб створити!',
            'notes_title'      => '📌 **Твої поточні нотатки:**',
            'search_title'     => '🔎 **Результати пошуку у хмарі для «%s»:**',
            'search_files_lbl' => '📄 **Файл:**',
            'search_cont_lbl'  => '📇 **Контакт:**',
            'search_note_lbl'  => '📌 **Нотатка:**',
            'search_all_btn'   => '📂 Шукати в усіх файлах',
            'fallback_files'   => '🔎 Я знайшов такі **файли за запитом «%s»**:',
            'fallback_reply'   => "Я ще не знайшов прямої відповіді на це формулювання. 🤔\n\nТи можеш запитати мене про:\n- **📊 Твій обліковий запис:** *«Скільки вільного місця залишилося?»*, *«Які події заплановані?»*, *«Які завдання відкриті?»*\n- **📝 Модулі:** *Office Light*, *Електронні книги (.epub)*, *PDF та сканер*, *OCR*, *WebDAV / CalDAV*\n- **⚡ Швидкі команди:** `Нотатка: ...` або `Завдання: ...`",
        ],
    ];

    return $packs[$lang] ?? $packs['de'];
}

/**
 * Liefert lokalisierte Titel und Erklärungen für Wissensdatenbank-Einträge
 * in Nicht-Deutsch-Sprachen (en, fr, es, it, nl, pl, pt, cs, tr, uk).
 *
 * @return array{title: string, answer: string}|null
 */
function getLocalizedKbTranslation(string $id, string $lang, string $appTitle): ?array
{
    if ($lang === 'de') {
        return null;
    }

    // Kompakte, präzise mehrsprachige Modul-Erklärungen für alle 10 weiteren Sprachen
    $translations = [
        'upload_files' => [
            'en' => ['📤 Upload files & folders', "How to upload **files or entire folders** to **{$appTitle}**:\n\n1. Open **📂 My Files** (`index.php`).\n2. Drag & drop files or a complete folder into the upload dropzone – or click **Choose files** / **Choose folder**.\n3. The upload starts **immediately and automatically** in the background while keeping your subfolder structure intact."],
            'fr' => ['📤 Téléverser des fichiers & dossiers', "Pour téléverser des **fichiers ou des dossiers entiers** dans **{$appTitle}** :\n\n1. Ouvrez **📂 Mes Fichiers** (`index.php`).\n2. Glissez-déposez vos fichiers ou dossiers dans la zone d'envoi.\n3. Le transfert démarre **automatiquement** en conservant toute l'arborescence des sous-dossiers."],
            'es' => ['📤 Subir archivos y carpetas', "Cómo subir **archivos o carpetas completas** a **{$appTitle}**:\n\n1. Abre **📂 Mis Archivos** (`index.php`).\n2. Arrastra y suelta archivos o carpetas en la zona superior.\n3. La subida comienza **automáticamente** manteniendo toda la estructura de subcarpetas."],
            'it' => ['📤 Caricare file e cartelle', "Come caricare **file o intere cartelle** su **{$appTitle}**:\n\n1. Apri **📂 I miei file** (`index.php`).\n2. Trascina file o cartelle nell'area di caricamento superiore.\n3. Il caricamento parte **automaticamente** mantenendo intatta la struttura delle sottocartelle."],
            'nl' => ['📤 Bestanden & mappen uploaden', "Zo upload je **bestanden of complete mappen** naar **{$appTitle}**:\n\n1. Open **📂 Mijn Bestanden** (`index.php`).\n2. Sleep bestanden of een map naar het uploadveld.\n3. De upload start **direct automatisch** met behoud van alle submappen."],
            'pl' => ['📤 Przesyłanie plików i folderów', "Jak przesłać **pliki lub całe foldery** do **{$appTitle}**:\n\n1. Otwórz **📂 Moje Pliki** (`index.php`).\n2. Przeciągnij i upuść pliki lub folder w górne pole przesyłania.\n3. Przesyłanie rozpocznie się **automatycznie** z zachowaniem struktury podfolderów."],
            'pt' => ['📤 Carregar ficheiros e pastas', "Como carregar **ficheiros ou pastas inteiras** para **{$appTitle}**:\n\n1. Abra **📂 Meus Ficheiros** (`index.php`).\n2. Arraste e largue ficheiros ou pastas na zona de carregamento.\n3. O envio inicia **automaticamente** mantendo a estrutura de subpastas."],
            'cs' => ['📤 Nahrávání souborů a složek', "Jak nahrát **soubory nebo celé složky** do **{$appTitle}**:\n\n1. Otevři **📂 Moje soubory** (`index.php`).\n2. Přetáhni soubory nebo složku myší do horního pole.\n3. Nahrávání začne **okamžitě automaticky** se zachováním podsložek."],
            'tr' => ['📤 Dosya ve klasör yükleme', "**{$appTitle}**'a **dosya veya klasör** yüklemek için:\n\n1. **📂 Dosyalarım** (`index.php`) sayfasını açın.\n2. Dosyaları veya klasörü üstteki alana sürükleyip bırakın.\n3. Yükleme alt klasör yapısını koruyarak **otomatik olarak** başlar."],
            'uk' => ['📤 Завантаження файлів і папок', "Як завантажити **файли або цілі папки** у **{$appTitle}**:\n\n1. Відкрийте **📂 Мої файли** (`index.php`).\n2. Перетягніть файли чи папку у верхнє поле завантаження.\n3. Завантаження почнеться **автоматично** зі збереженням усіх підпапок."],
        ],
        'share_files' => [
            'en' => ['🔗 Share files & folders (Links & internal)', "You can share **files and folders** in two ways:\n\n- **🌐 Public share link (for guests without an account):** Click the **Share icon (🔗)** next to a file or folder. You can set a **password**, **expiration date**, **download limit**, and generate a **QR code**.\n- **👥 Internally with cloud users or groups:** Select any user or group via the live search filter and choose **👁️ Read-only** or **✏️ Read & Write** permissions."],
            'fr' => ['🔗 Partager des fichiers & dossiers', "Partagez vos **fichiers et dossiers** de deux façons :\n\n- **🌐 Lien public (avec mot de passe, date d'expiration, limite & QR code)** via l'icône **🔗**.\n- **👥 En interne avec des utilisateurs ou groupes** avec droits *Lecture seule* ou *Lecture & Écriture*."],
            'es' => ['🔗 Compartir archivos y carpetas', "Comparte **archivos y carpetas** de dos maneras:\n\n- **🌐 Enlace público (con contraseña, caducidad, límite y código QR)** pulsando el icono **🔗**.\n- **👥 Internamente con usuarios o grupos** con permisos de *Solo lectura* o *Lectura y escritura*."],
            'it' => ['🔗 Condividere file e cartelle', "Puoi condividere **file e cartelle** in due modi:\n\n- **🌐 Link pubblico (con password, scadenza, limite download e QR code)** tramite l'icona **🔗**.\n- **👥 Internamente con utenti o gruppi** con permessi di *Sola lettura* o *Lettura e scrittura*."],
            'nl' => ['🔗 Bestanden & mappen delen', "Deel **bestanden en mappen** op twee manieren:\n\n- **🌐 Openbare deellink (met wachtwoord, vervaldatum, downloadlimiet & QR-code)** via het **🔗**-icoon.\n- **👥 Intern met gebruikers of groepen** met *Alleen lezen* of *Lezen & schrijven*."],
            'pl' => ['🔗 Udostępnianie plików i folderów', "Udostępniaj **pliki i foldery** na dwa sposoby:\n\n- **🌐 Publiczny link (z hasłem, datą ważności, limitem pobrań i kodem QR)** klikając ikonę **🔗**.\n- **👥 Wewnętrznie użytkownikom lub grupom** (*Tylko odczyt* lub *Odczyt i zapis*)."],
            'pt' => ['🔗 Partilhar ficheiros e pastas', "Partilhe **ficheiros e pastas** de duas formas:\n\n- **🌐 Link público (com palavra-passe, validade, limite e código QR)** clicando em **🔗**.\n- **👥 Internamente com utilizadores ou grupos** (*Apenas leitura* ou *Leitura e escrita*)."],
            'cs' => ['🔗 Sdílení souborů a složek', "Sdílej **soubory a složky** dvěma způsoby:\n\n- **🌐 Veřejný odkaz (s heslem, platností, limitem stažení a QR kódem)** přes ikonu **🔗**.\n- **👥 Interně s uživateli nebo skupinami** (*Jen pro čtení* nebo *Čtení a zápis*)."],
            'tr' => ['🔗 Dosya ve klasör paylaşımı', "**Dosya ve klasörleri** iki şekilde paylaşabilirsiniz:\n\n- **🌐 Herkese açık bağlantı (şifre, son kullanma tarihi, indirme sınırı ve QR kodlu)** için **🔗** simgesine tıklayın.\n- **👥 Bulut kullanıcıları veya gruplarla** (*Salt okunur* veya *Okuma ve Yazma*)."],
            'uk' => ['🔗 Спільний доступ до файлів і папок', "Діліться **файлами та папками** двома способами:\n\n- **🌐 Публічне посилання (з паролем, терміном дії, лімітом завантажень і QR-кодом)** через значок **🔗**.\n- **👥 Всередині хмари з користувачами чи групами** (*Тільки читання* або *Читання та запис*)."],
        ],
        'office_light' => [
            'en' => ['📝 Office Light (Word, Excel, PowerPoint, Visio, Mind Maps & Photo Studio)', "**📝 Office Light** (`office.php`) is your 6-in-1 office & media suite right in your browser:\n\n- **📘 Word documents (`.docx`):** Rich formatting, headings, tables, lists & print/PDF view.\n- **📗 Excel spreadsheets (`.xlsx` & `.csv`):** Multi-sheet support, formulas (`=SUM(A1:A10)`, `=AVERAGE(...)`, `=MIN(...)`, `=MAX(...)`, `=COUNT(...)`, `=ROUND(...)`, `=IF(...)`), sorting & charts.\n- **📙 PowerPoint (`.pptx`) & 📐 Visio (`.drawio`):** 16:9 slide decks with fullscreen beamer mode + flowcharts & org charts.\n- **🎨 Photo & Image Studio (`.jpg`, `.png`, `.webp`):** Crop, rotate, resize/compress with live size preview, photo filters, watermark & GDPR pixelation brush.\n- **🟢 Live team co-editing:** Multiple users can edit the same document simultaneously!"],
            'fr' => ['📝 Office Light (Word, Excel, PowerPoint, Visio, Studio Photo & Co-édition)', "**📝 Office Light** (`office.php`) permet de créer et modifier vos documents Word (`.docx`), tableaux Excel (`.xlsx`, `.csv`), présentations PowerPoint (`.pptx`), diagrammes Visio (`.drawio`), Mind-Maps ainsi que vos photos dans le **🎨 Studio Photo**."],
            'es' => ['📝 Office Light (Word, Excel, PowerPoint, Visio, Estudio Fotográfico y Co-edición)', "**📝 Office Light** (`office.php`) es tu suite ofimática 6 en 1 en el navegador: documentos Word (`.docx`), hojas Excel (`.xlsx`, `.csv`), presentaciones PowerPoint (`.pptx`), diagramas Visio (`.drawio`), mapas mentales y **🎨 Estudio Fotográfico**."],
            'it' => ['📝 Office Light (Word, Excel, PowerPoint, Visio, Studio Foto & Co-editing)', "**📝 Office Light** (`office.php`) ti permette di modificare documenti Word (`.docx`), fogli Excel (`.xlsx`, `.csv`), presentazioni PowerPoint (`.pptx`), diagrammi Visio (`.drawio`), mappe mentali e immagini nello **🎨 Studio Foto**."],
            'nl' => ['📝 Office Light (Word, Excel, PowerPoint, Visio, Fotostudio & Samenwerken)', "**📝 Office Light** (`office.php`) is je 6-in-1 kantooromgeving in de browser voor Word (`.docx`), Excel (`.xlsx`, `.csv`), PowerPoint (`.pptx`), Visio-diagrammen (`.drawio`), Mindmaps en **🎨 Fotostudio**."],
            'pl' => ['📝 Office Light (Word, Excel, PowerPoint, Visio, Studio Zdjęć i Współedycja)', "**📝 Office Light** (`office.php`) pozwala edytować dokumenty Word (`.docx`), arkusze Excel (`.xlsx`, `.csv`), prezentacje PowerPoint (`.pptx`), diagramy Visio (`.drawio`), mapy myśli oraz zdjęcia w **🎨 Studiu Zdjęć**."],
            'pt' => ['📝 Office Light (Word, Excel, PowerPoint, Visio, Estúdio de Fotos e Coedição)', "**📝 Office Light** (`office.php`) permite editar documentos Word (`.docx`), folhas Excel (`.xlsx`, `.csv`), apresentações PowerPoint (`.pptx`), diagramas Visio (`.drawio`), mapas mentais e imagens no **🎨 Estúdio de Fotos**."],
            'cs' => ['📝 Office Light (Word, Excel, PowerPoint, Visio, Foto Studio a živá spolupráce)', "**📝 Office Light** (`office.php`) umožňuje upravovat dokumenty Word (`.docx`), tabulky Excel (`.xlsx`, `.csv`), prezentace PowerPoint (`.pptx`), diagramy Visio (`.drawio`), myšlenkové mapy a fotky ve **🎨 Foto Studiu**."],
            'tr' => ['📝 Office Light (Word, Excel, PowerPoint, Visio, Fotoğraf Stüdyosu ve Ortak Çalışma)', "**📝 Office Light** (`office.php`) tarayıcıda Word (`.docx`), Excel (`.xlsx`, `.csv`), PowerPoint (`.pptx`), Visio diyagramları (`.drawio`), Zihin Haritaları ve **🎨 Fotoğraf Stüdyosu** ile resimleri düzenlemenizi sağlar."],
            'uk' => ['📝 Office Light (Word, Excel, PowerPoint, Visio, Фотостудія та спільна робота)', "**📝 Office Light** (`office.php`) дозволяє редагувати документи Word (`.docx`), таблиці Excel (`.xlsx`, `.csv`), презентації PowerPoint (`.pptx`), діаграми Visio (`.drawio`), інтелект-карти та фото у **🎨 Фотостудії**."],
        ],
        'image_studio' => [
            'en' => ['🎨 Photo & Image Studio (Crop, Resize, Filter & GDPR Pixelation)', "With the integrated **🎨 Photo & Image Studio** in **Office Light** (`office.php`) you can edit any image (`.jpg`, `.png`, `.webp`, `.gif`, `.bmp`) 100% locally in your browser:\n\n- **✂️ Crop, Rotate & Flip:** Rotate 90° left/right, flip horizontally/vertically, or crop freely / with fixed aspect ratios (`1:1`, `16:9`, `4:3`, `3:2`, `DIN A4`).\n- **📐 Resize & Compress:** Scale via 1-click presets (`75%`, `50%`, `Full-HD 1920px`, `Web 1280px`, `Compact 800px`), convert between `JPEG`, `WebP`, and `PNG`, and adjust quality with a **live file size estimate (`KB/MB`)**.\n- **☀️ Exposure & 1-Click Filters:** Adjust brightness, contrast, saturation, sepia, or apply presets (*Auto Brilliance, B/W, Vintage, Warm, Document Contrast*).\n- **🔲 GDPR Pixelation & Watermark:** Use the pixelation or blur brush to irreversibly anonymize faces, license plates, or private data before sharing, or add arrows, frames, and text watermarks. Save either as a new version of the original (with `🕓` version rollback) or as a new copy!"],
            'fr' => ['🎨 Studio Photo & Image (Recadrer, Compresser, Filtres & Pixelisation RGPD)', "Le **🎨 Studio Photo & Image** intégré à **Office Light** (`office.php`) permet d'éditer vos photos (`.jpg`, `.png`, `.webp`) localement dans le navigateur : recadrage (`1:1`, `16:9`, `4:3`), rotation, redimensionnement & compression (`JPEG`, `WebP`, `PNG`), filtres photo, filigrane texte et **🔲 pinceau de pixelisation RGPD**."],
            'es' => ['🎨 Estudio de Fotos e Imágenes (Recortar, Comprimir, Filtros y Pixelado RGPD)', "El **🎨 Estudio de Fotos** integrado en **Office Light** (`office.php`) permite editar imágenes (`.jpg`, `.png`, `.webp`) en el navegador: recortar (`1:1`, `16:9`, `4:3`), girar, cambiar tamaño y comprimir (`JPEG`, `WebP`, `PNG`), filtros de color, marca de agua y **🔲 pincel de pixelado RGPD**."],
            'it' => ['🎨 Studio Foto & Immagini (Ritaglia, Comprimi, Filtri & Pixelatura GDPR)', "Lo **🎨 Studio Foto** in **Office Light** (`office.php`) permette di modificare foto (`.jpg`, `.png`, `.webp`) nel browser: ritaglio, rotazione, ridimensionamento e compressione (`JPEG`, `WebP`, `PNG`), filtri fotografici, filigrana e **🔲 pennello di pixelatura GDPR**."],
            'nl' => ['🎨 Foto- & Beeldstudio (Bijsnijden, Comprimeren, Filters & AVG-Pixelatie)', "Met de **🎨 Foto- & Beeldstudio** in **Office Light** (`office.php`) bewerk je foto's (`.jpg`, `.png`, `.webp`) lokaal in de browser: bijsnijden, draaien, verkleinen & comprimeren (`JPEG`, `WebP`, `PNG`), fotofilters, watermerk en **🔲 AVG-pixelatiepenseel**."],
            'pl' => ['🎨 Studio Zdjęć i Obrazów (Kadrowanie, Kompresja, Filtry i Pikselizacja RODO)', "Wbudowane **🎨 Studio Zdjęć** w **Office Light** (`office.php`) pozwala edytować obrazy (`.jpg`, `.png`, `.webp`) w przeglądarce: kadrowanie, obracanie, zmiana rozmiaru i kompresja (`JPEG`, `WebP`, `PNG`), filtry, znak wodny oraz **🔲 pędzel pikselizacji RODO**."],
            'pt' => ['🎨 Estúdio de Fotos e Imagens (Cortar, Comprimir, Filtros e Pixelização RGPD)', "O **🎨 Estúdio de Fotos** no **Office Light** (`office.php`) permite editar imagens (`.jpg`, `.png`, `.webp`) no navegador: cortar, rodar, redimensionar e comprimir (`JPEG`, `WebP`, `PNG`), filtros, marca de água e **🔲 pincel de pixelização RGPD**."],
            'cs' => ['🎨 Foto a obrazové studio (Ořez, komprese, filtry a GDPR pixelizace)', "Integrované **🎨 Foto studio** v **Office Light** (`office.php`) umožňuje upravovat obrázky (`.jpg`, `.png`, `.webp`) v prohlížeči: ořez, otočení, změna velikosti a komprese (`JPEG`, `WebP`, `PNG`), filtry, vodoznak a **🔲 štětec GDPR pixelizace**."],
            'tr' => ['🎨 Fotoğraf ve Görsel Stüdyosu (Kırpma, Sıkıştırma, Filtre ve KVKK Pikselleştirme)', "**Office Light** (`office.php`) içindeki **🎨 Fotoğraf Stüdyosu** ile resimleri (`.jpg`, `.png`, `.webp`) tarayıcıda düzenleyin: kırpma, döndürme, boyut küçültme ve sıkıştırma (`JPEG`, `WebP`, `PNG`), filtreler, filigran ve **🔲 KVKK/GDPR pikselleştirme fırçası**."],
            'uk' => ['🎨 Фото- та графічна студія (Обрізка, стиснення, фільтри та пікселізація)', "Вбудована **🎨 Фотостудія** в **Office Light** (`office.php`) дозволяє редагувати зображення (`.jpg`, `.png`, `.webp`) у браузері: обрізка, поворот, зміна розміру та стиснення (`JPEG`, `WebP`, `PNG`), фотофільтри, водяний знак і **🔲 пензель пікселізації**."],
        ],
        'excel_formulas' => [
            'en' => ['📊 Excel Light Formulas & Functions (.xlsx / .csv)', "The spreadsheet editor in **📝 Office Light** (`office.php`) supports the following formulas (in both English and German, case-insensitive):\n\n- **`=SUM(A1:A10)` / `=SUMME(...)`:** Adds all numbers in a cell range or list.\n- **`=AVERAGE(A1:A10)` / `=AVG(...)` / `=MITTELWERT(...)`:** Calculates the arithmetic mean.\n- **`=MIN(A1:A10)` & `=MAX(A1:A10)`:** Smallest or largest value.\n- **`=COUNT(A1:A10)` / `=ANZAHL(...)`:** Counts how many cells contain numbers.\n- **`=PRODUCT(A1:A5)` / `=PRODUKT(...)`:** Multiplies all numbers in the range.\n- **`=ROUND(value; decimals)` / `=RUNDEN(...)`:** Rounds to decimal places (e.g. `=ROUND(A1*1.19; 2)`).\n- **`=IF(condition; then; else)` / `=WENN(...)`:** Conditional check (`>=`, `<=`, `<>`, `=`, `>`, `<`), e.g. `=IF(A1>=100; \"Target reached\"; \"Open\")`.\n- **`=ABS(A1)`, `=SQRT(A1)` / `=WURZEL(A1)` & `=TODAY()` / `=HEUTE()`:** Absolute value, square root, and current date.\n- **Arithmetic & parentheses:** `+`, `-`, `*`, `/`, `( )` (e.g. `=(D2-E2)*1.19`). Click **`ƒx Alle Formeln`** in the toolbar to insert any formula with 1 click!"],
            'fr' => ['📊 Formules Excel Light (.xlsx / .csv)', "Le tableur d'**📝 Office Light** (`office.php`) prend en charge les formules suivantes :\n\n- **`=SUM(A1:A10)` / `=SUMME(...)` :** Somme d'une plage.\n- **`=AVERAGE(A1:A10)` / `=AVG(...)` :** Moyenne.\n- **`=MIN(A1:A10)` & `=MAX(A1:A10)` :** Valeur minimale / maximale.\n- **`=COUNT(A1:A10)` :** Nombre de cellules numériques.\n- **`=PRODUCT(A1:A5)` :** Produit d'une plage.\n- **`=ROUND(valeur; décimales)` :** Arrondi.\n- **`=IF(condition; alors; sinon)` :** Condition logique (ex. `=IF(A1>=0; \"OK\"; \"À vérifier\")`).\n- **`=ABS(A1)`, `=SQRT(A1)`, `=TODAY()`** ainsi que `+`, `-`, `*`, `/` et `( )`."],
            'es' => ['📊 Fórmulas en Excel Light (.xlsx / .csv)', "La hoja de cálculo de **📝 Office Light** (`office.php`) admite estas fórmulas:\n\n- **`=SUM(A1:A10)` / `=SUMME(...)`:** Suma un rango de celdas.\n- **`=AVERAGE(A1:A10)` / `=AVG(...)`:** Promedio.\n- **`=MIN(A1:A10)` y `=MAX(A1:A10)`:** Valor mínimo / máximo.\n- **`=COUNT(A1:A10)`:** Cuenta celdas con números.\n- **`=PRODUCT(A1:A5)`:** Multiplica un rango.\n- **`=ROUND(valor; decimales)`:** Redondear.\n- **`=IF(condición; entonces; sino)`:** Condición lógica (`>=`, `<=`, `<>`, `=`, `>`, `<`).\n- **`=ABS(A1)`, `=SQRT(A1)`, `=TODAY()`** y operaciones `+`, `-`, `*`, `/`, `( )`."],
            'it' => ['📊 Formule in Excel Light (.xlsx / .csv)', "Il foglio di calcolo di **📝 Office Light** (`office.php`) supporta queste formule:\n\n- **`=SUM(A1:A10)` / `=SUMME(...)`:** Somma di un intervallo.\n- **`=AVERAGE(A1:A10)` / `=AVG(...)`:** Media aritmetica.\n- **`=MIN(A1:A10)` e `=MAX(A1:A10)`:** Valore minimo / massimo.\n- **`=COUNT(A1:A10)`:** Conta le celle numeriche.\n- **`=PRODUCT(A1:A5)`:** Moltiplica i valori.\n- **`=ROUND(valore; decimali)`:** Arrotondamento.\n- **`=IF(condizione; allora; altrimenti)`:** Condizione logica.\n- **`=ABS(A1)`, `=SQRT(A1)`, `=TODAY()`** e operatori `+`, `-`, `*`, `/`, `( )`."],
            'nl' => ['📊 Formules in Excel Light (.xlsx / .csv)', "De spreadsheet in **📝 Office Light** (`office.php`) ondersteunt deze formules:\n\n- **`=SUM(A1:A10)` / `=SUMME(...)`:** Optellen van een bereik.\n- **`=AVERAGE(A1:A10)` / `=AVG(...)`:** Gemiddelde.\n- **`=MIN(A1:A10)` & `=MAX(A1:A10)`:** Kleinste / grootste waarde.\n- **`=COUNT(A1:A10)`:** Aantal getallen tellen.\n- **`=PRODUCT(A1:A5)`:** Vermenigvuldigen.\n- **`=ROUND(waarde; decimalen)`:** Afronden.\n- **`=IF(voorwaarde; dan; anders)`:** Logische ALS-functie.\n- **`=ABS(A1)`, `=SQRT(A1)`, `=TODAY()`** en `+`, `-`, `*`, `/`, `( )`."],
            'pl' => ['📊 Formuły w Excel Light (.xlsx / .csv)', "Arkusz kalkulacyjny w **📝 Office Light** (`office.php`) obsługuje następujące formuły:\n\n- **`=SUM(A1:A10)` / `=SUMME(...)`:** Suma zakresu komórek.\n- **`=AVERAGE(A1:A10)` / `=AVG(...)`:** Średnia arytmetyczna.\n- **`=MIN(A1:A10)` i `=MAX(A1:A10)`:** Wartość minimalna / maksymalna.\n- **`=COUNT(A1:A10)`:** Liczba komórek z liczbami.\n- **`=PRODUCT(A1:A5)`:** Iloczyn zakresu.\n- **`=ROUND(wartość; miejsca)`:** Zaokrąglanie.\n- **`=IF(warunek; wtedy; inaczej)`:** Funkcja warunkowa.\n- **`=ABS(A1)`, `=SQRT(A1)`, `=TODAY()`** oraz `+`, `-`, `*`, `/`, `( )`."],
            'pt' => ['📊 Fórmulas no Excel Light (.xlsx / .csv)', "A folha de cálculo do **📝 Office Light** (`office.php`) suporta estas fórmulas:\n\n- **`=SUM(A1:A10)`:** Soma de um intervalo.\n- **`=AVERAGE(A1:A10)` / `=AVG(...)`:** Média.\n- **`=MIN(A1:A10)` e `=MAX(A1:A10)`:** Mínimo / máximo.\n- **`=COUNT(A1:A10)`:** Contar células numéricas.\n- **`=PRODUCT(A1:A5)`:** Multiplicar intervalo.\n- **`=ROUND(valor; decimais)`:** Arredondar.\n- **`=IF(condição; então; senão)`:** Condição lógica.\n- **`=ABS(A1)`, `=SQRT(A1)`, `=TODAY()`** e `+`, `-`, `*`, `/`, `( )`."],
            'cs' => ['📊 Vzorce v Excel Light (.xlsx / .csv)', "Tabulkový editor v **📝 Office Light** (`office.php`) podporuje tyto vzorce:\n\n- **`=SUM(A1:A10)` / `=SUMME(...)`:** Součet oblasti.\n- **`=AVERAGE(A1:A10)` / `=AVG(...)`:** Průměr.\n- **`=MIN(A1:A10)` a `=MAX(A1:A10)`:** Minimum / maximum.\n- **`=COUNT(A1:A10)`:** Počet číselných buněk.\n- **`=PRODUCT(A1:A5)`:** Součin.\n- **`=ROUND(hodnota; místa)`:** Zaokrouhlení.\n- **`=IF(podmínka; pak; jinak)`:** Podmínka.\n- **`=ABS(A1)`, `=SQRT(A1)`, `=TODAY()`** a `+`, `-`, `*`, `/`, `( )`."],
            'tr' => ['📊 Excel Light Formülleri (.xlsx / .csv)', "**📝 Office Light** (`office.php`) tablo düzenleyicisi şu formülleri destekler:\n\n- **`=SUM(A1:A10)`:** Hücre aralığını toplar.\n- **`=AVERAGE(A1:A10)` / `=AVG(...)`:** Ortalama hesaplar.\n- **`=MIN(A1:A10)` ve `=MAX(A1:A10)`:** En küçük / en büyük değer.\n- **`=COUNT(A1:A10)`:** Sayı içeren hücre sayısı.\n- **`=PRODUCT(A1:A5)`:** Çarpım.\n- **`=ROUND(değer; basamak)`:** Yuvarlama.\n- **`=IF(koşul; doğruysa; yanlışsa)`:** Koşullu mantık.\n- **`=ABS(A1)`, `=SQRT(A1)`, `=TODAY()`** ve `+`, `-`, `*`, `/`, `( )`."],
            'uk' => ['📊 Формули в Excel Light (.xlsx / .csv)', "Табличний редактор **📝 Office Light** (`office.php`) підтримує такі формули:\n\n- **`=SUM(A1:A10)` / `=SUMME(...)`:** Сума діапазону клітинок.\n- **`=AVERAGE(A1:A10)` / `=AVG(...)`:** Середнє значення.\n- **`=MIN(A1:A10)` та `=MAX(A1:A10)`:** Мінімум / максимум.\n- **`=COUNT(A1:A10)`:** Кількість числових клітинок.\n- **`=PRODUCT(A1:A5)`:** Добуток.\n- **`=ROUND(значення; знаки)`:** Округлення.\n- **`=IF(умова; тоді; інакше)`:** Логічна умова.\n- **`=ABS(A1)`, `=SQRT(A1)`, `=TODAY()`** та `+`, `-`, `*`, `/`, `( )`."],
        ],
        'ebook_reader_epub' => [
            'en' => ['📚 E-Book Reader & Library (.epub)', "With the **📚 E-Book Reader** (`ebooks.php`) you can read all **`.epub` books** in your cloud right in your browser:\n\n- **📚 Automatic bookshelf:** Finds `.epub` files across your folders, group folders, and shares with **cover art, title, author, and synopsis**.\n- **🔖 Synced reading progress:** Automatically saves your current chapter and scroll position (`% read`) across PC, tablet, and smartphone.\n- **🎨 Eye-friendly themes & search:** Paper, Sepia, Slate, and Night mode, bookmarks with notes, and full-text search."],
            'fr' => ['📚 Lecteur E-Book & Bibliothèque (.epub)', "Avec le **📚 Lecteur E-Book** (`ebooks.php`), lisez tous vos livres **`.epub`** dans le navigateur :\n\n- **📚 Bibliothèque automatique** avec couverture, auteur et résumé.\n- **🔖 Progression synchronisée** (`% lu`) entre PC et smartphone, signets avec notes, recherche plein texte et modes *Papier / Sépia / Ardoise / Nuit*."],
            'es' => ['📚 Lector de E-Books y Biblioteca (.epub)', "Con el **📚 Lector de E-Books** (`ebooks.php`) puedes leer todos tus libros **`.epub`** en el navegador:\n\n- **📚 Estantería automática** con portada, título y autor.\n- **🔖 Progreso de lectura sincronizado** (`% leído`), marcadores con notas, búsqueda de texto completo y modos *Papel, Sepia, Pizarra y Noche*."],
            'it' => ['📚 Lettore E-Book & Libreria (.epub)', "Con il **📚 Lettore E-Book** (`ebooks.php`) leggi tutti i tuoi libri **`.epub`** nel browser:\n\n- **📚 Libreria automatica** con copertina, titolo e autore.\n- **🔖 Progresso di lettura sincronizzato** (`% letto`), segnalibri con note, ricerca nel libro e temi *Carta, Seppia, Ardesia e Notte*."],
            'nl' => ['📚 E-Book-lezer & Bibliotheek (.epub)', "Met de **📚 E-Book-lezer** (`ebooks.php`) lees je al je **`.epub`-boeken** in de browser:\n\n- **📚 Automatische boekenplank** met boekomslag, titel en auteur.\n- **🔖 Gesynchroniseerde leesvoortgang** (`% gelezen`), bladwijzers met notities, tekstzoeken en *Papier / Sepia / Leisteen / Nachtmodus*."],
            'pl' => ['📚 Czytnik E-Booków i Biblioteka (.epub)', "W **📚 Czytniku E-Booków** (`ebooks.php`) czytasz książki **`.epub`** w przeglądarce:\n\n- **📚 Automatyczna półka** z okładką, tytułem i autorem.\n- **🔖 Synchronizowany postęp czytania** (`% przeczytano`), zakładki z notatkami, wyszukiwarka oraz tryby *Papier, Sepia, Łupek i Nocny*."],
            'pt' => ['📚 Leitor de E-Books e Biblioteca (.epub)', "Com o **📚 Leitor de E-Books** (`ebooks.php`) lê todos os livros **`.epub`** no navegador:\n\n- **📚 Estante automática** com capa, título e autor.\n- **🔖 Progresso sincronizado** (`% lido`), marcadores com notas, pesquisa no livro e modos *Papel, Sépia, Ardósia e Noite*."],
            'cs' => ['📚 Čtečka E-knih a knihovna (.epub)', "S **📚 Čtečkou E-knih** (`ebooks.php`) můžeš číst knihy **`.epub`** přímo v prohlížeči:\n\n- **📚 Automatická knihovna** s obálkou, názvem a autorem.\n- **🔖 Synchronizovaný postup čtení** (`% přečteno`), záložky s poznámkami, fulltextové hledání a režimy *Papír, Sépie, Břidlice a Noc*."],
            'tr' => ['📚 E-Kitap Okuyucu ve Kitaplık (.epub)', "**📚 E-Kitap Okuyucu** (`ebooks.php`) ile buluttaki tüm **`.epub`** kitaplarınızı tarayıcıda okuyabilirsiniz:\n\n- **📚 Otomatik kitaplık:** Kapak resmi, başlık ve yazar bilgileri.\n- **🔖 Senkronize okuma ilerlemesi** (`% okundu`), notlu yer imleri, kitap içi arama ve *Kağıt, Sepya, Arduvaz ve Gece modu*."],
            'uk' => ['📚 Рідер електронних книг та бібліотека (.epub)', "За допомогою **📚 Рідера E-Books** (`ebooks.php`) читайте книги **`.epub`** прямо у браузері:\n\n- **📚 Автоматична книжкова полиця** з обкладинкою, назвою та автором.\n- **🔖 Синхронізований прогрес читання** (`% прочитано`), закладки з нотатками, пошук по книзі та режими *Папір, Сепія, Сланець і Нічний*."],
        ],
        'pdf_edit_redact' => [
            'en' => ['✏️ Fill out, sign & redact PDFs in browser', "Under **📄 PDF & Scan → ✏️ 5. Edit, fill & redact PDF** (`pdf_tools.php#tab-redact`) you can edit any PDF right in your browser:\n\n- **🔤 Insert text**, **✓ / ✕ checkboxes**, and **✍️ draw your signature**.\n- **⬛ GDPR-compliant redaction:** Draw black bars over sensitive data (like IBANs or names) or use the white eraser. Pages are flattened on save so redacted text is **permanently removed**!"],
            'fr' => ['✏️ Remplir, signer et caviarder un PDF', "Dans **📄 PDF & Scan → ✏️ 5. Éditer & caviarder un PDF** (`pdf_tools.php#tab-redact`) :\n\n- Ajoutez du **texte (🔤)**, des **cases ✓ / ✕** et votre **signature dessinée (✍️)**.\n- **⬛ Caviardage RGPD irréversible** avec barres noires ou gomme blanche."],
            'es' => ['✏️ Rellenar, firmar y censurar un PDF', "En **📄 PDF y Escáner → ✏️ 5. Editar y censurar PDF** (`pdf_tools.php#tab-redact`):\n\n- Añade **texto (🔤)**, marcas **✓ / ✕** y tu **firma dibujada (✍️)**.\n- **⬛ Censura RGPD irreversible** con barras negras o borrador blanco."],
            'it' => ['✏️ Compilare, firmare e oscurare un PDF', "In **📄 PDF & Scan → ✏️ 5. Modifica e oscura PDF** (`pdf_tools.php#tab-redact`):\n\n- Inserisci **testo (🔤)**, segni **✓ / ✕** e **firma disegnata (✍️)**.\n- **⬛ Oscuramento GDPR irreversibile** con barre nere o gomma bianca."],
            'nl' => ['✏️ PDF invullen, ondertekenen & zwartmaken', "Onder **📄 PDF & Scan → ✏️ 5. PDF bewerken & zwartmaken** (`pdf_tools.php#tab-redact`):\n\n- Voeg **tekst (🔤)**, vinkjes **✓ / ✕** en je **handtekening (✍️)** toe.\n- **⬛ AVG-veilig zwartmaken** van vertrouwelijke gegevens."],
            'pl' => ['✏️ Wypełnianie, podpisywanie i zamazywanie PDF', "W **📄 PDF i Skaner → ✏️ 5. Edytuj i zamazuj PDF** (`pdf_tools.php#tab-redact`):\n\n- Wstawiaj **tekst (🔤)**, znaczki **✓ / ✕** oraz **własnoręczny podpis (✍️)**.\n- **⬛ Nieodwracalne zamazywanie RODO** czarnymi paskami."],
            'pt' => ['✏️ Preencher, assinar e ocultar dados num PDF', "Em **📄 PDF e Scanner → ✏️ 5. Editar PDF** (`pdf_tools.php#tab-redact`):\n\n- Adicione **texto (🔤)**, marcas **✓ / ✕** e a sua **assinatura (✍️)**.\n- **⬛ Ocultação RGPD irreversível** com barras pretas."],
            'cs' => ['✏️ Vyplnění, podpis a začernění PDF', "V **📄 PDF a skener → ✏️ 5. Upravit a začernit PDF** (`pdf_tools.php#tab-redact`):\n\n- Vkládej **text (🔤)**, značky **✓ / ✕** a **vlastnoruční podpis (✍️)**.\n- **⬛ Nevratné GDPR začernění** citlivých údajů."],
            'tr' => ['✏️ PDF doldurma, imzalama ve karartma', "**📄 PDF ve Tarayıcı → ✏️ 5. PDF Düzenle ve Karart** (`pdf_tools.php#tab-redact`) sekmesinde:\n\n- **Metin (🔤)**, **✓ / ✕ işaretleri** ve **el yazısı imza (✍️)** ekleyin.\n- **⬛ KVKK/GDPR uyumlu kalıcı karartma** yapın."],
            'uk' => ['✏️ Заповнення, підпис та замальовування PDF', "У **📄 PDF та сканер → ✏️ 5. Редагувати PDF** (`pdf_tools.php#tab-redact`):\n\n- Додавайте **текст (🔤)**, позначки **✓ / ✕** та **власноручний підпис (✍️)**.\n- **⬛ Незворотне приховування конфіденційних даних** чорними смугами."],
        ],
        'ocr_text_recognition' => [
            'en' => ['🔎 Local OCR Text Recognition (12 languages + Fraktur)', "With **🔎 OCR Text Recognition** (`pdf_tools.php#tab-ocr`) you can extract editable text from scanned PDFs, receipts, or photos:\n\n- **100% local on your server:** Uses local Tesseract WebAssembly without sending any data to external services.\n- **12 European languages:** German, English, French, Spanish, Italian, Dutch, Polish, Czech, Portuguese, Turkish, Ukrainian + **German Fraktur (`frk`)**.\n- Save recognized text directly as `.txt`, an **Office Light document**, or a **sticky note**!"],
            'fr' => ['🔎 Reconnaissance de texte OCR locale (12 langues)', "Avec **🔎 Reconnaissance OCR** (`pdf_tools.php#tab-ocr`), extrayez le texte de vos images et PDF scannés :\n\n- **100 % local** sur votre serveur en **12 langues européennes** (dont français, allemand, anglais, espagnol, italien, etc.).\n- Enregistrez le texte en `.txt`, document **Office Light** ou **note**."],
            'es' => ['🔎 Reconocimiento de texto OCR local (12 idiomas)', "Con **🔎 Reconocimiento OCR** (`pdf_tools.php#tab-ocr`) conviertes fotos y PDFs escaneados en **texto editable**:\n\n- **100 % local** en tu servidor con **12 idiomas europeos**.\n- Guarda el texto como `.txt`, documento de **Office Light** o **nota adhesiva**."],
            'it' => ['🔎 Riconoscimento testo OCR locale (12 lingue)', "Con **🔎 Riconoscimento OCR** (`pdf_tools.php#tab-ocr`) estrai testo modificabile da foto e PDF scansionati:\n\n- **100 % locale** sul server in **12 lingue europee**.\n- Salva il testo come `.txt`, documento **Office Light** o **nota**."],
            'nl' => ['🔎 Lokale OCR-tekstherkenning (12 talen)', "Met **🔎 OCR-tekstherkenning** (`pdf_tools.php#tab-ocr`) zet je foto's en gescande PDF's om in **bewerkbare tekst**:\n\n- **100 % lokaal** op je server in **12 Europese talen**.\n- Sla direct op als `.txt`, **Office Light-document** of **notitie**."],
            'pl' => ['🔎 Lokalne rozpoznawanie tekstu OCR (12 języków)', "Dzięki **🔎 Rozpoznawaniu OCR** (`pdf_tools.php#tab-ocr`) zamienisz zdjęcia i skany PDF w **edytowalny tekst**:\n\n- **100 % lokalnie** na serwerze w **12 językach europejskich** (w tym polskim).\n- Zapisz wynik jako `.txt`, dokument **Office Light** lub **notatkę**."],
            'pt' => ['🔎 Reconhecimento de texto OCR local (12 idiomas)', "Com o **🔎 Reconhecimento OCR** (`pdf_tools.php#tab-ocr`) converte fotos e PDFs digitalizados em **texto editável**:\n\n- **100 % local** no servidor em **12 idiomas europeus**.\n- Guarde como `.txt`, documento **Office Light** ou **nota**."],
            'cs' => ['🔎 Lokální OCR rozpoznávání textu (12 jazyků)', "Pomocí **🔎 OCR rozpoznávání textu** (`pdf_tools.php#tab-ocr`) převedeš fotky a naskenované PDF na **upravitelný text**:\n\n- **100 % lokálně** na serveru ve **12 evropských jazycích** (včetně češtiny).\n- Ulož text jako `.txt`, dokument **Office Light** nebo **poznámku**."],
            'tr' => ['🔎 Yerel OCR Metin Tanıma (12 dil)', "**🔎 OCR Metin Tanıma** (`pdf_tools.php#tab-ocr`) ile fotoğraflardaki ve taranmış PDF'lerdeki yazıları **düzenlenebilir metne** dönüştürün:\n\n- Sunucunuzda **%100 yerel** çalışır ve Türkçe dahil **12 Avrupa dilini** destekler.\n- Metni `.txt`, **Office Light belgesi** veya **yapışkan not** olarak kaydedin."],
            'uk' => ['🔎 Локальне OCR-розпізнавання тексту (12 мов)', "За допомогою **🔎 OCR-розпізнавання** (`pdf_tools.php#tab-ocr`) перетворюйте фото та скановані PDF на **редагований текст**:\n\n- **100 % локально** на сервері з підтримкою **12 європейських мов** (включно з українською `ukr`).\n- Зберігайте текст як `.txt`, документ **Office Light** або **нотатку**."],
        ],
        'webdav_caldav_carddav' => [
            'en' => ['📡 WebDAV, CalDAV & CardDAV (Network drive & Phone Sync)', "Your cloud supports all three open sync standards – find your personal URLs in **⚙️ Settings**:\n\n1. **📡 WebDAV (`webdav.php`):** Mount your cloud as a network drive in Windows Explorer, macOS Finder, or Linux.\n2. **📅 CalDAV (`caldav.php`):** Sync your calendar with iPhone/Mac, Thunderbird, or Android.\n3. **📇 CardDAV (`carddav.php`):** Sync your contacts with your phone or PC.\n💡 *Tip:* Create a dedicated **App Password** in **⚙️ Settings**!"],
            'fr' => ['📡 WebDAV, CalDAV & CardDAV (Lecteur réseau & Sync mobile)', "Retrouvez vos URL de synchronisation dans **⚙️ Paramètres** :\n\n1. **📡 WebDAV (`webdav.php`) :** Lecteur réseau sous Windows, macOS ou Linux.\n2. **📅 CalDAV (`caldav.php`) :** Synchronisation du calendrier.\n3. **📇 CardDAV (`carddav.php`) :** Synchronisation des contacts.\n💡 Créez un **mot de passe d'application** dans vos paramètres !"],
            'es' => ['📡 WebDAV, CalDAV y CardDAV (Unidad de red y Sincronización)', "Encuentra tus URLs de conexión en **⚙️ Ajustes**:\n\n1. **📡 WebDAV (`webdav.php`):** Unidad de red en Windows, macOS o Linux.\n2. **📅 CalDAV (`caldav.php`):** Sincroniza tu calendario con el móvil o PC.\n3. **📇 CardDAV (`carddav.php`):** Sincroniza tus contactos."],
            'it' => ['📡 WebDAV, CalDAV & CardDAV (Disco di rete & Sync smartphone)', "Trovi gli URL di connessione in **⚙️ Impostazioni**:\n\n1. **📡 WebDAV (`webdav.php`):** Disco di rete su Windows, Mac o Linux.\n2. **📅 CalDAV (`caldav.php`):** Sincronizza il calendario.\n3. **📇 CardDAV (`carddav.php`):** Sincronizza la rubrica contatti."],
            'nl' => ['📡 WebDAV, CalDAV & CardDAV (Netwerkschijf & Sync)', "Vind je verbindings-URL's in **⚙️ Instellingen**:\n\n1. **📡 WebDAV (`webdav.php`):** Netwerkschijf in Windows Verkenner of macOS Finder.\n2. **📅 CalDAV (`caldav.php`):** Agenda synchroniseren.\n3. **📇 CardDAV (`carddav.php`):** Contacten synchroniseren."],
            'pl' => ['📡 WebDAV, CalDAV i CardDAV (Dysk sieciowy i Synchronizacja)', "Adresy URL połączenia znajdziesz w **⚙️ Ustawieniach**:\n\n1. **📡 WebDAV (`webdav.php`):** Dysk sieciowy w Windows, macOS lub Linux.\n2. **📅 CalDAV (`caldav.php`):** Synchronizacja kalendarza.\n3. **📇 CardDAV (`carddav.php`):** Synchronizacja kontaktów."],
            'pt' => ['📡 WebDAV, CalDAV e CardDAV (Disco de rede e Sincronização)', "Encontre os URLs de ligação em **⚙️ Definições**:\n\n1. **📡 WebDAV (`webdav.php`):** Disco de rede no Windows, macOS ou Linux.\n2. **📅 CalDAV (`caldav.php`):** Sincronizar calendário.\n3. **📇 CardDAV (`carddav.php`):** Sincronizar contactos."],
            'cs' => ['📡 WebDAV, CalDAV a CardDAV (Síťový disk a synchronizace)', "Spojovací adresy URL najdeš v **⚙️ Nastavení**:\n\n1. **📡 WebDAV (`webdav.php`):** Síťový disk ve Windows, macOS nebo Linuxu.\n2. **📅 CalDAV (`caldav.php`):** Synchronizace kalendáře.\n3. **📇 CardDAV (`carddav.php`):** Synchronizace kontaktů."],
            'tr' => ['📡 WebDAV, CalDAV ve CardDAV (Ağ sürücüsü ve Senkronizasyon)', "Bağlantı adreslerinizi **⚙️ Ayarlar** sayfasında bulabilirsiniz:\n\n1. **📡 WebDAV (`webdav.php`):** Bilgisayarınıza ağ sürücüsü olarak bağlayın.\n2. **📅 CalDAV (`caldav.php`):** Takviminizi telefonunuzla eşitleyin.\n3. **📇 CardDAV (`carddav.php`):** Kişilerinizi senkronize edin."],
            'uk' => ['📡 WebDAV, CalDAV та CardDAV (Мережевий диск і синхронізація)', "URL-адреси підключення знаходяться в **⚙️ Налаштуваннях**:\n\n1. **📡 WebDAV (`webdav.php`):** Мережевий диск у Windows, macOS або Linux.\n2. **📅 CalDAV (`caldav.php`):** Синхронізація календаря зі смартфоном.\n3. **📇 CardDAV (`carddav.php`):** Синхронізація контактів."],
        ],
        'vault_encryption' => [
            'en' => ['🔒 Zero-Knowledge File Vault (AES-256-GCM)', "With the **🔒 Zero-Knowledge File Vault** you can encrypt confidential files directly on the server using **AES-256-GCM** (PBKDF2 key derivation):\n\n1. Click the **Lock icon (🔒)** next to any file in `index.php`.\n2. Enter a personal **Vault password**.\n3. **Important:** Your password is never stored in the database (true zero-knowledge)."],
            'fr' => ['🔒 Coffre-fort de fichiers Zero-Knowledge (AES-256-GCM)', "Chiffrez vos fichiers sensibles avec **AES-256-GCM** en cliquant sur l'icône **🔒** dans la liste des fichiers. Le mot de passe n'est jamais stocké sur le serveur."],
            'es' => ['🔒 Bóveda de archivos Zero-Knowledge (AES-256-GCM)', "Cifra tus archivos confidenciales con **AES-256-GCM** pulsando el icono **🔒** en la lista de archivos. La contraseña nunca se guarda en el servidor."],
            'it' => ['🔒 Cassaforte file Zero-Knowledge (AES-256-GCM)', "Crittografa i tuoi file riservati con **AES-256-GCM** cliccando sull'icona **🔒** nella lista file."],
            'nl' => ['🔒 Zero-Knowledge Bestandskluis (AES-256-GCM)', "Versleutel vertrouwelijke bestanden met **AES-256-GCM** via het **🔒**-sloticoon in de bestandenlijst."],
            'pl' => ['🔒 Sejf plików Zero-Knowledge (AES-256-GCM)', "Szyfruj poufne pliki algorytmem **AES-256-GCM**, klikając ikonę kłódki **🔒** na liście plików."],
            'pt' => ['🔒 Cofre de Ficheiros Zero-Knowledge (AES-256-GCM)', "Encripte ficheiros confidenciais com **AES-256-GCM** clicando no ícone **🔒** na lista de ficheiros."],
            'cs' => ['🔒 Zero-Knowledge trezor souborů (AES-256-GCM)', "Zašifruj důvěrné soubory pomocí **AES-256-GCM** kliknutím na ikonu zámku **🔒** v seznamu souborů."],
            'tr' => ['🔒 Sıfır Bilgi Dosya Kasası (AES-256-GCM)', "Dosya listesindeki kilit simgesine (**🔒**) tıklayarak gizli dosyalarınızı **AES-256-GCM** ile şifreleyebilirsiniz."],
            'uk' => ['🔒 Сейф файлів Zero-Knowledge (AES-256-GCM)', "Шифруйте конфіденційні файли алгоритмом **AES-256-GCM**, натиснувши на значок замка (**🔒**) у списку файлів."],
        ],
        'mindmap_editor' => [
            'en' => ['🧠 Mind Map Editor & Idea Collector', "With the **🧠 Mind Map Editor** (`mindmap.php`) you can visually structure ideas, projects, and study topics:\n\n- **Keyboard shortcuts:** `Tab` creates a child node, `Enter` creates a sibling node, `Del` deletes a node.\n- **Auto-Layout & Templates:** Tree and radial layouts, plus templates for Brainstorming, Project Planning, Travel, and Study.\n- **1-Click Export & Tasks:** Convert any node directly into a **Kanban task** (`tasks.php`) or **sticky note** (`notes.php`), or save your map as SVG/PNG/Markdown to your cloud files!"],
            'fr' => ['🧠 Éditeur de cartes mentales (Mind-Map)', "L'**🧠 Éditeur de cartes mentales** (`mindmap.php`) vous permet de structurer vos idées visuellement (`Tab` = sous-nœud, `Entrée` = nœud frère), de partager vos cartes en équipe et de convertir un nœud en tâche ou en note en 1 clic."],
            'es' => ['🧠 Editor de mapas mentales', "Con el **🧠 Editor de mapas mentales** (`mindmap.php`) estructura ideas y proyectos visualmente (`Tab` = subnodo, `Enter` = nodo hermano), aplica auto-diseño y convierte nodos en tareas o notas con 1 clic."],
            'it' => ['🧠 Editor di mappe mentali', "Con l'**🧠 Editor di mappe mentali** (`mindmap.php`) organizza visivamente idee e progetti (`Tab` = sottonodo, `Invio` = nodo fratello) ed esporta in PNG/SVG/Markdown o in attività e note."],
            'nl' => ['🧠 Mindmap-editor & Ideeënverzamelaar', "Met de **🧠 Mindmap-editor** (`mindmap.php`) structureer je ideeën visueel (`Tab` = subknoop, `Enter` = nevenknoop) en zet je knopen met 1 klik om in taken of notities."],
            'pl' => ['🧠 Edytor map myśli (Mind-Map)', "W **🧠 Edytorze map myśli** (`mindmap.php`) wizualnie porządkujesz pomysły i projekty (`Tab` = podwęzeł, `Enter` = węzeł równorzędny) oraz przekształcasz węzły w zadania lub notatki."],
            'pt' => ['🧠 Editor de Mapas Mentais', "Com o **🧠 Editor de Mapas Mentais** (`mindmap.php`) estruture ideias visualmente (`Tab` = subnó, `Enter` = nó irmão) e converta nós em tarefas ou notas com 1 clique."],
            'cs' => ['🧠 Editor myšlenkových map', "V **🧠 Editoru myšlenkových map** (`mindmap.php`) vizuálně třídíš nápady a projekty (`Tab` = poduzel, `Enter` = sourozenecký uzel) a jedním kliknutím z nich vytvoříš úkol nebo poznámku."],
            'tr' => ['🧠 Zihin Haritası (Mind-Map) Düzenleyici', "**🧠 Zihin Haritası Düzenleyici** (`mindmap.php`) ile fikirlerinizi görsel olarak yapılandırın (`Tab` = alt düğüm, `Enter` = kardeş düğüm) ve düğümleri tek tıkla göreve veya nota dönüştürün."],
            'uk' => ['🧠 Редактор інтелект-карт (Mind-Map)', "У **🧠 Редакторі інтелект-карт** (`mindmap.php`) візуально структуруйте ідеї та проєкти (`Tab` = підвузол, `Enter` = сусідній вузол) і перетворюйте вузли на завдання чи нотатки."],
        ],
        'budget_planner' => [
            'en' => ['💰 Household Planner & Finance Tracker', "The **💰 Household Planner** (`budget.php`) helps you manage personal, family, or shared-flat finances:\n\n- **Income, Expenses & Categories:** Visual monthly breakdown, 6-month trend chart, and receipt file linking (`files`).\n- **Recurring Subscriptions & Fixed Costs:** Track rent, utilities, and streaming subscriptions and book them into any month with **1 click**.\n- **Savings Goals & Fair Split:** Track savings targets with progress bars and automatically calculate **who owes whom** in shared household books."],
            'fr' => ['💰 Planificateur de budget & Finances', "Le **💰 Planificateur de budget** (`budget.php`) gère vos revenus, dépenses, charges fixes/abonnements, objectifs d'épargne et la répartition équitable des frais en famille ou colocation."],
            'es' => ['💰 Planificador familiar y Finanzas', "El **💰 Planificador familiar** (`budget.php`) gestiona ingresos, gastos, gastos fijos/suscripciones, metas de ahorro y el reparto automático de gastos entre miembros."],
            'it' => ['💰 Pianificatore familiare & Finanze', "Il **💰 Pianificatore familiare** (`budget.php`) gestisce entrate, uscite, costi fissi/abbonamenti, obiettivi di risparmio e la divisione spese in famiglia o casa condivisa."],
            'nl' => ['💰 Huishoudplanner & Financieel overzicht', "De **💰 Huishoudplanner** (`budget.php`) beheert inkomsten, uitgaven, vaste lasten/abonnementen, spaardoelen en eerlijke kostenverdeling."],
            'pl' => ['💰 Planer budżetu domowego i finansów', "**💰 Planer budżetu** (`budget.php`) pozwala zarządzać przychodami, wydatkami, opłatami stałymi, celami oszczędnościowymi i rozliczeniami grupowymi."],
            'pt' => ['💰 Planeador Financeiro e Orçamento Familiar', "O **💰 Planeador Financeiro** (`budget.php`) gere receitas, despesas, custos fixos/subscrições, metas de poupança e divisão de despesas."],
            'cs' => ['💰 Rodinný rozpočet a plánovač financí', "**💰 Plánovač rozpočtu** (`budget.php`) spravuje příjmy, výdaje, trvalé platby, spořicí cíle a vyrovnání výdajů v domácnosti."],
            'tr' => ['💰 Bütçe Planlayıcı ve Finans Özeti', "**💰 Bütçe Planlayıcı** (`budget.php`) ile gelir-giderlerinizi, sabit fatura/aboneliklerinizi, tasarruf hedeflerinizi ve ortak harcama paylaşımlarını yönetin."],
            'uk' => ['💰 Планувальник бюджету та фінансів', "**💰 Планувальник бюджету** (`budget.php`) допомагає вести облік доходів і витрат, регулярних платежів, цілей заощаджень та спільних витрат."],
        ],
        'browser_games' => [
            'en' => ['🎮 Browser Games, Cloud Highscores & Live Duels', "In the **🎮 Games Center** (`games.php`) you'll find 17 ad-free browser games with **⛶ Fullscreen Mode**:\n\n- **Retro, Strategy, Racing & RPG:** *Gummigeruch Kart-Cup*, *Cloud-Traffic-Racer*, *Cloud-Lemmings (1991)*, *The Last Ninja (1987 C64)*, *Cloud-Canabalt*, *Cloud-Invaders (1978)*, *Cloud-Kong (1981)*, *Commodore VC-20 Blitz (1981)*, *Cloud-PacMan*, *World of MiniCloud (RPG)*, *Block Puzzle*, *Retro Snake*, and *Brick Breaker*.\n- **Logic & Live Multiplayer Duels:** *Sudoku*, *Minesweeper*, *Memory*, plus **Connect Four** and **Tic-Tac-Toe** against AI or other cloud users!"],
            'fr' => ['🎮 Jeux de navigateur & Duels en direct', "Dans **🎮 Jeux** (`games.php`), profitez de 17 jeux sans publicité en mode plein écran (*Gummigeruch Kart-Cup, Cloud-Traffic-Racer, Cloud-Lemmings, The Last Ninja, Cloud-Canabalt, Cloud-Invaders, Cloud-Kong, VC-20 Blitz, Cloud-PacMan, World of MiniCloud, Sudoku, Démineur, Memory, Block-Puzzle, Snake, Brick Breaker, Puissance 4 & Morpion*) avec classements Cloud et duels en direct !"],
            'es' => ['🎮 Juegos de navegador y Duelos en vivo', "En **🎮 Juegos** (`games.php`) tienes 17 juegos sin publicidad con modo pantalla completa (*Gummigeruch Kart-Cup, Cloud-Traffic-Racer, Cloud-Lemmings, The Last Ninja, Cloud-Canabalt, Cloud-Invaders, Cloud-Kong, VC-20 Blitz, Cloud-PacMan, World of MiniCloud, Sudoku, Buscaminas, Memory, Block-Puzzle, Snake, Brick Breaker, 4 en Raya y Tres en Raya*) con récords en la nube."],
            'it' => ['🎮 Giochi da browser & Sfide dal vivo', "In **🎮 Giochi** (`games.php`) trovi 17 giochi senza pubblicità a schermo intero (*Gummigeruch Kart-Cup, Cloud-Traffic-Racer, Cloud-Lemmings, The Last Ninja, Cloud-Canabalt, Cloud-Invaders, Cloud-Kong, VC-20 Blitz, Cloud-PacMan, World of MiniCloud, Sudoku, Campo Minato, Memory, Block-Puzzle, Snake, Brick Breaker, Forza 4 e Tris*) con classifiche Cloud."],
            'nl' => ['🎮 Browserspellen & Live Duels', "In **🎮 Spellen** (`games.php`) vind je 17 reclamevrije spellen met volledig scherm (*Gummigeruch Kart-Cup, Cloud-Traffic-Racer, Cloud-Lemmings, The Last Ninja, Cloud-Canabalt, Cloud-Invaders, Cloud-Kong, VC-20 Blitz, Cloud-PacMan, World of MiniCloud, Sudoku, Mijnenveger, Memory, Block-Puzzle, Snake, Brick Breaker, Vier op een rij & Boter-kaas-en-eieren*) met highscores."],
            'pl' => ['🎮 Gry przeglądarkowe i pojedynki na żywo', "W **🎮 Grach** (`games.php`) znajdziesz 17 gier bez reklam w trybie pełnoekranowym (*Gummigeruch Kart-Cup, Cloud-Traffic-Racer, Cloud-Lemmings, The Last Ninja, Cloud-Canabalt, Cloud-Invaders, Cloud-Kong, VC-20 Blitz, Cloud-PacMan, World of MiniCloud, Sudoku, Saper, Memory, Block-Puzzle, Snake, Brick Breaker, Cztery w rzędzie i Kółko-krzyżyk*) z rankingami Cloud."],
            'pt' => ['🎮 Jogos de Navegador e Duelos ao Vivo', "Em **🎮 Jogos** (`games.php`) encontra 17 jogos sem anúncios em ecrã inteiro (*Gummigeruch Kart-Cup, Cloud-Traffic-Racer, Cloud-Lemmings, The Last Ninja, Cloud-Canabalt, Cloud-Invaders, Cloud-Kong, VC-20 Blitz, Cloud-PacMan, World of MiniCloud, Sudoku, Campo Minado, Memória, Block-Puzzle, Snake, Brick Breaker, 4 em Linha e Jogo do Galo*) com recordes na nuvem."],
            'cs' => ['🎮 Prohlížečové hry a živé souboje', "V **🎮 Hrách** (`games.php`) najdeš 17 her bez reklam v režimu celé obrazovky (*Gummigeruch Kart-Cup, Cloud-Traffic-Racer, Cloud-Lemmings, The Last Ninja, Cloud-Canabalt, Cloud-Invaders, Cloud-Kong, VC-20 Blitz, Cloud-PacMan, World of MiniCloud, Sudoku, Hledání min, Pexeso, Block-Puzzle, Snake, Brick Breaker, Čtyři v řadě a Piškvorky*) s žebříčky."],
            'tr' => ['🎮 Tarayıcı Oyunları ve Canlı Düellolar', "**🎮 Oyunlar** (`games.php`) bölümünde tam ekran modlu reklamsız 17 oyun (*Gummigeruch Kart-Cup, Cloud-Traffic-Racer, Cloud-Lemmings, The Last Ninja, Cloud-Canabalt, Cloud-Invaders, Cloud-Kong, VC-20 Blitz, Cloud-PacMan, World of MiniCloud, Sudoku, Mayın Tarlası, Hafıza, Blok Yapboz, Yılan, Tuğla Kırıcı, Dört Bağlantı ve XOX*) ve bulut liderlik tabloları bulunur."],
            'uk' => ['🎮 Браузерні ігри та живі дуелі', "У **🎮 Іграх** (`games.php`) доступні 17 ігор без реклами в повноекранному режимі (*Gummigeruch Kart-Cup, Cloud-Traffic-Racer, Cloud-Lemmings, The Last Ninja, Cloud-Canabalt, Cloud-Invaders, Cloud-Kong, VC-20 Blitz, Cloud-PacMan, World of MiniCloud, Судоку, Сапер, Меморі, Блок-пазл, Змійка, Арканоїд, Чотири в ряд і Хрестики-нулики*) з таблицями рекордів."],
        ],
        'music_player' => [
            'en' => ['🎵 Music, Podcast & Audiobook Studio (`music.php`)', "With the **🎵 Music & Audiobook Studio** (`music.php`) you can stream all audio files (`.mp3`, `.m4a`, `.wav`, `.ogg`, `.flac`, `.aac`, `.opus`) directly from your cloud:\n\n- **📖 1-Click Audiobook Mode & `1 → 99` Chapter Sorting:** Automatically recognizes track/disc tags (`TRCK`/`TPOS`) and filename chapter numbers (`Kapitel 2` before `Kapitel 10`, `CD 1/2`), activates the *🎙️ Voice / Audiobook* equalizer, disables shuffle, and shows a resume banner for your last audiobook.\n- **💿 Local ID3 Tag & Cover Art Parser:** Automatically extracts Title, Artist, Album, Year, Duration, and embedded Album Cover art (`APIC`).\n- **📊 Live Frequency Visualizer & Equalizer:** Animated Web Audio API spectrum bars plus 4 sound presets (*🎧 Studio Neutral, 🔊 Bass Boost, 🎙️ Voice / Audiobook, 🎸 Brilliance / Pop*).\n- **🔖 Audiobook Resume & Sleep Timer:** Automatically remembers your playback position (`last_position_sec`), offers `-15s`/`+15s` skip buttons, playback speed (`0.75×`–`2.0×`), Sleep Timer (`🌙 15–60 min`), and **📱 Smartphone Lock-Screen / Car Bluetooth controls**.\n- **📋 Shareable Playlists:** Group tracks into playlists and share them with Cloud users or via public link + QR code!"],
            'fr' => ['🎵 Studio Musique, Podcast & Livre Audio (`music.php`)', "Le **🎵 Studio Musique & Livre Audio** (`music.php`) lit tous vos fichiers audio (`.mp3`, `.m4a`, `.wav`, `.ogg`, `.flac`) avec **📖 Mode Livre Audio 1-clic & tri automatique des chapitres (`1 → 99`)**, lecture des tags ID3 & pochettes d'album, égaliseur 4 profils, reprise automatique des livres audio, minuteur de veille (`🌙`) et playlists partageables."],
            'es' => ['🎵 Estudio de Música, Podcasts y Audiolibros (`music.php`)', "El **🎵 Estudio de Música y Audiolibros** (`music.php`) reproduce todos tus archivos de audio (`.mp3`, `.m4a`, `.wav`, `.ogg`, `.flac`) con **📖 Modo Audiolibro y ordenación natural de capítulos (`1 → 99`)**, etiquetas ID3 y carátulas, visualizador de frecuencias, ecualizador, memoria de posición, temporizador de sueño (`🌙`) y listas de reproducción compartibles."],
            'it' => ['🎵 Studio Musica, Podcast & Audiolibri (`music.php`)', "Lo **🎵 Studio Musica & Audiolibri** (`music.php`) riproduce tutti i file audio (`.mp3`, `.m4a`, `.wav`, `.ogg`, `.flac`) con **📖 Modalità Audiolibro e ordinamento capitoli (`1 → 99`)**, tag ID3 e copertine, visualizzatore di spettro, equalizzatore, segnalibro automatico, sleep timer (`🌙`) e playlist condivisibili."],
            'nl' => ['🎵 Muziek-, Podcast- & Luisterboekstudio (`music.php`)', "Met de **🎵 Muziek- & Luisterboekstudio** (`music.php`) speel je alle audiobestanden (`.mp3`, `.m4a`, `.wav`, `.ogg`, `.flac`) af met **📖 1-klik Luisterboekmodus & hoofdstuksortering (`1 → 99`)**, ID3-tags en albumhoezen, equalizer, automatische hervatting, slaaptimer (`🌙`) en deelbare afspeellijsten."],
            'pl' => ['🎵 Studio Muzyki, Podcastów i Audiobooków (`music.php`)', "**🎵 Studio Muzyki i Audiobooków** (`music.php`) odtwarza pliki audio (`.mp3`, `.m4a`, `.wav`, `.ogg`, `.flac`) z **📖 Trybem Audiobooka i sortowaniem rozdziałów (`1 → 99`)**, tagami ID3 i okładkami, korektorem dźwięku, zapamiętywaniem pozycji, wyłącznikiem czasowym (`🌙`) i udostępnianiem playlist."],
            'pt' => ['🎵 Estúdio de Música, Podcasts e Audiolivros (`music.php`)', "O **🎵 Estúdio de Música e Audiolivros** (`music.php`) reproduz ficheiros de áudio (`.mp3`, `.m4a`, `.wav`, `.ogg`, `.flac`) com **📖 Modo Audiolivro e ordenação de capítulos (`1 → 99`)**, tags ID3 e capas, equalizador, memória de posição, temporizador (`🌙`) e playlists partilháveis."],
            'cs' => ['🎵 Studio hudby, podcastů a audioknih (`music.php`)', "**🎵 Studio hudby a audioknih** (`music.php`) přehrává zvukové soubory (`.mp3`, `.m4a`, `.wav`, `.ogg`, `.flac`) s **📖 Režimem audioknihy a řazením kapitol (`1 → 99`)**, ID3 tagy a obaly alb, ekvalizérem, pamětí pozice, časovačem vypnutí (`🌙`) a sdílenými playlisty."],
            'tr' => ['🎵 Müzik, Podcast ve Sesli Kitap Stüdyosu (`music.php`)', "**🎵 Müzik ve Sesli Kitap Stüdyosu** (`music.php`) buluttaki tüm ses dosyalarını (`.mp3`, `.m4a`, `.wav`, `.ogg`, `.flac`) **📖 Sesli Kitap Modu ve bölüm sıralaması (`1 → 99`)**, ID3 etiketleri, albüm kapakları, ekolayzır, kaldığı yerden devam etme, uyku zamanlayıcısı (`🌙`) ve paylaşılabilir çalma listeleriyle oynatır."],
            'uk' => ['🎵 Студія музики, подкастів та аудіокниг (`music.php`)', "**🎵 Студія музики та аудіокниг** (`music.php`) відтворює аудіофайли (`.mp3`, `.m4a`, `.wav`, `.ogg`, `.flac`) з **📖 Режимом аудіокниги та сортуванням розділів (`1 → 99`)**, ID3-тегами та обкладинками, еквалайзером, запам'ятовуванням позиції, таймером сну (`🌙`) та спільними плейлистами."],
        ],
    ];

    if (isset($translations[$id][$lang])) {
        return [
            'title'  => $translations[$id][$lang][0],
            'answer' => $translations[$id][$lang][1],
        ];
    }

    return null;
}

/**
 * Liefert die strukturierte Wissensdatenbank zu allen Funktionen der MiniCloud
 * inkl. mehrsprachiger Such-Keywords für alle 11 Sprachen.
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
function getAssistantKnowledgeBase(array $appSettings, bool $isAdmin, string $lang = 'de'): array
{
    $appTitle = (string)($appSettings['app_title'] ?? 'MiniCloud');
    $pack     = getAssistantLangPack($lang, '', $appTitle);

    $kb = [
        [
            'id'       => 'upload_files',
            'title'    => 'Dateien & Ordner hochladen',
            'icon'     => '📤',
            'keywords' => [
                'hochladen', 'upload', 'datei hochladen', 'ordner hochladen', 'drag', 'drop', 'ziehen', 'maximale dateigroesse', 'limit', 'speichern',
                'upload file', 'upload folder', 'televerser', 'envoyer fichier', 'subir archivo', 'subir carpeta', 'caricare file',
                'bestand uploaden', 'przeslij plik', 'wgraj plik', 'carregar ficheiro', 'nahrat soubor', 'dosya yukle', 'завантажити файл',
            ],
            'answer'   => "So lädst du **Dateien oder ganze Ordner** in die **{$appTitle}** hoch:\n\n"
                . "1. Öffne **📂 Dateien** (`index.php`).\n"
                . "2. Ziehe Dateien oder einen kompletten Ordner einfach per **Drag & Drop** in das obere Upload-Feld – oder klicke auf **„Dateien wählen“** bzw. **„Ordner wählen“**.\n"
                . "3. Der Upload startet **sofort automatisch** im Hintergrund (inkl. Fortschrittsanzeige). Bei Ordner-Uploads bleibt deine gesamte Unterordner-Struktur 1:1 erhalten.",
            'actions'  => [
                ['label' => $pack['act_files'], 'url' => 'index.php'],
            ],
            'followups' => [
                $pack['q_storage'],
                $pack['q_vault'],
            ],
        ],
        [
            'id'       => 'share_files',
            'title'    => 'Dateien & Ordner teilen (Links & intern)',
            'icon'     => '🔗',
            'keywords' => [
                'teilen', 'freigeben', 'freigabe', 'link', 'oeffentlicher link', 'passwortschutz', 'ablaufdatum', 'benutzer teilen', 'gruppe teilen', 'berechtigung', 'schreibrecht',
                'share', 'share link', 'public link', 'partager', 'lien de partage', 'compartir', 'condividere', 'delen', 'udostepnij', 'partilhar', 'sdilet', 'paylas', 'поділитися', 'спільний доступ',
            ],
            'answer'   => "Du kannst **Dateien und ganze Ordner** auf zwei Arten teilen:\n\n"
                . "- **🌐 Öffentlicher Freigabelink (auch für Gäste ohne Konto):** Klicke bei einer Datei oder einem Ordner auf das **Teilen-Symbol (🔗)**. Optional kannst du ein **Passwort**, ein **Ablaufdatum** und ein **Download-Limit** festlegen oder einen **QR-Code** anzeigen.\n"
                . "- **👤 Intern mit Cloud-Benutzern:** Wähle im Teilen-Dialog einfach per **Live-Suche** den gewünschten Benutzer aus und lege die **3-stufige Berechtigung** fest (**👁️ Nur Lesen**, **✏️ Lesen & Schreiben** oder **🗑️ Lesen, Schreiben & Löschen**).\n\n"
                . "*Hinweis:* Damit dich andere Benutzer in der Teilen-Auswahl finden, muss in deinen **⚙️ Einstellungen** die Option *„Teilen-Sichtbarkeit“* aktiviert sein.",
            'actions'  => [
                ['label' => $pack['act_files'], 'url' => 'index.php'],
                ['label' => $pack['act_settings'], 'url' => 'settings.php'],
            ],
            'followups' => [
                $pack['q_office'],
                $pack['q_webdav'],
            ],
        ],
        [
            'id'       => 'group_folders',
            'title'    => 'Gemeinsame Ordner mit Cloud-Benutzern teilen',
            'icon'     => '📁',
            'keywords' => [
                'gruppenordner', 'teamordner', 'gemeinsamer ordner', 'abteilung', 'verein', 'gruppe', 'ordner teilen',
                'group folder', 'shared folder', 'team folder', 'dossier de groupe', 'carpeta de grupo', 'cartella di gruppo', 'groepsmap', 'folder grupowy', 'pasta de grupo', 'skupinova slozka', 'grup klasoru', 'групова папка',
            ],
            'answer'   => "Gemeinsame **📁 Austausch- & Projekt-Ordner** richtest du direkt über das **Teilen-Menü (`🔗`)** ein:\n\n"
                . "1. Lege in **📂 Dateien** (`index.php`) einen beliebigen Ordner an und klicke auf das **🔗 Teilen-Symbol**.\n"
                . "2. Wähle unter *„Mit Cloud-Benutzern teilen“* die gewünschten Benutzer aus (oder erstelle einen **öffentlichen Ordner-Link / Digitalen Briefkasten**).\n"
                . "3. Lege die gewünschte **3-stufige Berechtigung** fest: **👁️ Nur Lesen**, **✏️ Lesen & Schreiben** oder **🗑️ Lesen, Schreiben & Löschen**.\n"
                . "4. Der geteilte Ordner erscheint bei den berechtigten Benutzern sofort unter *„Für mich freigegeben“* (sowie per WebDAV) und unterstützt die gemeinsame Live-Bearbeitung in **📝 Office Light**!",
            'actions'  => [
                ['label' => $pack['act_files'], 'url' => 'index.php'],
            ],
            'followups' => [
                $pack['q_webdav'],
                $pack['q_office'],
            ],
        ],
        [
            'id'       => 'vault_encryption',
            'title'    => 'Zero-Knowledge Datei-Tresor (AES-256-GCM)',
            'icon'     => '🔒',
            'keywords' => [
                'tresor', 'verschluesseln', 'verschluesselung', 'entschluesseln', 'aes', 'sicher', 'geheim', 'zero knowledge', 'datei schuetzen', 'vault',
                'encrypt', 'encryption', 'decrypt', 'coffre fort', 'chiffrer', 'boveda', 'cifrar', 'cassaforte', 'crittografare', 'kluis', 'versleutelen', 'sejf', 'szyfrowanie', 'cofre', 'encriptar', 'sifrovat', 'kasa', 'sifrele', 'сейф', 'зашифрувати',
            ],
            'answer'   => "Mit dem **🔒 Zero-Knowledge Datei-Tresor** kannst du besonders vertrauliche Dateien direkt auf dem Server mit **AES-256-GCM** (und PBKDF2-Schlüsselableitung) verschlüsseln:\n\n"
                . "1. Klicke in der Dateiliste (`index.php`) bei der gewünschten Datei auf das **Schloss-Symbol (🔒)**.\n"
                . "2. Vergib ein eigenes **Tresor-Passwort**.\n"
                . "3. **Wichtig:** Dieses Passwort wird **nirgendwo** in der Datenbank gespeichert (echtes Zero-Knowledge). Ohne das Passwort kann selbst der Serverbetreiber die Datei nicht lesen.",
            'actions'  => [
                ['label' => $pack['act_files'], 'url' => 'index.php'],
                ['label' => '🔐 Passwords', 'url' => 'passwords.php'],
            ],
            'followups' => [
                $pack['q_storage'],
                $pack['q_pdf'],
            ],
        ],
        [
            'id'       => 'office_light',
            'title'    => 'Office Light (Word, Excel, PowerPoint, Visio, Mind-Maps & Foto-Studio)',
            'icon'     => '📝',
            'keywords' => [
                'office', 'word', 'excel', 'powerpoint', 'visio', 'drawio', 'organigramm', 'flussdiagramm', 'flowchart', 'diagramm', 'praesentation', 'folien', 'beamer', 'slides', 'pptx', 'tabelle', 'kalkulation', 'docx', 'xlsx', 'csv', 'markdown', 'dokument erstellen', 'vorlage', 'brief', 'protokoll', 'gleichzeitig bearbeiten', 'co editing',
                'spreadsheet', 'presentation', 'document', 'tableur', 'hoja de calculo', 'foglio di calcolo', 'arkusz', 'tabulka', 'tablo', 'таблиця', 'документ',
            ],
            'answer'   => "**📝 Office Light** (`office.php`) ist deine integrierte 6-in-1 Büro- & Medien-Zentrale direkt im Browser – komplett ohne Zusatzsoftware:\n\n"
                . "- **📘 Word-Dokumente (`.docx`):** Formatierter Text, Überschriften, Tabellen, Listen, Farben & Druck-/PDF-Ansicht.\n"
                . "- **📗 Excel-Tabellen (`.xlsx` & `.csv`):** Mehrere Tabellenblätter, Rechenformeln (`=SUMME(A1:A10)`, `=MITTELWERT(...)`, `=MIN(...)`, `=MAX(...)`, `=ANZAHL(...)`, `=RUNDEN(...)`, `=WENN(...)`), Spalten-Sortierung und Diagramm-Vorschau.\n"
                . "- **📙 PowerPoint-Präsentationen (`.pptx`):** Visueller 16:9-Folien-Editor mit 6 Folien-Layouts (inkl. Cloud-Bildern & Kennzahlen), 6 Farbdesigns, Sprechernotizen, Handout-PDF-Druck und **▶️ Vollbild-Beamer-Modus mit Laserpointer**.\n"
                . "- **📐 Visio Light (`.drawio` Diagramme & Organigramme):** Flussdiagramme, Prozesspläne und Vereins-/Team-Organigramme mit 6 Formen, intelligenten Verbindungspfeilen, Auto-Layout und SVG-Vektor-Export.\n"
                . "- **🧠 Integrierter Mind-Map-Editor:** Direkt in der Office-Zentrale unter dem Reiter **🧠 Mind-Maps** erreichbar.\n"
                . "- **🎨 Integriertes Foto- & Bild-Studio (`.jpg`, `.png`, `.webp`, `.gif`, `.bmp`):** Fotos direkt zuschneiden, drehen, verkleinern/komprimieren (mit Live-Dateigrößen-Vorschau), mit Filtern optimieren oder per **🔲 DSGVO-Verpixelung** anonymisieren.\n"
                . "- **🟢 Live-Team-Zusammenarbeit:** Wenn mehrere Personen dasselbe Dokument öffnen, seht ihr oben live, wer gerade mitschreibt, und Änderungen werden automatisch synchronisiert!",
            'actions'  => [
                ['label' => $pack['act_office'], 'url' => 'office.php'],
            ],
            'followups' => [
                'Welche Formeln gibt es in Excel Light?',
                'Wie bearbeite oder verkleinere ich ein Bild?',
                $pack['q_pdf'],
            ],
        ],
        [
            'id'       => 'image_studio',
            'title'    => 'Foto- & Bild-Studio (Zuschneiden, Komprimieren, Filter & DSGVO-Verpixelung)',
            'icon'     => '🎨',
            'keywords' => [
                'bildbearbeitung', 'bild bearbeiten', 'foto bearbeiten', 'fotostudio', 'bildstudio', 'zuschneiden', 'crop', 'drehen', 'spiegeln', 'skalieren', 'verkleinern', 'komprimieren', 'bildgroesse', 'aufloesung', 'filter', 'helligkeit', 'kontrast', 'wasserzeichen', 'verpixeln', 'dsgvo pixel', 'unkenntlich', 'kennzeichen', 'gesichter verpixeln', 'webp',
                'image editor', 'photo editor', 'resize image', 'compress image', 'pixelate', 'watermark', 'editeur photo', 'recadrer', 'editor de fotos', 'recortar', 'fotoritocco', 'fotobewerking', 'edytor zdjec', 'kadrowanie', 'editor de imagem', 'uprava fotek', 'fotograf duzenleme', 'редактор фото',
            ],
            'answer'   => "Mit dem integrierten **🎨 Foto- & Bild-Studio** in **Office Light** (`office.php`) bearbeitest du jedes Bild (`.jpg`, `.jpeg`, `.png`, `.webp`, `.gif`, `.bmp`) zu **100 % lokal im Browser** – erreichbar über die **Dateivorschau (`🎨 Bild bearbeiten`)**, die **🖼️ Foto-Galerie (`🎨 Bearbeiten`)** oder den Reiter **🎨 Bilder** in Office Light:\n\n"
                . "- **✂️ Zuschneiden, Drehen & Spiegeln:** 90° links/rechts drehen, horizontal/vertikal spiegeln oder frei bzw. im festen Format (`1:1`, `16:9`, `4:3`, `3:2`, `DIN A4`) zuschneiden.\n"
                . "- **📐 Größe ändern & Komprimieren:** 1-Klick-Skalierung (`75 %`, `50 %`, `Full-HD 1920 px`, `Web 1280 px`, `Kompakt 800 px`), Formatwechsel (`JPEG`, `WebP`, `PNG`) und Qualitätsregler mit **Live-Dateigrößen-Anzeige (`KB / MB`)**.\n"
                . "- **☀️ Belichtung & 1-Klick-Foto-Filter:** Helligkeit, Kontrast, Sättigung und Sepia anpassen oder Filter (*✨ Auto-Brillanz, 🖤 Schwarz-Weiß, 📜 Vintage, 🌅 Warm, 📄 Dokumenten-Kontrast*) wählen.\n"
                . "- **✏️ Zeichnen, Wasserzeichen & 🔲 DSGVO-Verpixelung:** Mit dem **🔲 DSGVO-Verpixelungs-Pinsel** (oder 💧 Unschärfe-Pinsel) machst du Gesichter, Kfz-Kennzeichen oder sensible Daten vor dem Teilen irreversibel unkenntlich. Außerdem kannst du Hinweispfeile, Rahmen und Text-Wasserzeichen (z. B. `© Verein 2026`) einfügen.\n"
                . "- **💾 Sicher speichern:** Wähle oben zwischen **„💾 Original aktualisieren (mit 🕓 Versionsschutz)“** oder **„📄 Als neue Kopie speichern“**!",
            'actions'  => [
                ['label' => '🎨 Bild-Studio in Office Light', 'url' => 'office.php?filter=image'],
                ['label' => '🖼️ Foto-Galerie öffnen', 'url' => 'gallery.php'],
            ],
            'followups' => [
                $pack['q_office'],
                $pack['q_pdf'],
            ],
        ],
        [
            'id'       => 'excel_formulas',
            'title'    => 'Formeln & Rechenfunktionen in Excel Light (.xlsx / .csv)',
            'icon'     => '📊',
            'keywords' => [
                'formel', 'formeln', 'excel formel', 'excel formeln', 'tabellenkalkulation formel', 'tabellenkalkulation formeln', 'rechnen', 'berechnen', 'summe', 'mittelwert', 'durchschnitt', 'anzahl', 'runden', 'wenn', 'wurzel', 'produkt', 'minimum', 'maximum',
                'formula', 'formulas', 'excel formulas', 'sum', 'average', 'count', 'round', 'sqrt', 'formule', 'formules', 'formulas excel', 'formuly', 'vzorce', 'formul', 'формули',
            ],
            'answer'   => "Die Tabellenkalkulation **📗 Excel Light** in **Office Light** (`office.php`) unterstützt folgende **Formeln und Rechenoperationen** (sowohl auf **Deutsch** als auch auf **Englisch**, Groß-/Kleinschreibung egal):\n\n"
                . "- **`=SUMME(A1:A10)` / `=SUM(...)`:** Addiert alle Zahlen im Zellbereich oder einzeln (`=SUMME(A1;B1;50)`).\n"
                . "- **`=MITTELWERT(A1:A10)` / `=AVERAGE(...)` / `=AVG(...)`:** Berechnet den Durchschnitt.\n"
                . "- **`=MIN(A1:A10)` & `=MAX(A1:A10)`:** Findet den kleinsten bzw. größten Zahlenwert.\n"
                . "- **`=ANZAHL(A1:A10)` / `=COUNT(...)`:** Zählt, wie viele Zellen im Bereich Zahlen enthalten.\n"
                . "- **`=PRODUKT(A1:A5)` / `=PRODUCT(...)`:** Multipliziert alle Zahlen im angegebenen Bereich.\n"
                . "- **`=RUNDEN(Wert; Stellen)` / `=ROUND(...)`:** Rundet auf die gewünschte Nachkommastelle (z. B. `=RUNDEN(A1*1,19; 2)`).\n"
                . "- **`=WENN(Bedingung; Dann; Sonst)` / `=IF(...)`:** Logische Prüfung mit `>=`, `<=`, `<>`, `=`, `>`, `<` (z. B. `=WENN(A1>=100; \"Ziel erreicht\"; \"Offen\")`).\n"
                . "- **`=ABS(A1)`, `=WURZEL(A1)` / `=SQRT(A1)` & `=HEUTE()` / `=TODAY()`:** Absolutbetrag, Quadratwurzel und heutiges Datum (`TT.MM.JJJJ`).\n"
                . "- **Grundrechenarten & Klammern:** `+`, `-`, `*`, `/`, `( )` inkl. deutscher Kommazahlen (z. B. `=(D2-E2)*1,19`) und Zirkelbezug-Schutz (`#ZIRKEL!`).\n\n"
                . "💡 *Tipp:* Klicke in der Tabellen-Werkzeugleiste auf **`ƒx Alle Formeln`**, um jedes Beispiel mit 1 Klick in die aktive Zelle einzufügen!",
            'actions'  => [
                ['label' => $pack['act_office'], 'url' => 'office.php'],
                ['label' => '📖 Handbuch öffnen', 'url' => 'help.php#vorschau-sortierung'],
            ],
            'followups' => [
                $pack['q_office'],
                $pack['q_storage'],
            ],
        ],
        [
            'id'       => 'ebook_reader_epub',
            'title'    => 'E-Book-Reader & Bibliothek (.epub)',
            'icon'     => '📚',
            'keywords' => [
                'ebook', 'e book', 'ebooks', 'epub', 'buch', 'buecher', 'lesen', 'lesezeichen', 'buecherregal', 'kapitel', 'sepia',
                'book', 'books', 'reader', 'livres', 'liseuse', 'libro', 'libros', 'lectura', 'boeken', 'ksiazka', 'ksiazki', 'czytnik', 'livro', 'kniha', 'knihy', 'ctecka', 'kitap', 'ekitap', 'книга', 'книги', 'рідер',
            ],
            'answer'   => "Mit dem **📚 E-Book-Reader** (`ebooks.php`) liest du alle **`.epub`-Bücher** deiner Cloud bequem im Browser:\n\n"
                . "- **📚 Automatisches Bücherregal:** Findet alle `.epub`-Dateien aus deinen Ordnern, Gruppenordnern und Freigaben und zeigt automatisch **Buchcover, Titel, Autor und Klappentext** an.\n"
                . "- **🔖 Synchronisierter Lesefortschritt:** Die Cloud merkt sich automatisch dein aktuelles Kapitel und deine Scroll-Position (`% gelesen`), sodass du am Smartphone nahtlos dort weiterlesen kannst, wo du am PC aufgehört hast!\n"
                . "- **🎨 Augenkomfort & Suche:** Wähle zwischen *☀️ Papier*, *📜 Sepia*, *🌆 Schiefer* und *🌙 Nachtmodus*, passe Schriftgröße & Schriftart an, setze **Lesezeichen mit Notiz** und durchsuche das gesamte Buch im Volltext.",
            'actions'  => [
                ['label' => $pack['act_ebooks'], 'url' => 'ebooks.php'],
            ],
            'followups' => [
                $pack['q_office'],
                $pack['q_storage'],
            ],
        ],
        [
            'id'       => 'games_module',
            'title'    => 'Browserspiele, Cloud-Bestenlisten & Live-Duelle',
            'icon'     => '🎮',
            'keywords' => [
                'spiel', 'spiele', 'spielen', 'browserspiele', 'pausenraum', 'vollbild', 'gummigeruch', 'kart', 'traffic racer', 'cloud traffic racer', 'autobahn', 'darts', 'dart', 'cloud darts', 'ally pally', '501', 'bullseye', 'frogger', 'cloud frogger', 'frosch', 'impossible mission', 'atombender', 'stay a while', 'lemmings', 'lemminge', 'cloud lemmings', 'last ninja', 'the last ninja', 'ninja', 'armakuni', 'kunitoki', 'canabalt', 'cloud canabalt', 'parkour', 'space invaders', 'cloud invaders', 'invaders', 'donkey kong', 'cloud kong', 'kong', 'blitz', 'vc20', 'pacman', 'pac-man', 'wow', 'world of minicloud', 'sudoku', 'minesweeper', 'memory', 'tetris', 'block puzzle', 'snake', 'breakout', 'brick breaker', 'vier gewinnt', 'tic tac toe', 'highscore', 'bestenliste', 'duell',
                'game', 'games', 'play', 'leaderboard', 'jeux', 'juegos', 'giochi', 'spellen', 'gry', 'jogos', 'hry', 'oyun', 'oyunlar', 'ігри', 'грати',
            ],
            'answer'   => "In der **🎮 Spiele-Zentrale** (`games.php`) erwarten dich **20 werbefreie Browserspiele** (inkl. **⛶ Vollbild- & Smartphone-Großansicht**):\n\n"
                . "- **🏎️ Rennspiel, Sport & RPG:** **🏎️ Gummigeruch Kart-Cup** (6 Strecken & Ghost-Duelle), **🚘 Cloud-Traffic-Racer** (4-spurige Highway-Action mit Close-Overtake-Kombos, Gegenverkehr, Lichthupe & 5 Tuning-Autos), **🎯 Cloud-Darts (Ally Pally Edition)** (501 Double-Out, 301, Highscore-Shootout & Around the Clock mit Checkout-Berater, 2,5× Lupe & 180-Caller) sowie **⚔️ World of MiniCloud** (Co-Op Fantasy-RPG).\n"
                . "- **🕹️ Retro & Arcade:** **🐸 Cloud-Frogger (1981)** (5-spuriger Highway, Baumstämme, Tauch-Schildkröten & 5 Seerosen-Häuser), **🕵️‍♂️ Impossible Mission (1984 C64)** (*„Stay a while… stay forever!“*), **🥷 The Last Ninja (1987 C64)**, **🏃‍♂️ Cloud-Canabalt**, **👾 Cloud-Invaders (1978)**, **🦍 Cloud-Kong (1981)**, **✈️ Commodore VC-20 „Blitz“ (1981)**, **🟡 Cloud-PacMan**, **🧱 Block-Puzzle (Tetris)**, **🐍 Retro-Snake** und **🏓 Brick Breaker (Breakout)**.\n"
                . "- **🧠 Strategie, Logik & Live-Duelle:** **🐹 Cloud-Lemmings (1991)** (zerstörbares Pixel-Gelände & 8 Fähigkeiten), **🧠 Cloud-Sudoku**, **💣 Minesweeper**, **🃏 Memory** sowie **🔴🟡 Vier Gewinnt & ❌⭕ Tic-Tac-Toe** (gegen KI oder live gegen andere Cloud-Benutzer).\n"
                . "- **🏆 Cloud-Bestenlisten:** Vergleiche deine Highscores (*Heute / 30 Tage / Gesamt*) mit allen Mitgliedern deiner Cloud.",
            'actions'  => [
                ['label' => '🎮 Spiele-Zentrale öffnen', 'url' => 'games.php'],
            ],
            'followups' => [
                $pack['q_ebooks'],
                $pack['q_storage'],
            ],
        ],
        [
            'id'       => 'photo_gallery_albums',
            'title'    => 'Foto-Alben, Medien-Galerie, Diashow & Gäste-Sammelalben',
            'icon'     => '🖼️',
            'keywords' => [
                'galerie', 'foto', 'fotos', 'album', 'alben', 'fotoalbum', 'diashow', 'slideshow', 'exif', 'kameradaten', 'sammelalbum', 'partyfotos', 'bilderwand',
                'gallery', 'photo', 'photos', 'albums', 'galerie photo', 'galeria', 'galleria', 'fotomuur', 'zdjecia', 'fotogalerie', 'galeri', 'галерея', 'фото',
            ],
            'answer'   => "Mit der **🖼️ Foto-Alben & Medien-Galerie** (`gallery.php`) verwaltest und präsentierst du alle Bilder deiner Cloud:\n\n"
                . "- **🖼️ Chronologische Fotowand & Virtuelle Alben:** Fasse Bilder aus verschiedenen Ordnern in Alben zusammen, **ohne dass die Dateien doppelt Speicherplatz verbrauchen**.\n"
                . "- **▶️ Vollbild-Lightbox & Diashow:** Starte eine automatische Diashow (3s / 5s / 8s) mit Zoom und Tastatursteuerung (`←` / `→` / `Leertaste`).\n"
                . "- **📷 Lokaler EXIF-Inspektor:** Zeigt Aufnahmedatum, Kamera-Modell, Blende, Belichtungszeit, ISO und Auflösung direkt im Browser an.\n"
                . "- **📱 Gäste-Link, QR-Code & Party-/Vereins-Sammelalbum:** Teile ein Album per QR-Code mit Gästen (optional passwortgeschützt) und schalte auf Wunsch den **📸 Gäste-Foto-Upload** frei, damit Gäste auf Festen ihre Schnappschüsse direkt vom Smartphone beisteuern können!",
            'actions'  => [
                ['label' => '🖼️ Foto-Galerie öffnen', 'url' => 'gallery.php'],
            ],
            'followups' => [
                $pack['q_storage'],
                $pack['q_files'] ?? $pack['q_events'],
            ],
        ],
        [
            'id'       => 'online_forms_registration',
            'title'    => 'Online-Formulare, Event-Anmeldung, Helferlisten & Mitgliedsanträge',
            'icon'     => '📝',
            'keywords' => [
                'formular', 'formulare', 'anmeldung', 'veranstaltung', 'vereinsfest', 'helferliste', 'mitbringliste', 'schichtplan', 'mitgliedsantrag', 'feedbackbogen', 'fragebogen',
                'forms', 'registration', 'sign up', 'event form', 'formulaire', 'formulario', 'modulo', 'formulier', 'formularz', 'formular', 'анкета', 'форма',
            ],
            'answer'   => "Mit **📝 Online-Formulare & Veranstaltungs-Anmeldung** (`forms.php`) erstellst du im Handumdrehen eigene Anmelde- und Abfragebögen:\n\n"
                . "- **⚡ 4 Schnellstart-Vorlagen:** *🎉 Vereinsfest-/Event-Anmeldung*, *🤝 Helfer- & Schichtliste*, *🏛️ Online-Mitgliedsantrag* und *💬 Feedback-Bogen*.\n"
                . "- **📱 Öffentlicher Gäste-Link & QR-Code:** Teilnehmer können ohne eigenes Cloud-Konto per Smartphone-QR-Code teilnehmen. Auf Wunsch mit **Kapazitätslimit (Max. Plätze)** und **Anmeldeschluss**.\n"
                . "- **📊 CSV-Export & 1-Klick-Vereinsübernahme:** Speichere alle Rückmeldungen mit 1 Klick als CSV-Tabelle im Cloud-Ordner `Formulare/` (für Office Light) oder übernimm eingegangene Mitgliedsanträge direkt per **🏛️ +Mitglied** in die Vereinsverwaltung (`club.php`)!",
            'actions'  => [
                ['label' => '📝 Online-Formulare öffnen', 'url' => 'forms.php'],
                ['label' => '🏛️ Vereinsverwaltung', 'url' => 'club.php'],
            ],
            'followups' => [
                $pack['q_events'],
                $pack['q_office'],
            ],
        ],
        [
            'id'       => 'club_management',
            'title'    => 'Vereinsverwaltung, Mitglieder-Import, Beiträge & Vorstandsrollen',
            'icon'     => '🏛️',
            'keywords' => [
                'verein', 'vereinsverwaltung', 'mitglieder', 'mitgliedsbeitrag', 'kassenwart', 'vorstand', 'arbeitsstunden', 'ehrenamt', 'inventar', 'schluessel', 'mitglieder importieren',
                'club', 'association', 'members', 'membership fee', 'treasurer',
            ],
            'answer'   => "Die **🏛️ Vereinsverwaltung** (`club.php`) bündelt alle Aufgaben für Vereine, Verbände, Sparten und Initiativen:\n\n"
                . "- **👥 Mitgliederdatei & Multi-Quellen-Import (`📤 Importieren`):** Importiere bestehende Mitglieder per CSV/Excel, vCard (`.vcf`), aus deinen Cloud-Kontakten, aus Cloud-Benutzerkonten oder direkt aus einem Online-Mitgliedsantrag (`forms.php`).\n"
                . "- **💶 Beitragstarife & Kassenwart-Sync:** Generiere Jahresbeiträge mit 1 Klick und übergebe bezahlte Beiträge direkt an das gekoppelte Haushaltsbuch (`budget.php`).\n"
                . "- **🛠️ Ehrenamts-Arbeitsstunden, Inventar/Schlüssel & 6 Vorstandsrollen:** Verteile Aufgaben gezielt auf *1. Vorsitzenden, Schriftführer, Kassenwart, Mitgliederwart, Gerätewart* und *Kassenprüfer*.",
            'actions'  => [
                ['label' => '🏛️ Vereinsverwaltung öffnen', 'url' => 'club.php'],
            ],
            'followups' => [
                $pack['q_events'],
                $pack['q_storage'],
            ],
        ],
        [
            'id'       => 'pdf_tools_scan',
            'title'    => 'PDF-Werkzeugkasten & Kamera-Dokumentenscanner',
            'icon'     => '📄',
            'keywords' => [
                'pdf', 'scanner', 'scannen', 'kamera', 'zusammenfuegen', 'merge', 'teilen', 'split', 'komprimieren', 'verkleinern', 'seiten extrahieren', 'beleg scannen',
                'scan', 'compress pdf', 'merge pdf', 'split pdf', 'fusionner pdf', 'unir pdf', 'scansionare', 'skaner', 'skanowac', 'skenovat', 'tara', 'сканер', 'сканувати',
            ],
            'answer'   => "Im Modul **📄 PDF & Scan** (`pdf_tools.php`) stehen dir **6 leistungsstarke PDF-Werkzeuge** zur Verfügung:\n\n"
                . "1. **📸 Kamera- & Foto-Scanner:** Fotografiere Briefe, Rechnungen oder Kassenbons direkt mit dem Smartphone ab – inkl. automatischem **S/W-Dokumentenfilter** für gestochen scharfe PDFs.\n"
                . "2. **🔗 PDFs zusammenfügen (Merge):** Kombiniere mehrere PDF-Dateien aus deiner Cloud zu einem Gesamtdokument.\n"
                . "3. **✂️ Seiten extrahieren (Split):** Löse einzelne Seiten oder Bereiche (z. B. `1-3, 5`) als neues PDF heraus.\n"
                . "4. **🗜️ PDF komprimieren:** Verkleinert große PDF-Dateien für den E-Mail-Versand.\n"
                . "5. **✏️ PDF bearbeiten, ausfüllen & schwärzen**\n"
                . "6. **🔎 Lokale OCR-Texterkennung**",
            'actions'  => [
                ['label' => '📄 PDF & Scan', 'url' => 'pdf_tools.php'],
                ['label' => '✏️ PDF-Editor', 'url' => 'pdf_tools.php#tab-redact'],
                ['label' => '🔎 OCR', 'url' => 'pdf_tools.php#tab-ocr'],
            ],
            'followups' => [
                $pack['q_pdf'],
                $pack['q_ocr'],
            ],
        ],
        [
            'id'       => 'pdf_edit_redact',
            'title'    => 'PDF im Browser ausfüllen, unterschreiben & schwärzen',
            'icon'     => '✏️',
            'keywords' => [
                'pdf bearbeiten', 'pdf ausfuellen', 'formular ausfuellen', 'unterschreiben', 'unterschrift', 'signatur', 'schwaerzen', 'zensieren', 'unkenntlich', 'haekchen', 'kreuz', 'text in pdf',
                'redact', 'sign pdf', 'signature', 'fill pdf', 'caviarder', 'signer pdf', 'firmar pdf', 'censurar pdf', 'firmare pdf', 'oscurare pdf', 'ondertekenen', 'zwartmaken', 'podpisac pdf', 'zamazac', 'assinar pdf', 'podepsat pdf', 'zacernit', 'pdf imzala', 'karart', 'підписати pdf', 'замалювати',
            ],
            'answer'   => "Unter **📄 PDF & Scan → ✏️ 5. PDF bearbeiten, ausfüllen & schwärzen** (`pdf_tools.php#tab-redact`) kannst du jedes PDF direkt im Browser bearbeiten:\n\n"
                . "- **🔤 Text einfügen:** Klicke an eine beliebige Stelle im Formular, um Text (in Wunschgröße und -farbe) einzutragen und frei zu verschieben.\n"
                . "- **✓ / ✕ Stempel:** Setze per Klick Häkchen oder Kreuze in Ankreuzfelder.\n"
                . "- **✍️ Unterschrift zeichnen:** Unterschreibe mit Maus, Finger oder Stift im Signatur-Pad und platziere deine Unterschrift frei skalierbar auf dem Dokument.\n"
                . "- **⬛ DSGVO-sicheres Schwärzen:** Ziehe schwarze Balken über vertrauliche Stellen (z. B. IBAN, Namen) oder übermale sie mit dem **⬜ Weiß-Radierer**.\n"
                . "- Beim Speichern wird jede Seite fest gerastert, sodass geschwärzter Originaltext **physikalisch unwiderruflich entfernt** ist!",
            'actions'  => [
                ['label' => '✏️ PDF-Editor', 'url' => 'pdf_tools.php#tab-redact'],
            ],
            'followups' => [
                $pack['q_ocr'],
                $pack['q_office'],
            ],
        ],
        [
            'id'       => 'ocr_text_recognition',
            'title'    => 'Lokale OCR-Texterkennung (12 Sprachen + Fraktur)',
            'icon'     => '🔎',
            'keywords' => [
                'ocr', 'texterkennung', 'text aus bild', 'text aus pdf', 'scan in text', 'tesseract', 'fraktur', 'altdeutsch', 'sprachen', 'foto in text',
                'text recognition', 'extract text', 'reconnaissance de texte', 'reconocimiento de texto', 'riconoscimento testo', 'tekstherkenning', 'rozpoznawanie tekstu', 'reconhecimento de texto', 'rozpoznavani textu', 'metin tanima', 'розпізнавання тексту',
            ],
            'answer'   => "Mit der **🔎 OCR-Texterkennung** (`pdf_tools.php#tab-ocr`) wandelst du abfotografierte Briefe, Rechnungen, Buchseiten oder gescannte PDFs in **bearbeitbaren Text** um:\n\n"
                . "- **100 % lokal & Strato-/Intranet-fähig:** Die komplette OCR-Engine (`Tesseract WebAssembly`) und alle Sprachmodelle liegen lokal auf deinem Cloud-Server – es werden **keinerlei Daten ins Internet** übertragen.\n"
                . "- **12 europäische Sprachen + Fraktur:** Unterstützt Deutsch (`deu`), Englisch (`eng`), Französisch (`fra`), Spanisch (`spa`), Italienisch (`ita`), Niederländisch (`nld`), Polnisch (`pol`), Tschechisch (`ces`), Portugiesisch (`por`), Türkisch (`tur`), Ukrainisch (`ukr`) sowie **📜 Deutsche Fraktur / Altdeutsch (`frk`)**.\n"
                . "- Den erkannten Text kannst du sofort bearbeiten, kopieren oder als **`.txt`**, **📝 Office-Light-Dokument (`.html`)** oder **📌 Haftnotiz** in der Cloud speichern.",
            'actions'  => [
                ['label' => '🔎 OCR', 'url' => 'pdf_tools.php#tab-ocr'],
            ],
            'followups' => [
                $pack['q_office'],
                $pack['q_pdf'],
            ],
        ],
        [
            'id'       => 'webdav_caldav_carddav',
            'title'    => 'WebDAV, CalDAV & CardDAV (Netzlaufwerk & Smartphone-Sync)',
            'icon'     => '📡',
            'keywords' => [
                'webdav', 'caldav', 'carddav', 'netzlaufwerk', 'synchronisieren', 'sync', 'windows explorer', 'mac finder', 'iphone', 'ios', 'android', 'davx5', 'thunderbird', 'app passwort',
                'network drive', 'lecteur reseau', 'unidad de red', 'disco di rete', 'netwerkschijf', 'dysk sieciowy', 'disco de rede', 'sitovy disk', 'ag surucusu', 'мережевий диск', 'синхронізація',
            ],
            'answer'   => "Deine Cloud unterstützt alle drei offenen Synchronisations-Standards – die genauen Verbindungs-URLs findest du in deinen **⚙️ Einstellungen**:\n\n"
                . "1. **📡 WebDAV (`webdav.php`):** Binde deine Cloud-Dateien als echtes **Netzlaufwerk** im Windows Explorer, macOS Finder oder unter Linux ein.\n"
                . "2. **📅 CalDAV (`caldav.php`):** Synchronisiere deinen Cloud-Kalender live mit Apple Kalender (iPhone/Mac), Thunderbird oder Android (DAVx⁵).\n"
                . "3. **📇 CardDAV (`carddav.php`):** Synchronisiere dein Cloud-Adressbuch direkt mit den Kontakten auf deinem Smartphone oder PC.\n\n"
                . "💡 **Tipp:** Erstelle dir unter **⚙️ Einstellungen → App-Passwörter** ein eigenes Geräte-Passwort für WebDAV/CalDAV/CardDAV – das funktioniert auch bei aktivierter 2FA!",
            'actions'  => [
                ['label' => $pack['act_settings'], 'url' => 'settings.php'],
            ],
            'followups' => [
                $pack['q_events'],
                $pack['q_storage'],
            ],
        ],
        [
            'id'       => 'calendar_module',
            'title'    => 'Termin-Kalender & Kalender-Freigaben',
            'icon'     => '📅',
            'keywords' => [
                'kalender', 'termin', 'termine', 'geburtstag', 'wiederholung', 'serie', 'ics', 'ical', 'kalender teilen',
                'calendar', 'appointment', 'event', 'calendrier', 'rendez vous', 'calendario', 'cita', 'appuntamento', 'agenda', 'afspraak', 'kalendarz', 'wydarzenie', 'compromisso', 'kalendar', 'udalost', 'takvim', 'randevu', 'календар', 'подія',
            ],
            'answer'   => "Im **📅 Kalender** (`calendar.php`) verwaltest du persönliche Termine, Gruppentermine und Erinnerungen:\n\n"
                . "- Unterstützt **Monats-, Wochen- und Listenansicht**, ganztägige Termine, Serien-Wiederholungen (täglich, wöchentlich, monatlich, jährlich) und **Cloud-Dateianhänge** direkt am Termin.\n"
                . "- **Geburtstage** aus deinen **📇 Kontakten** werden automatisch mit 🎂-Symbol im Kalender eingeblendet.\n"
                . "- Du kannst deinen Kalender für andere Benutzer oder Gruppen freigeben (private Termine mit `🔒 Privat` bleiben dabei geschützt) sowie `.ics`-Dateien importieren und exportieren.",
            'actions'  => [
                ['label' => $pack['act_calendar'], 'url' => 'calendar.php'],
            ],
            'followups' => [
                $pack['q_events'],
                $pack['q_webdav'],
            ],
        ],
        [
            'id'       => 'tasks_kanban',
            'title'    => 'Aufgaben & Kanban-Board',
            'icon'     => '📋',
            'keywords' => [
                'aufgaben', 'todo', 'to do', 'kanban', 'board', 'erledigen', 'checkliste', 'prioritaet', 'faelligkeit',
                'tasks', 'taches', 'tareas', 'compiti', 'taken', 'zadania', 'tarefas', 'ukoly', 'gorevler', 'завдання',
            ],
            'answer'   => "Das **📋 Aufgaben- & Kanban-Board** (`tasks.php`) organisiert deine To-Dos übersichtlich in 3 Spalten (**Offen**, **In Arbeit**, **Erledigt**):\n\n"
                . "- Verschiebe Karten einfach per **Drag & Drop** zwischen den Spalten.\n"
                . "- Jede Aufgabe unterstützt **Prioritäten** (Niedrig bis Kritisch), **Fälligkeitsdaten**, **Unteraufgaben-Checklisten**, **Cloud-Dateianhänge** und die Zuweisung an Benutzer oder Gruppen.\n"
                . "- 💡 **Schnellbefehl hier im Chat:** Schreibe mir einfach `Aufgabe: Steuerunterlagen sortieren` (bzw. `Task: ...`) und ich lege die Aufgabe direkt für dich an!",
            'actions'  => [
                ['label' => $pack['act_tasks'], 'url' => 'tasks.php'],
            ],
            'followups' => [
                $pack['q_tasks'],
                $pack['q_events'],
            ],
        ],
        [
            'id'       => 'projects_module',
            'title'    => 'Projektmanagement, Meilensteine & Gantt-Zeitstrahl',
            'icon'     => '🚀',
            'keywords' => [
                'projekt', 'projekte', 'projektmanagement', 'gantt', 'zeitstrahl', 'meilenstein', 'zeiterfassung', 'budget', 'projektbericht',
                'project', 'projects', 'milestone', 'time tracking', 'projet', 'proyecto', 'progetto', 'projecten', 'projekt', 'projeto', 'proje', 'проєкт', 'проект',
            ],
            'answer'   => "Unter **🚀 Projekte** (`projects.php`) steuerst du größere Vorhaben im Team:\n\n"
                . "- **Visueller Gantt-Zeitstrahl** mit Start-/Enddaten und Fortschrittsbalken (`0–100 %`).\n"
                . "- **Meilensteine (🎯)**, **Budget- & Ausgabenkontrolle** sowie integrierte **⏱️ Zeiterfassung (Stechuhr & Stundeneinträge)**.\n"
                . "- Direkt verknüpft mit Aufgaben aus dem Kanban-Board und einem druckfertigen **Projektstatus-Bericht**.",
            'actions'  => [
                ['label' => '🚀 Projects', 'url' => 'projects.php'],
            ],
            'followups' => [
                $pack['q_tasks'],
                $pack['q_office'],
            ],
        ],
        [
            'id'       => 'notes_pinboard',
            'title'    => 'Notizwand, Sprachnotizen & Diktierfunktion (Haftnotizen)',
            'icon'     => '📌',
            'keywords' => [
                'notiz', 'notizen', 'haftnotiz', 'pinboard', 'pinnwand', 'tag', 'hashtag', 'anpinnen', 'markdown export', 'sprachnotiz', 'sprachnotizen', 'sprachaufnahme', 'diktieren', 'diktat', 'mikrofon',
                'notes', 'sticky note', 'voice note', 'dictation', 'notas', 'notities', 'notatki', 'poznamky', 'notlar', 'нотатки',
            ],
            'answer'   => "Auf der **📌 Notizwand** (`notes.php`) hältst du Ideen, Checklisten und Sprachmemos auf farbigen Haftnotizen fest:\n\n"
                . "- **🎙️ Sprachnotizen per Mikrofon:** Nimm im Notiz-Dialog direkt per Mikrofon eine Sprachnotiz auf (inkl. Live-Pegelanzeige) oder hänge eine Audiodatei an. Die Aufnahme wird in `Notizen/Sprachnotizen/` gespeichert und lässt sich direkt auf der Notizkarte mit Tempo-Wahl (`1×` bis `2×`) abspielen.\n"
                . "- **🗣️ Sprache-zu-Text-Diktierfunktion:** Klicke auf **„🗣️ Text diktieren“**, um gesprochene Sätze live in das Textfeld schreiben zu lassen.\n"
                . "- **🏷️ `#Tags`, Anpinnen & Teilen:** Filtere nach `#Tags` oder über den Schnellfilter **„🎙️ Sprachnotizen“**, pinne wichtige Notizen oben an (erscheinen auch in **🏠 Mein Tag**), teile sie mit Benutzern und exportiere jede Notiz als `.md`-Datei.\n"
                . "- 💡 **Schnellbefehl hier im Chat:** Schreibe mir einfach `Notiz: WLAN-Gastpasswort` (bzw. `Note: ...`) und ich erstelle die Haftnotiz sofort für dich!",
            'actions'  => [
                ['label' => $pack['act_notes'], 'url' => 'notes.php'],
            ],
            'followups' => [
                $pack['q_notes'],
                $pack['q_tasks'],
            ],
        ],
        [
            'id'       => 'passwords_manager',
            'title'    => 'Passwort-Manager & Schlüsselbund',
            'icon'     => '🔐',
            'keywords' => [
                'passwort manager', 'schluesselbund', 'zugangsdaten', 'logins speichern', 'passwort generator', 'wlan passwort', 'lizenzschluessel', 'pin speichern',
                'password manager', 'keychain', 'mot de passe', 'gestor de contrasenas', 'portachiavi', 'wachtwoordbeheer', 'menedzer hasel', 'gestor de senhas', 'spravce hesel', 'sifre yoneticisi', 'менеджер паролів',
            ],
            'answer'   => "Der **🔐 Passwort-Manager** (`passwords.php`) speichert deine Zugangsdaten, WLAN-Passwörter, Server-Logins, Lizenzen und PINs **AES-256-GCM-verschlüsselt** in der Datenbank:\n\n"
                . "- Enthält einen **Passwort-Generator** mit Sicherheitsbewertung, Ein-Klick-Kopieren für Benutzername & Passwort, Kategorien und Favoriten.\n"
                . "- Einzelne Einträge (z. B. ein gemeinsamer Vereins- oder Team-Zugang) lassen sich gezielt mit anderen Cloud-Benutzern oder Gruppen teilen.",
            'actions'  => [
                ['label' => '🔐 Passwords', 'url' => 'passwords.php'],
            ],
            'followups' => [
                $pack['q_vault'],
                $pack['q_storage'],
            ],
        ],
        [
            'id'       => 'polls_menti',
            'title'    => 'Umfragen (Doodle-Alternative) & Live-Mentimeter',
            'icon'     => '📊',
            'keywords' => [
                'umfrage', 'abstimmung', 'terminfinder', 'doodle', 'menti', 'mentimeter', 'wortwolke', 'quiz', 'publikum', 'voting', 'qr code',
                'poll', 'polls', 'survey', 'sondage', 'encuesta', 'sondaggio', 'peiling', 'ankieta', 'sondagem', 'anketa', 'anket', 'опитування',
            ],
            'answer'   => "Für Abstimmungen bietet die Cloud gleich zwei spezialisierte Module:\n\n"
                . "1. **📊 Terminfinder & Umfragen (`polls.php`):** Ideal zum Finden eines gemeinsamen Termins (*Ja / Vielleicht / Nein*) oder für Entscheidungen – intern für Gruppen oder per **öffentlichem Gast-Link** ohne Login. Den Sieger-Termin übernimmst du per 1-Klick in den Kalender!\n"
                . "2. **🎤 Live-Mentimeter (`menti.php`):** Interaktive Live-Präsentationen für Meetings, Unterricht oder Events mit **Wortwolken, Live-Balken, Bewertungs-Skalen, Q&A-Fragerunde und Quiz** – Teilnehmer scannen einfach den QR-Code oder geben den 6-stelligen PIN ein.",
            'actions'  => [
                ['label' => '📊 Polls', 'url' => 'polls.php'],
                ['label' => '🎤 Live-Mentimeter', 'url' => 'menti.php'],
            ],
            'followups' => [
                $pack['q_events'],
                $pack['q_messages'],
            ],
        ],
        [
            'id'       => 'chat_messages',
            'title'    => 'Messenger, Chat & Rundnachrichten',
            'icon'     => '💬',
            'keywords' => [
                'chat', 'nachricht', 'nachrichten', 'messenger', 'schreiben', 'rundnachricht', 'broadcast',
                'message', 'messages', 'mensajes', 'messaggi', 'berichten', 'wiadomosci', 'mensagens', 'zpravy', 'mesajlar', 'повідомлення',
            ],
            'answer'   => "Über **💬 Nachrichten** (`messages.php`) tauschst du dich direkt mit anderen Cloud-Benutzern aus:\n\n"
                . "- Unterstützt **Direktnachrichten**, **Cloud-Dateianhänge** direkt im Chat sowie **📢 Admin-Rundnachrichten** an alle Benutzer.\n"
                . "- Über das **Schnell-Suchfeld** findest du auch bei vielen Benutzern sofort den gewünschten Empfänger.\n"
                . "- In den **⚙️ Einstellungen** kannst du festlegen, ob du bei neuen Nachrichten per Ton oder Browser-Meldung informiert werden möchtest.",
            'actions'  => [
                ['label' => $pack['act_messages'], 'url' => 'messages.php'],
            ],
            'followups' => [
                $pack['q_messages'],
                $pack['q_storage'],
            ],
        ],
        [
            'id'       => 'trash_versions',
            'title'    => 'Papierkorb & Datei-Versionierung',
            'icon'     => '🗑️',
            'keywords' => [
                'papierkorb', 'geloescht', 'wiederherstellen', 'aus versehen geloescht', 'version', 'versionen', 'aeltere version', 'historie',
                'trash', 'recycle bin', 'restore', 'deleted', 'corbeille', 'papelera', 'cestino', 'prullenbak', 'kosz', 'reciclagem', 'kos', 'cop kutusu', 'кошик', 'відновити',
            ],
            'answer'   => "Keine Sorge bei versehentlich gelöschten oder überschriebenen Dateien:\n\n"
                . "- **🗑️ Papierkorb (`trash.php`):** Gelöschte Dateien landen zuerst für **30 Tage** im Papierkorb und können dort jederzeit mit einem Klick an ihren ursprünglichen Ort wiederhergestellt werden.\n"
                . "- **🕒 Datei-Versionierung:** Wenn du eine gleichnamige Datei erneut hochlädst oder in *Office Light* überschreibst, hebt die Cloud ältere Stände automatisch in der Versionshistorie auf.",
            'actions'  => [
                ['label' => $pack['act_trash'], 'url' => 'trash.php'],
                ['label' => $pack['act_files'], 'url' => 'index.php'],
            ],
            'followups' => [
                $pack['q_storage'],
                $pack['q_vault'],
            ],
        ],
        [
            'id'       => 'settings_2fa_themes',
            'title'    => 'Einstellungen, 2FA-Sicherheit, Themes & 11 Sprachen',
            'icon'     => '⚙️',
            'keywords' => [
                'einstellungen', 'passwort aendern', 'zwei faktor', '2fa', 'sicherheitscode', 'theme', 'dark mode', 'dunkel', 'design', 'sprache', 'sprachen', 'timeout', 'abmelden', 'anheften', 'stern',
                'settings', 'language', 'languages', 'parametres', 'langue', 'ajustes', 'idioma', 'impostazioni', 'lingua', 'instellingen', 'taal', 'ustawienia', 'jezyk', 'definicoes', 'nastaveni', 'jazyk', 'ayarlar', 'dil', 'налаштування', 'мова',
            ],
            'answer'   => "In deinen persönlichen **⚙️ Einstellungen** (`settings.php`) passt du die Cloud genau an deine Wünsche an:\n\n"
                . "- **🌐 11 europäische Sprachen:** Deutsch, English, Français, Español, Italiano, Nederlands, Polski, Português, Čeština, Türkçe & Українська.\n"
                . "- **🎨 13 augenschonende Design-Themes** (Sanft Hell, Halbdunkel wie *Dämmerung/Mokka/Petrol* und Dunkel).\n"
                . "- **🔒 Zwei-Faktor-Authentifizierung (2FA per E-Mail)** und **⏱️ automatisches Sitzungs-Timeout**.\n"
                . "- **⭐ Module oben anheften:** Öffne oben in der Navigation das Menü **„⊞ Module“** und klicke auf den Stern (`☆`/`⭐`), um deine Lieblingsmodule direkt in die obere Leiste zu holen!",
            'actions'  => [
                ['label' => $pack['act_settings'], 'url' => 'settings.php'],
            ],
            'followups' => [
                $pack['q_webdav'],
                $pack['q_storage'],
            ],
        ],
        [
            'id'       => 'mindmap_editor',
            'title'    => 'Mind-Map-Editor & Ideensammler',
            'icon'     => '🧠',
            'keywords' => [
                'mindmap', 'mind map', 'mind-map', 'ideensammler', 'brainstorming', 'knoten', 'gedankenkarte', 'baumdiagramm',
                'carte mentale', 'mapa mental', 'mappa mentale', 'mapa mysli', 'myslenkova mapa', 'zihin haritasi', 'інтелект-карта',
            ],
            'answer'   => "Mit dem **🧠 Mind-Map-Editor** (`mindmap.php`) strukturierst du Ideen, Projekte, Reisen oder Lernthemen visuell auf einer interaktiven Zeichenfläche:\n\n"
                . "- **Tastatur-Turbo:** `Tab` legt einen Unterknoten an, `Enter` einen Geschwisterknoten, `Entf` löscht einen Knoten und per Doppelklick bearbeitest du den Text.\n"
                . "- **Auto-Layout & Vorlagen:** Wähle zwischen Radial- und Baum-Layout sowie Vorlagen für *Brainstorming, Projektplanung, Reise & Wissen*.\n"
                . "- **1-Klick-Übergabe & Export:** Wandle jeden Knoten per Klick direkt in eine **📋 Aufgabe** (`tasks.php`) oder **📌 Haftnotiz** (`notes.php`) um oder speichere die Mind-Map als SVG/PNG/Markdown in deinen Cloud-Dateien!",
            'actions'  => [
                ['label' => '🧠 Mind-Map-Editor', 'url' => 'mindmap.php'],
            ],
            'followups' => [
                $pack['q_tasks'],
                $pack['q_notes'],
            ],
        ],
        [
            'id'       => 'budget_planner',
            'title'    => 'Haushaltsplaner & Finanz-Übersicht',
            'icon'     => '💰',
            'keywords' => [
                'haushaltsplaner', 'haushaltsbuch', 'finanzen', 'budget', 'ausgaben', 'einnahmen', 'fixkosten', 'abo', 'abos', 'sparziel', 'kassensturz', 'kostenausgleich',
                'finance', 'expenses', 'income', 'presupuesto', 'bilancio', 'huishoudboek', 'budzet', 'orcamento', 'rozpocet', 'butce', 'бюджет', 'фінанси',
            ],
            'answer'   => "Der **💰 Haushaltsplaner** (`budget.php`) unterstützt dich bei deiner persönlichen oder gemeinsamen Finanzplanung (Familie, Paar, WG oder Verein):\n\n"
                . "- **📊 Einnahmen, Ausgaben & Kategorien:** Monatliche Bilanz, 6-Monats-Trend, Kategorie-Balken und direkte **Beleg-Verknüpfung** mit deinen Cloud-Dateien.\n"
                . "- **🔄 Fixkosten & Abos:** Hinterlege Miete, Strom, Versicherungen oder Streaming-Abos einmalig und übernimm alle fälligen Posten per **1-Klick** in den aktuellen Monat.\n"
                . "- **🐷 Sparziele & 👥 Kostenausgleich:** Verfolge Sparziele mit Fortschrittsbalken und lass bei geteilten Haushaltsbüchern automatisch berechnen, wer wem wie viel ausgleichen muss.",
            'actions'  => [
                ['label' => '💰 Haushaltsplaner', 'url' => 'budget.php'],
            ],
            'followups' => [
                $pack['q_storage'],
                $pack['q_office'],
            ],
        ],
        [
            'id'       => 'browser_games',
            'title'    => 'Browserspiele, Cloud-Highscores & Live-Duelle',
            'icon'     => '🎮',
            'keywords' => [
                'spiele', 'spiel', 'browserspiele', 'pausenraum', 'vollbild', 'gummigeruch', 'kart', 'traffic racer', 'cloud traffic racer', 'autobahn', 'lemmings', 'lemminge', 'cloud lemmings', 'last ninja', 'the last ninja', 'ninja', 'armakuni', 'kunitoki', 'canabalt', 'cloud canabalt', 'parkour', 'space invaders', 'cloud invaders', 'invaders', 'donkey kong', 'cloud kong', 'kong', 'blitz', 'vc20', 'pacman', 'pac-man', 'wow', 'world of minicloud', 'sudoku', 'minesweeper', 'memory', 'tetris', 'block puzzle', 'snake', 'brick breaker', 'vier gewinnt', 'tic tac toe', 'highscore',
                'games', 'game', 'jeux', 'juegos', 'giochi', 'spellen', 'gry', 'jogos', 'hry', 'oyunlar', 'ігри',
            ],
            'answer'   => "In der **🎮 Spiele-Zentrale** (`games.php`) erwarten dich 17 werbefreie Browserspiele (inkl. **⛶ Vollbild-Modus** für Smartphone & PC):\n\n"
                . "- **Rennspiel, Strategie, Retro & RPG:** *Gummigeruch Kart-Cup*, *Cloud-Traffic-Racer*, *Cloud-Lemmings (1991)*, *The Last Ninja (1987 C64)*, *Cloud-Canabalt*, *Cloud-Invaders (1978)*, *Cloud-Kong (1981)*, *Commodore VC-20 Blitz (1981)*, *Cloud-PacMan*, *World of MiniCloud (RPG)*, *Block-Puzzle*, *Retro-Snake* und *Brick Breaker*.\n"
                . "- **Logik & Live-Duelle:** *Cloud-Sudoku*, *Minesweeper*, *Memory* sowie **Vier Gewinnt** oder **Tic-Tac-Toe** gegen die Computer-KI oder andere Cloud-Mitglieder in Echtzeit!",
            'actions'  => [
                ['label' => '🎮 Spiele-Zentrale', 'url' => 'games.php'],
            ],
            'followups' => [
                $pack['q_ebooks'],
                $pack['q_messages'],
            ],
        ],
        [
            'id'       => 'music_player',
            'title'    => 'Musik-, Podcast- & Hörbuch-Studio (MP3-Player & Hörbuch-Modus)',
            'icon'     => '🎵',
            'keywords' => [
                'musik', 'mp3', 'audio', 'player', 'musikplayer', 'hoerbuch', 'hörbuch', 'hoerbuchmodus', 'hörbuchmodus', 'kapitel', 'nummerierung', 'sortieren', 'reihenfolge', 'podcast', 'playlist', 'wiedergabeliste', 'equalizer', 'id3', 'cover', 'sleep timer', 'schlummer', 'flac', 'wav', 'ogg', 'm4a',
                'music', 'music player', 'audiobook', 'chapter', 'musique', 'lecteur audio', 'musica', 'reproductor', 'muziek', 'muzyka', 'hudba', 'muzik', 'музика', 'плеєр',
            ],
            'answer'   => "Mit dem **🎵 Musik-, Podcast- & Hörbuch-Studio** (`music.php`) spielst du alle Audiodateien (`.mp3`, `.m4a`, `.wav`, `.ogg`, `.flac`, `.aac`, `.opus`) direkt aus deiner Cloud ab:\n\n"
                . "- **📖 1-Klick-Hörbuch-Modus & Kapitel-Sortierung (`1 → 99`):** Erkennt Track-/CD-Nummern aus ID3-Tags (`TRCK`/`TPOS`) sowie Dateinamen (`Kapitel 2` vor `Kapitel 10`, `01_Intro.mp3`, `CD 1/2`), sortiert mehrteilige Hörbücher automatisch in die richtige Reihenfolge, aktiviert das Klangprofil *🎙️ Stimme / Hörbuch* und zeigt oben ein **Hörbuch-Gedächtnis-Banner** zum Weiterhören an.\n"
                . "- **💿 100 % lokale ID3-Tag- & Cover-Erkennung:** Liest Titel, Interpret, Album, Jahr, Spieldauer und eingebettete Album-Cover (`APIC`) automatisch aus.\n"
                . "- **📊 Frequenz-Visualizer & 4 Sound-Presets:** Live-Frequenzbalken sowie 4 Klangprofile (*🎧 Studio Neutral, 🔊 Bass-Boost, 🎙️ Stimme / Hörbuch, 🎸 Brillanz / Pop*).\n"
                . "- **🔖 Hörbuch-Positionsmerker & Sleep-Timer:** Merkt sich automatisch deine letzte Hörposition (`last_position_sec`), bietet `-15s`/`+15s`-Sprungtasten, Tempo-Wahl (`0.75×`–`2.0×`), **🌙 Sleep-Timer** (`15–60 Min.`) sowie **📱 Smartphone-Sperrbildschirm- & Auto-Bluetooth-Steuerung**.\n"
                . "- **📋 Teilbare Playlists:** Erstelle eigene Wiedergabelisten und teile sie intern mit Cloud-Mitgliedern oder extern per Link & QR-Code!",
            'actions'  => [
                ['label' => '🎵 Musik- & Hörbuch-Studio', 'url' => 'music.php'],
                ['label' => '📖 Hörbuch-Modus starten', 'url' => 'music.php?audiobook=1&sort=chapter'],
            ],
            'followups' => [
                $pack['q_ebooks'],
                $pack['q_files'] ?? $pack['q_storage'],
            ],
        ],
        [
            'id'       => 'pwa_app_install',
            'title'    => 'Als App auf Smartphone, Tablet oder PC installieren (PWA)',
            'icon'     => '📱',
            'keywords' => [
                'app', 'installieren', 'smartphone', 'handy', 'iphone', 'ipad', 'android', 'startbildschirm', 'homescreen', 'pwa',
                'install app', 'installer application', 'instalar app', 'installare app', 'app installeren', 'zainstaluj aplikacje', 'uygulama yukle', 'встановити додаток',
            ],
            'answer'   => "Du kannst die **{$appTitle}** ohne App-Store wie eine echte App mit eigenem Symbol auf deinem Startbildschirm installieren:\n\n"
                . "- **📱 Android (Chrome) & Desktop (Chrome/Edge):** Klicke unten in der Fußzeile auf **„📲 App installieren“**.\n"
                . "- **🍏 iPhone & iPad (Safari):** Tippe unten in Safari auf das **Teilen-Symbol (⬆️)** und wähle **„Zum Home-Bildschirm“**.\n"
                . "- Die Cloud startet danach im Vollbildmodus ohne störende Browser-Adressleiste!",
            'actions'  => [
                ['label' => $pack['act_dashboard'], 'url' => 'dashboard.php'],
            ],
            'followups' => [
                $pack['q_webdav'],
                $pack['q_ebooks'],
            ],
        ],
        [
            'id'       => 'it_security_concept',
            'title'    => 'IT-Sicherheitskonzept, Verschlüsselung & Datenschutz (TOM)',
            'icon'     => '🛡️',
            'keywords' => [
                'sicherheitskonzept', 'it-sicherheit', 'it sicherheit', 'wie sicher', 'abgesichert', 'datenschutz', 'dsgvo', 'tom',
                'bcrypt', 'aes-256', 'brute force', 'rate limit', 'prepared statements', 'sql injection', 'xss', 'csrf', 'security concept', 'gdpr',
            ],
            'answer'   => "Laut dem offiziellen **🛡️ IT-Sicherheitskonzept** (`help.php#it-sicherheit`) ist deine **{$appTitle}** mehrstufig gehärtet:\n\n"
                . "1. **🔐 Login, 2FA & Brute-Force-Schutz:** Passwörter werden mit **BCRYPT (Cost 12)** gehasht. Optional ist **2FA per E-Mail oder Google Authenticator (TOTP RFC 6238 mit 100 % lokalem QR-Code)** aktivierbar. Nach **5 Fehlversuchen in 5 Minuten** greift eine automatische Sperre.\n"
                . "2. **🔒 Dateispeicher-Isolierung & Zero-Knowledge-Tresor:** Dateien werden niemals unter ihrem Originalnamen im Dateisystem abgelegt, sondern als nicht ausführbare `.bin`-Dateien (`0640`) im per Webserver gesperrten Ordner `uploads/`. Im **Tresor-Modus** erfolgt eine **AES-256-GCM-Verschlüsselung** (`PBKDF2-HMAC-SHA256`), ohne dass das Passwort jemals gespeichert wird.\n"
                . "3. **🛡️ Schutz vor SQLi, XSS, CSRF & IDOR:** 100 % echte **PDO Prepared Statements**, kontextsensitive HTML-/JSON-Maskierung, **256-Bit-CSRF-Tokens** für jede Änderung sowie strikte Eigentümer-/Freigabeprüfungen.\n"
                . "4. **🧠 100 % Lokale KI (DSGVO-konform):** Der Cloud-Assistent und das optionale Sprachmodell (Ollama auf `127.0.0.1:11434`) laufen vollständig lokal auf diesem Server – es fließen keinerlei Daten an externe Cloud-Anbieter.",
            'actions'  => [
                ['label' => '🛡️ IT-Sicherheitskonzept', 'url' => 'help.php#it-sicherheit'],
                ['label' => '⚙️ Sicherheit & 2FA', 'url' => 'settings.php'],
            ],
            'followups' => [
                'Welche Daten speichert die Cloud laut Datenverzeichnis?',
                $pack['q_vault'],
            ],
        ],
        [
            'id'       => 'data_dictionary_retention',
            'title'    => 'Datenverzeichnis, Speicherorte & Löschfristen (Data Dictionary)',
            'icon'     => '🗂️',
            'keywords' => [
                'datenverzeichnis', 'data dictionary', 'welche daten', 'was wird gespeichert', 'loeschfristen', 'löschfristen',
                'aufbewahrung', 'tabellen', 'datenbanktabellen', 'speicherorte', 'localstorage', 'wie lange gespeichert', 'audit_logs',
            ],
            'answer'   => "Das **🗂️ Datenverzeichnis** (`help.php#datenverzeichnis`) dokumentiert transparent alle gespeicherten Daten und Löschfristen:\n\n"
                . "- **🗑️ Papierkorb (`files.deleted_at`):** Gelöschte Dateien werden nach **30 Tagen** automatisch physisch vom Server entfernt (`unlink`) oder sofort beim Leeren des Papierkorbs.\n"
                . "- **🕓 Dateiversionen (`file_versions`):** Automatische Rotation der maximal **5 neuesten Stände** pro Datei; Endlöschung zusammen mit der Hauptdatei.\n"
                . "- **📜 Sicherheits-Audit-Log (`audit_logs`):** Speichert sicherheitsrelevante Ereignisse mit **anonymisierter IP-Adresse** (letztes Oktett maskiert) und bereinigt Einträge automatisch nach **90 Tagen**.\n"
                . "- **💾 System-Snapshots (`uploads/.system_backups/`):** Reiner Programmcode für den 1-Klick-Rollback (maximal die **8 neuesten Stände**).\n"
                . "- **🧠 Assistenten-Kontext (`\$_SESSION['assistant_context']`):** Dein Gesprächsgedächtnis für Anschlussfragen liegt nur flüchtig in der aktuellen PHP-Sitzung und verfällt beim Abmelden.",
            'actions'  => [
                ['label' => '🗂️ Datenverzeichnis öffnen', 'url' => 'help.php#datenverzeichnis'],
                ['label' => $pack['act_trash'], 'url' => 'trash.php'],
            ],
            'followups' => [
                'Wie ist die Cloud laut IT-Sicherheitskonzept geschützt?',
                $pack['q_storage'],
            ],
        ],
        [
            'id'       => 'calculator_studio',
            'title'    => 'Taschenrechner, Rechenstreifen (Bon-Summe Σ), MwSt.-, Einheiten- & Zinsrechner',
            'icon'     => '🧮',
            'keywords' => ['taschenrechner', 'rechner', 'calculator', 'rechenstreifen', 'bon', 'mehrwertsteuer', 'mwst', 'netto', 'brutto', 'prozentrechner', 'rabatt', 'trinkgeld', 'einheiten', 'umrechner', 'umrechnen', 'zinsrechner', 'zinseszins', 'sparplan', 'kreditrechner', 'darlehen', 'wissenschaftlicher rechner', 'sinus', 'wurzel'],
            'answer'   => "Das Modul **🧮 Taschenrechner & Rechen-Studio** (`calculator.php`) bietet 4 spezialisierte Rechen-Werkzeuge:\n\n"
                . "1. **🔢 Standard- & Wissenschaftlicher Rechner:** Mit Live-Vorschau, Klammern, Potenzen, Wurzeln, `sin`/`cos`/`tan` (DEG/RAD), Logarithmen, Fakultät (`n!`), Speicher (`MC`, `MR`, `M+`, `M-`) und voller **Tastatur-/Numpad-Steuerung**.\n"
                . "2. **🧾 Interaktiver Rechenstreifen (Bon-Rechner):** Jeder Rechenschritt landet auf dem chronologischen Streifen, lässt sich einzeln beschriften (z. B. *„Getränke“*, *„Material“*), in die **Gesamtsumme ($\Sigma$)** ein-/ausschließen und per **1-Klick** als **📌 Haftnotiz** (`notes.php`) oder als **`.txt` / `.csv` Datei** im Cloud-Ordner `Rechnungen/` speichern.\n"
                . "3. **💶 MwSt.-, Rabatt- & Trinkgeld-Rechner:** Sofortige Netto $\leftrightarrow$ Brutto Berechnung (19 %, 7 % oder frei), Rabatt-Ersparnis und Aufteilen von Rechnungen auf mehrere Personen.\n"
                . "4. **📐 Einheiten-Umrechner & 📈 Zins-/Kredit-Rechner:** Länge, Gewicht, Fläche, Volumen, Temperatur, Datenspeicher (`MB`/`GB`/`TB`), Geschwindigkeit sowie Zinseszins-/Sparplan- und Darlehens-Raten.",
            'actions'  => [
                ['label' => '🧮 Taschenrechner öffnen', 'url' => 'calculator.php'],
                ['label' => '💰 Haushaltsplaner', 'url' => 'budget.php'],
            ],
            'followups' => [
                'Was ist 19% von 249,50?',
                'Wie funktioniert der Haushaltsplaner?',
            ],
        ],
        [
            'id'       => 'toolbox_studio',
            'title'    => 'Alltags-Werkzeuge & Toolbox (WLAN-QR, GiroCode, Glücksrad, Stoppuhr, Text-Diff & Farben)',
            'icon'     => '🧰',
            'keywords' => ['toolbox', 'werkzeuge', 'alltagswerkzeuge', 'qr code', 'wlan qr', 'gäste wlan', 'girocode', 'epc qr', 'überweisung qr', 'glücksrad', 'auslosung', 'zufall', 'würfel', 'münzwurf', 'lotto', 'stoppuhr', 'timer', 'countdown', 'weltzeituhr', 'zeitzone', 'diff', 'textvergleich', 'wortzähler', 'farbwähler', 'hex', 'rgb', 'wcag', 'kontrast', 'passphrase', 'uuid', 'base64', 'sha256'],
            'answer'   => "Das Modul **🧰 Alltags-Werkzeuge & Toolbox** (`toolbox.php`) ist das 6-in-1 Schweizer Taschenmesser deiner Cloud:\n\n"
                . "1. **📶 QR-Code, Gäste-WLAN-Karten & GiroCode-Studio:** Druckfertige Gäste-WLAN-Aufsteller, SEPA-Überweisungs-QR-Codes (GiroCode für Banking-Apps), Visitenkarten und Weblinks – 100 % lokal berechnet und direkt im Cloud-Ordner `Werkzeuge/` speicherbar.\n"
                . "2. **🎡 Glücksrad, Team-Generator & Würfel:** Animiertes Glücksrad mit Sound, Zufalls-Team-/Turnier-Auslosung, Münzwurf, Rollenspiel-Würfel (`W6`/`W20`) und Lottozahlen.\n"
                . "3. **⏱️ Stoppuhr, Countdown & Weltzeituhr:** Hundertstelsekunden-Stoppuhr mit Rundenzeiten, Küchentimer/Pomodoro-Wecker und internationale Weltzeituhr mit Zeitzonen-Schieberegler.\n"
                . "4. **🔍 Text-Werkstatt & Dokumenten-Vergleich:** Zeilenweiser Diff-Checker (Original vs. Geändert), Wort-/Zeichen-/Lesezeit-Zähler und 1-Klick-Zeilenreiniger.\n"
                . "5. **🎨 Farb- & WCAG-Kontrast-Studio:** Umrechner (`HEX` $\leftrightarrow$ `RGB` $\leftrightarrow$ `HSL` $\leftrightarrow$ `CMYK`), Farbharmonien und Barrierefreiheits-Prüfer.\n"
                . "6. **🔐 Passphrasen, UUID & Kodierung:** Deutsche 4-Wort-Passphrasen, starke Zufallspasswörter, `UUID v4`, `Base64`, `JSON`-Formatter & `SHA-256`.",
            'actions'  => [
                ['label' => '🧰 Toolbox öffnen', 'url' => 'toolbox.php'],
                ['label' => '🧮 Taschenrechner', 'url' => 'calculator.php'],
            ],
            'followups' => [
                'Wie funktioniert der Taschenrechner?',
                'Welche Spiele gibt es in der Cloud?',
            ],
        ],
    ];

    if ($isAdmin) {
        $kb[] = [
            'id'       => 'admin_features',
            'title'    => 'Administration, Benutzersperre, Teil-Admins, Statistik & Update',
            'icon'     => '🛠️',
            'keywords' => ['admin', 'administration', 'benutzer anlegen', 'benutzer sperren', 'entsperren', 'sperre', 'teil admin', 'teil-admin', 'adminrechte uebertragen', 'kontingent', 'quota', 'smtp', 'mail', 'impressum', 'logo', 'backup', 'datenbank', 'update', 'github', 'modul statistik', 'audit log', 'statistics'],
            'answer'   => "Als **Administrator** stehen dir unter **🛠️ Admin** (`admin.php`) 5 Verwaltungsbereiche zur Verfügung:\n\n"
                . "1. **👥 Benutzer, Sperren & Teil-Admin-Rechte:** Konten anlegen, Quotas vergeben, einzelne Benutzer per **🔒 Sperren / 🔓 Entsperren** sofort sperren (inkl. Sperrgrund) sowie einzelne Verwaltungsbereiche (**🛡️ Teil-Rechte**) gezielt an andere Benutzer delegieren.\n"
                . "2. **🧩 Module & Statistik:** Alle 18 Cloud-Module einzeln ein-/ausschalten sowie die **📊 Modul-Nutzungsstatistik** (Heute / 7 Tage / 30 Tage / Gesamt + Datenbestand) einsehen.\n"
                . "3. **🎨 Darstellung & Impressum:** Cloud-Name, eigenes Logo und Impressum anpassen.\n"
                . "4. **✉️ E-Mail & SMTP:** Versand für 2FA-Codes, Einladungen und Passwort-Reset konfigurieren.\n"
                . "5. **🖥️ System, Backup & GitHub-Updater:** Server-Auslastung, Sicherheits-Audit-Log, 1-Klick-SQL-Backup und direktes Online-Update von GitHub.",
            'actions'  => [
                ['label' => '🛠️ Admin', 'url' => 'admin.php'],
                ['label' => '📊 Modul-Statistik', 'url' => 'admin.php#tab-modules'],
            ],
            'followups' => [
                $pack['q_storage'],
                'Welche Module werden am häufigsten genutzt?',
            ],
        ];
    }

    // Lokalisierte Übersetzungen für Nicht-Deutsch anwenden
    foreach ($kb as &$entry) {
        $loc = getLocalizedKbTranslation($entry['id'], $lang, $appTitle);
        if ($loc !== null) {
            $entry['title']  = $loc['title'];
            $entry['answer'] = $loc['answer'];
        }
    }
    unset($entry);

    return $kb;
}

/**
 * Speichert den aktuellen Gesprächsschritt im Session-Kontext-Gedächtnis (Stufe 1 & 2),
 * damit Folgefragen ("Und wie teile ich das?", "Geht das auch mit Passwort?") verstanden werden.
 *
 * @param array<int, array{label: string, url: string}> $actions
 */
function rememberAssistantTurn(string $question, string $reply, string $topicId = '', array $actions = []): void
{
    if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
        @session_start();
    }

    if (!isset($_SESSION['assistant_context']) || !is_array($_SESSION['assistant_context'])) {
        $_SESSION['assistant_context'] = [
            'last_topic_id' => '',
            'last_actions'  => [],
            'history'       => [],
        ];
    }

    if ($topicId !== '') {
        $_SESSION['assistant_context']['last_topic_id'] = $topicId;
    }
    if (!empty($actions)) {
        $_SESSION['assistant_context']['last_actions'] = $actions;
    }

    $cleanReply = trim(preg_replace('/\s+/u', ' ', strip_tags($reply)) ?? '');
    if ($cleanReply !== '') {
        $_SESSION['assistant_context']['history'][] = [
            'q' => mb_substr(trim($question), 0, 260),
            'a' => mb_substr($cleanReply, 0, 420),
        ];

        if (count($_SESSION['assistant_context']['history']) > 4) {
            $_SESSION['assistant_context']['history'] = array_slice($_SESSION['assistant_context']['history'], -4);
        }
    }
}

/**
 * Sicherer mathematischer Ausdrucks-Auswerter (Shunting-Yard ohne eval()),
 * unterstützt +, -, *, /, ^, Klammern sowie deutsche Kommazahlen (z. B. "1250,50 * 1,19").
 */
function evaluateSafeMathExpression(string $expr): ?float
{
    $clean = str_replace([',', '×', '÷', ':'], ['.', '*', '/', '/'], trim($expr));
    $clean = preg_replace('/\s+/u', '', $clean) ?? '';
    if ($clean === '' || !preg_match('/^[0-9+\-*\/().^]+$/', $clean) || !preg_match('/[+\-*\/^]/', $clean)) {
        return null;
    }

    $tokens = [];
    $len    = strlen($clean);
    $i      = 0;
    while ($i < $len) {
        $ch = $clean[$i];
        if (ctype_digit($ch) || $ch === '.' || (($ch === '-' || $ch === '+') && ($i === 0 || in_array($clean[$i - 1], ['(', '+', '-', '*', '/', '^'], true)))) {
            $numStr = $ch;
            $i++;
            while ($i < $len && (ctype_digit($clean[$i]) || $clean[$i] === '.')) {
                $numStr .= $clean[$i];
                $i++;
            }
            if (!is_numeric($numStr)) {
                return null;
            }
            $tokens[] = (float)$numStr;
            continue;
        }
        if (in_array($ch, ['+', '-', '*', '/', '^', '(', ')'], true)) {
            $tokens[] = $ch;
            $i++;
            continue;
        }
        return null;
    }

    $prec = ['+' => 1, '-' => 1, '*' => 2, '/' => 2, '^' => 3];
    $output = [];
    $ops    = [];
    foreach ($tokens as $tok) {
        if (is_float($tok)) {
            $output[] = $tok;
        } elseif ($tok === '(') {
            $ops[] = $tok;
        } elseif ($tok === ')') {
            while (!empty($ops) && end($ops) !== '(') {
                $output[] = array_pop($ops);
            }
            if (empty($ops)) {
                return null;
            }
            array_pop($ops);
        } else {
            while (!empty($ops) && end($ops) !== '(' && ($prec[end($ops)] ?? 0) >= ($prec[$tok] ?? 0)) {
                $output[] = array_pop($ops);
            }
            $ops[] = $tok;
        }
    }
    while (!empty($ops)) {
        $op = array_pop($ops);
        if ($op === '(' || $op === ')') {
            return null;
        }
        $output[] = $op;
    }

    $stack = [];
    foreach ($output as $rpn) {
        if (is_float($rpn)) {
            $stack[] = $rpn;
        } else {
            if (count($stack) < 2) {
                return null;
            }
            $b = (float)array_pop($stack);
            $a = (float)array_pop($stack);
            switch ($rpn) {
                case '+': $stack[] = $a + $b; break;
                case '-': $stack[] = $a - $b; break;
                case '*': $stack[] = $a * $b; break;
                case '/':
                    if (abs($b) < 1e-12) {
                        return null;
                    }
                    $stack[] = $a / $b;
                    break;
                case '^': $stack[] = $a ** $b; break;
                default: return null;
            }
        }
    }

    return count($stack) === 1 && is_finite($stack[0]) ? (float)$stack[0] : null;
}

/**
 * Stufe 2 (Optional): Fragt das lokale KI-Sprachmodell über Ollama (127.0.0.1:11434) an,
 * sofern in den Einstellungen aktiviert und auf dem Server verfügbar (z. B. auf runready.cloud).
 * Unterstützt den Dual-Modus:
 *  - Bei Fragen zur MiniCloud nutzt das Modell strikt das Handbuch & die Live-Kontodaten.
 *  - Bei allgemeinen Wissens-, Alltags-, Text- oder Übersetzungsfragen antwortet es frei aus seinem Allgemeinwissen.
 * Auf Shared-Webhosting (wie STRATO) kehrt diese Funktion sofort (< 1 ms) mit null zurück.
 *
 * @param array<int, array{id: string, title: string, icon: string, answer: string}> $topKbEntries
 */
function queryLocalOllamaAssistant(
    PDO $pdo,
    int $userId,
    string $username,
    bool $isAdmin,
    array $appSettings,
    string $question,
    string $lang,
    array $topKbEntries,
    bool $isGeneralQuestion = false
): ?string {
    if (($appSettings['assistant_use_ollama'] ?? '1') !== '1') {
        return null;
    }

    $preferredModel = trim((string)($appSettings['assistant_ollama_model'] ?? 'gemma2:2b')) ?: 'gemma2:2b';
    $status = probeLocalOllamaStatus($preferredModel);
    if (!$status['available'] || $status['active_model'] === '') {
        return null;
    }

    // Session-Lock vor dem lokalen Modell-Aufruf nicht blockieren, aber History vorher auslesen
    $history = $_SESSION['assistant_context']['history'] ?? [];

    // Kompakte Live-Konto-Fakten für den System-Prompt zusammenstellen
    $appTitle = (string)($appSettings['app_title'] ?? 'MiniCloud');
    $stats    = getUserStorageStats($pdo, $userId);
    $freeStr  = formatBytes($stats['free']);
    $usedStr  = formatBytes($stats['used']);
    $quotaStr = formatBytes($stats['quota']);
    $unread   = getUnreadMessagesCount($pdo, $userId);

    $openTasksCount = 0;
    $nextEventStr   = 'keine anstehenden Termine';
    try {
        $stmtT = $pdo->prepare("SELECT COUNT(*) FROM `tasks` WHERE (`user_id` = :u1 OR `assigned_to` = :u2) AND `status` IN ('todo', 'in_progress')");
        $stmtT->execute([':u1' => $userId, ':u2' => $userId]);
        $openTasksCount = (int)$stmtT->fetchColumn();

        $stmtE = $pdo->prepare("SELECT `title`, `start_at` FROM `calendar_events` WHERE (`user_id` = :u1 OR `shared_user_id` = :u2) AND `end_at` >= CURDATE() ORDER BY `start_at` ASC LIMIT 2");
        $stmtE->execute([':u1' => $userId, ':u2' => $userId]);
        $evRows = $stmtE->fetchAll();
        if (!empty($evRows)) {
            $evParts = [];
            foreach ($evRows as $er) {
                $evParts[] = date('d.m.Y H:i', strtotime((string)$er['start_at']) ?: time()) . ' (' . $er['title'] . ')';
            }
            $nextEventStr = implode(', ', $evParts);
        }
    } catch (Throwable) {
    }

    $kbContextLines = [];
    foreach (array_slice($topKbEntries, 0, 1) as $kbe) {
        $shortAns = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$kbe['answer']) ?? ''), 0, 520);
        $kbContextLines[] = "{$kbe['icon']} {$kbe['title']}: {$shortAns}";
    }
    $kbContextStr = !empty($kbContextLines)
        ? implode("\n", $kbContextLines)
        : "MiniCloud Module: Dateien & Tresor (index.php), Office Light (office.php), Kalender (calendar.php), Aufgaben (tasks.php), Projekte (projects.php), Notizen & Sprachnotizen (notes.php), Kontakte (contacts.php), Messenger & Anrufe (messages.php), Musik- & Hörbuch-Studio (music.php), E-Books (ebooks.php), PDF & OCR (pdf_tools.php), Foto-Galerie (gallery.php), Formulare (forms.php), Verein (club.php), Haushalt (budget.php), Mind-Map (mindmap.php), Umfragen (polls.php), Mentimeter (menti.php), Passwörter (passwords.php), Spiele (games.php).";

    $langNames = [
        'de' => 'Deutsch', 'en' => 'English', 'fr' => 'Français', 'es' => 'Español',
        'it' => 'Italiano', 'nl' => 'Nederlands', 'pl' => 'Polski', 'pt' => 'Português',
        'cs' => 'Čeština', 'tr' => 'Türkçe', 'uk' => 'Українська',
    ];
    $targetLangName = $langNames[$lang] ?? 'Deutsch';

    if ($isGeneralQuestion) {
        $sysPrompt = "Du bist der freundliche lokale KI-Assistent von {$username} in {$appTitle} (Datum: " . date('d.m.Y') . "). "
            . "Antworte IMMER auf {$targetLangName}, präzise, hilfreich und kompakt (ca. 2 bis 5 Sätze oder Stichpunkte mit **Fettdruck**). "
            . "WICHTIG: Wenn der Benutzer eine kurze Anschlussfrage stellt (z. B. 'Und wie lautet sie für ...?', 'Und bei ...?', 'Und dort?'), beziehe sie direkt auf die unmittelbar vorherige Frage im Gesprächsverlauf!";
    } else {
        $sysPrompt = "Du bist der lokale KI-Assistent von {$appTitle} (Benutzer: {$username}, Datum: " . date('d.m.Y') . "). "
            . "Antworte auf {$targetLangName} kompakt (3-5 Sätze/Stichpunkte). Beachte bei Anschlussfragen immer den vorherigen Gesprächsverlauf. "
            . "Nutze für Cloud-Fragen diese Fakten (nenne niemals Google Drive/Dropbox): "
            . "Speicher frei: {$freeStr} von {$quotaStr}, Offene Aufgaben: {$openTasksCount}, Ungelesene Nachrichten: {$unread}, Termine: {$nextEventStr}. "
            . "Handbuch: {$kbContextStr}";
    }

    $messages = [
        ['role' => 'system', 'content' => $sysPrompt],
    ];
    if (is_array($history)) {
        foreach (array_slice($history, -2) as $turn) {
            if (!empty($turn['q']) && !empty($turn['a'])) {
                $messages[] = ['role' => 'user', 'content' => mb_substr((string)$turn['q'], 0, 180)];
                $messages[] = ['role' => 'assistant', 'content' => mb_substr((string)$turn['a'], 0, 240)];
            }
        }
    }
    $messages[] = ['role' => 'user', 'content' => $question];

    $payload = json_encode([
        'model'      => $status['active_model'],
        'messages'   => $messages,
        'stream'     => false,
        'keep_alive' => '30m',
        'options'    => [
            'temperature' => $isGeneralQuestion ? 0.3 : 0.2,
            'num_predict' => 180,
            'num_thread'  => 3,
        ],
    ], JSON_UNESCAPED_UNICODE);

    if (!is_string($payload)) {
        return null;
    }

    // PHP-Session-Sperre vor dem lokalen KI-Aufruf freigeben, damit andere Requests nicht warten
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $ctx = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\nContent-Length: " . strlen($payload) . "\r\n",
            'content'       => $payload,
            'timeout'       => 45.0,
            'ignore_errors' => true,
        ],
    ]);

    $rawResp = @file_get_contents('http://127.0.0.1:11434/api/chat', false, $ctx);
    if (!is_string($rawResp) || $rawResp === '') {
        return null;
    }

    $decoded = json_decode($rawResp, true);
    $content = trim((string)($decoded['message']['content'] ?? ''));
    if ($content === '') {
        return null;
    }

    // Falls das Token-Limit mitten im letzten Satz erreicht wurde, sauber am letzten Satzende abschneiden
    if (($decoded['done_reason'] ?? '') === 'length' && !preg_match('/[.!?:)`*»"”]\s*$/u', $content)) {
        if (preg_match('/^(.*[.!?])\s+[^.!?]+$/su', $content, $mSent) && mb_strlen($mSent[1]) >= 60) {
            $content = trim($mSent[1]);
        } else {
            $content .= '.';
        }
    }

    return $content;
}

/**
 * Wandelt einen Markdown-/Text-Entwurf (des KI-Modells oder Vorlagen-Generators)
 * in sauberes HTML für writeHtmlAsDocx() um.
 */
function convertMarkdownDraftToDocxHtml(string $markdown): string
{
    $lines = preg_split('/\r\n|\r|\n/', trim($markdown)) ?: [];
    $htmlParts = [];
    $inList = false;

    $formatInline = static function (string $line): string {
        $safe = htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safe = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $safe) ?? $safe;
        $safe = preg_replace('/\*(.+?)\*/u', '<em>$1</em>', $safe) ?? $safe;
        return $safe;
    };

    foreach ($lines as $rawLine) {
        $line = trim($rawLine);
        if ($line === '' || $line === '---') {
            if ($inList) {
                $htmlParts[] = '</ul>';
                $inList = false;
            }
            continue;
        }

        if (preg_match('/^###\s+(.+)$/u', $line, $m)) {
            if ($inList) {
                $htmlParts[] = '</ul>';
                $inList = false;
            }
            $htmlParts[] = '<h3>' . $formatInline(trim($m[1], '* ')) . '</h3>';
        } elseif (preg_match('/^##\s+(.+)$/u', $line, $m)) {
            if ($inList) {
                $htmlParts[] = '</ul>';
                $inList = false;
            }
            $htmlParts[] = '<h2>' . $formatInline(trim($m[1], '* ')) . '</h2>';
        } elseif (preg_match('/^#\s+(.+)$/u', $line, $m)) {
            if ($inList) {
                $htmlParts[] = '</ul>';
                $inList = false;
            }
            $htmlParts[] = '<h1>' . $formatInline(trim($m[1], '* ')) . '</h1>';
        } elseif (preg_match('/^(?:[-*•])\s+(.+)$/u', $line, $m)) {
            if (!$inList) {
                $htmlParts[] = '<ul>';
                $inList = true;
            }
            $htmlParts[] = '<li>' . $formatInline($m[1]) . '</li>';
        } else {
            if ($inList) {
                $htmlParts[] = '</ul>';
                $inList = false;
            }
            if (preg_match('/^\*\*Betreff:(.+)\*\*$/iu', $line, $mSubj) || preg_match('/^Betreff:\s*(.+)$/iu', $line, $mSubj)) {
                $htmlParts[] = '<h2>Betreff: ' . $formatInline(trim($mSubj[1], '* ')) . '</h2>';
            } else {
                $htmlParts[] = '<p>' . $formatInline($line) . '</p>';
            }
        }
    }

    if ($inList) {
        $htmlParts[] = '</ul>';
    }

    return implode("\n", $htmlParts);
}

/**
 * Erstellt auf Wunsch des Benutzers (z. B. "Formuliere mir ein Kündigungsschreiben für den Tennisverein")
 * automatisch ein fertiges Word-Dokument (.docx) im Cloud-Ordner "Dokumente/" und gibt die Datei-ID
 * für MiniCloud Office Light (office.php?id=...) zurück.
 *
 * @return array{file_id: int, filename: string, draft_markdown: string, used_ai: bool}|null
 */
function createAssistantOfficeDocx(
    PDO $pdo,
    int $userId,
    string $username,
    array $appSettings,
    string $question
): ?array {
    if (($appSettings['enable_office'] ?? '1') !== '1') {
        return null;
    }

    $storageStats = getUserStorageStats($pdo, $userId);
    if ($storageStats['free'] < 65536) {
        return null;
    }

    $qNorm = normalizeAssistantText($question);
    $todayStr = date('d.m.Y');

    // Thema / Empfänger aus der Anfrage extrahieren (z. B. "für den Tennisverein" -> "Tennisverein")
    $topicLabel = 'Schreiben';
    if (preg_match('/(?:fuer|für|an|beim|bei|wegen|zum|zur)\s+(?:den|die|das|dem|der|meinen|meine|mein)?\s*([a-zA-ZäöüÄÖÜß0-9\-\s]{3,45})/iu', $question, $mTop)) {
        $cand = trim((string)preg_replace('/\b(bitte|formulieren|formuliere|schreiben|erstelle|erstellen|entwerfe|mir|ein|eine|einen|als|in|office|word|dokument)\b/iu', '', $mTop[1]), " -,.?!");
        if (mb_strlen($cand) >= 3) {
            $topicLabel = ucfirst($cand);
        }
    }

    $isCancellation = hasAnyKeyword($qNorm, ['kuendigung', 'kuendigungsschreiben', 'kuendigen', 'abmeldung', 'austritt']);
    $isInvitation   = hasAnyKeyword($qNorm, ['einladung', 'einladungsschreiben', 'jahreshauptversammlung', 'mitgliederversammlung', 'sommerfest', 'weihnachtsfeier']);
    $isProtocol     = hasAnyKeyword($qNorm, ['protokoll', 'sitzungsprotokoll', 'besprechungsprotokoll', 'tagesordnung']);

    if ($isCancellation) {
        $docCategoryTitle = 'Kündigungsschreiben';
        $filePrefix       = 'Kuendigung_' . ($topicLabel !== 'Schreiben' ? $topicLabel : 'Vertrag');
    } elseif ($isInvitation) {
        $docCategoryTitle = 'Einladungsschreiben';
        $filePrefix       = 'Einladung_' . ($topicLabel !== 'Schreiben' ? $topicLabel : 'Veranstaltung');
    } elseif ($isProtocol) {
        $docCategoryTitle = 'Besprechungsprotokoll';
        $filePrefix       = 'Protokoll_' . ($topicLabel !== 'Schreiben' ? $topicLabel : 'Sitzung');
    } else {
        $docCategoryTitle = 'Schreiben';
        $filePrefix       = 'Dokument_' . ($topicLabel !== 'Schreiben' ? $topicLabel : 'Entwurf');
    }

    $draftMarkdown = '';
    $usedAi = false;

    // 1. Versuch: Stufe 2 (Lokales KI-Modell z. B. gemma2:2b via Ollama), falls aktiv
    if (($appSettings['assistant_use_ollama'] ?? '1') === '1') {
        $preferredModel = trim((string)($appSettings['assistant_ollama_model'] ?? 'gemma2:2b')) ?: 'gemma2:2b';
        $status = probeLocalOllamaStatus($preferredModel);
        if ($status['available'] && $status['active_model'] !== '') {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            $docPrompt = "Du bist ein professioneller deutscher Brief- und Dokumenten-Assistent. "
                . "Verfasse SOFORT und OHNE einleitende Vorrede direkt den fertigen Text für folgenden Wunsch von {$username} (Datum: {$todayStr}):\n"
                . "„{$question}“\n\n"
                . "Vorgaben:\n"
                . "- Beginne mit Absender ({$username}, [Straße], [PLZ Ort]), Empfänger ({$topicLabel}, [Adresse]) und Datum ({$todayStr}).\n"
                . "- Füge eine fettgedruckte Betreffzeile (**Betreff: ...**), eine höfliche Anrede, alle rechtlich/inhaltlich wichtigen Absätze (bei Kündigungen inkl. Mitglieds-/Vertragsnummer-Platzhalter, Kündigung zum nächstmöglichen Zeitpunkt, Bitte um schriftliche Bestätigung und Widerruf einer evtl. Einzugsermächtigung) sowie „Mit freundlichen Grüßen“ und {$username} ein.";

            $payload = json_encode([
                'model'      => $status['active_model'],
                'messages'   => [
                    ['role' => 'user', 'content' => $docPrompt],
                ],
                'stream'     => false,
                'keep_alive' => '30m',
                'options'    => [
                    'temperature' => 0.25,
                    'num_predict' => 380,
                    'num_thread'  => 3,
                ],
            ], JSON_UNESCAPED_UNICODE);

            if (is_string($payload)) {
                $ctx = stream_context_create([
                    'http' => [
                        'method'        => 'POST',
                        'header'        => "Content-Type: application/json\r\nContent-Length: " . strlen($payload) . "\r\n",
                        'content'       => $payload,
                        'timeout'       => 45.0,
                        'ignore_errors' => true,
                    ],
                ]);
                $rawResp = @file_get_contents('http://127.0.0.1:11434/api/chat', false, $ctx);
                if (is_string($rawResp) && $rawResp !== '') {
                    $decoded = json_decode($rawResp, true);
                    $aiText  = trim((string)($decoded['message']['content'] ?? ''));
                    if (mb_strlen($aiText) >= 80) {
                        $draftMarkdown = $aiText;
                        $usedAi = true;
                    }
                }
            }
        }
    }

    // 2. Fallback: Stufe 1 (DIN-5008 Vorlagen-Generator – funktioniert auch auf STRATO ohne KI)
    if ($draftMarkdown === '') {
        $recipientName = $topicLabel !== 'Schreiben' ? $topicLabel : '[Name des Vereins / Unternehmens]';
        if ($isCancellation) {
            $draftMarkdown = "**Absender:** {$username} · [Straße & Hausnummer] · [PLZ Ort]\n\n"
                . "**Empfänger:**\n{$recipientName}\n[Straße & Hausnummer]\n[PLZ Ort]\n\n"
                . "**Datum:** {$todayStr}\n\n"
                . "**Betreff: Ordentliche Kündigung meiner Mitgliedschaft / meines Vertrags ({$recipientName})**\n"
                . "**Mitglieds- / Vertragsnummer:** [Nummer eintragen]\n\n"
                . "Sehr geehrte Damen und Herren,\n\n"
                . "hiermit kündige ich meine bestehende Mitgliedschaft bzw. meinen Vertrag bei **{$recipientName}** ordentlich und fristgerecht zum nächstmöglichen Zeitpunkt.\n\n"
                . "Bitte bestätigen Sie mir den Eingang dieser Kündigung sowie das genaue Beendigungsdatum schriftlich oder per E-Mail.\n\n"
                . "Gleichzeitig widerrufe ich das Ihnen erteilte SEPA-Lastschriftmandat (Einzugsermächtigung) zum Ablauf der regulären Vertragslaufzeit.\n\n"
                . "Ich bedanke mich für die bisherige Zeit und verbleibe\n\n"
                . "mit freundlichen Grüßen\n\n"
                . "**{$username}**";
        } elseif ($isInvitation) {
            $draftMarkdown = "# Einladung: {$recipientName}\n\n"
                . "**Datum des Schreibens:** {$todayStr}\n"
                . "**Von:** {$username}\n\n"
                . "Liebe Mitglieder, liebe Kolleginnen und Kollegen,\n\n"
                . "hiermit lade ich herzlich zu unserer kommenden Veranstaltung / Versammlung (**{$recipientName}**) ein:\n\n"
                . "- **Datum:** [TT.MM.JJJJ]\n"
                . "- **Uhrzeit:** [HH:MM] Uhr\n"
                . "- **Ort:** [Vereinsheim / Konferenzraum / Adresse]\n\n"
                . "## Geplante Tagesordnung\n"
                . "- TOP 1: Begrüßung und Feststellung der Beschlussfähigkeit\n"
                . "- TOP 2: Rückblick und aktueller Stand\n"
                . "- TOP 3: Wichtige Themen, Abstimmungen & Ausblick\n"
                . "- TOP 4: Verschiedenes & gemütlicher Ausklang\n\n"
                . "Über eine kurze Rückmeldung zur Teilnahme bis zum **[Datum]** freue ich mich sehr.\n\n"
                . "Mit herzlichen Grüßen\n\n"
                . "**{$username}**";
        } elseif ($isProtocol) {
            $draftMarkdown = "# 📋 Besprechungsprotokoll: {$recipientName}\n\n"
                . "**Datum:** {$todayStr} · **Protokollführung:** {$username}\n\n"
                . "## 1. Teilnehmer\n"
                . "- {$username}\n"
                . "- [Weitere Teilnehmer eintragen]\n\n"
                . "## 2. Besprochene Tagesordnungspunkte\n"
                . "- **TOP 1:** Aktueller Status & Rückblick\n"
                . "- **TOP 2:** Zentrale Entscheidungen & Abstimmungen\n\n"
                . "## 3. Beschlüsse & Nächste Aufgaben (To-Dos)\n"
                . "- [ ] Aufgabe 1 – Verantwortlich: [Name] – Frist: [Datum]\n"
                . "- [ ] Aufgabe 2 – Verantwortlich: [Name] – Frist: [Datum]\n";
        } else {
            $draftMarkdown = "**Absender:** {$username} · [Straße & Hausnummer] · [PLZ Ort]\n\n"
                . "**Empfänger:**\n{$recipientName}\n[Straße & Hausnummer]\n[PLZ Ort]\n\n"
                . "**Datum:** {$todayStr}\n\n"
                . "**Betreff: {$recipientName}**\n\n"
                . "Sehr geehrte Damen und Herren,\n\n"
                . "ich wende mich heute bezüglich **{$recipientName}** an Sie.\n\n"
                . "[Hier Ihr konkretes Anliegen ergänzen...]\n\n"
                . "Für Rückfragen stehe ich Ihnen jederzeit gerne zur Verfügung und danke Ihnen im Voraus für Ihre Unterstützung.\n\n"
                . "Mit freundlichen Grüßen\n\n"
                . "**{$username}**";
        }
    }

    // 3. Als echtes .docx-Dokument in den Cloud-Ordner "Dokumente/" schreiben
    if (!is_dir(UPLOAD_DIR) && !mkdir(UPLOAD_DIR, 0750, true) && !is_dir(UPLOAD_DIR)) {
        return null;
    }

    do {
        $storedFilename = generateSafeStoredFilename();
        $physicalPath   = UPLOAD_DIR . $storedFilename;
    } while (file_exists($physicalPath));

    $safeFileBase = preg_replace('/[^a-zA-Z0-9äöüÄÖÜß_\-]/u', '_', $filePrefix) ?: 'Schreiben';
    $safeFileBase = trim((string)preg_replace('/_+/u', '_', $safeFileBase), '_');
    $origName     = sanitizeOriginalFilename(mb_substr($safeFileBase, 0, 60) . '_' . date('Y-m-d_Hi') . '.docx');

    $htmlForDocx = convertMarkdownDraftToDocxHtml($draftMarkdown);
    if (!writeHtmlAsDocx($htmlForDocx, $physicalPath) || !is_file($physicalPath)) {
        @unlink($physicalPath);
        return null;
    }

    $fileSize   = (int)filesize($physicalPath);
    $mimeType   = getOfficeMimeType($origName);
    $folderPath = 'Dokumente';

    try {
        registerFolderHierarchy($pdo, $userId, $folderPath, null);
        $stmtIns = $pdo->prepare(
            'INSERT INTO files (user_id, group_folder_id, filename, original_name, folder_path, file_size, mime_type, is_encrypted)
             VALUES (:uid, NULL, :fn, :orig, :fp, :sz, :mime, 0)'
        );
        $stmtIns->execute([
            ':uid'  => $userId,
            ':fn'   => $storedFilename,
            ':orig' => $origName,
            ':fp'   => $folderPath,
            ':sz'   => $fileSize,
            ':mime' => $mimeType,
        ]);
        $newFileId = (int)$pdo->lastInsertId();
    } catch (Throwable) {
        @unlink($physicalPath);
        return null;
    }

    return [
        'file_id'        => $newFileId,
        'filename'       => $origName,
        'draft_markdown' => $draftMarkdown,
        'used_ai'        => $usedAi,
    ];
}

/**
 * Beantwortet eine Frage des Benutzers mehrsprachig in allen 11 angebotenen Sprachen.
 *
 * @return array{
 *   reply: string,
 *   actions: array<int, array{label: string, url: string}>,
 *   followups: array<int, string>,
 *   lang: string
 * }
 */
function generateAssistantResponse(PDO $pdo, int $userId, string $username, bool $isAdmin, array $appSettings, string $rawQuestion, string $preferredLang = 'auto'): array
{
    $appTitle = (string)($appSettings['app_title'] ?? 'MiniCloud');
    $uiLang   = getCurrentLang();
    $allowed  = array_keys(getAllowedLanguages());

    $question = trim($rawQuestion);
    $qTrim    = $question;
    if ($preferredLang !== 'auto' && in_array($preferredLang, $allowed, true)) {
        $lang = $preferredLang;
    } else {
        $lang = detectAssistantLanguage($question, $uiLang);
    }

    $pack = getAssistantLangPack($lang, $username, $appTitle);

    if ($question === '') {
        return [
            'reply'     => $pack['welcome'],
            'actions'   => [],
            'followups' => [
                $pack['q_storage'],
                $pack['q_events'],
                $pack['q_ocr'],
            ],
            'lang'      => $lang,
        ];
    }

    $norm = normalizeAssistantText($question);

    // =========================================================================
    // 1. SCHNELLBEFEHL: NOTIZ ODER AUFGABE DIREKT ANLEGEN (IN ALLEN 11 SPRACHEN)
    // =========================================================================
    if (preg_match('/^(?:neue\s+notiz|notiz\s+erstellen|notiz\s+anlegen|notiz|new\s+note|note|nouvelle\s+note|nueva\s+nota|nota|notitie|notatka|poznamka|poznámka|not|нотатка)\s*:\s*(.+)$/isu', $question, $mNote)) {
        $noteBody = trim($mNote[1]);
        if ($noteBody !== '') {
            try {
                $firstLine = trim(strtok($noteBody, "\n") ?: 'Notiz');
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
                    'reply'     => sprintf($pack['note_created'], htmlspecialchars($title, ENT_QUOTES, 'UTF-8')),
                    'actions'   => [
                        ['label' => $pack['act_notes'], 'url' => 'notes.php'],
                    ],
                    'followups' => [
                        $pack['q_notes'],
                        $pack['q_tasks'],
                    ],
                    'lang'      => $lang,
                ];
            } catch (Throwable) {
            }
        }
    }

    if (preg_match('/^(?:neue\s+aufgabe|aufgabe\s+erstellen|aufgabe\s+anlegen|aufgabe|todo|to-do|new\s+task|task|tache|tâche|tarea|compito|taak|zadanie|tarefa|ukol|úkol|gorev|görev|завдання)\s*:\s*(.+)$/isu', $question, $mTask)) {
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
                    'reply'     => sprintf($pack['task_created'], htmlspecialchars($cleanTitle, ENT_QUOTES, 'UTF-8')),
                    'actions'   => [
                        ['label' => $pack['act_tasks'], 'url' => 'tasks.php'],
                    ],
                    'followups' => [
                        $pack['q_tasks'],
                        $pack['q_events'],
                    ],
                    'lang'      => $lang,
                ];
            } catch (Throwable) {
            }
        }
    }

    // Natürlicher Schnellbefehl: Kalender-Termin direkt anlegen ("Termin: Morgen 15:00 Zahnarzt" oder "Termin am 20.11.2026 18:30 Vorstand")
    if (preg_match('/^(?:neuer\s+termin|termin\s+eintragen|termin\s+anlegen|trage\s+termin\s+ein|termin|new\s+event|event|appointment)\s*:?\s+(.+)$/isu', $question, $mEvCmd)
        && !preg_match('/^(welche|meine|wann|was|hast|habe|gibt|zeig)/isu', trim($mEvCmd[1]))
    ) {
        $rawEvText = trim($mEvCmd[1]);
        if ($rawEvText !== '') {
            try {
                $baseTs = time();
                $allDay = 1;
                $hour   = 9;
                $min    = 0;

                if (preg_match('/\b(uebermorgen|übermorgen)\b/iu', $rawEvText)) {
                    $baseTs = strtotime('+2 days');
                    $rawEvText = trim((string)preg_replace('/\b(uebermorgen|übermorgen)\b/iu', '', $rawEvText));
                } elseif (preg_match('/\b(morgen|tomorrow)\b/iu', $rawEvText)) {
                    $baseTs = strtotime('+1 day');
                    $rawEvText = trim((string)preg_replace('/\b(morgen|tomorrow)\b/iu', '', $rawEvText));
                } elseif (preg_match('/\b(heute|today)\b/iu', $rawEvText)) {
                    $baseTs = time();
                    $rawEvText = trim((string)preg_replace('/\b(heute|today)\b/iu', '', $rawEvText));
                } elseif (preg_match('/\b(?:am\s+)?(\d{1,2})\.(\d{1,2})\.(\d{2,4})?\b/u', $rawEvText, $mDate)) {
                    $d = (int)$mDate[1];
                    $m = (int)$mDate[2];
                    $y = !empty($mDate[3]) ? (int)$mDate[3] : (int)date('Y');
                    if ($y < 100) {
                        $y += 2000;
                    }
                    $candTs = mktime(9, 0, 0, $m, $d, $y);
                    if ($candTs !== false) {
                        $baseTs = $candTs;
                    }
                    $rawEvText = trim(str_replace($mDate[0], '', $rawEvText));
                }

                if (preg_match('/\b(?:um\s+)?(\d{1,2})(?::(\d{2}))?\s*(?:uhr)?\b/iu', $rawEvText, $mTime)) {
                    $candH = (int)$mTime[1];
                    $candM = isset($mTime[2]) && $mTime[2] !== '' ? (int)$mTime[2] : 0;
                    if ($candH >= 0 && $candH <= 23 && $candM >= 0 && $candM <= 59 && (isset($mTime[2]) || stripos($mTime[0], 'uhr') !== false || stripos($mTime[0], 'um') !== false)) {
                        $hour   = $candH;
                        $min    = $candM;
                        $allDay = 0;
                        $rawEvText = trim(str_replace($mTime[0], '', $rawEvText));
                    }
                }

                $evTitle = mb_substr(trim((string)preg_replace('/\s+/u', ' ', $rawEvText), " -,:."), 0, 160);
                if ($evTitle === '') {
                    $evTitle = 'Termin';
                }

                $startDt = date('Y-m-d', $baseTs) . ($allDay ? ' 00:00:00' : sprintf(' %02d:%02d:00', $hour, $min));
                $endDt   = date('Y-m-d', $baseTs) . ($allDay ? ' 23:59:59' : sprintf(' %02d:%02d:00', min(23, $hour + 1), $min));

                $stmtInsEv = $pdo->prepare(
                    "INSERT INTO `calendar_events` (`user_id`, `title`, `start_at`, `end_at`, `all_day`, `color`)
                     VALUES (:uid, :title, :sa, :ea, :ad, '#2563eb')"
                );
                $stmtInsEv->execute([
                    ':uid'   => $userId,
                    ':title' => $evTitle,
                    ':sa'    => $startDt,
                    ':ea'    => $endDt,
                    ':ad'    => $allDay,
                ]);

                $dispDate = date('d.m.Y', $baseTs) . ($allDay ? ' (' . $pack['events_allday'] . ')' : sprintf(' um %02d:%02d Uhr', $hour, $min));
                $replyMsg = "📅 ✅ Termin **„" . htmlspecialchars($evTitle, ENT_QUOTES, 'UTF-8') . "“** wurde für den **{$dispDate}** in deinem Kalender eingetragen!";
                rememberAssistantTurn($question, $replyMsg, 'calendar_sync', [['label' => $pack['act_calendar'], 'url' => 'calendar.php']]);

                return [
                    'reply'     => $replyMsg,
                    'actions'   => [
                        ['label' => $pack['act_calendar'], 'url' => 'calendar.php'],
                    ],
                    'followups' => [
                        $pack['q_events'],
                        $pack['q_tasks'],
                    ],
                    'lang'      => $lang,
                ];
            } catch (Throwable) {
            }
        }
    }

    // =========================================================================
    // 1b. AUTOMATISCHE OFFICE-LIGHT-DOKUMENTENERSTELLUNG (.docx)
    //     z. B. "Formuliere mir bitte ein Kündigungsschreiben für den Tennisverein",
    //           "Schreibe eine Einladung zur Jahreshauptversammlung",
    //           "Erstelle ein Protokoll für die Vorstandssitzung" oder "Brief: ..."
    // =========================================================================
    $hasDraftVerb = hasAnyKeyword($norm, [
        'formuliere', 'formulieren', 'schreib', 'schreibe', 'schreiben sie', 'verfasse', 'verfassen',
        'entwirf', 'entwerfe', 'entwerfen', 'erstelle mir ein', 'erstelle ein', 'erstell mir', 'generiere',
    ]);
    $hasDocNoun = hasAnyKeyword($norm, [
        'kuendigung', 'kuendigungsschreiben', 'einladung', 'einladungsschreiben', 'brief', 'anschreiben',
        'schreiben', 'protokoll', 'besprechungsprotokoll', 'vertrag', 'antrag', 'beschwerde', 'widerspruch',
        'mahnung', 'entschuldigung', 'vollmacht', 'word dokument', 'office dokument',
    ]);
    $isDirectDocPrefix = (bool)preg_match('/^(?:brief|schreiben|kuendigung|kündigung|einladung|protokoll|dokument)\s*:\s*(.+)$/isu', $question);

    if (($isDirectDocPrefix || ($hasDraftVerb && $hasDocNoun))
        && !hasAnyKeyword($norm, ['wie funktioniert', 'wo finde ich', 'erklaere', 'was ist'])
    ) {
        $docRes = createAssistantOfficeDocx($pdo, $userId, $username, $appSettings, $question);
        if ($docRes !== null) {
            $officeUrl = 'office.php?id=' . (int)$docRes['file_id'] . '&return_folder=Dokumente';
            $replyMsg  = "📄 ✅ Ich habe das gewünschte Dokument für dich formuliert und direkt als Word-Datei **`" . htmlspecialchars($docRes['filename'], ENT_QUOTES, 'UTF-8') . "`** in deinem Cloud-Ordner **`Dokumente/`** angelegt!\n\n"
                . "Du kannst es mit einem Klick unten sofort in **MiniCloud Office Light** öffnen, anpassen, ausdrucken oder als PDF speichern:\n\n"
                . "---\n"
                . $docRes['draft_markdown'];

            $docActions = [
                ['label' => '📝 Jetzt in Office Light öffnen & bearbeiten', 'url' => $officeUrl],
                ['label' => '📂 Ordner „Dokumente“', 'url' => 'index.php?folder=Dokumente'],
            ];
            rememberAssistantTurn($question, $replyMsg, 'office_light', $docActions);

            return [
                'reply'     => $replyMsg,
                'actions'   => $docActions,
                'followups' => [
                    $pack['q_office'],
                    $pack['q_storage'],
                ],
                'lang'      => $lang,
            ];
        }
    }

    // =========================================================================
    // 2. BEGRÜSSUNG / DANKESCHÖN IN ALLEN 11 SPRACHEN
    // =========================================================================
    if (preg_match('/^(hallo|hi|hey|moin|servus|guten\s+morgen|guten\s+tag|guten\s+abend|wer\s+bist\s+du|was\s+kannst\s+du|hilfe|hello|good\s+morning|who\s+are\s+you|help|bonjour|salut|coucou|aide|hola|buenos\s+dias|ayuda|ciao|buongiorno|aiuto|hoi|goedemorgen|czesc|witaj|pomoc|ola|bom\s+dia|ajuda|ahoj|dobry\s+den|merhaba|selam|yardim|привіт|вітаю|добрий\s+день|допомога)$/u', $norm)) {
        return [
            'reply'   => $pack['hello_reply'],
            'actions' => [
                ['label' => $pack['act_dashboard'], 'url' => 'dashboard.php'],
                ['label' => $pack['act_files'], 'url' => 'index.php'],
                ['label' => $pack['act_office'], 'url' => 'office.php'],
            ],
            'followups' => [
                $pack['q_storage'],
                $pack['q_events'],
                $pack['q_ocr'],
            ],
            'lang' => $lang,
        ];
    }

    if (preg_match('/^(danke|vielen\s+dank|super|klasse|perfekt|top|alles\s+klar|thanks|thank\s+you|great|awesome|merci|merci\s+beaucoup|gracias|muchas\s+gracias|grazie|dank\s+je|bedankt|dziekuje|dzieki|obrigado|obrigada|dekuji|diky|tesekkurler|sag\s+ol|дякую|супер)$/u', $norm)) {
        return [
            'reply'     => $pack['thanks_reply'],
            'actions'   => [],
            'followups' => [
                $pack['q_tasks'],
                $pack['q_ebooks'],
            ],
            'lang'      => $lang,
        ];
    }

    // =========================================================================
    // 3. LIVE-ABFRAGE: SPEICHERPLATZ & KONTINGENT (11 SPRACHEN)
    // =========================================================================
    $storageTriggers = [
        'speicher', 'speicherplatz', 'kontingent', 'quota', 'wie voll', 'wie viel platz',
        'storage', 'free space', 'disk space', 'space left',
        'espace de stockage', 'stockage', 'combien d espace',
        'almacenamiento', 'espacio libre', 'cuanto espacio',
        'spazio di archiviazione', 'quanto spazio', 'spazio libero',
        'opslagruimte', 'vrije opslag', 'hoeveel ruimte',
        'wolnego miejsca', 'ile miejsca', 'pamiec w chmurze',
        'espaco de armazenamento', 'espaco livre', 'quanto espaco',
        'volneho mista', 'kolik mista', 'uloziste',
        'depolama', 'bos alan', 'ne kadar alan',
        'вільного місця', 'скільки місця', 'сховище',
    ];
    $isStorageQuery = false;
    foreach ($storageTriggers as $st) {
        if (str_contains($norm, $st)) {
            $isStorageQuery = true;
            break;
        }
    }
    if ($isStorageQuery && !str_contains($norm, 'passwort') && !str_contains($norm, 'password')) {
        $stats    = getUserStorageStats($pdo, $userId);
        $usedStr  = formatBytes($stats['used']);
        $quotaStr = formatBytes($stats['quota']);
        $freeStr  = formatBytes($stats['free']);
        $pctStr   = number_format($stats['percent'], 1, ',', '.');

        return [
            'reply' => sprintf($pack['storage_title'], $username) . "\n\n"
                . "- **{$pack['storage_used']}:** **{$usedStr}** {$pack['storage_of']} **{$quotaStr}** (**{$pctStr} %**)\n"
                . "- **{$pack['storage_free']}:** **{$freeStr}**\n"
                . "- **{$pack['storage_files']}:** **{$stats['file_count']}**\n"
                . "- **{$pack['storage_trash']}:** **{$stats['trash_count']}**",
            'actions' => [
                ['label' => $pack['act_files'], 'url' => 'index.php'],
                ['label' => $pack['act_trash'] . ' (' . $stats['trash_count'] . ')', 'url' => 'trash.php'],
            ],
            'followups' => [
                $pack['q_events'],
                $pack['q_tasks'],
            ],
            'lang' => $lang,
        ];
    }

    // =========================================================================
    // 4. LIVE-ABFRAGE: ANSTEHENDE TERMINE (11 SPRACHEN)
    // =========================================================================
    $eventTriggers = [
        'welche termine', 'meine termine', 'naechster termin', 'naechsten termine', 'was steht heute', 'was steht morgen', 'was steht diese woche', 'was steht bei mir an', 'termine heute', 'termine diese woche',
        'what appointments', 'upcoming appointments', 'my appointments', 'my schedule', 'events this week',
        'prochains rendez vous', 'mes rendez vous',
        'que citas tengo', 'proximas citas', 'mis citas',
        'prossimi appuntamenti', 'miei appuntamenti',
        'welke afspraken', 'mijn afspraken',
        'nadchodzace terminy', 'moje terminy', 'jakie mam terminy',
        'proximos compromissos', 'meus compromissos',
        'jake udalosti', 'moje udalosti',
        'hangi randevularim', 'yaklasan randevu', 'randevularim',
        'які події', 'мої події', 'заплановані',
    ];
    $isEventQuery = false;
    foreach ($eventTriggers as $et) {
        if (str_contains($norm, $et)) {
            $isEventQuery = true;
            break;
        }
    }
    if ($isEventQuery) {
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
                $reply = $pack['events_empty'];
            } else {
                $lines = [$pack['events_title'] . "\n"];
                foreach ($events as $ev) {
                    $ts      = strtotime((string)$ev['start_at']) ?: time();
                    $dateStr = date('d.m.Y', $ts);
                    $timeStr = ((int)$ev['all_day'] === 1) ? $pack['events_allday'] : date('H:i', $ts);
                    $locStr  = trim((string)($ev['location'] ?? '')) !== '' ? ' · 📍 ' . htmlspecialchars((string)$ev['location'], ENT_QUOTES, 'UTF-8') : '';
                    $lines[] = "- **{$dateStr} ({$timeStr}):** " . htmlspecialchars((string)$ev['title'], ENT_QUOTES, 'UTF-8') . $locStr;
                }
                $reply = implode("\n", $lines);
            }

            return [
                'reply'   => $reply,
                'actions' => [
                    ['label' => $pack['act_calendar'], 'url' => 'calendar.php'],
                    ['label' => $pack['act_dashboard'], 'url' => 'dashboard.php'],
                ],
                'followups' => [
                    $pack['q_tasks'],
                    $pack['q_webdav'],
                ],
                'lang' => $lang,
            ];
        } catch (Throwable) {
        }
    }

    // =========================================================================
    // 5. LIVE-ABFRAGE: OFFENE AUFGABEN / TO-DOS (11 SPRACHEN)
    // =========================================================================
    $taskTriggers = [
        'welche aufgaben', 'meine aufgaben', 'offene aufgaben', 'offenen aufgaben', 'meine todos', 'meine to dos', 'was muss ich erledigen', 'was ist noch zu tun', 'noch offen',
        'open tasks', 'which tasks', 'my tasks', 'my to dos',
        'taches ouvertes', 'mes taches', 'quelles sont mes taches',
        'tareas pendientes', 'tareas abiertas', 'que tareas tengo',
        'compiti aperti', 'quali compiti', 'miei compiti',
        'openstaande taken', 'welke taken', 'mijn taken',
        'otwarte zadania', 'jakie zadania', 'moje zadania',
        'tarefas abertas', 'que tarefas', 'minhas tarefas',
        'otevrene ukoly', 'ktere ukoly', 'moje ukoly',
        'acik gorev', 'hangi gorevlerim', 'gorevlerim',
        'відкриті завдання', 'які завдання', 'мої завдання',
    ];
    $isTaskQuery = false;
    foreach ($taskTriggers as $tt) {
        if (str_contains($norm, $tt)) {
            $isTaskQuery = true;
            break;
        }
    }
    if ($isTaskQuery) {
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
                $reply = $pack['tasks_empty'];
            } else {
                $lines = [sprintf($pack['tasks_title'], count($tasks)) . "\n"];
                foreach ($tasks as $t) {
                    $statusBadge = ($t['status'] === 'in_progress') ? $pack['tasks_in_prog'] : $pack['tasks_todo'];
                    $duePart = !empty($t['due_date']) ? ' · ' . $pack['tasks_due'] . ': ' . date('d.m.Y', strtotime((string)$t['due_date']) ?: time()) : '';
                    $lines[] = "- {$statusBadge}: **" . htmlspecialchars((string)$t['title'], ENT_QUOTES, 'UTF-8') . "**{$duePart}";
                }
                $lines[] = "\n" . $pack['tasks_tip'];
                $reply = implode("\n", $lines);
            }

            return [
                'reply'   => $reply,
                'actions' => [
                    ['label' => $pack['act_tasks'], 'url' => 'tasks.php'],
                ],
                'followups' => [
                    $pack['q_events'],
                    $pack['q_notes'],
                ],
                'lang' => $lang,
            ];
        } catch (Throwable) {
        }
    }

    // =========================================================================
    // 6. LIVE-ABFRAGE: UNGELESENE NACHRICHTEN (11 SPRACHEN)
    // =========================================================================
    $msgTriggers = [
        'ungelesene nachricht', 'neue nachricht', 'habe ich nachrichten',
        'unread message', 'new message', 'do i have messages',
        'messages non lus', 'nouveaux messages',
        'mensajes sin leer', 'mensajes no leidos',
        'messaggi non letti',
        'ongelezen bericht',
        'nieprzeczytane wiadomosci',
        'mensagens nao lidas',
        'neprectene zpravy',
        'okunmamis mesaj',
        'непрочитані повідомлення',
    ];
    $isMsgQuery = false;
    foreach ($msgTriggers as $mt) {
        if (str_contains($norm, $mt)) {
            $isMsgQuery = true;
            break;
        }
    }
    if ($isMsgQuery) {
        $unread = getUnreadMessagesCount($pdo, $userId);
        $reply  = $unread > 0
            ? sprintf($pack['msg_unread'], $unread)
            : $pack['msg_empty'];

        return [
            'reply'   => $reply,
            'actions' => [
                ['label' => $pack['act_messages'], 'url' => 'messages.php'],
            ],
            'followups' => [
                $pack['q_events'],
                $pack['q_storage'],
            ],
            'lang' => $lang,
        ];
    }

    // =========================================================================
    // 7. LIVE-ABFRAGE: MEINE LETZTEN NOTIZEN (11 SPRACHEN)
    // =========================================================================
    $noteTriggers = [
        'meine notizen', 'letzten notizen', 'letzte notizen', 'zeige meine notizen', 'aktuelle haftnotizen',
        'my notes', 'latest notes', 'sticky notes', 'show my notes',
        'mes notes', 'dernieres notes',
        'mis notas', 'ultimas notas',
        'mie note', 'ultime note',
        'mijn notities', 'laatste notities',
        'moje notatki', 'ostatnie notatki',
        'minhas notas', 'ultimas notas',
        'moje poznamky', 'posledni poznamky',
        'son notlarim', 'notlarimi goster',
        'мої нотатки', 'останні нотатки',
    ];
    $isNoteQuery = false;
    foreach ($noteTriggers as $nt) {
        if (str_contains($norm, $nt)) {
            $isNoteQuery = true;
            break;
        }
    }
    if ($isNoteQuery) {
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
                $reply = $pack['notes_empty'];
            } else {
                $lines = [$pack['notes_title'] . "\n"];
                foreach ($notes as $n) {
                    $pin     = ((int)$n['is_pinned'] === 1) ? '📌 ' : '• ';
                    $snippet = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$n['content']) ?? ''), 0, 70);
                    $lines[] = "- {$pin}**" . htmlspecialchars((string)$n['title'], ENT_QUOTES, 'UTF-8') . "**: " . htmlspecialchars($snippet, ENT_QUOTES, 'UTF-8');
                }
                $reply = implode("\n", $lines);
            }

            return [
                'reply'   => $reply,
                'actions' => [
                    ['label' => $pack['act_notes'], 'url' => 'notes.php'],
                ],
                'followups' => [
                    $pack['q_tasks'],
                    $pack['q_storage'],
                ],
                'lang' => $lang,
            ];
        } catch (Throwable) {
        }
    }

    // =========================================================================
    // 8. LIVE-ABFRAGE (ADMIN): MODUL-STATISTIK PER CHAT
    // =========================================================================
    if ($isAdmin && (str_contains($norm, 'modul statistik') || str_contains($norm, 'beliebteste modul') || str_contains($norm, 'am haeufigsten genutzt') || str_contains($norm, 'welche module werden') || str_contains($norm, 'module statistics') || str_contains($norm, 'most used module'))) {
        $mStats = getModuleUsageStatistics($pdo, $appSettings);
        $lines  = ["📊 **Top-Module nach bisherigen Aufrufen (Gesamt: {$mStats['totals']['total']}):**\n"];
        $i = 0;
        foreach ($mStats['modules'] as $mItem) {
            if ($i >= 5) {
                break;
            }
            $lines[] = ($i + 1) . ". {$mItem['icon']} **{$mItem['label']}** – **{$mItem['hits_total']}** ({$mItem['pct_total']} % · *{$mItem['inventory']}*)";
            $i++;
        }
        return [
            'reply'   => implode("\n", $lines),
            'actions' => [
                ['label' => '📊 Modul-Statistik', 'url' => 'admin.php#tab-modules'],
            ],
            'followups' => [
                $pack['q_storage'],
            ],
            'lang' => $lang,
        ];
    }

    // =========================================================================
    // 8b. LIVE-ABFRAGE (STUFE 1): HAUSHALTSBUCH & FINANZEN (budget.php)
    // =========================================================================
    $budgetLiveTriggers = [
        'mein haushaltsbuch', 'meine finanzen', 'meine ausgaben', 'meine einnahmen', 'wie viel ausgegeben', 'ausgaben diesen monat', 'einnahmen diesen monat', 'kontostand', 'monatsbilanz', 'kassensturz', 'meine abos', 'meine fixkosten', 'meine sparziele',
        'my budget', 'my expenses', 'my income', 'monthly balance',
    ];
    $isBudgetLive = false;
    foreach ($budgetLiveTriggers as $bt) {
        if (str_contains($norm, $bt)) {
            $isBudgetLive = true;
            break;
        }
    }
    if ($isBudgetLive) {
        try {
            $curMonth = date('Y-m');
            $stmtBk = $pdo->prepare(
                "SELECT `id`, `name`, `currency`
                 FROM `budget_books`
                 WHERE `user_id` = :u1 OR `shared_user_id` = :u2
                 ORDER BY `id` ASC LIMIT 5"
            );
            $stmtBk->execute([':u1' => $userId, ':u2' => $userId]);
            $books = $stmtBk->fetchAll();

            if (!empty($books)) {
                $lines = ["💰 **Live-Finanzübersicht (" . date('m/Y') . "):**\n"];
                foreach ($books as $bk) {
                    $bid  = (int)$bk['id'];
                    $curr = htmlspecialchars((string)($bk['currency'] ?: '€'), ENT_QUOTES, 'UTF-8');
                    $stmtSum = $pdo->prepare(
                        "SELECT
                            COALESCE(SUM(CASE WHEN `type` = 'income' THEN `amount` ELSE 0 END), 0) AS inc,
                            COALESCE(SUM(CASE WHEN `type` = 'expense' THEN `amount` ELSE 0 END), 0) AS exp
                         FROM `budget_entries`
                         WHERE `book_id` = :bid AND `entry_date` LIKE :ym"
                    );
                    $stmtSum->execute([':bid' => $bid, ':ym' => $curMonth . '%']);
                    $sums = $stmtSum->fetch() ?: ['inc' => 0, 'exp' => 0];
                    $inc  = (float)$sums['inc'];
                    $exp  = (float)$sums['exp'];
                    $bal  = $inc - $exp;
                    $sign = $bal >= 0 ? '+' : '';
                    $lines[] = "- 📘 **" . htmlspecialchars((string)$bk['name'], ENT_QUOTES, 'UTF-8') . ":** Einnahmen **" . number_format($inc, 2, ',', '.') . " {$curr}** · Ausgaben **" . number_format($exp, 2, ',', '.') . " {$curr}** · Saldo: **{$sign}" . number_format($bal, 2, ',', '.') . " {$curr}**";
                }
                $reply = implode("\n", $lines);
                rememberAssistantTurn($question, $reply, 'budget_planner', [['label' => '💰 Haushaltsplaner', 'url' => 'budget.php']]);
                return [
                    'reply'     => $reply,
                    'actions'   => [['label' => '💰 Haushaltsplaner öffnen', 'url' => 'budget.php']],
                    'followups' => [$pack['q_storage'], $pack['q_tasks']],
                    'lang'      => $lang,
                ];
            }
        } catch (Throwable) {
        }
    }

    // =========================================================================
    // 8c. LIVE-ABFRAGE (STUFE 1): HÖRBUCH- / MUSIK-GEDÄCHTNIS & E-BOOK-LESESTAND
    // =========================================================================
    $mediaResumeTriggers = [
        'welches hoerbuch', 'wo war ich beim hoerbuch', 'hoerbuch weiterhoeren', 'weiterhoeren', 'zuletzt gehoert', 'welches buch lese ich', 'wo war ich beim buch', 'weiterlesen', 'lesefortschritt', 'lesestand',
        'continue audiobook', 'continue reading', 'last audiobook', 'reading progress',
    ];
    $isMediaResume = false;
    foreach ($mediaResumeTriggers as $mrt) {
        if (str_contains($norm, $mrt)) {
            $isMediaResume = true;
            break;
        }
    }
    if ($isMediaResume) {
        $lines   = [];
        $actions = [];
        try {
            $stmtAud = $pdo->prepare(
                "SELECT m.file_id, m.last_position_sec, m.cached_title, f.original_name, f.folder_path
                 FROM `music_track_meta` m
                 INNER JOIN `files` f ON f.id = m.file_id
                 WHERE m.user_id = :uid AND m.last_position_sec >= 5 AND f.deleted_at IS NULL
                 ORDER BY m.updated_at DESC LIMIT 2"
            );
            $stmtAud->execute([':uid' => $userId]);
            foreach ($stmtAud->fetchAll() as $ar) {
                $posSec = (int)$ar['last_position_sec'];
                $posFmt = sprintf('%02d:%02d Min.', intdiv($posSec, 60), $posSec % 60);
                $label  = trim((string)($ar['cached_title'] ?: $ar['original_name']));
                $lines[] = "- 📖 **Hörbuch / Audio:** **" . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . "** (gespeicherte Position: **{$posFmt}**)";
                $actions[] = ['label' => '▶️ ' . mb_substr($label, 0, 22) . ' weiterhören', 'url' => 'music.php?play=' . (int)$ar['file_id'] . '&audiobook=1&sort=chapter'];
            }
        } catch (Throwable) {
        }

        try {
            $stmtEb = $pdo->prepare(
                "SELECT p.file_id, p.progress_percent, f.original_name
                 FROM `ebook_progress` p
                 INNER JOIN `files` f ON f.id = p.file_id
                 WHERE p.user_id = :uid AND f.deleted_at IS NULL
                 ORDER BY p.updated_at DESC LIMIT 2"
            );
            $stmtEb->execute([':uid' => $userId]);
            foreach ($stmtEb->fetchAll() as $eb) {
                $pct   = number_format((float)$eb['progress_percent'], 0, ',', '.');
                $bName = (string)$eb['original_name'];
                $lines[] = "- 📚 **E-Book:** **" . htmlspecialchars($bName, ENT_QUOTES, 'UTF-8') . "** (**{$pct} %** gelesen)";
                $actions[] = ['label' => '📚 ' . mb_substr($bName, 0, 22) . ' weiterlesen', 'url' => 'ebooks.php?id=' . (int)$eb['file_id']];
            }
        } catch (Throwable) {
        }

        if (!empty($lines)) {
            $reply = "🔖 **Deine zuletzt gespeicherten Hörbuch- & Lese-Positionen:**\n\n" . implode("\n", $lines);
            rememberAssistantTurn($question, $reply, 'music_player', $actions);
            return [
                'reply'     => $reply,
                'actions'   => array_slice($actions, 0, 4),
                'followups' => [$pack['q_ebooks'], $pack['q_storage']],
                'lang'      => $lang,
            ];
        }
    }

    // =========================================================================
    // 8d. LIVE-ABFRAGE (STUFE 1): VEREINSVERWALTUNG & MITGLIEDER-STATISTIK (club.php)
    // =========================================================================
    if (str_contains($norm, 'wie viele mitglieder') || str_contains($norm, 'mitgliederzahl') || str_contains($norm, 'vereinsstatistik') || str_contains($norm, 'offene beitraege') || str_contains($norm, 'mitgliedsbeitraege')) {
        try {
            $totalM  = (int)$pdo->query("SELECT COUNT(*) FROM `club_members` WHERE `status` = 'active'")->fetchColumn();
            $passive = (int)$pdo->query("SELECT COUNT(*) FROM `club_members` WHERE `status` = 'passive'")->fetchColumn();
            $honor   = (int)$pdo->query("SELECT COUNT(*) FROM `club_members` WHERE `status` = 'honorary'")->fetchColumn();
            $openFee = (float)$pdo->query("SELECT COALESCE(SUM(`amount`), 0) FROM `club_fees` WHERE `status` = 'open'")->fetchColumn();
            $paidFee = (float)$pdo->query("SELECT COALESCE(SUM(`amount`), 0) FROM `club_fees` WHERE `status` = 'paid'")->fetchColumn();

            $reply = "🏛️ **Live-Status aus der Vereinsverwaltung (`club.php`):**\n\n"
                . "- 👥 **Aktive Mitglieder:** **{$totalM}** *(Passiv: {$passive} · Ehrenmitglieder: {$honor})*\n"
                . "- 💶 **Beiträge bezahlt:** **" . number_format($paidFee, 2, ',', '.') . " €**\n"
                . "- ⏳ **Offene Beiträge:** **" . number_format($openFee, 2, ',', '.') . " €**";
            rememberAssistantTurn($question, $reply, 'club_management', [['label' => '🏛️ Vereinsverwaltung', 'url' => 'club.php']]);
            return [
                'reply'     => $reply,
                'actions'   => [['label' => '🏛️ Vereinsverwaltung öffnen', 'url' => 'club.php']],
                'followups' => [$pack['q_events'], $pack['q_storage']],
                'lang'      => $lang,
            ];
        } catch (Throwable) {
        }
    }

    // =========================================================================
    // 8e. LIVE-ABFRAGE (STUFE 1): GEBURTSTAGE & KONTAKTE (contacts.php)
    // =========================================================================
    if (str_contains($norm, 'geburtstag') || str_contains($norm, 'birthday')) {
        try {
            $stmtB = $pdo->prepare(
                "SELECT `first_name`, `last_name`, `birthday`
                 FROM `contacts`
                 WHERE (`user_id` = :u1 OR `shared_user_id` = :u2)
                   AND `birthday` IS NOT NULL
                 ORDER BY MONTH(`birthday`) ASC, DAY(`birthday`) ASC
                 LIMIT 10"
            );
            $stmtB->execute([':u1' => $userId, ':u2' => $userId]);
            $bRows = $stmtB->fetchAll();
            if (!empty($bRows)) {
                $lines = ["🎂 **Eingetragene Geburtstage in deinen Kontakten:**\n"];
                foreach ($bRows as $br) {
                    $bTs  = strtotime((string)$br['birthday']) ?: time();
                    $name = trim($br['first_name'] . ' ' . $br['last_name']);
                    $lines[] = "- 🎉 **" . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ":** " . date('d.m.Y', $bTs);
                }
                $reply = implode("\n", $lines);
                rememberAssistantTurn($question, $reply, 'calendar_sync', [['label' => '📇 Kontakte', 'url' => 'contacts.php']]);
                return [
                    'reply'     => $reply,
                    'actions'   => [['label' => '📇 Kontakte öffnen', 'url' => 'contacts.php'], ['label' => $pack['act_calendar'], 'url' => 'calendar.php']],
                    'followups' => [$pack['q_events'], $pack['q_tasks']],
                    'lang'      => $lang,
                ];
            }
        } catch (Throwable) {
        }
    }

    // =========================================================================
    // 8f. LIVE-ABFRAGE (STUFE 1): PROJEKTE (projects.php) & UMFRAGEN (polls.php)
    // =========================================================================
    if (str_contains($norm, 'meine projekte') || str_contains($norm, 'aktive projekte') || str_contains($norm, 'projektstatus')) {
        try {
            $stmtPr = $pdo->prepare(
                "SELECT `name`, `status`, `progress`
                 FROM `projects`
                 WHERE `owner_id` = :u1 OR `shared_user_id` = :u2
                 ORDER BY `id` DESC LIMIT 6"
            );
            $stmtPr->execute([':u1' => $userId, ':u2' => $userId]);
            $pRows = $stmtPr->fetchAll();
            if (!empty($pRows)) {
                $lines = ["🚀 **Deine aktuellen Projekte:**\n"];
                foreach ($pRows as $pr) {
                    $lines[] = "- **" . htmlspecialchars((string)$pr['name'], ENT_QUOTES, 'UTF-8') . "** – Fortschritt: **" . (int)$pr['progress'] . " %**";
                }
                $reply = implode("\n", $lines);
                rememberAssistantTurn($question, $reply, 'projects_module', [['label' => '🚀 Projekte', 'url' => 'projects.php']]);
                return [
                    'reply'     => $reply,
                    'actions'   => [['label' => '🚀 Projekte öffnen', 'url' => 'projects.php']],
                    'followups' => [$pack['q_tasks'], $pack['q_events']],
                    'lang'      => $lang,
                ];
            }
        } catch (Throwable) {
        }
    }

    // =========================================================================
    // 8g. ALLTAGS-HELFER (STUFE 1 – LÄUFT AUCH AUF STRATO OHNE KI-MODELL):
    //     1) Taschen- & Prozentrechner  2) Datums- & Countdown-Rechner
    //     3) Kryptografischer Passwort- & PIN-Generator
    // =========================================================================
    // 1) Prozentrechner ("Was ist 19% von 249,50?", "15 Prozent von 800")
    if (preg_match('/([0-9]+(?:[.,][0-9]+)?)\s*(?:%|prozent|percent)\s*(?:von|of|de)\s*([0-9]+(?:[.,][0-9]+)?)/iu', $qTrim, $mPct)) {
        $pct  = (float)str_replace(',', '.', $mPct[1]);
        $base = (float)str_replace(',', '.', $mPct[2]);
        $val  = ($pct / 100.0) * $base;
        $plus = $base + $val;
        $minus = $base - $val;
        $fmt  = static fn(float $n): string => rtrim(rtrim(number_format($n, 2, ',', '.'), '0'), ',');
        $reply = "🧮 **Prozentrechner-Ergebnis:**\n\n"
            . "- **{$fmt($pct)} %** von **{$fmt($base)}** = **`{$fmt($val)}`**\n"
            . "- ➕ Inklusive (+{$fmt($pct)} %): **`{$fmt($plus)}`**\n"
            . "- ➖ Abzüglich (-{$fmt($pct)} %): **`{$fmt($minus)}`**";
        rememberAssistantTurn($qTrim, $reply, 'math_helper');
        return [
            'reply'     => $reply,
            'actions'   => [['label' => '🧮 Taschenrechner öffnen', 'url' => 'calculator.php'], ['label' => '💰 Haushaltsplaner', 'url' => 'budget.php']],
            'followups' => ['Wie funktioniert der Taschenrechner?', 'Wie steht mein Haushaltsbuch diesen Monat?'],
            'lang'      => $lang,
        ];
    }

    // 2) Taschenrechner ("Rechne (1250 + 480) / 12", "Was ist 145 * 19?")
    if (preg_match('/^(?:rechne|berechne|was\s+ist|wie\s+viel\s+ist|calculate|what\s+is)?\s*([0-9(),.\s+\-*\/×÷:^]{3,})\s*[=?]*$/iu', $qTrim, $mMath)) {
        $mathCandidate = trim($mMath[1]);
        $mathResult = evaluateSafeMathExpression($mathCandidate);
        if ($mathResult !== null) {
            $formattedRes = (abs($mathResult - round($mathResult)) < 1e-9)
                ? number_format($mathResult, 0, ',', '.')
                : rtrim(rtrim(number_format($mathResult, 4, ',', '.'), '0'), ',');
            $reply = "🧮 **Rechenergebnis:**\n\n"
                . "`" . htmlspecialchars($mathCandidate, ENT_QUOTES, 'UTF-8') . "` = **`{$formattedRes}`**";
            rememberAssistantTurn($qTrim, $reply, 'math_helper');
            return [
                'reply'     => $reply,
                'actions'   => [['label' => '🧮 Taschenrechner & Rechenstreifen', 'url' => 'calculator.php'], ['label' => '📝 Excel Light', 'url' => 'office.php']],
                'followups' => ['Was ist 19% von 249,50?', 'Wie funktioniert der Taschenrechner?'],
                'lang'      => $lang,
            ];
        }
    }

    // 3) Datums-, Wochentags- & Countdown-Rechner
    $weekdaysDe = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
    if (hasAnyKeyword($norm, [
        'welches datum', 'welcher tag ist heute', 'welche kalenderwoche', 'welche kw', 'wie viel uhr',
        'wie viele tage bis', 'wieviele tage bis', 'welcher wochentag ist der', 'welcher tag ist der',
    ])) {
        $nowTs = time();
        $todayName = $weekdaysDe[(int)date('w', $nowTs)];
        $kw = date('W', $nowTs);

        if (preg_match('/(\d{1,2})\.(\d{1,2})\.(\d{4})/', $qTrim, $mTargetDate)) {
            $tTs = mktime(0, 0, 0, (int)$mTargetDate[2], (int)$mTargetDate[1], (int)$mTargetDate[3]);
            if ($tTs !== false) {
                $tDayName = $weekdaysDe[(int)date('w', $tTs)];
                $diffDays = (int)round(($tTs - strtotime('today')) / 86400);
                $diffText = $diffDays === 0
                    ? 'Das ist **heute**!'
                    : ($diffDays > 0 ? "Das ist in **{$diffDays} Tag(en)**." : "Das war vor **" . abs($diffDays) . " Tag(en)**.");
                $reply = "📆 **Datums- & Wochentagsrechner:**\n\n"
                    . "- Der **" . date('d.m.Y', $tTs) . "** fällt auf einen **{$tDayName}** (Kalenderwoche **" . date('W', $tTs) . "**).\n"
                    . "- {$diffText}";
                rememberAssistantTurn($qTrim, $reply, 'date_helper');
                return [
                    'reply'     => $reply,
                    'actions'   => [['label' => $pack['act_calendar'], 'url' => 'calendar.php']],
                    'followups' => [$pack['q_events'], 'Termin: Morgen 15:00 Besprechung'],
                    'lang'      => $lang,
                ];
            }
        }

        if (str_contains($norm, 'weihnacht') || str_contains($norm, 'heiligabend')) {
            $year = (int)date('Y');
            $xmasTs = mktime(0, 0, 0, 12, 24, $year);
            if ($xmasTs < strtotime('today')) {
                $xmasTs = mktime(0, 0, 0, 12, 24, $year + 1);
            }
            $daysLeft = (int)round(($xmasTs - strtotime('today')) / 86400);
            $wDay = $weekdaysDe[(int)date('w', $xmasTs)];
            $reply = "🎄 **Countdown bis Heiligabend (" . date('d.m.Y', $xmasTs) . "):**\n\n"
                . "Es sind noch **{$daysLeft} Tage** bis zum 24. Dezember (fällt auf einen **{$wDay}**).";
            rememberAssistantTurn($qTrim, $reply, 'date_helper');
            return [
                'reply'     => $reply,
                'actions'   => [['label' => $pack['act_calendar'], 'url' => 'calendar.php']],
                'followups' => [$pack['q_events']],
                'lang'      => $lang,
            ];
        }

        if (str_contains($norm, 'silvester') || str_contains($norm, 'neujahr')) {
            $year = (int)date('Y');
            $nyiTs = mktime(0, 0, 0, 12, 31, $year);
            $daysLeft = (int)round(($nyiTs - strtotime('today')) / 86400);
            $reply = "🎆 **Countdown bis Silvester (" . date('d.m.Y', $nyiTs) . "):**\n\n"
                . "Es sind noch **{$daysLeft} Tage** bis zum Jahreswechsel.";
            rememberAssistantTurn($qTrim, $reply, 'date_helper');
            return [
                'reply'     => $reply,
                'actions'   => [['label' => $pack['act_calendar'], 'url' => 'calendar.php']],
                'followups' => [$pack['q_events']],
                'lang'      => $lang,
            ];
        }

        $dayOfYear = (int)date('z', $nowTs) + 1;
        $reply = "📆 **Aktuelles Datum & Kalenderwoche:**\n\n"
            . "- **Heute:** {$todayName}, der **" . date('d.m.Y') . "** (" . date('H:i') . " Uhr)\n"
            . "- **Kalenderwoche:** KW **{$kw}** (Tag {$dayOfYear} des Jahres)";
        rememberAssistantTurn($qTrim, $reply, 'date_helper');
        return [
            'reply'     => $reply,
            'actions'   => [['label' => $pack['act_calendar'], 'url' => 'calendar.php']],
            'followups' => [$pack['q_events'], $pack['q_tasks']],
            'lang'      => $lang,
        ];
    }

    // 4) Kryptografischer Passwort- & PIN-Generator ("Generiere ein sicheres Passwort", "Passwort vorschlagen")
    if (hasAnyKeyword($norm, [
        'generiere ein passwort', 'generiere ein sicheres passwort', 'sicheres passwort generieren',
        'passwort vorschlagen', 'neues passwort generieren', 'zufallspasswort', 'generate password',
    ])) {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%&*+-=?';
        $alphaLen = strlen($alphabet) - 1;
        $genPass  = static function (int $length) use ($alphabet, $alphaLen): string {
            $out = '';
            for ($i = 0; $i < $length; $i++) {
                $out .= $alphabet[random_int(0, $alphaLen)];
            }
            return $out;
        };
        $p16 = $genPass(16);
        $p24 = $genPass(24);
        $pin = sprintf('%06d', random_int(100000, 999999));
        $reply = "🔑 **Frisch generierte, kryptografisch sichere Passwörter (`random_int`):**\n\n"
            . "- **16 Zeichen (Standard):** `" . $p16 . "`\n"
            . "- **24 Zeichen (Hochsicher / Tresor):** `" . $p24 . "`\n"
            . "- **6-stellige PIN:** `" . $pin . "`\n\n"
            . "💡 *Tipp:* Du kannst das gewünschte Passwort direkt im **🔐 Schlüsselbund** (`passwords.php`) AES-256-verschlüsselt ablegen.";
        rememberAssistantTurn($qTrim, $reply, 'passwords_manager', [['label' => '🔐 Schlüsselbund', 'url' => 'passwords.php']]);
        return [
            'reply'     => $reply,
            'actions'   => [['label' => '🔐 Schlüsselbund öffnen', 'url' => 'passwords.php'], ['label' => '⚙️ Einstellungen', 'url' => 'settings.php']],
            'followups' => [$pack['q_vault'], 'Wie ist die Cloud laut IT-Sicherheitskonzept geschützt?'],
            'lang'      => $lang,
        ];
    }

    // =========================================================================
    // 9. EXPLIZITE LIVE-SUCHE IN DATEIEN, KONTAKTEN & NOTIZEN (11 SPRACHEN)
    // =========================================================================
    if (preg_match('/^(?:suche\s+nach|suche\s+datei|suche|finde\s+datei|finde|wo\s+ist\s+die\s+datei|wo\s+liegt|wo\s+finde\s+ich|telefonnummer\s+von|kontakt\s+von|adresse\s+von|search\s+for|search|find\s+file|find|where\s+is|chercher|cherche|rechercher|trouver|buscar|busca|encontrar|cerca|trova|zoek|zoeken|szukaj|znajdz|znajdź|procurar|procura|hledat|najit|najít|ara|bul|пошук|знайди|знайти)\s+(.+)$/isu', $question, $mSearch)) {
        $searchTerm = trim($mSearch[1], " ?.!\"'„“«»");
        if (mb_strlen($searchTerm) >= 2) {
            $like = '%' . $searchTerm . '%';
            $foundLines = [];
            $actions = [];

            // A) Dateien durchsuchen (WICHTIG: Spalte heißt upload_date!)
            try {
                $stmtFiles = $pdo->prepare(
                    "SELECT `id`, `original_name`, `folder_path`, `file_size`
                     FROM `files`
                     WHERE `user_id` = :uid
                       AND `deleted_at` IS NULL
                       AND (`original_name` LIKE :q1 OR `folder_path` LIKE :q2)
                     ORDER BY `upload_date` DESC, `id` DESC
                     LIMIT 5"
                );
                $stmtFiles->execute([':uid' => $userId, ':q1' => $like, ':q2' => $like]);
                foreach ($stmtFiles->fetchAll() as $f) {
                    $fName   = (string)$f['original_name'];
                    $fFolder = trim((string)($f['folder_path'] ?? '')) !== '' ? ' (`' . $f['folder_path'] . '`)' : '';
                    $foundLines[] = "- {$pack['search_files_lbl']} **" . htmlspecialchars($fName, ENT_QUOTES, 'UTF-8') . "**{$fFolder} · " . formatBytes((int)$f['file_size']);
                    if (strtolower(pathinfo($fName, PATHINFO_EXTENSION)) === 'epub') {
                        $actions[] = ['label' => '📖 ' . mb_substr($fName, 0, 24), 'url' => 'ebooks.php?id=' . (int)$f['id']];
                    } elseif (isOfficeEditableFile($fName)) {
                        $actions[] = ['label' => '📝 ' . mb_substr($fName, 0, 24), 'url' => 'office.php?id=' . (int)$f['id']];
                    } else {
                        $actions[] = ['label' => '⬇️ ' . mb_substr($fName, 0, 24), 'url' => 'download.php?id=' . (int)$f['id']];
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
                    $foundLines[] = "- {$pack['search_cont_lbl']} **" . htmlspecialchars($cName, ENT_QUOTES, 'UTF-8') . "**" . (!empty($cDetails) ? ' (' . htmlspecialchars(implode(' · ', $cDetails), ENT_QUOTES, 'UTF-8') . ')' : '');
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
                    $foundLines[] = "- {$pack['search_note_lbl']} **" . htmlspecialchars((string)$n['title'], ENT_QUOTES, 'UTF-8') . "**";
                }
            } catch (Throwable) {
            }

            if (!empty($foundLines)) {
                $actions[] = ['label' => $pack['search_all_btn'], 'url' => 'index.php?search=' . rawurlencode($searchTerm)];
                return [
                    'reply'     => sprintf($pack['search_title'], htmlspecialchars($searchTerm, ENT_QUOTES, 'UTF-8')) . "\n\n" . implode("\n", $foundLines),
                    'actions'   => array_slice($actions, 0, 4),
                    'followups' => [
                        $pack['q_storage'],
                        $pack['q_tasks'],
                    ],
                    'lang'      => $lang,
                ];
            }
        }
    }

    // =========================================================================
    // 10. WISSENSDATENBANK-ABGLEICH (MIT KONTEXT-GEDÄCHTNIS, VOLLTEXT & FUZZY)
    // =========================================================================
    $kb = getAssistantKnowledgeBase($appSettings, $isAdmin, $lang);
    $queryWords = array_values(array_filter(explode(' ', $norm), static fn(string $w): bool => mb_strlen($w) >= 2));

    $lastTopicId = (string)($_SESSION['assistant_context']['last_topic_id'] ?? '');
    $isFollowUpPhrase = hasAnyKeyword($norm, [
        'und wie', 'und fuer', 'und bei', 'wie lautet sie', 'wie lautet er', 'wie lautet das', 'wie ist es bei',
        'geht das', 'kann man das', 'kann ich das', 'dort', 'damit', 'dazu', 'davon', 'darin', 'dafuer',
        'auch mit', 'passwort', 'teilen', 'freigeben', 'exportieren', 'drucken', 'smartphone', 'handy', 'android', 'iphone',
        'and how', 'and for', 'what about', 'can i also', 'with that', 'there', 'how do i share that',
    ]);

    $scoredEntries = [];

    foreach ($kb as $entry) {
        $score = 0.0;
        $titleNorm  = normalizeAssistantText($entry['title']);
        $answerNorm = normalizeAssistantText(strip_tags((string)$entry['answer']));

        // Folgefragen-Bonus: Wenn der Nutzer ein Pronomen/Anschlusswort nutzt ("Und wie teile ich das?"),
        // erhält das zuletzt besprochene Thema bzw. eng verwandte Themen einen Kontext-Bonus
        if ($lastTopicId !== '' && $lastTopicId !== 'ollama_general' && $isFollowUpPhrase && $entry['id'] === $lastTopicId) {
            $score += 6.5;
        }

        if ($titleNorm !== '' && str_contains($norm, $titleNorm)) {
            $score += 18.0;
        }

        foreach ($entry['keywords'] as $kw) {
            $kwNorm = normalizeAssistantText($kw);
            if ($kwNorm === '') {
                continue;
            }
            if (str_contains($norm, $kwNorm)) {
                $score += str_contains($kwNorm, ' ') ? 14.0 : 8.5;
                continue;
            }

            foreach ($queryWords as $qw) {
                if ($qw === $kwNorm) {
                    $score += 7.5;
                } elseif (mb_strlen($qw) >= 5 && mb_strlen($kwNorm) >= 5) {
                    if (str_starts_with($qw, $kwNorm) || str_starts_with($kwNorm, $qw)) {
                        $score += 5.0;
                    } elseif (strlen($qw) <= 20 && strlen($kwNorm) <= 20 && levenshtein($qw, $kwNorm) <= 1) {
                        $score += 4.0;
                    }
                }
            }
        }

        // Volltext-Bonus auf den Antworttext (Stufe 1 Tiefensuche)
        foreach ($queryWords as $qw) {
            if (mb_strlen($qw) >= 6 && str_contains($answerNorm, $qw)) {
                $score += 2.0;
            }
        }

        if ($score > 0.0) {
            $scoredEntries[] = ['score' => $score, 'entry' => $entry];
        }
    }

    usort($scoredEntries, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);

    $bestScore = $scoredEntries[0]['score'] ?? 0.0;
    $bestEntry = $scoredEntries[0]['entry'] ?? null;

    // Top-3 Wissensartikel für Stufe 2 (Ollama RAG) zusammenstellen
    $topKbForRag = [];
    foreach (array_slice($scoredEntries, 0, 3) as $item) {
        $topKbForRag[] = $item['entry'];
    }
    // Falls eine Folgefrage gestellt wurde, das vorherige Thema ebenfalls als Kontext mitgeben
    if ($lastTopicId !== '' && $lastTopicId !== 'ollama_general') {
        foreach ($kb as $entry) {
            if ($entry['id'] === $lastTopicId) {
                $alreadyIn = false;
                foreach ($topKbForRag as $existing) {
                    if ($existing['id'] === $lastTopicId) {
                        $alreadyIn = true;
                        break;
                    }
                }
                if (!$alreadyIn) {
                    $topKbForRag[] = $entry;
                }
                break;
            }
        }
    }

    // =========================================================================
    // 10b. STUFE 2 (OPTIONAL): LOKALES KI-SPRACHMODELL (OLLAMA AUF 127.0.0.1)
    // =========================================================================
    // Dual-Modus:
    // - Wenn die Frage klar einen einzelnen MiniCloud-KB-Artikel trifft ($bestScore >= 10),
    //   liefert Stufe 1 sofort (< 5 ms) die exakte Handbuch-Antwort.
    // - Wenn es sich um eine allgemeine Wissens-/Text-/Alltagsfrage ($bestScore < 7.5),
    //   eine kombinierte Frage oder eine Anschlussfrage handelt, antwortet das
    //   lokale Sprachmodell (qwen2.5:1.5b / 3b) im Dual-Modus.
    $isGeneralQuestion  = ($bestScore < 7.5 && (!$isFollowUpPhrase || $lastTopicId === '' || $lastTopicId === 'ollama_general'));
    $shouldInvokeOllama = ($bestScore < 10.0 || $isFollowUpPhrase || count($queryWords) >= 7);
    if ($shouldInvokeOllama && ($appSettings['assistant_use_ollama'] ?? '1') === '1') {
        $topicId = ($bestEntry !== null && !$isGeneralQuestion)
            ? (string)$bestEntry['id']
            : 'ollama_general';

        $ollamaReply = queryLocalOllamaAssistant(
            $pdo,
            $userId,
            $username,
            $isAdmin,
            $appSettings,
            $qTrim,
            $lang,
            $topKbForRag,
            $isGeneralQuestion
        );
        if ($ollamaReply !== null && $ollamaReply !== '') {
            rememberAssistantTurn($qTrim, $ollamaReply, $topicId);
            $actions = ($bestEntry !== null && !$isGeneralQuestion)
                ? $bestEntry['actions']
                : [
                    ['label' => '📝 Office Light', 'url' => 'office.php'],
                    ['label' => $pack['act_notes'], 'url' => 'notes.php'],
                    ['label' => $pack['act_dashboard'], 'url' => 'dashboard.php'],
                ];
            $followups = ($bestEntry !== null && !$isGeneralQuestion)
                ? $bestEntry['followups']
                : [
                    $pack['q_storage'],
                    $pack['q_events'],
                    'Generiere ein sicheres Passwort',
                ];
            return [
                'reply'     => "🧠 **Lokale KI-Antwort**\n\n" . $ollamaReply,
                'actions'   => $actions,
                'followups' => $followups,
                'lang'      => $lang,
            ];
        }
    }

    if ($bestEntry !== null && $bestScore >= 7.5) {
        $replyText = "{$bestEntry['icon']} **{$bestEntry['title']}**\n\n" . $bestEntry['answer'];
        rememberAssistantTurn($qTrim, $replyText, (string)$bestEntry['id']);
        return [
            'reply'     => $replyText,
            'actions'   => $bestEntry['actions'],
            'followups' => $bestEntry['followups'],
            'lang'      => $lang,
        ];
    }

    // =========================================================================
    // 11. AUTOMATISCHER FALLBACK: SUCHE IN EIGENEN DATEIEN & TEXTDOKUMENTEN
    // =========================================================================
    $stopWords = [
        'wie', 'was', 'wo', 'wann', 'warum', 'kann', 'ich', 'man', 'in', 'der', 'die', 'das', 'den', 'dem', 'ein', 'eine', 'mit', 'fuer', 'von', 'auf', 'ist', 'sind', 'cloud', 'minicloud', 'bitte', 'mir', 'mein', 'meine',
        'how', 'what', 'where', 'when', 'why', 'can', 'the', 'and', 'for', 'with', 'comment', 'que', 'quoi', 'como', 'come', 'hoe', 'jak', 'nasil', 'як', 'що',
    ];
    $meaningfulWords = array_values(array_filter(
        $queryWords,
        static fn(string $w): bool => mb_strlen($w) >= 3 && !in_array($w, $stopWords, true)
    ));

    if (!empty($meaningfulWords)) {
        $searchCandidate = $meaningfulWords[0];
        $like = '%' . $searchCandidate . '%';
        try {
            $stmtFiles = $pdo->prepare(
                "SELECT `id`, `original_name`, `stored_name`, `folder_path`, `file_size`
                 FROM `files`
                 WHERE `user_id` = :uid AND `deleted_at` IS NULL AND `original_name` LIKE :q
                 ORDER BY `upload_date` DESC, `id` DESC
                 LIMIT 4"
            );
            $stmtFiles->execute([':uid' => $userId, ':q' => $like]);
            $matchedFiles = $stmtFiles->fetchAll();

            // Zusätzlich Volltextsuche in kleinen .txt / .md Cloud-Dokumenten des Nutzers
            $contentHits = [];
            $stmtTxt = $pdo->prepare(
                "SELECT `id`, `original_name`, `stored_name`, `file_size`
                 FROM `files`
                 WHERE `user_id` = :uid AND `deleted_at` IS NULL
                   AND (`original_name` LIKE '%.txt' OR `original_name` LIKE '%.md')
                   AND `file_size` <= 204800
                 ORDER BY `upload_date` DESC
                 LIMIT 15"
            );
            $stmtTxt->execute([':uid' => $userId]);
            foreach ($stmtTxt->fetchAll() as $txtFile) {
                $fullPath = UPLOAD_DIR . '/' . basename((string)$txtFile['stored_name']);
                if (is_file($fullPath) && is_readable($fullPath)) {
                    $rawContent = (string)@file_get_contents($fullPath, false, null, 0, 65536);
                    if ($rawContent !== '' && mb_stripos($rawContent, $searchCandidate, 0, 'UTF-8') !== false) {
                        $pos = (int)mb_stripos($rawContent, $searchCandidate, 0, 'UTF-8');
                        $snippet = trim(preg_replace('/\s+/u', ' ', mb_substr($rawContent, max(0, $pos - 35), 90, 'UTF-8')) ?? '');
                        $contentHits[] = "- 📝 **" . htmlspecialchars((string)$txtFile['original_name'], ENT_QUOTES, 'UTF-8') . "**: *„…" . htmlspecialchars($snippet, ENT_QUOTES, 'UTF-8') . "…“*";
                        if (count($contentHits) >= 3) {
                            break;
                        }
                    }
                }
            }

            if (!empty($matchedFiles) || !empty($contentHits)) {
                $lines = [sprintf($pack['fallback_files'], htmlspecialchars($searchCandidate, ENT_QUOTES, 'UTF-8')) . "\n"];
                foreach ($matchedFiles as $f) {
                    $lines[] = "- 📄 **" . htmlspecialchars((string)$f['original_name'], ENT_QUOTES, 'UTF-8') . "** (" . formatBytes((int)$f['file_size']) . ")";
                }
                if (!empty($contentHits)) {
                    $lines[] = "\n🔎 **Textstellen in deinen Dokumenten:**";
                    foreach ($contentHits as $ch) {
                        $lines[] = $ch;
                    }
                }
                $replyText = implode("\n", $lines);
                rememberAssistantTurn($qTrim, $replyText, 'file_search');
                return [
                    'reply'   => $replyText,
                    'actions' => [
                        ['label' => $pack['search_all_btn'], 'url' => 'index.php?search=' . rawurlencode($searchCandidate)],
                    ],
                    'followups' => [
                        $pack['q_storage'],
                        $pack['q_office'],
                    ],
                    'lang' => $lang,
                ];
            }
        } catch (Throwable) {
        }
    }

    rememberAssistantTurn($qTrim, $pack['fallback_reply'], 'fallback');
    return [
        'reply'   => $pack['fallback_reply'],
        'actions' => [
            ['label' => $pack['act_help'], 'url' => 'help.php'],
            ['label' => $pack['act_dashboard'], 'url' => 'dashboard.php'],
        ],
        'followups' => [
            $pack['q_storage'],
            $pack['q_pdf'],
            $pack['q_webdav'],
        ],
        'lang' => $lang,
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

    if (isset($_POST['clear_context']) && $_POST['clear_context'] === '1') {
        unset($_SESSION['assistant_context']);
        echo json_encode(['ok' => true]);
        exit;
    }

    recordModuleUsage($pdo, 'assistant', true);

    $message       = trim((string)($_POST['message'] ?? ''));
    $preferredLang = trim((string)($_POST['lang'] ?? 'auto'));
    $result        = generateAssistantResponse($pdo, $userId, $username, $isAdmin, $appSettings, $message, $preferredLang);

    echo json_encode([
        'ok'        => true,
        'reply'     => $result['reply'],
        'actions'   => $result['actions'],
        'followups' => $result['followups'],
        'lang'      => $result['lang'],
    ]);
    exit;
}

$appTitle         = (string)($appSettings['app_title'] ?? 'MiniCloud');
$uiPack           = getAssistantLangPack($uiLang, $username, $appTitle);
$allowedLanguages = getAllowedLanguages();
$pageTitle        = $uiPack['page_title'];
$ollamaUiEnabled  = ($appSettings['assistant_use_ollama'] ?? '1') === '1';
$ollamaUiModel    = trim((string)($appSettings['assistant_ollama_model'] ?? 'gemma2:2b')) ?: 'gemma2:2b';
$ollamaUiProbe    = $ollamaUiEnabled ? probeLocalOllamaStatus($ollamaUiModel) : ['available' => false, 'active_model' => ''];
require_once __DIR__ . '/includes/header.php';
$csrfToken = getCsrfToken();
?>

<div class="space-y-6">
    <!-- KOPFBEREICH -->
    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs flex flex-wrap items-center justify-between gap-4">
        <div class="flex items-center gap-3.5">
            <div class="w-11 h-11 rounded-2xl bg-brand-50 dark:bg-brand-950/70 border border-brand-200/70 dark:border-brand-800/70 text-brand-600 dark:text-brand-400 flex items-center justify-center text-2xl shadow-xs shrink-0">
                🤖
            </div>
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-xl sm:text-2xl font-bold text-slate-900 dark:text-white">
                        <?= e($uiPack['header_title']) ?>
                    </h1>
                    <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-emerald-100 dark:bg-emerald-950/80 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800">
                        <?= e($uiPack['header_badge']) ?>
                    </span>
                    <?php if (!empty($ollamaUiProbe['available'])): ?>
                        <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-indigo-100 dark:bg-indigo-950/80 text-indigo-700 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800" title="Lokales KI-Sprachmodell auf 127.0.0.1:11434 aktiv">
                            🧠 Stufe 1 + 2 (Ollama: <?= e((string)$ollamaUiProbe['active_model']) ?>)
                        </span>
                    <?php else: ?>
                        <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border border-slate-200 dark:border-slate-700" title="100 % lokale PHP-&-MySQL-Engine (ideal auch für Shared-Webhosting/STRATO)">
                            ⚡ Stufe 1 (Lokale Engine + Kontext-Gedächtnis)
                        </span>
                    <?php endif; ?>
                </div>
                <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-0.5">
                    <?= e($uiPack['header_desc']) ?>
                </p>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <!-- Sprachauswahl für den Assistenten (alle 11 Sprachen + Auto) -->
            <select id="assistantLangSelect"
                    class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs font-semibold text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-500 cursor-pointer"
                    title="Antwortsprache des Assistenten">
                <option value="auto"><?= e($uiPack['lang_auto']) ?></option>
                <?php foreach ($allowedLanguages as $lCode => $lLabel): ?>
                    <option value="<?= e($lCode) ?>" <?= $lCode === $uiLang ? 'selected' : '' ?>>
                        <?= e($lLabel) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="button" onclick="clearAssistantHistory()"
                    class="px-3.5 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-xs font-semibold text-slate-700 dark:text-slate-200 transition-colors cursor-pointer">
                <?= e($uiPack['btn_clear']) ?>
            </button>
            <a href="help.php"
               class="px-3.5 py-2 rounded-xl bg-brand-50 dark:bg-brand-950/60 hover:bg-brand-100 text-brand-700 dark:text-brand-300 border border-brand-200 dark:border-brand-800 text-xs font-semibold transition-colors">
                <?= e($uiPack['btn_help']) ?>
            </a>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
        <!-- LINKE SPALTE: SCHNELLFRAGEN & FÄHIGKEITEN -->
        <div class="lg:col-span-4 space-y-4">
            <!-- 1. Live-Konto-Abfragen -->
            <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-4 shadow-xs space-y-2.5">
                <h2 class="text-xs font-bold uppercase tracking-wider text-brand-600 dark:text-brand-400 flex items-center gap-1.5">
                    <span>📊</span> <?= e($uiPack['sec_live']) ?>
                </h2>
                <div class="flex flex-col gap-1.5">
                    <button type="button" onclick="askAssistantQuick(<?= e(json_encode($uiPack['q_storage'])) ?>)"
                            class="text-left px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800/70 hover:bg-brand-50 dark:hover:bg-brand-950/40 border border-slate-200/80 dark:border-slate-700 text-xs font-medium text-slate-800 dark:text-slate-200 transition-colors cursor-pointer">
                        💾 <?= e($uiPack['q_storage']) ?>
                    </button>
                    <button type="button" onclick="askAssistantQuick(<?= e(json_encode($uiPack['q_events'])) ?>)"
                            class="text-left px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800/70 hover:bg-brand-50 dark:hover:bg-brand-950/40 border border-slate-200/80 dark:border-slate-700 text-xs font-medium text-slate-800 dark:text-slate-200 transition-colors cursor-pointer">
                        📅 <?= e($uiPack['q_events']) ?>
                    </button>
                    <button type="button" onclick="askAssistantQuick(<?= e(json_encode($uiPack['q_tasks'])) ?>)"
                            class="text-left px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800/70 hover:bg-brand-50 dark:hover:bg-brand-950/40 border border-slate-200/80 dark:border-slate-700 text-xs font-medium text-slate-800 dark:text-slate-200 transition-colors cursor-pointer">
                        📋 <?= e($uiPack['q_tasks']) ?>
                    </button>
                    <button type="button" onclick="askAssistantQuick('Wie steht mein Haushaltsbuch diesen Monat?')"
                            class="text-left px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800/70 hover:bg-brand-50 dark:hover:bg-brand-950/40 border border-slate-200/80 dark:border-slate-700 text-xs font-medium text-slate-800 dark:text-slate-200 transition-colors cursor-pointer">
                        💰 Wie steht mein Haushaltsbuch diesen Monat?
                    </button>
                    <button type="button" onclick="askAssistantQuick('Wo war ich beim Hörbuch oder E-Book stehengeblieben?')"
                            class="text-left px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800/70 hover:bg-brand-50 dark:hover:bg-brand-950/40 border border-slate-200/80 dark:border-slate-700 text-xs font-medium text-slate-800 dark:text-slate-200 transition-colors cursor-pointer">
                        🎧 Wo war ich beim Hörbuch / E-Book stehengeblieben?
                    </button>
                    <button type="button" onclick="askAssistantQuick('Welche Geburtstage stehen als Nächstes an?')"
                            class="text-left px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800/70 hover:bg-brand-50 dark:hover:bg-brand-950/40 border border-slate-200/80 dark:border-slate-700 text-xs font-medium text-slate-800 dark:text-slate-200 transition-colors cursor-pointer">
                        🎂 Welche Geburtstage stehen als Nächstes an?
                    </button>
                    <button type="button" onclick="askAssistantQuick(<?= e(json_encode($uiPack['q_messages'])) ?>)"
                            class="text-left px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800/70 hover:bg-brand-50 dark:hover:bg-brand-950/40 border border-slate-200/80 dark:border-slate-700 text-xs font-medium text-slate-800 dark:text-slate-200 transition-colors cursor-pointer">
                        💬 <?= e($uiPack['q_messages']) ?>
                    </button>
                </div>
            </div>

            <!-- 2. Häufige Fragen zur Cloud -->
            <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-4 shadow-xs space-y-2.5">
                <h2 class="text-xs font-bold uppercase tracking-wider text-indigo-600 dark:text-indigo-400 flex items-center gap-1.5">
                    <span>💡</span> <?= e($uiPack['sec_modules']) ?>
                </h2>
                <div class="flex flex-col gap-1.5">
                    <button type="button" onclick="askAssistantQuick(<?= e(json_encode($uiPack['q_office'])) ?>)"
                            class="text-left px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800/70 hover:bg-indigo-50 dark:hover:bg-indigo-950/40 border border-slate-200/80 dark:border-slate-700 text-xs font-medium text-slate-800 dark:text-slate-200 transition-colors cursor-pointer">
                        📝 <?= e($uiPack['q_office']) ?>
                    </button>
                    <button type="button" onclick="askAssistantQuick(<?= e(json_encode($uiPack['q_ebooks'])) ?>)"
                            class="text-left px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800/70 hover:bg-indigo-50 dark:hover:bg-indigo-950/40 border border-slate-200/80 dark:border-slate-700 text-xs font-medium text-slate-800 dark:text-slate-200 transition-colors cursor-pointer">
                        📚 <?= e($uiPack['q_ebooks']) ?>
                    </button>
                    <button type="button" onclick="askAssistantQuick(<?= e(json_encode($uiPack['q_pdf'])) ?>)"
                            class="text-left px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800/70 hover:bg-indigo-50 dark:hover:bg-indigo-950/40 border border-slate-200/80 dark:border-slate-700 text-xs font-medium text-slate-800 dark:text-slate-200 transition-colors cursor-pointer">
                        ✏️ <?= e($uiPack['q_pdf']) ?>
                    </button>
                    <button type="button" onclick="askAssistantQuick(<?= e(json_encode($uiPack['q_ocr'])) ?>)"
                            class="text-left px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800/70 hover:bg-indigo-50 dark:hover:bg-indigo-950/40 border border-slate-200/80 dark:border-slate-700 text-xs font-medium text-slate-800 dark:text-slate-200 transition-colors cursor-pointer">
                        🔎 <?= e($uiPack['q_ocr']) ?>
                    </button>
                    <button type="button" onclick="askAssistantQuick(<?= e(json_encode($uiPack['q_webdav'])) ?>)"
                            class="text-left px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800/70 hover:bg-indigo-50 dark:hover:bg-indigo-950/40 border border-slate-200/80 dark:border-slate-700 text-xs font-medium text-slate-800 dark:text-slate-200 transition-colors cursor-pointer">
                        📡 <?= e($uiPack['q_webdav']) ?>
                    </button>
                    <button type="button" onclick="askAssistantQuick(<?= e(json_encode($uiPack['q_vault'])) ?>)"
                            class="text-left px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800/70 hover:bg-indigo-50 dark:hover:bg-indigo-950/40 border border-slate-200/80 dark:border-slate-700 text-xs font-medium text-slate-800 dark:text-slate-200 transition-colors cursor-pointer">
                        🔒 <?= e($uiPack['q_vault']) ?>
                    </button>
                    <button type="button" onclick="askAssistantQuick('Wie ist die Cloud laut IT-Sicherheitskonzept geschützt?')"
                            class="text-left px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800/70 hover:bg-indigo-50 dark:hover:bg-indigo-950/40 border border-slate-200/80 dark:border-slate-700 text-xs font-medium text-slate-800 dark:text-slate-200 transition-colors cursor-pointer">
                        🛡️ Wie ist die Cloud laut Sicherheitskonzept geschützt?
                    </button>
                </div>
            </div>

            <!-- 2b. Allgemeinwissen, Text-KI & Alltags-Helfer -->
            <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-4 shadow-xs space-y-2.5">
                <h2 class="text-xs font-bold uppercase tracking-wider text-emerald-600 dark:text-emerald-400 flex items-center gap-1.5">
                    <span>🌍</span> Allgemeinwissen, Text-KI &amp; Alltags-Helfer
                </h2>
                <div class="flex flex-col gap-1.5">
                    <button type="button" onclick="askAssistantQuick('Schreib mir eine freundliche Einladung zur Jahreshauptversammlung unseres Vereins')"
                            class="text-left px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800/70 hover:bg-emerald-50 dark:hover:bg-emerald-950/40 border border-slate-200/80 dark:border-slate-700 text-xs font-medium text-slate-800 dark:text-slate-200 transition-colors cursor-pointer">
                        ✍️ Einladung zur Vereinsversammlung entwerfen
                    </button>
                    <button type="button" onclick="askAssistantQuick('Was ist 19% von 249,50?')"
                            class="text-left px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800/70 hover:bg-emerald-50 dark:hover:bg-emerald-950/40 border border-slate-200/80 dark:border-slate-700 text-xs font-medium text-slate-800 dark:text-slate-200 transition-colors cursor-pointer">
                        🧮 Prozent- &amp; Taschenrechner: 19% von 249,50
                    </button>
                    <button type="button" onclick="askAssistantQuick('Generiere ein sicheres Passwort')"
                            class="text-left px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800/70 hover:bg-emerald-50 dark:hover:bg-emerald-950/40 border border-slate-200/80 dark:border-slate-700 text-xs font-medium text-slate-800 dark:text-slate-200 transition-colors cursor-pointer">
                        🔑 Kryptografisch sicheres Passwort generieren
                    </button>
                    <button type="button" onclick="askAssistantQuick('Wie viele Tage bis Weihnachten?')"
                            class="text-left px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800/70 hover:bg-emerald-50 dark:hover:bg-emerald-950/40 border border-slate-200/80 dark:border-slate-700 text-xs font-medium text-slate-800 dark:text-slate-200 transition-colors cursor-pointer">
                        📆 Datums- &amp; Countdown-Rechner
                    </button>
                </div>
            </div>

            <!-- 3. Tipp: Schnellbefehle -->
            <div class="mc-card bg-amber-50/70 dark:bg-amber-950/20 rounded-2xl border border-amber-200/80 dark:border-amber-800/60 p-4 text-xs space-y-1.5">
                <p class="font-bold text-amber-900 dark:text-amber-300"><?= e($uiPack['tip_title']) ?></p>
                <p class="text-amber-800/90 dark:text-amber-200/80 leading-relaxed">
                    <?= $uiPack['tip_body'] ?>
                </p>
            </div>
        </div>

        <!-- RECHTE SPALTE: INTERAKTIVES CHAT-FENSTER -->
        <div class="lg:col-span-8 mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xs flex flex-col h-[640px] overflow-hidden">
            <!-- Chat-Verlauf -->
            <div id="assistantChatMessages" class="flex-1 overflow-y-auto p-4 sm:p-6 space-y-4"></div>

            <!-- Eingabezeile -->
            <form id="assistantChatForm" onsubmit="submitAssistantQuestion(event)" class="p-3.5 sm:p-4 border-t border-slate-200 dark:border-slate-800 bg-slate-50/70 dark:bg-slate-900/90 flex items-center gap-2.5">
                <input
                    type="text"
                    id="assistantQuestionInput"
                    placeholder="<?= e($uiPack['input_placeholder']) ?>"
                    autocomplete="off"
                    class="flex-1 px-4 py-2.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500"
                >
                <button
                    type="submit"
                    id="assistantSendBtn"
                    class="px-5 py-2.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-bold text-xs sm:text-sm shadow-xs transition-colors cursor-pointer shrink-0"
                >
                    <?= e($uiPack['btn_send']) ?>
                </button>
            </form>
        </div>
    </div>
</div>

<script>
const ASSISTANT_CSRF = <?= json_encode($csrfToken) ?>;
const ASSISTANT_UI_LANG = <?= json_encode($uiLang) ?>;
const ASSISTANT_STORAGE_KEY = 'mc_assistant_chat_v2_' + <?= json_encode($userId) ?> + '_' + ASSISTANT_UI_LANG;

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
            reply: <?= json_encode($uiPack['welcome']) ?>,
            actions: [
                { label: <?= json_encode($uiPack['act_dashboard']) ?>, url: 'dashboard.php' },
                { label: <?= json_encode($uiPack['act_files']) ?>, url: 'index.php' },
                { label: <?= json_encode($uiPack['act_office']) ?>, url: 'office.php' },
                { label: <?= json_encode($uiPack['act_ebooks']) ?>, url: 'ebooks.php' }
            ],
            followups: [
                <?= json_encode($uiPack['q_storage']) ?>,
                <?= json_encode($uiPack['q_events']) ?>,
                <?= json_encode($uiPack['q_ocr']) ?>
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
        const fd = new FormData();
        fd.append('ajax', '1');
        fd.append('csrf_token', ASSISTANT_CSRF);
        fd.append('clear_context', '1');
        fetch('assistant.php', { method: 'POST', body: fd }).catch(() => {});
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

    const container = document.getElementById('assistantChatMessages');
    let typingEl = null;
    if (container) {
        typingEl = document.createElement('div');
        typingEl.className = 'flex items-start gap-2.5';
        typingEl.innerHTML = `
            <div class="w-8 h-8 rounded-xl bg-gradient-to-br from-brand-600 to-indigo-600 text-white flex items-center justify-center text-sm shrink-0 mt-0.5 animate-pulse">🤖</div>
            <div class="px-4 py-3 rounded-2xl rounded-tl-sm bg-slate-100 dark:bg-slate-800/90 border border-slate-200/80 dark:border-slate-700/80 text-xs sm:text-sm text-slate-600 dark:text-slate-300 flex items-center gap-2.5">
                <span class="inline-flex items-center gap-1 text-brand-600 dark:text-brand-400">
                    <span class="inline-block w-2 h-2 rounded-full bg-current animate-bounce" style="animation-delay:0ms"></span>
                    <span class="inline-block w-2 h-2 rounded-full bg-current animate-bounce" style="animation-delay:150ms"></span>
                    <span class="inline-block w-2 h-2 rounded-full bg-current animate-bounce" style="animation-delay:300ms"></span>
                </span>
                <span class="font-medium">Assistent denkt nach…</span>
            </div>
        `;
        container.appendChild(typingEl);
        container.scrollTop = container.scrollHeight;
    }

    const btn = document.getElementById('assistantSendBtn');
    if (btn) btn.disabled = true;

    try {
        const langSelect = document.getElementById('assistantLangSelect');
        const selectedLang = langSelect ? langSelect.value : 'auto';

        const fd = new FormData();
        fd.append('ajax', '1');
        fd.append('csrf_token', ASSISTANT_CSRF);
        fd.append('message', text);
        fd.append('lang', selectedLang);

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
        if (typingEl && typingEl.parentNode) {
            typingEl.parentNode.removeChild(typingEl);
        }
        if (btn) btn.disabled = false;
        saveAssistantHistory(history);
        renderAssistantMessages();
    }
}

document.addEventListener('DOMContentLoaded', renderAssistantMessages);
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
