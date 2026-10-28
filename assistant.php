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
        'nl' => [' hoe ', ' wat ', ' welke ', ' mijn ', ' opslag ', ' opslagruimte ', ' vrij ', ' taak:', ' notitie:', ' zoek ', ' bestand ', ' bestanden ', ' map ', ' afspraken ', ' hallo '],
        'pl' => [' jak ', ' co ', ' jakie ', ' moje ', ' miejsce ', ' pamięć ', ' pamiec ', ' zadanie:', ' notatka:', ' szukaj ', ' znajdź ', ' znajdz ', ' plik ', ' pliki ', ' katalog ', ' cześć ', ' czesc '],
        'pt' => [' como ', ' qual ', ' quais ', ' meu ', ' meus ', ' minha ', ' minhas ', ' armazenamento ', ' espaço ', ' espaco ', ' tarefa:', ' procurar ', ' ficheiro ', ' arquivo ', ' pasta ', ' olá ', ' ola '],
        'cs' => [' jak ', ' co ', ' jaké ', ' jake ', ' moje ', ' můj ', ' muj ', ' úložiště ', ' uloziste ', ' místo ', ' misto ', ' úkol:', ' ukol:', ' poznámka:', ' poznamka:', ' hledat ', ' najít ', ' soubor ', ' složka ', ' ahoj '],
        'tr' => [' nasıl ', ' nasil ', ' ne ', ' hangi ', ' benim ', ' depolama ', ' alan ', ' görev:', ' gorev:', ' not:', ' ara ', ' bul ', ' dosya ', ' klasör ', ' klasor ', ' merhaba ', ' selam '],
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
            'header_desc'      => 'Beantwortet Fragen zur Cloud in 11 Sprachen, durchsucht live deine Dateien, Termine & Aufgaben und legt per Befehl direkt Notizen oder To-Dos an.',
            'btn_clear'        => '🔄 Verlauf leeren',
            'btn_help'         => '📖 Handbuch öffnen',
            'lang_auto'        => '🌐 Automatisch (Sprache erkennen)',
            'sec_live'         => 'Live-Abfragen zu deinem Konto',
            'sec_modules'      => 'Häufige Fragen zu Modulen',
            'tip_title'        => '⚡ Profi-Tipp: Schnellbefehle im Chat',
            'tip_body'         => '• <code>Suche Rechnung</code> durchsucht Dateien, Kontakte & Notizen.<br>• <code>Notiz: Einkaufsliste…</code> legt direkt eine Haftnotiz an.<br>• <code>Aufgabe: Steuererklärung</code> legt direkt ein To-Do an.',
            'input_placeholder'=> 'Frage stellen (in 11 Sprachen) oder „Suche …“ / „Aufgabe: …“ eingeben…',
            'btn_send'         => 'Senden →',
            'welcome'          => "Hallo **{$username}**! 👋 Ich bin dein lokaler **{$appTitle}-Assistent**.\n\nIch spreche alle **11 Sprachen** deiner Cloud! Frag mich gerne alles zur Bedienung, zu deinem freien **Speicherplatz**, deinen **Terminen & Aufgaben** oder suche direkt nach **Dateien, Kontakten und Notizen**.",
            'quick_welcome'    => "Hallo **{$username}**! 👋 Wie kann ich dir auf dieser Seite helfen?",
            'hello_reply'      => "Hallo **{$username}**! 🤖 Ich bin dein **lokaler Cloud-Assistent** (100 % offline-, Intranet- & DSGVO-konform direkt auf diesem Server).\n\nIch kann dir in **11 Sprachen** helfen:\n1. **📊 Live-Fragen zu deinem Konto:** Frag mich nach freiem *Speicherplatz*, *Terminen*, offenen *Aufgaben*, ungelesenen *Nachrichten* oder suche nach *Dateien, Kontakten & Notizen*.\n2. **💡 Erklärungen zu allen Modulen:** Frag mich zu *Office Light*, *E-Book-Reader (.epub)*, *PDF schwärzen & ausfüllen*, *OCR-Texterkennung*, *WebDAV/CalDAV* oder *Datei-Tresor*.\n3. **⚡ Schnellbefehle:** Schreib `Notiz: ...` oder `Aufgabe: ...`, um sofort einen Eintrag anzulegen!",
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
            'en' => ['📝 Office Light (Word, Excel, Markdown & Live Co-Editing)', "**📝 Office Light** (`office.php`) lets you create and edit documents right in your browser:\n\n- **📘 Word documents (`.docx`):** Rich formatting, headings, tables, lists & print/PDF view.\n- **📗 Excel spreadsheets (`.xlsx` & `.csv`):** Multi-sheet support, formulas (`=SUM(A1:A10)`, `=AVERAGE(...)`, `=MIN(...)`, `=MAX(...)`), sorting & charts.\n- **🟢 Live team co-editing:** Multiple users can edit the same document simultaneously with live presence badges!"],
            'fr' => ['📝 Office Light (Word, Excel & Co-édition en direct)', "**📝 Office Light** (`office.php`) permet de créer et modifier vos documents dans le navigateur :\n\n- **📘 Documents Word (`.docx`)** et **📗 Tableaux Excel (`.xlsx`, `.csv`)** avec formules (`=SUM(...)`, `=AVERAGE(...)`) et graphiques.\n- **🟢 Co-édition en direct** à plusieurs en même temps et 6 modèles prêts à l'emploi."],
            'es' => ['📝 Office Light (Word, Excel y Co-edición en vivo)', "**📝 Office Light** (`office.php`) es tu suite ofimática integrada en el navegador:\n\n- **📘 Documentos Word (`.docx`)** y **📗 Hojas Excel (`.xlsx`, `.csv`)** con fórmulas (`=SUM(...)`, `=AVERAGE(...)`) y gráficos.\n- **🟢 Edición colaborativa en tiempo real** en equipo y 6 plantillas rápidas."],
            'it' => ['📝 Office Light (Word, Excel & Co-editing dal vivo)', "**📝 Office Light** (`office.php`) ti permette di modificare documenti direttamente nel browser:\n\n- **📘 Documenti Word (`.docx`)** e **📗 Fogli Excel (`.xlsx`, `.csv`)** con formule (`=SUM(...)`, `=AVERAGE(...)`) e grafici.\n- **🟢 Modifica collaborativa in tempo reale** con il tuo team."],
            'nl' => ['📝 Office Light (Word, Excel & Live Samenwerken)', "**📝 Office Light** (`office.php`) is je kantooromgeving direct in de browser:\n\n- **📘 Word-documenten (`.docx`)** en **📗 Excel-spreadsheets (`.xlsx`, `.csv`)** met formules (`=SUM(...)`, `=AVERAGE(...)`).\n- **🟢 Live samenwerken** met meerdere personen tegelijk."],
            'pl' => ['📝 Office Light (Word, Excel i Współedycja na żywo)', "**📝 Office Light** (`office.php`) pozwala edytować dokumenty w przeglądarce:\n\n- **📘 Dokumenty Word (`.docx`)** oraz **📗 Arkusze Excel (`.xlsx`, `.csv`)** z formułami (`=SUM(...)`, `=AVERAGE(...)`).\n- **🟢 Współpraca na żywo** wielu użytkowników jednocześnie."],
            'pt' => ['📝 Office Light (Word, Excel e Coedição ao vivo)', "**📝 Office Light** (`office.php`) permite editar documentos no navegador:\n\n- **📘 Documentos Word (`.docx`)** e **📗 Folhas Excel (`.xlsx`, `.csv`)** com fórmulas (`=SUM(...)`, `=AVERAGE(...)`).\n- **🟢 Colaboração em tempo real** em equipa."],
            'cs' => ['📝 Office Light (Word, Excel a živá spolupráce)', "**📝 Office Light** (`office.php`) umožňuje upravovat dokumenty přímo v prohlížeči:\n\n- **📘 Dokumenty Word (`.docx`)** a **📗 Tabulky Excel (`.xlsx`, `.csv`)** se vzorci (`=SUM(...)`, `=AVERAGE(...)`).\n- **🟢 Živá týmová spolupráce** více uživatelů najednou."],
            'tr' => ['📝 Office Light (Word, Excel ve Canlı Ortak Çalışma)', "**📝 Office Light** (`office.php`) tarayıcıda belge düzenlemenizi sağlar:\n\n- **📘 Word belgeleri (`.docx`)** ve **📗 Excel tabloları (`.xlsx`, `.csv`)** (`=SUM(...)`, `=AVERAGE(...)` formülleriyle).\n- **🟢 Ekiple canlı eşzamanlı düzenleme** ve 6 hazır şablon."],
            'uk' => ['📝 Office Light (Word, Excel та спільне редагування)', "**📝 Office Light** (`office.php`) дозволяє редагувати документи прямо у браузері:\n\n- **📘 Документи Word (`.docx`)** та **📗 Таблиці Excel (`.xlsx`, `.csv`)** з формулами (`=SUM(...)`, `=AVERAGE(...)`).\n- **🟢 Жива спільна робота** кількох користувачів одночасно."],
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
                . "- **👥 Intern mit Cloud-Benutzern oder Gruppen:** Wähle im Teilen-Dialog einfach per **Live-Suche** den gewünschten Benutzer oder eine Gruppe aus und lege fest, ob **👁️ Nur Lesen** oder **✏️ Lesen & Schreiben** erlaubt sein soll.\n\n"
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
            'title'    => 'Gruppenordner & Team-Laufwerke',
            'icon'     => '👥',
            'keywords' => [
                'gruppenordner', 'teamordner', 'gemeinsamer ordner', 'abteilung', 'verein', 'gruppe',
                'group folder', 'shared folder', 'team folder', 'dossier de groupe', 'carpeta de grupo', 'cartella di gruppo', 'groepsmap', 'folder grupowy', 'pasta de grupo', 'skupinova slozka', 'grup klasoru', 'групова папка',
            ],
            'answer'   => "**👥 Gruppenordner** sind gemeinsame Team-Bereiche (z. B. für Familie, Projekte, Vereine oder Abteilungen):\n\n"
                . "- Sie erscheinen direkt unterhalb deiner eigenen Dateien in **📂 Dateien** sowie im **WebDAV-Netzlaufwerk** unter `/Gruppenordner/`.\n"
                . "- Je nach Berechtigung (*Nur Lesen* oder *Lesen & Schreiben*) können alle berechtigten Mitglieder dort gemeinsam Dateien ablegen, Ordner anlegen und Dokumente in **📝 Office Light** bearbeiten.\n"
                . "- Neue Gruppenordner und deren Zugriffsrechte werden vom **Administrator** unter *⚙️ Admin → Gruppen & Gruppenordner* eingerichtet.",
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
            'title'    => 'Office Light (Word, Excel, Markdown & Live-Co-Editing)',
            'icon'     => '📝',
            'keywords' => [
                'office', 'word', 'excel', 'tabelle', 'kalkulation', 'docx', 'xlsx', 'csv', 'markdown', 'dokument erstellen', 'vorlage', 'brief', 'protokoll', 'formel', 'gleichzeitig bearbeiten', 'co editing',
                'spreadsheet', 'document', 'tableur', 'hoja de calculo', 'foglio di calcolo', 'arkusz', 'tabulka', 'tablo', 'таблиця', 'документ',
            ],
            'answer'   => "**📝 Office Light** (`office.php`) ist deine integrierte Büro-Zentrale direkt im Browser – komplett ohne Zusatzsoftware:\n\n"
                . "- **📘 Word-Dokumente (`.docx`):** Formatierter Text, Überschriften, Tabellen, Listen, Farben & Druck-/PDF-Ansicht.\n"
                . "- **📗 Excel-Tabellen (`.xlsx` & `.csv`):** Mehrere Tabellenblätter, Formeln wie `=SUM(A1:A10)`, `=AVERAGE(...)`, `=MIN(...)`, `=MAX(...)`, Spalten-Sortierung und Diagramm-Vorschau.\n"
                . "- **✨ 6 Schnellstart-Vorlagen:** Leeres Word-Dokument, Geschäftsbrief, Besprechungsprotokoll, Leere Tabelle, Budget-/Kassenplan und Stundenzettel.\n"
                . "- **🟢 Live-Team-Zusammenarbeit:** Wenn mehrere Personen dasselbe Dokument öffnen, seht ihr oben live, wer gerade mitschreibt, und Änderungen werden automatisch synchronisiert!",
            'actions'  => [
                ['label' => $pack['act_office'], 'url' => 'office.php'],
            ],
            'followups' => [
                $pack['q_ebooks'],
                $pack['q_pdf'],
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
            'title'    => 'Notizwand & Pinboard (Haftnotizen)',
            'icon'     => '📌',
            'keywords' => [
                'notiz', 'notizen', 'haftnotiz', 'pinboard', 'pinnwand', 'tag', 'hashtag', 'anpinnen', 'markdown export',
                'notes', 'sticky note', 'notas', 'notities', 'notatki', 'poznamky', 'notlar', 'нотатки',
            ],
            'answer'   => "Auf der **📌 Notizwand** (`notes.php`) hältst du Ideen, Checklisten und Infos auf farbigen Haftnotizen fest:\n\n"
                . "- Nutze **`#Tags`** zur schnellen Filterung, pinne wichtige Notizen oben an (erscheinen dann auch in **🏠 Mein Tag**) und teile Notizen mit Benutzern oder Gruppen.\n"
                . "- Mit einem Klick exportierst du jede Notiz als `.md`-Datei in deine Cloud.\n"
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
    ];

    if ($isAdmin) {
        $kb[] = [
            'id'       => 'admin_features',
            'title'    => 'Administration, Modul-Statistik, Backup & GitHub-Update',
            'icon'     => '🛠️',
            'keywords' => ['admin', 'administration', 'benutzer anlegen', 'kontingent', 'quota', 'smtp', 'mail', 'impressum', 'logo', 'backup', 'datenbank', 'update', 'github', 'modul statistik', 'audit log', 'statistics'],
            'answer'   => "Als **Administrator** stehen dir unter **🛠️ Admin** (`admin.php`) 5 Verwaltungsbereiche zur Verfügung:\n\n"
                . "1. **👥 Benutzer & Gruppen:** Konten anlegen, Speicherkontingente (Quota) vergeben, Gruppen & Gruppenordner-Rechte verwalten (inkl. Live-Suchfilter).\n"
                . "2. **🧩 Module & Statistik:** Alle 15 Zusatzmodule einzeln ein-/ausschalten sowie die **📊 Modul-Nutzungsstatistik** (Heute / 7 Tage / 30 Tage / Gesamt + Datenbestand) einsehen.\n"
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
    // 10. WISSENSDATENBANK-ABGLEICH (MIT GEWICHTUNG & FUZZY-MATCHING)
    // =========================================================================
    $kb = getAssistantKnowledgeBase($appSettings, $isAdmin, $lang);
    $queryWords = array_values(array_filter(explode(' ', $norm), static fn(string $w): bool => mb_strlen($w) >= 2));

    $bestScore = 0.0;
    $bestEntry = null;

    foreach ($kb as $entry) {
        $score = 0.0;
        $titleNorm = normalizeAssistantText($entry['title']);

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
                } elseif (mb_strlen($qw) >= 4 && mb_strlen($kwNorm) >= 4) {
                    if (str_starts_with($qw, $kwNorm) || str_starts_with($kwNorm, $qw)) {
                        $score += 5.0;
                    } elseif (strlen($qw) <= 20 && strlen($kwNorm) <= 20 && levenshtein($qw, $kwNorm) <= 1) {
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
            'lang'      => $lang,
        ];
    }

    // =========================================================================
    // 11. AUTOMATISCHER FALLBACK: SUCHE IN EIGENEN DATEIEN
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
                "SELECT `id`, `original_name`, `folder_path`, `file_size`
                 FROM `files`
                 WHERE `user_id` = :uid AND `deleted_at` IS NULL AND `original_name` LIKE :q
                 ORDER BY `upload_date` DESC, `id` DESC
                 LIMIT 4"
            );
            $stmtFiles->execute([':uid' => $userId, ':q' => $like]);
            $matchedFiles = $stmtFiles->fetchAll();

            if (!empty($matchedFiles)) {
                $lines = [sprintf($pack['fallback_files'], htmlspecialchars($searchCandidate, ENT_QUOTES, 'UTF-8')) . "\n"];
                foreach ($matchedFiles as $f) {
                    $lines[] = "- 📄 **" . htmlspecialchars((string)$f['original_name'], ENT_QUOTES, 'UTF-8') . "** (" . formatBytes((int)$f['file_size']) . ")";
                }
                return [
                    'reply'   => implode("\n", $lines),
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
                        <?= e($uiPack['header_title']) ?>
                    </h1>
                    <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-emerald-100 dark:bg-emerald-950/80 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800">
                        <?= e($uiPack['header_badge']) ?>
                    </span>
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
                    <button type="button" onclick="askAssistantQuick(<?= e(json_encode($uiPack['q_notes'])) ?>)"
                            class="text-left px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800/70 hover:bg-brand-50 dark:hover:bg-brand-950/40 border border-slate-200/80 dark:border-slate-700 text-xs font-medium text-slate-800 dark:text-slate-200 transition-colors cursor-pointer">
                        📌 <?= e($uiPack['q_notes']) ?>
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
        if (btn) btn.disabled = false;
        saveAssistantHistory(history);
        renderAssistantMessages();
    }
}

document.addEventListener('DOMContentLoaded', renderAssistantMessages);
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
