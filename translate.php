<?php
declare(strict_types=1);

/**
 * MiniCloud – Neuronales Übersetzungs-Studio & Dokument-Übersetzer (translate.php)
 *
 * Funktionen:
 * - Satz- und kontextbasierte Neuronale Übersetzung (NMT) für 18 Sprachen (inkl. automatischer Spracherkennung)
 * - Dual-Engine:
 *   1. Sofort einsatzbereite Standard-Engine ohne API-Key (Google NMT gtx + MyMemory NMT Fallback, 100 % STRATO-tauglich)
 *   2. Optionale DeepL API (Free / Pro) Anbindung inkl. Förmlichkeits-Steuerung („Sie“ / „Du“)
 * - Direktes Laden & Übersetzen von Cloud-Dokumenten (PDF inkl. Scan-OCR, Word .docx, Excel .xlsx, PowerPoint .pptx, .txt, .md, .csv, .srt)
 * - 1-Klick-Export der Übersetzung als Word-Dokument (.docx), Markdown (.md) oder Textdatei (.txt) direkt in die Cloud
 * - Sprachausgabe (Vorlesen / Text-to-Speech) für Ausgangs- und Zieltext
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/office_functions.php';
require_once __DIR__ . '/includes/i18n.php';

requireLogin();

$pdo         = getDB();
$appSettings = getAppSettings($pdo);

if (($appSettings['enable_translator'] ?? '1') !== '1' && !isAdmin()) {
    setFlash('error', 'Das Übersetzungsmodul ist derzeit durch den Administrator deaktiviert.');
    header('Location: index.php');
    exit;
}

$userId  = (int)$_SESSION['user_id'];
$isAdmin = isAdmin();

/**
 * Liefert alle vom Übersetzungs-Studio unterstützten Sprachen samt Metadaten.
 *
 * @return array<string, array{name: string, deepl_source: string, deepl_target: string, tts: string}>
 */
function getTranslatorLanguages(): array
{
    return [
        'de' => ['name' => 'Deutsch',             'deepl_source' => 'DE', 'deepl_target' => 'DE',    'tts' => 'de-DE'],
        'en' => ['name' => 'Englisch',            'deepl_source' => 'EN', 'deepl_target' => 'EN-GB', 'tts' => 'en-GB'],
        'fr' => ['name' => 'Französisch',         'deepl_source' => 'FR', 'deepl_target' => 'FR',    'tts' => 'fr-FR'],
        'es' => ['name' => 'Spanisch',            'deepl_source' => 'ES', 'deepl_target' => 'ES',    'tts' => 'es-ES'],
        'it' => ['name' => 'Italienisch',         'deepl_source' => 'IT', 'deepl_target' => 'IT',    'tts' => 'it-IT'],
        'nl' => ['name' => 'Niederländisch',      'deepl_source' => 'NL', 'deepl_target' => 'NL',    'tts' => 'nl-NL'],
        'pl' => ['name' => 'Polnisch',            'deepl_source' => 'PL', 'deepl_target' => 'PL',    'tts' => 'pl-PL'],
        'pt' => ['name' => 'Portugiesisch',       'deepl_source' => 'PT', 'deepl_target' => 'PT-PT', 'tts' => 'pt-PT'],
        'cs' => ['name' => 'Tschechisch',         'deepl_source' => 'CS', 'deepl_target' => 'CS',    'tts' => 'cs-CZ'],
        'tr' => ['name' => 'Türkisch',            'deepl_source' => 'TR', 'deepl_target' => 'TR',    'tts' => 'tr-TR'],
        'uk' => ['name' => 'Ukrainisch',          'deepl_source' => 'UK', 'deepl_target' => 'UK',    'tts' => 'uk-UA'],
        'da' => ['name' => 'Dänisch',             'deepl_source' => 'DA', 'deepl_target' => 'DA',    'tts' => 'da-DK'],
        'sv' => ['name' => 'Schwedisch',          'deepl_source' => 'SV', 'deepl_target' => 'SV',    'tts' => 'sv-SE'],
        'el' => ['name' => 'Griechisch',          'deepl_source' => 'EL', 'deepl_target' => 'EL',    'tts' => 'el-GR'],
        'ru' => ['name' => 'Russisch',            'deepl_source' => 'RU', 'deepl_target' => 'RU',    'tts' => 'ru-RU'],
        'ar' => ['name' => 'Arabisch',            'deepl_source' => 'AR', 'deepl_target' => 'AR',    'tts' => 'ar-SA'],
        'zh' => ['name' => 'Chinesisch (Vereinf.)','deepl_source' => 'ZH', 'deepl_target' => 'ZH',   'tts' => 'zh-CN'],
        'ja' => ['name' => 'Japanisch',           'deepl_source' => 'JA', 'deepl_target' => 'JA',    'tts' => 'ja-JP'],
    ];
}

/**
 * Rendert eine SVG-Flagge für alle 18 Übersetzer-Sprachen.
 */
function renderTranslatorFlagSvg(string $code, string $class = 'w-5 h-3.5 rounded-[2px] shadow-2xs shrink-0'): string
{
    $c = strtolower(trim($code));
    if (in_array($c, ['de', 'en', 'fr', 'es', 'it', 'nl', 'pl', 'pt', 'cs', 'tr', 'uk'], true)) {
        return renderLanguageFlagSvg($c, $class);
    }

    $svgInner = match ($c) {
        'da' => '<rect width="24" height="16" fill="#C60C30"/><rect width="2.6" height="16" x="7.5" fill="#fff"/><rect width="24" height="2.6" y="6.7" fill="#fff"/>',
        'sv' => '<rect width="24" height="16" fill="#006AA7"/><rect width="2.8" height="16" x="7.5" fill="#FECC02"/><rect width="24" height="2.8" y="6.6" fill="#FECC02"/>',
        'el' => '<rect width="24" height="16" fill="#0D5EAF"/><path d="M0,2.67 H24 M0,6.22 H24 M0,9.78 H24 M0,13.33 H24" stroke="#fff" stroke-width="1.78"/><rect width="8.9" height="8.9" fill="#0D5EAF"/><path d="M4.45,0 V8.9 M0,4.45 H8.9" stroke="#fff" stroke-width="1.78"/>',
        'ru' => '<rect width="24" height="5.33" y="0" fill="#fff"/><rect width="24" height="5.34" y="5.33" fill="#0039A6"/><rect width="24" height="5.33" y="10.67" fill="#D52B1E"/>',
        'ar' => '<rect width="24" height="16" fill="#006C35"/><rect width="14" height="2" x="5" y="10" rx="1" fill="#fff"/><rect width="10" height="2" x="7" y="6" rx="1" fill="#fff"/>',
        'zh' => '<rect width="24" height="16" fill="#EE1C25"/><circle cx="5" cy="5" r="2.2" fill="#FFFF00"/><circle cx="9.2" cy="2.2" r="0.7" fill="#FFFF00"/><circle cx="10.8" cy="4" r="0.7" fill="#FFFF00"/><circle cx="10.5" cy="6.5" r="0.7" fill="#FFFF00"/><circle cx="8.8" cy="8.2" r="0.7" fill="#FFFF00"/>',
        'ja' => '<rect width="24" height="16" fill="#fff"/><circle cx="12" cy="8" r="4.6" fill="#BC002D"/>',
        default => '<rect width="24" height="16" fill="#64748b"/>',
    };

    return '<svg class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '" viewBox="0 0 24 16" aria-hidden="true">' . $svgInner . '</svg>';
}

/**
 * Zerlegt längere Texte schonend an Absatz- und Satzgrenzen in Blöcke <= $maxChunkChars Zeichen,
 * damit Zeilenumbrüche und der Satzkontext bei der neuronalen Übersetzung vollständig erhalten bleiben.
 *
 * @return array<int, string>
 */
function splitTextIntoTranslationChunks(string $text, int $maxChunkChars = 1600): array
{
    $text = str_replace(["\r\n", "\r"], "\n", trim($text));
    if ($text === '') {
        return [];
    }
    if (mb_strlen($text, 'UTF-8') <= $maxChunkChars) {
        return [$text];
    }

    $paragraphs = explode("\n", $text);
    $chunks = [];
    $current = '';

    foreach ($paragraphs as $para) {
        $candidate = $current === '' ? $para : ($current . "\n" . $para);
        if (mb_strlen($candidate, 'UTF-8') <= $maxChunkChars) {
            $current = $candidate;
            continue;
        }

        if ($current !== '') {
            $chunks[] = $current;
            $current = '';
        }

        if (mb_strlen($para, 'UTF-8') <= $maxChunkChars) {
            $current = $para;
            continue;
        }

        // Sehr langer einzelner Absatz -> an Satzgrenzen (. ! ? ;) teilen
        $sentences = preg_split('/(?<=[.!?;])\s+/u', $para, -1, PREG_SPLIT_NO_EMPTY) ?: [$para];
        foreach ($sentences as $sent) {
            $sentCand = $current === '' ? $sent : ($current . ' ' . $sent);
            if (mb_strlen($sentCand, 'UTF-8') <= $maxChunkChars) {
                $current = $sentCand;
            } else {
                if ($current !== '') {
                    $chunks[] = $current;
                }
                if (mb_strlen($sent, 'UTF-8') > $maxChunkChars) {
                    // Fallback falls ein einzelner Satz ohne Punkt länger als $maxChunkChars ist
                    $len = mb_strlen($sent, 'UTF-8');
                    for ($offset = 0; $offset < $len; $offset += $maxChunkChars) {
                        $chunks[] = mb_substr($sent, $offset, $maxChunkChars, 'UTF-8');
                    }
                    $current = '';
                } else {
                    $current = $sent;
                }
            }
        }
    }

    if ($current !== '') {
        $chunks[] = $current;
    }

    return $chunks;
}

/**
 * Führt einen schnellen HTTPS-Request (per cURL oder PHP-Stream-Fallback) aus.
 *
 * @param array<int, string> $headers
 * @return array{ok: bool, status: int, body: string}
 */
function performTranslationHttpRequest(string $url, ?string $postBody = null, array $headers = [], int $timeoutSec = 15): array
{
    $ua = 'GoogleTranslate/6.29.59279 (Linux; U; Android 11; Pixel 5)';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch !== false) {
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_TIMEOUT        => $timeoutSec,
                CURLOPT_CONNECTTIMEOUT => 6,
                CURLOPT_USERAGENT      => $ua,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            if ($postBody !== null) {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $postBody);
            }
            if (!empty($headers)) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            }
            $body   = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (is_string($body) && $status >= 200 && $status < 300) {
                return ['ok' => true, 'status' => $status, 'body' => $body];
            }
            return ['ok' => false, 'status' => $status, 'body' => is_string($body) ? $body : ''];
        }
    }

    $httpOpts = [
        'method'        => $postBody !== null ? 'POST' : 'GET',
        'timeout'       => $timeoutSec,
        'ignore_errors' => true,
        'header'        => implode("\r\n", array_merge(['User-Agent: ' . $ua], $headers)),
    ];
    if ($postBody !== null) {
        $httpOpts['content'] = $postBody;
    }
    $ctx  = stream_context_create(['http' => $httpOpts, 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $body = @file_get_contents($url, false, $ctx);
    return [
        'ok'     => is_string($body) && $body !== '',
        'status' => is_string($body) && $body !== '' ? 200 : 0,
        'body'   => is_string($body) ? $body : '',
    ];
}

/**
 * Übersetzt Text über die DeepL API (Free oder Pro), sofern ein API-Key hinterlegt ist.
 *
 * @return array{ok: bool, translated: string, detected_lang: string, engine: string, error?: string}
 */
function translateViaDeepL(string $text, string $sourceLang, string $targetLang, string $apiKey, string $formality = 'default'): array
{
    $langs = getTranslatorLanguages();
    if (!isset($langs[$targetLang])) {
        return ['ok' => false, 'translated' => '', 'detected_lang' => '', 'engine' => 'deepl', 'error' => 'Zielsprache wird von DeepL nicht unterstützt.'];
    }

    $isFreeKey = str_ends_with(trim($apiKey), ':fx');
    $endpoint  = $isFreeKey
        ? 'https://api-free.deepl.com/v2/translate'
        : 'https://api.deepl.com/v2/translate';

    $chunks = splitTextIntoTranslationChunks($text, 4000);
    $translatedParts = [];
    $detected = $sourceLang !== 'auto' ? $sourceLang : '';

    // Sprachen, bei denen DeepL den Formality-Parameter unterstützt
    $formalitySupportedTargets = ['de', 'fr', 'it', 'es', 'nl', 'pl', 'pt', 'ru', 'ja'];

    foreach ($chunks as $chunk) {
        $params = [
            'text'        => $chunk,
            'target_lang' => $langs[$targetLang]['deepl_target'],
        ];
        if ($sourceLang !== 'auto' && isset($langs[$sourceLang])) {
            $params['source_lang'] = $langs[$sourceLang]['deepl_source'];
        }
        if (in_array($formality, ['more', 'less'], true) && in_array($targetLang, $formalitySupportedTargets, true)) {
            $params['formality'] = $formality === 'more' ? 'prefer_more' : 'prefer_less';
        }

        $res = performTranslationHttpRequest(
            $endpoint,
            http_build_query($params),
            [
                'Authorization: DeepL-Auth-Key ' . trim($apiKey),
                'Content-Type: application/x-www-form-urlencoded',
            ],
            18
        );

        if (!$res['ok']) {
            return [
                'ok'            => false,
                'translated'    => '',
                'detected_lang' => $detected,
                'engine'        => 'deepl',
                'error'         => 'DeepL API-Antwort (HTTP ' . $res['status'] . ').',
            ];
        }

        $json = json_decode($res['body'], true);
        if (!is_array($json) || empty($json['translations'][0]['text'])) {
            return [
                'ok'            => false,
                'translated'    => '',
                'detected_lang' => $detected,
                'engine'        => 'deepl',
                'error'         => 'Ungültige Antwort von DeepL.',
            ];
        }

        $translatedParts[] = (string)$json['translations'][0]['text'];
        if ($detected === '' && !empty($json['translations'][0]['detected_source_language'])) {
            $detCode = strtolower((string)$json['translations'][0]['detected_source_language']);
            if (isset($langs[$detCode])) {
                $detected = $detCode;
            }
        }
    }

    return [
        'ok'            => true,
        'translated'    => implode("\n", $translatedParts),
        'detected_lang' => $detected !== '' ? $detected : ($sourceLang !== 'auto' ? $sourceLang : 'de'),
        'engine'        => 'DeepL Neural (' . ($isFreeKey ? 'Free API' : 'Pro API') . ')',
    ];
}

/**
 * Übersetzt Text über die kostenlose Neuronale Standard-Engine (Google NMT gtx + MyMemory NMT Fallback).
 * Funktioniert ohne API-Key sowohl auf VPS (`runready.cloud`) als auch auf Shared-Webhosting (STRATO).
 *
 * @return array{ok: bool, translated: string, detected_lang: string, engine: string, error?: string}
 */
function translateViaStandardNmt(string $text, string $sourceLang, string $targetLang): array
{
    $chunks = splitTextIntoTranslationChunks($text, 1800);
    $translatedParts = [];
    $detected = $sourceLang !== 'auto' ? $sourceLang : '';
    $usedEngine = 'Neuronale Direkt-Übersetzung (NMT)';

    foreach ($chunks as $chunk) {
        $sl = $sourceLang === 'auto' ? 'auto' : $sourceLang;
        $chunkTranslated = '';

        // 1. Primärer Neuronaler Endpunkt (translate.google.de client=at per POST)
        $primaryUrl = 'https://translate.google.de/translate_a/single?client=at&dt=t&dj=1&sl='
            . rawurlencode($sl)
            . '&tl=' . rawurlencode($targetLang);
        $res = performTranslationHttpRequest(
            $primaryUrl,
            http_build_query(['q' => $chunk]),
            ['Content-Type: application/x-www-form-urlencoded;charset=UTF-8'],
            12
        );

        if ($res['ok'] && $res['body'] !== '') {
            $data = json_decode($res['body'], true);
            if (is_array($data)) {
                if (isset($data['sentences']) && is_array($data['sentences'])) {
                    foreach ($data['sentences'] as $sentObj) {
                        if (is_array($sentObj) && isset($sentObj['trans']) && is_string($sentObj['trans'])) {
                            $chunkTranslated .= $sentObj['trans'];
                        }
                    }
                    if ($detected === '' && !empty($data['src']) && is_string($data['src'])) {
                        $detected = strtolower(substr($data['src'], 0, 2));
                    }
                } elseif (isset($data[0]) && is_array($data[0])) {
                    foreach ($data[0] as $segment) {
                        if (is_array($segment) && isset($segment[0]) && is_string($segment[0])) {
                            $chunkTranslated .= $segment[0];
                        }
                    }
                    if ($detected === '' && isset($data[2]) && is_string($data[2]) && $data[2] !== '') {
                        $detected = strtolower(substr($data[2], 0, 2));
                    }
                }
            }
        }

        // 2. Fallback auf MyMemory NMT falls der primäre Endpunkt gedrosselt oder nicht erreichbar sein sollte
        if (trim($chunkTranslated) === '') {
            $mmSource = ($sourceLang === 'auto' || $sourceLang === '') ? ($targetLang === 'de' ? 'en' : 'de') : $sourceLang;
            $mmUrl = 'https://api.mymemory.translated.net/get?q='
                . rawurlencode(mb_substr($chunk, 0, 500, 'UTF-8'))
                . '&langpair=' . rawurlencode($mmSource . '|' . $targetLang);
            $mmRes = performTranslationHttpRequest($mmUrl, null, [], 10);
            if ($mmRes['ok']) {
                $mmJson = json_decode($mmRes['body'], true);
                if (is_array($mmJson) && !empty($mmJson['responseData']['translatedText'])) {
                    $chunkTranslated = html_entity_decode((string)$mmJson['responseData']['translatedText'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $usedEngine = 'MyMemory Neural NMT';
                }
            }
        }

        if (trim($chunkTranslated) === '') {
            return [
                'ok'            => false,
                'translated'    => '',
                'detected_lang' => $detected,
                'engine'        => $usedEngine,
                'error'         => 'Der Übersetzungsdienst ist momentan nicht erreichbar.',
            ];
        }

        $translatedParts[] = $chunkTranslated;
    }

    return [
        'ok'            => true,
        'translated'    => implode("\n", $translatedParts),
        'detected_lang' => $detected !== '' ? $detected : ($sourceLang !== 'auto' ? $sourceLang : 'de'),
        'engine'        => $usedEngine,
    ];
}

/**
 * Extrahiert lesbaren Dokumenttext mit erhaltenen Zeilenumbrüchen für die Dokument-Übersetzung.
 */
function extractDocumentTextForTranslation(PDO $pdo, array $fileRow, int $maxChars = 25000): string
{
    $fileId   = (int)$fileRow['id'];
    $origName = (string)$fileRow['original_name'];
    $physPath = UPLOAD_DIR . basename((string)$fileRow['filename']);
    $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

    if (!is_file($physPath) || !is_readable($physPath)) {
        return '';
    }
    if ((int)($fileRow['is_encrypted'] ?? 0) === 1 || (function_exists('isVaultEncryptedFile') && isVaultEncryptedFile($physPath))) {
        return '';
    }

    $text = '';

    // 1. Bei Word (.docx) oder Textdateien (.txt, .md, .csv, .srt) Absätze sauber mit Zeilenumbrüchen erhalten
    if (in_array($ext, ['txt', 'md', 'csv', 'srt', 'vtt'], true)) {
        $raw = @file_get_contents($physPath, false, null, 0, $maxChars * 3);
        if (is_string($raw) && $raw !== '') {
            $text = @mb_convert_encoding($raw, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252') ?: $raw;
        }
    } elseif ($ext === 'docx' && class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if (@$zip->open($physPath) === true) {
            $xml = $zip->getFromName('word/document.xml');
            $zip->close();
            if (is_string($xml) && $xml !== '') {
                $xml = str_replace(['</w:p>', '<w:br/>', '</w:tr>'], "\n", $xml);
                $xml = str_replace(['<w:tab/>', '</w:tc>'], "\t", $xml);
                $text = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }
    } elseif ($ext === 'pdf' && probePdfToTextAvailable()) {
        $bin = '/usr/bin/pdftotext';
        foreach (['/usr/bin/pdftotext', '/usr/local/bin/pdftotext', '/bin/pdftotext'] as $cand) {
            if (@is_executable($cand)) {
                $bin = $cand;
                break;
            }
        }
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open([$bin, '-q', '-nopgbrk', '-enc', 'UTF-8', $physPath, '-'], $descriptors, $pipes);
        if (is_resource($proc)) {
            @fclose($pipes[0]);
            $stdout = @stream_get_contents($pipes[1], $maxChars * 4);
            @fclose($pipes[1]);
            @fclose($pipes[2]);
            @proc_close($proc);
            if (is_string($stdout) && trim($stdout) !== '') {
                $text = $stdout;
            }
        }
    }

    // 2. Falls noch leer (z. B. gescanntes PDF wie „Herberts Tod.pdf“, das im Volltextindex liegt):
    // Zuerst im bereits vorhandenen Volltext-Suchindex `file_search_index` nachschlagen!
    if (trim($text) === '') {
        try {
            $stmtIdx = $pdo->prepare('SELECT `content_text` FROM `file_search_index` WHERE `file_id` = :fid LIMIT 1');
            $stmtIdx->execute([':fid' => $fileId]);
            $idxText = $stmtIdx->fetchColumn();
            if (is_string($idxText) && trim($idxText) !== '') {
                $text = $idxText;
            }
        } catch (Throwable) {
        }
    }

    // 3. Fallback: Allgemeine Extraktionsfunktion (inkl. Live-OCR für neue Scan-PDFs)
    if (trim($text) === '') {
        $text = extractSearchableDocumentText($physPath, $origName, $maxChars);
    }

    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = (string)preg_replace("/\n{3,}/u", "\n\n", $text);
    $text = trim($text);

    if (mb_strlen($text, 'UTF-8') > $maxChars) {
        $text = mb_substr($text, 0, $maxChars, 'UTF-8');
    }

    return $text;
}

// ============================================================================
// AJAX-ENDPUNKTE (Übersetzen, Cloud-Datei auslesen, Übersetzung in Cloud speichern)
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json; charset=UTF-8');

    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Ungültiges Sicherheits-Token (CSRF). Bitte Seite neu laden.']);
        exit;
    }

    $ajaxAction = (string)$_POST['ajax_action'];
    $langs      = getTranslatorLanguages();

    // 1. Text übersetzen
    if ($ajaxAction === 'translate_text') {
        $sourceText = trim((string)($_POST['text'] ?? ''));
        $sourceLang = strtolower(trim((string)($_POST['source_lang'] ?? 'auto')));
        $targetLang = strtolower(trim((string)($_POST['target_lang'] ?? 'en')));
        $formality  = strtolower(trim((string)($_POST['formality'] ?? 'default')));

        if ($sourceText === '') {
            echo json_encode(['ok' => false, 'error' => 'Bitte geben Sie einen Text zum Übersetzen ein.']);
            exit;
        }
        if (mb_strlen($sourceText, 'UTF-8') > 30000) {
            $sourceText = mb_substr($sourceText, 0, 30000, 'UTF-8');
        }
        if ($sourceLang !== 'auto' && !isset($langs[$sourceLang])) {
            $sourceLang = 'auto';
        }
        if (!isset($langs[$targetLang])) {
            $targetLang = 'en';
        }

        session_write_close();

        $deeplKey = trim((string)($appSettings['translator_deepl_api_key'] ?? ''));
        $result   = null;

        if ($deeplKey !== '') {
            $result = translateViaDeepL($sourceText, $sourceLang, $targetLang, $deeplKey, $formality);
        }

        // Falls kein DeepL-Key gesetzt ist oder DeepL fehlschlägt -> Standard NMT Engine
        if ($result === null || !$result['ok']) {
            $nmtRes = translateViaStandardNmt($sourceText, $sourceLang, $targetLang);
            if ($nmtRes['ok']) {
                $result = $nmtRes;
            } elseif ($result === null) {
                $result = $nmtRes;
            }
        }

        if (!$result['ok']) {
            echo json_encode([
                'ok'    => false,
                'error' => $result['error'] ?? 'Übersetzung fehlgeschlagen.',
            ]);
            exit;
        }

        $detCode = strtolower((string)($result['detected_lang'] ?? ''));
        $detName = isset($langs[$detCode]) ? $langs[$detCode]['name'] : strtoupper($detCode);

        echo json_encode([
            'ok'                 => true,
            'translated_text'    => $result['translated'],
            'detected_lang'      => $detCode,
            'detected_lang_name' => $detName,
            'engine'             => $result['engine'],
            'char_count'         => mb_strlen($result['translated'], 'UTF-8'),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 2. Cloud-Datei für die Übersetzung auslesen
    if ($ajaxAction === 'load_cloud_file') {
        $fileId = (int)($_POST['file_id'] ?? 0);
        if ($fileId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Ungültige Datei-ID.']);
            exit;
        }

        $accGfs = getAccessibleGroupFolders($pdo, $userId, $isAdmin);
        $gfIds  = array_map(static fn(array $g): int => (int)$g['id'], $accGfs);
        $gfClause = !empty($gfIds)
            ? ('OR `group_folder_id` IN (' . implode(',', array_map('intval', $gfIds)) . ')')
            : '';

        $stmt = $pdo->prepare(
            "SELECT `id`, `user_id`, `group_folder_id`, `filename`, `original_name`, `folder_path`, `is_encrypted`, `deleted_at`
             FROM `files`
             WHERE `id` = :fid AND `deleted_at` IS NULL AND (`user_id` = :uid {$gfClause})
             LIMIT 1"
        );
        $stmt->execute([':fid' => $fileId, ':uid' => $userId]);
        $fileRow = $stmt->fetch();

        if (!$fileRow) {
            echo json_encode(['ok' => false, 'error' => 'Datei nicht gefunden oder keine Berechtigung.']);
            exit;
        }
        if ((int)($fileRow['is_encrypted'] ?? 0) === 1) {
            echo json_encode(['ok' => false, 'error' => 'Tresor-verschlüsselte Dateien (🔒) können aus Datenschutzgründen nicht direkt ausgelesen werden.']);
            exit;
        }

        session_write_close();
        $extracted = extractDocumentTextForTranslation($pdo, $fileRow, 25000);

        if ($extracted === '') {
            echo json_encode([
                'ok'    => false,
                'error' => 'Aus dieser Datei konnte kein lesbarer Text extrahiert werden.',
            ]);
            exit;
        }

        echo json_encode([
            'ok'          => true,
            'file_id'     => (int)$fileRow['id'],
            'file_name'   => (string)$fileRow['original_name'],
            'folder_path' => (string)($fileRow['folder_path'] ?? ''),
            'text'        => $extracted,
            'char_count'  => mb_strlen($extracted, 'UTF-8'),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 3. Übersetzten Text als neue Datei (.docx, .txt, .md) in der Cloud speichern
    if ($ajaxAction === 'save_translated_file') {
        $content      = trim((string)($_POST['content'] ?? ''));
        $rawFilename  = trim((string)($_POST['filename'] ?? 'Uebersetzung'));
        $format       = strtolower(trim((string)($_POST['format'] ?? 'docx')));
        $targetFolder = normalizeFolderPath((string)($_POST['folder_path'] ?? ''));

        if ($content === '') {
            echo json_encode(['ok' => false, 'error' => 'Es ist kein übersetzter Text zum Speichern vorhanden.']);
            exit;
        }
        if (!in_array($format, ['docx', 'txt', 'md'], true)) {
            $format = 'docx';
        }

        // Dateiname bereinigen
        $baseName = pathinfo($rawFilename, PATHINFO_FILENAME);
        $baseName = (string)preg_replace('/[^\p{L}\p{N}_\-\s\.()]+/u', '_', $baseName);
        $baseName = trim($baseName, " ._-");
        if ($baseName === '') {
            $baseName = 'Uebersetzung_' . date('Y-m-d_H-i');
        }
        $finalOriginalName = $baseName . '.' . $format;

        if (!is_dir(UPLOAD_DIR)) {
            @mkdir(UPLOAD_DIR, 0750, true);
        }

        $storedFilename = bin2hex(random_bytes(16)) . '.' . $format;
        $destPath       = UPLOAD_DIR . $storedFilename;

        $writeOk = false;
        if ($format === 'docx') {
            $paragraphs = explode("\n", str_replace(["\r\n", "\r"], "\n", $content));
            $htmlLines  = [];
            foreach ($paragraphs as $p) {
                $pTrim = trim($p);
                if ($pTrim === '') {
                    $htmlLines[] = '<p><br></p>';
                } else {
                    $htmlLines[] = '<p>' . htmlspecialchars($pTrim, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
                }
            }
            $writeOk = writeHtmlAsDocx(implode("\n", $htmlLines), $destPath);
        } else {
            $writeOk = (@file_put_contents($destPath, $content) !== false);
        }

        if (!$writeOk || !is_file($destPath)) {
            echo json_encode(['ok' => false, 'error' => 'Datei konnte nicht auf dem Server erstellt werden.']);
            exit;
        }

        $fileSize = (int)@filesize($destPath);
        $stats    = getUserStorageStats($pdo, $userId);
        if ($fileSize > $stats['free']) {
            @unlink($destPath);
            echo json_encode(['ok' => false, 'error' => 'Ihr freier Cloud-Speicherplatz reicht für diese Datei nicht aus.']);
            exit;
        }

        $mimeType = getOfficeMimeType($finalOriginalName);
        $sha256   = @hash_file('sha256', $destPath) ?: null;

        if ($targetFolder !== '') {
            ensureFolderHierarchyExists($pdo, $userId, $targetFolder);
        }

        $stmtIns = $pdo->prepare(
            'INSERT INTO `files` (`user_id`, `filename`, `original_name`, `folder_path`, `file_size`, `mime_type`, `file_hash`)
             VALUES (:uid, :fn, :orig, :fp, :sz, :mime, :hash)'
        );
        $stmtIns->execute([
            ':uid'  => $userId,
            ':fn'   => $storedFilename,
            ':orig' => $finalOriginalName,
            ':fp'   => $targetFolder,
            ':sz'   => $fileSize,
            ':mime' => $mimeType,
            ':hash' => $sha256,
        ]);
        $newFileId = (int)$pdo->lastInsertId();

        if ($newFileId > 0) {
            indexFileForFulltextSearch($pdo, $newFileId);
        }

        $openUrl = $format === 'docx' || $format === 'md' || $format === 'txt'
            ? ('office.php?id=' . $newFileId)
            : ('index.php?preview_id=' . $newFileId);
        $folderUrl = 'index.php' . ($targetFolder !== '' ? ('?folder=' . rawurlencode($targetFolder) . '&highlight_id=' . $newFileId) : ('?highlight_id=' . $newFileId));

        echo json_encode([
            'ok'         => true,
            'file_id'    => $newFileId,
            'file_name'  => $finalOriginalName,
            'size_fmt'   => formatBytes($fileSize),
            'open_url'   => $openUrl,
            'folder_url' => $folderUrl,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unbekannte Aktion.']);
    exit;
}

// ============================================================================
// DATEN FÜR DIE OBERFLÄCHE LADEN (Übersetzbare Cloud-Dokumente & Ordnerliste)
// ============================================================================
$translatorLangs = getTranslatorLanguages();
$defaultTarget   = strtolower(trim((string)($appSettings['translator_default_target'] ?? 'en')));
if (!isset($translatorLangs[$defaultTarget])) {
    $defaultTarget = 'en';
}
$hasDeepLKey = trim((string)($appSettings['translator_deepl_api_key'] ?? '')) !== '';

// Übersetzbare Dokumente des Benutzers (und seiner Gruppenordner) laden
$accGfs = getAccessibleGroupFolders($pdo, $userId, $isAdmin);
$gfIds  = array_map(static fn(array $g): int => (int)$g['id'], $accGfs);
$gfClause = !empty($gfIds)
    ? ('OR f.`group_folder_id` IN (' . implode(',', array_map('intval', $gfIds)) . ')')
    : '';

$translatableFiles = [];
try {
    $stmtFiles = $pdo->prepare(
        "SELECT f.`id`, f.`original_name`, f.`folder_path`, f.`file_size`, f.`upload_date`, f.`group_folder_id`
         FROM `files` f
         WHERE f.`deleted_at` IS NULL
           AND f.`is_encrypted` = 0
           AND (f.`user_id` = :uid {$gfClause})
         ORDER BY f.`upload_date` DESC
         LIMIT 300"
    );
    $stmtFiles->execute([':uid' => $userId]);
    $allowedExts = ['pdf', 'docx', 'odt', 'txt', 'md', 'csv', 'xlsx', 'pptx', 'html', 'htm', 'srt', 'vtt'];
    foreach ($stmtFiles->fetchAll() as $row) {
        $ext = strtolower(pathinfo((string)$row['original_name'], PATHINFO_EXTENSION));
        if (in_array($ext, $allowedExts, true)) {
            $translatableFiles[] = $row;
        }
    }
} catch (Throwable) {
}

$userFolders = getUserFolders($pdo, $userId);
$preselectedFileId = isset($_GET['file_id']) ? max(0, (int)$_GET['file_id']) : 0;

$pageTitle = 'Übersetzungs-Studio';
require_once __DIR__ . '/includes/header.php';
?>

<div class="space-y-5 mb-10">
    <!-- Kopfbereich -->
    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-4 sm:p-5 shadow-xs flex flex-col md:flex-row md:items-center justify-between gap-3">
        <div class="flex items-start sm:items-center gap-3">
            <div class="w-10 h-10 sm:w-11 sm:h-11 rounded-2xl bg-gradient-to-br from-sky-500 to-indigo-600 text-white flex items-center justify-center text-xl shadow-xs shrink-0">
                🌐
            </div>
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">
                        Übersetzungs-Studio &amp; Dokument-Übersetzer
                    </h1>
                    <span id="translatorEngineBadge" class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-bold <?= $hasDeepLKey ? 'bg-indigo-100 dark:bg-indigo-950/80 text-indigo-700 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800' : 'bg-emerald-100 dark:bg-emerald-950/80 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800' ?>">
                        <?= $hasDeepLKey ? '⚡ DeepL Neural API aktiv' : '🧠 Neuronale Satz- & Kontext-Engine aktiv' ?>
                    </span>
                </div>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                    Ganze Texte, Briefe, PDFs (inkl. Scan-OCR) und Word-Dokumente im vollen Satzkontext in 18 Sprachen übersetzen und direkt in der Cloud speichern.
                </p>
            </div>
        </div>

        <!-- Cloud-Dokument direkt auswählen -->
        <div class="flex flex-wrap items-center gap-2 w-full md:w-auto">
            <div class="relative flex-1 md:w-72">
                <select
                    id="cloudDocSelect"
                    onchange="if (this.value) loadCloudDocumentForTranslation(this.value)"
                    class="w-full pl-3 pr-8 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs font-semibold text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-brand-500 cursor-pointer"
                >
                    <option value="">📂 Cloud-Dokument zum Übersetzen laden…</option>
                    <?php foreach ($translatableFiles as $tf): ?>
                        <?php
                        $tfId   = (int)$tf['id'];
                        $tfName = (string)$tf['original_name'];
                        $tfPath = trim((string)($tf['folder_path'] ?? ''));
                        $tfLoc  = $tfPath !== '' ? ('[' . $tfPath . '] ') : '';
                        ?>
                        <option value="<?= $tfId ?>" <?= $preselectedFileId === $tfId ? 'selected' : '' ?>>
                            📄 <?= e($tfLoc . $tfName . ' (' . formatBytes((int)$tf['file_size']) . ')') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <!-- Haupt-Übersetzungs-Arbeitsbereich (2 Spalten wie DeepL) -->
    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xs overflow-hidden">
        <!-- Obere Sprach- & Steuerungsleiste -->
        <div class="p-3 sm:px-5 sm:py-3.5 bg-slate-50/80 dark:bg-slate-800/60 border-b border-slate-200 dark:border-slate-800 grid grid-cols-1 lg:grid-cols-12 gap-2.5 items-center">
            <!-- Ausgangssprache (Spalte 1-5) -->
            <div class="lg:col-span-5 flex flex-wrap items-center gap-1.5">
                <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500 mr-1">Von:</span>
                <div class="relative flex-1 min-w-[180px]">
                    <select
                        id="sourceLangSelect"
                        onchange="onSourceLangChange()"
                        class="w-full px-3 py-1.5 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 text-xs font-bold text-slate-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500 cursor-pointer"
                    >
                        <option value="auto" selected>✨ Sprache automatisch erkennen</option>
                        <?php foreach ($translatorLangs as $lCode => $lMeta): ?>
                            <option value="<?= e($lCode) ?>"><?= e($lMeta['name']) ?> (<?= strtoupper($lCode) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <span id="detectedLangBadge" class="hidden px-2 py-1 rounded-lg bg-sky-100 dark:bg-sky-950/80 text-sky-800 dark:text-sky-300 border border-sky-200 dark:border-sky-800 text-[11px] font-semibold"></span>
            </div>

            <!-- Mitte: Sprachen tauschen (Spalte 6) -->
            <div class="lg:col-span-2 flex items-center justify-center gap-1.5">
                <button
                    type="button"
                    onclick="swapTranslatorLanguages()"
                    title="Ausgangs- und Zielsprache sowie Texte tauschen (⇄)"
                    class="inline-flex items-center justify-center gap-1 px-3 py-1.5 rounded-xl bg-white dark:bg-slate-900 hover:bg-brand-50 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700 text-xs font-bold shadow-2xs transition-all cursor-pointer"
                >
                    <span>⇄</span>
                    <span class="lg:hidden">Tauschen</span>
                </button>
            </div>

            <!-- Zielsprache & Förmlichkeit (Spalte 7-12) -->
            <div class="lg:col-span-5 flex flex-wrap items-center gap-1.5 justify-between lg:justify-end">
                <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500 mr-1">Nach:</span>
                <div class="flex items-center gap-1.5 flex-1 min-w-[180px]">
                    <span id="targetLangFlagBox" class="inline-flex items-center justify-center shrink-0">
                        <?= renderTranslatorFlagSvg($defaultTarget, 'w-5 h-3.5 rounded-[2px] shadow-2xs') ?>
                    </span>
                    <select
                        id="targetLangSelect"
                        onchange="onTargetLangChange()"
                        class="w-full px-3 py-1.5 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 text-xs font-bold text-slate-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500 cursor-pointer"
                    >
                        <?php foreach ($translatorLangs as $lCode => $lMeta): ?>
                            <option value="<?= e($lCode) ?>" <?= $lCode === $defaultTarget ? 'selected' : '' ?>>
                                <?= e($lMeta['name']) ?> (<?= strtoupper($lCode) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if ($hasDeepLKey): ?>
                    <select
                        id="formalitySelect"
                        onchange="triggerTranslationNow()"
                        title="Tonfall / Anredeform für DeepL auswählen"
                        class="px-2.5 py-1.5 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 text-[11px] font-semibold text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-500 cursor-pointer"
                    >
                        <option value="default">Tonfall: Standard</option>
                        <option value="more">👔 Förmlich („Sie“)</option>
                        <option value="less">👋 Locker („Du“)</option>
                    </select>
                <?php else: ?>
                    <input type="hidden" id="formalitySelect" value="default">
                <?php endif; ?>
            </div>
        </div>

        <!-- Schnellwahl-Flaggenleiste für die häufigsten Zielsprachen -->
        <div class="px-3 sm:px-5 py-2 bg-slate-50/40 dark:bg-slate-900/40 border-b border-slate-200/70 dark:border-slate-800/80 flex flex-wrap items-center justify-between gap-2">
            <div class="flex flex-wrap items-center gap-1">
                <span class="text-[11px] font-semibold text-slate-400 dark:text-slate-500 mr-1">Schnellziel:</span>
                <?php foreach (['de', 'en', 'fr', 'es', 'it', 'nl', 'pl', 'pt', 'tr', 'uk'] as $qCode): ?>
                    <button
                        type="button"
                        data-quick-target="<?= e($qCode) ?>"
                        onclick="selectQuickTargetLang('<?= e($qCode) ?>')"
                        class="inline-flex items-center gap-1.5 px-2 py-1 rounded-lg text-[11px] font-semibold border transition-all cursor-pointer <?= $qCode === $defaultTarget ? 'bg-brand-600 text-white border-brand-600 shadow-2xs' : 'bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-300 border-slate-200 dark:border-slate-700 hover:border-brand-400' ?>"
                    >
                        <?= renderTranslatorFlagSvg($qCode, 'w-4 h-3 rounded-[2px] shrink-0') ?>
                        <span><?= strtoupper($qCode) ?></span>
                    </button>
                <?php endforeach; ?>
            </div>

            <label class="inline-flex items-center gap-1.5 text-[11px] font-medium text-slate-500 dark:text-slate-400 cursor-pointer select-none">
                <input type="checkbox" id="autoTranslateToggle" checked class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                <span>⚡ Automatisch beim Tippen übersetzen</span>
            </label>
        </div>

        <!-- Zweispaltige Textfelder -->
        <div class="grid grid-cols-1 lg:grid-cols-2 divide-y lg:divide-y-0 lg:divide-x divide-slate-200 dark:divide-slate-800">
            <!-- LINKS: Ausgangstext -->
            <div class="flex flex-col">
                <div id="loadedDocBanner" class="hidden px-4 py-2 bg-amber-50/80 dark:bg-amber-950/40 border-b border-amber-200/80 dark:border-amber-800/70 flex items-center justify-between gap-2 text-xs">
                    <span id="loadedDocName" class="font-bold text-amber-900 dark:text-amber-200 truncate">📄 Dokument geladen</span>
                    <button type="button" onclick="clearSourceText()" class="text-[11px] font-semibold text-amber-700 dark:text-amber-300 hover:underline cursor-pointer shrink-0">
                        ✕ Schließen
                    </button>
                </div>

                <div class="relative flex-1">
                    <textarea
                        id="sourceTextInput"
                        rows="12"
                        placeholder="Text hier eingeben, per Strg+V einfügen oder oben ein Cloud-Dokument (PDF, Word, Text) auswählen…"
                        oninput="onSourceTextInput()"
                        class="w-full h-full min-h-[270px] p-4 sm:p-5 bg-transparent text-slate-900 dark:text-slate-100 text-sm sm:text-base leading-relaxed resize-y focus:outline-none"
                    ></textarea>
                </div>

                <!-- Fußleiste Links -->
                <div class="px-4 py-2.5 bg-slate-50/50 dark:bg-slate-800/30 border-t border-slate-200/70 dark:border-slate-800 flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-1.5">
                        <button
                            type="button"
                            onclick="speakTranslatorText('source')"
                            title="Ausgangstext vorlesen"
                            class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-white dark:bg-slate-800 hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700 text-xs font-semibold transition-colors cursor-pointer"
                        >
                            <span>🔊</span>
                            <span class="hidden sm:inline">Vorlesen</span>
                        </button>
                        <button
                            type="button"
                            onclick="pasteFromClipboard()"
                            title="Text aus der Zwischenablage einfügen"
                            class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-white dark:bg-slate-800 hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700 text-xs font-semibold transition-colors cursor-pointer"
                        >
                            <span>📋</span>
                            <span class="hidden sm:inline">Einfügen</span>
                        </button>
                        <button
                            type="button"
                            onclick="clearSourceText()"
                            title="Eingabefeld leeren"
                            class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-white dark:bg-slate-800 hover:bg-red-50 dark:hover:bg-red-950/50 text-slate-600 dark:text-slate-300 hover:text-red-600 border border-slate-200 dark:border-slate-700 text-xs font-semibold transition-colors cursor-pointer"
                        >
                            <span>🗑️</span>
                            <span class="hidden sm:inline">Leeren</span>
                        </button>
                    </div>

                    <div class="flex items-center gap-3">
                        <span id="sourceStatsLabel" class="text-[11px] font-mono text-slate-400 dark:text-slate-500">0 Zeichen · 0 Wörter</span>
                        <button
                            type="button"
                            id="btnTranslateNow"
                            onclick="triggerTranslationNow()"
                            class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs font-bold shadow-2xs transition-colors cursor-pointer"
                        >
                            <span>🌐 Übersetzen</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- RECHTS: Übersetzung -->
            <div class="flex flex-col bg-slate-50/25 dark:bg-slate-900/50">
                <div class="relative flex-1">
                    <textarea
                        id="targetTextOutput"
                        rows="12"
                        placeholder="Die neuronale Übersetzung erscheint hier (und kann vor dem Speichern bei Bedarf frei nachbearbeitet werden)…"
                        class="w-full h-full min-h-[270px] p-4 sm:p-5 bg-transparent text-slate-900 dark:text-slate-100 text-sm sm:text-base leading-relaxed resize-y focus:outline-none"
                    ></textarea>

                    <!-- Lade-Indikator während der Übersetzung -->
                    <div id="translationLoadingOverlay" class="hidden absolute inset-0 bg-white/75 dark:bg-slate-900/75 backdrop-blur-[1px] flex items-center justify-center">
                        <div class="inline-flex items-center gap-2.5 px-4 py-2.5 rounded-2xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 shadow-md text-xs font-bold text-brand-600 dark:text-brand-400">
                            <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                            </svg>
                            <span id="translationLoadingText">Übersetze im Satzkontext…</span>
                        </div>
                    </div>
                </div>

                <!-- Fußleiste Rechts -->
                <div class="px-4 py-2.5 bg-slate-50/50 dark:bg-slate-800/30 border-t border-slate-200/70 dark:border-slate-800 flex flex-wrap items-center justify-between gap-2">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <button
                            type="button"
                            onclick="copyTranslationToClipboard()"
                            id="btnCopyTranslation"
                            title="Übersetzung in die Zwischenablage kopieren"
                            class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-white dark:bg-slate-800 hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700 text-xs font-semibold transition-colors cursor-pointer"
                        >
                            <span>📋</span>
                            <span>Kopieren</span>
                        </button>
                        <button
                            type="button"
                            onclick="speakTranslatorText('target')"
                            title="Übersetzung in der Zielsprache vorlesen"
                            class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-white dark:bg-slate-800 hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700 text-xs font-semibold transition-colors cursor-pointer"
                        >
                            <span>🔊</span>
                            <span class="hidden sm:inline">Vorlesen</span>
                        </button>
                        <button
                            type="button"
                            onclick="toggleSaveCloudBar()"
                            title="Übersetzung als Word-Dokument (.docx), Markdown (.md) oder Textdatei (.txt) direkt in der Cloud speichern"
                            class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold shadow-2xs transition-colors cursor-pointer"
                        >
                            <span>💾</span>
                            <span>In Cloud speichern</span>
                        </button>
                    </div>

                    <span id="targetStatsLabel" class="text-[11px] font-mono text-slate-400 dark:text-slate-500">0 Zeichen</span>
                </div>
            </div>
        </div>

        <!-- Ausklappbare Speicherleiste: Übersetzung als Cloud-Datei (.docx / .txt / .md) ablegen -->
        <div id="saveTranslationCloudBar" class="hidden px-4 sm:px-5 py-3.5 bg-emerald-50/80 dark:bg-emerald-950/30 border-t border-emerald-200/80 dark:border-emerald-800/70">
            <form onsubmit="return saveTranslationToCloud(event)" class="grid grid-cols-1 md:grid-cols-12 gap-2.5 items-end">
                <div class="md:col-span-4">
                    <label class="block text-[11px] font-bold text-emerald-900 dark:text-emerald-200 mb-1">
                        📄 Dateiname der Übersetzung:
                    </label>
                    <input
                        type="text"
                        id="saveTransFilename"
                        required
                        placeholder="z.B. Übersetzung_EN"
                        class="w-full px-3 py-1.5 rounded-xl bg-white dark:bg-slate-800 border border-emerald-300 dark:border-emerald-700 text-xs sm:text-sm font-medium text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-emerald-500"
                    >
                </div>
                <div class="md:col-span-3">
                    <label class="block text-[11px] font-bold text-emerald-900 dark:text-emerald-200 mb-1">
                        📑 Dateiformat:
                    </label>
                    <select
                        id="saveTransFormat"
                        class="w-full px-3 py-1.5 rounded-xl bg-white dark:bg-slate-800 border border-emerald-300 dark:border-emerald-700 text-xs sm:text-sm font-semibold text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-emerald-500 cursor-pointer"
                    >
                        <option value="docx">📘 Word-Dokument (.docx)</option>
                        <option value="txt">📄 Textdatei (.txt)</option>
                        <option value="md">📝 Markdown-Dokument (.md)</option>
                    </select>
                </div>
                <div class="md:col-span-3">
                    <label class="block text-[11px] font-bold text-emerald-900 dark:text-emerald-200 mb-1">
                        📁 Zielordner in der Cloud:
                    </label>
                    <select
                        id="saveTransFolder"
                        class="w-full px-3 py-1.5 rounded-xl bg-white dark:bg-slate-800 border border-emerald-300 dark:border-emerald-700 text-xs sm:text-sm font-medium text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-emerald-500 cursor-pointer"
                    >
                        <option value="">🏠 Hauptverzeichnis</option>
                        <?php foreach ($userFolders as $uf): ?>
                            <?php $ufP = (string)$uf['folder_path']; ?>
                            <option value="<?= e($ufP) ?>">📁 <?= e($ufP) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="md:col-span-2 flex items-center gap-1.5">
                    <button
                        type="submit"
                        id="btnSubmitSaveCloud"
                        class="flex-1 inline-flex items-center justify-center gap-1 px-3 py-1.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs sm:text-sm font-bold shadow-2xs transition-colors cursor-pointer"
                    >
                        <span>💾 Speichern</span>
                    </button>
                    <button
                        type="button"
                        onclick="toggleSaveCloudBar(false)"
                        class="px-2.5 py-1.5 rounded-xl bg-white/80 dark:bg-slate-800 text-slate-600 dark:text-slate-300 text-xs font-semibold cursor-pointer"
                    >
                        ✕
                    </button>
                </div>
            </form>
            <div id="saveTransResultBox" class="hidden mt-2.5 p-2.5 rounded-xl bg-white dark:bg-slate-900 border border-emerald-300 dark:border-emerald-800 flex flex-wrap items-center justify-between gap-2 text-xs"></div>
        </div>
    </div>

    <!-- Letzte Übersetzungen (Lokaler Schnellverlauf im Browser) -->
    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-4 sm:p-5 shadow-xs">
        <div class="flex items-center justify-between gap-2 mb-3">
            <h2 class="text-xs sm:text-sm font-bold text-slate-800 dark:text-slate-200 flex items-center gap-1.5">
                <span>🕓</span>
                <span>Letzte Übersetzungen (Schnellzugriff)</span>
            </h2>
            <button
                type="button"
                onclick="clearTranslationHistory()"
                class="text-[11px] font-semibold text-slate-400 hover:text-red-600 dark:hover:text-red-400 cursor-pointer transition-colors"
            >
                Verlauf leeren
            </button>
        </div>
        <div id="translationHistoryList" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2.5 text-xs">
            <p class="text-slate-400 italic col-span-full">Noch keine Übersetzungen im aktuellen Browser-Verlauf gespeichert.</p>
        </div>
    </div>
</div>

<script>
const csrfToken = <?= json_encode(getCsrfToken()) ?>;
const translatorLangsMeta = <?= json_encode($translatorLangs, JSON_UNESCAPED_UNICODE) ?>;
const flagSvgMap = <?= json_encode(array_combine(
    array_keys($translatorLangs),
    array_map(static fn(string $c): string => renderTranslatorFlagSvg($c, 'w-5 h-3.5 rounded-[2px] shadow-2xs'), array_keys($translatorLangs))
), JSON_UNESCAPED_UNICODE) ?>;
const historyStorageKey = 'mc_translator_history_u<?= (int)$userId ?>';

let debounceTimer = null;
let currentLoadedFileName = '';
let currentLoadedFolderPath = '';

function updateTextCounters() {
    const srcEl = document.getElementById('sourceTextInput');
    const tgtEl = document.getElementById('targetTextOutput');
    const srcLbl = document.getElementById('sourceStatsLabel');
    const tgtLbl = document.getElementById('targetStatsLabel');

    if (srcEl && srcLbl) {
        const val = srcEl.value;
        const chars = val.length;
        const words = val.trim() === '' ? 0 : val.trim().split(/\s+/).length;
        srcLbl.textContent = `${chars.toLocaleString('de-DE')} Zeichen · ${words.toLocaleString('de-DE')} ${words === 1 ? 'Wort' : 'Wörter'}`;
    }
    if (tgtEl && tgtLbl) {
        const chars = tgtEl.value.length;
        tgtLbl.textContent = `${chars.toLocaleString('de-DE')} Zeichen`;
    }
}

function onSourceTextInput() {
    updateTextCounters();
    const autoToggle = document.getElementById('autoTranslateToggle');
    if (!autoToggle || !autoToggle.checked) return;

    if (debounceTimer) clearTimeout(debounceTimer);
    const val = document.getElementById('sourceTextInput')?.value.trim() || '';
    if (val === '') {
        const tgtEl = document.getElementById('targetTextOutput');
        if (tgtEl) tgtEl.value = '';
        updateTextCounters();
        return;
    }
    // Bei kurzen Texten schneller (550ms), bei langen Texten 950ms warten
    const waitMs = val.length > 800 ? 950 : 550;
    debounceTimer = setTimeout(() => {
        triggerTranslationNow(false);
    }, waitMs);
}

function onSourceLangChange() {
    const val = document.getElementById('sourceTextInput')?.value.trim() || '';
    if (val !== '') triggerTranslationNow(true);
}

function onTargetLangChange() {
    const targetSel = document.getElementById('targetLangSelect');
    const flagBox = document.getElementById('targetLangFlagBox');
    if (!targetSel) return;
    const code = targetSel.value;
    if (flagBox && flagSvgMap[code]) {
        flagBox.innerHTML = flagSvgMap[code];
    }

    document.querySelectorAll('[data-quick-target]').forEach((btn) => {
        const isAct = btn.getAttribute('data-quick-target') === code;
        btn.className = isAct
            ? 'inline-flex items-center gap-1.5 px-2 py-1 rounded-lg text-[11px] font-semibold border transition-all cursor-pointer bg-brand-600 text-white border-brand-600 shadow-2xs'
            : 'inline-flex items-center gap-1.5 px-2 py-1 rounded-lg text-[11px] font-semibold border transition-all cursor-pointer bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-300 border-slate-200 dark:border-slate-700 hover:border-brand-400';
    });

    updateSuggestedSaveFilename();
    const val = document.getElementById('sourceTextInput')?.value.trim() || '';
    if (val !== '') triggerTranslationNow(true);
}

function selectQuickTargetLang(code) {
    const targetSel = document.getElementById('targetLangSelect');
    if (!targetSel) return;
    targetSel.value = code;
    onTargetLangChange();
}

function swapTranslatorLanguages() {
    const srcSel = document.getElementById('sourceLangSelect');
    const tgtSel = document.getElementById('targetLangSelect');
    const srcInp = document.getElementById('sourceTextInput');
    const tgtOut = document.getElementById('targetTextOutput');
    if (!srcSel || !tgtSel || !srcInp || !tgtOut) return;

    const currentSource = srcSel.value === 'auto'
        ? (srcSel.getAttribute('data-last-detected') || 'de')
        : srcSel.value;
    const currentTarget = tgtSel.value;

    if (translatorLangsMeta[currentTarget]) {
        srcSel.value = currentTarget;
    }
    if (translatorLangsMeta[currentSource]) {
        tgtSel.value = currentSource;
    }

    const oldTargetText = tgtOut.value.trim();
    if (oldTargetText !== '') {
        srcInp.value = oldTargetText;
    }
    onTargetLangChange();
}

async function triggerTranslationNow(saveToHistory = true) {
    if (debounceTimer) {
        clearTimeout(debounceTimer);
        debounceTimer = null;
    }

    const srcInp = document.getElementById('sourceTextInput');
    const tgtOut = document.getElementById('targetTextOutput');
    const srcSel = document.getElementById('sourceLangSelect');
    const tgtSel = document.getElementById('targetLangSelect');
    const formSel = document.getElementById('formalitySelect');
    const overlay = document.getElementById('translationLoadingOverlay');
    const detBadge = document.getElementById('detectedLangBadge');
    const engineBadge = document.getElementById('translatorEngineBadge');

    if (!srcInp || !tgtOut || !srcSel || !tgtSel) return;
    const text = srcInp.value.trim();
    if (text === '') {
        tgtOut.value = '';
        updateTextCounters();
        return;
    }

    if (overlay) overlay.classList.remove('hidden');

    try {
        const fd = new FormData();
        fd.append('csrf_token', csrfToken);
        fd.append('ajax_action', 'translate_text');
        fd.append('text', text);
        fd.append('source_lang', srcSel.value);
        fd.append('target_lang', tgtSel.value);
        fd.append('formality', formSel ? formSel.value : 'default');

        const res = await fetch('translate.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        const data = await res.json();

        if (!data || !data.ok) {
            tgtOut.value = '⚠️ ' + (data?.error || 'Fehler bei der Übersetzung.');
            return;
        }

        tgtOut.value = data.translated_text || '';
        updateTextCounters();

        if (data.detected_lang) {
            srcSel.setAttribute('data-last-detected', data.detected_lang);
            if (detBadge && srcSel.value === 'auto') {
                detBadge.textContent = 'Erkannt: ' + (data.detected_lang_name || data.detected_lang.toUpperCase());
                detBadge.classList.remove('hidden');
            } else if (detBadge) {
                detBadge.classList.add('hidden');
            }
        }

        if (engineBadge && data.engine) {
            engineBadge.textContent = '✨ ' + data.engine;
        }

        if (saveToHistory && text.length >= 3) {
            addTranslationToHistory({
                srcLang: data.detected_lang || srcSel.value,
                tgtLang: tgtSel.value,
                srcText: text.substring(0, 260),
                tgtText: (data.translated_text || '').substring(0, 260),
                fullSrc: text.substring(0, 4000),
                fullTgt: (data.translated_text || '').substring(0, 4000),
                ts: new Date().toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' })
            });
        }
    } catch (e) {
        tgtOut.value = '⚠️ Netzwerkfehler bei der Verbindung zum Übersetzungsdienst.';
    } finally {
        if (overlay) overlay.classList.add('hidden');
    }
}

async function loadCloudDocumentForTranslation(fileId) {
    const id = parseInt(fileId, 10);
    if (!id) return;

    const overlay = document.getElementById('translationLoadingOverlay');
    const loadTxt = document.getElementById('translationLoadingText');
    const banner  = document.getElementById('loadedDocBanner');
    const docName = document.getElementById('loadedDocName');
    const srcInp  = document.getElementById('sourceTextInput');

    if (overlay) overlay.classList.remove('hidden');
    if (loadTxt) loadTxt.textContent = 'Extrahiere Dokumenttext aus der Cloud…';

    try {
        const fd = new FormData();
        fd.append('csrf_token', csrfToken);
        fd.append('ajax_action', 'load_cloud_file');
        fd.append('file_id', String(id));

        const res = await fetch('translate.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        const data = await res.json();

        if (!data || !data.ok) {
            alert(data?.error || 'Dokument konnte nicht geladen werden.');
            return;
        }

        currentLoadedFileName = data.file_name || '';
        currentLoadedFolderPath = data.folder_path || '';

        if (srcInp) {
            srcInp.value = data.text || '';
        }
        if (banner && docName) {
            docName.textContent = `📄 Geladen: ${currentLoadedFileName} (${(data.char_count || 0).toLocaleString('de-DE')} Zeichen)`;
            banner.classList.remove('hidden');
        }

        const folderSel = document.getElementById('saveTransFolder');
        if (folderSel) {
            folderSel.value = currentLoadedFolderPath;
        }
        updateSuggestedSaveFilename();
        updateTextCounters();

        if (loadTxt) loadTxt.textContent = 'Übersetze Dokument im Satzkontext…';
        await triggerTranslationNow(true);
    } catch (e) {
        alert('Fehler beim Laden des Cloud-Dokuments.');
    } finally {
        if (loadTxt) loadTxt.textContent = 'Übersetze im Satzkontext…';
        if (overlay) overlay.classList.add('hidden');
    }
}

function updateSuggestedSaveFilename() {
    const fnInput = document.getElementById('saveTransFilename');
    const tgtSel = document.getElementById('targetLangSelect');
    if (!fnInput || !tgtSel) return;

    const langSuffix = (tgtSel.value || 'en').toUpperCase();
    if (currentLoadedFileName !== '') {
        const dotIdx = currentLoadedFileName.lastIndexOf('.');
        const base = dotIdx > 0 ? currentLoadedFileName.substring(0, dotIdx) : currentLoadedFileName;
        fnInput.value = `${base}_${langSuffix}`;
    } else {
        const today = new Date().toISOString().slice(0, 10);
        fnInput.value = `Uebersetzung_${langSuffix}_${today}`;
    }
}

function toggleSaveCloudBar(forceShow) {
    const bar = document.getElementById('saveTranslationCloudBar');
    if (!bar) return;
    const show = typeof forceShow === 'boolean' ? forceShow : bar.classList.contains('hidden');
    bar.classList.toggle('hidden', !show);
    if (show) {
        updateSuggestedSaveFilename();
        setTimeout(() => document.getElementById('saveTransFilename')?.focus(), 40);
    }
}

async function saveTranslationToCloud(ev) {
    if (ev) ev.preventDefault();
    const tgtOut = document.getElementById('targetTextOutput');
    const fnInp  = document.getElementById('saveTransFilename');
    const fmtSel = document.getElementById('saveTransFormat');
    const fldSel = document.getElementById('saveTransFolder');
    const btn    = document.getElementById('btnSubmitSaveCloud');
    const resBox = document.getElementById('saveTransResultBox');

    if (!tgtOut || tgtOut.value.trim() === '') {
        alert('Es ist noch kein übersetzter Text zum Speichern vorhanden.');
        return false;
    }

    if (btn) {
        btn.disabled = true;
        btn.textContent = '⏳ Speichere…';
    }

    try {
        const fd = new FormData();
        fd.append('csrf_token', csrfToken);
        fd.append('ajax_action', 'save_translated_file');
        fd.append('content', tgtOut.value);
        fd.append('filename', fnInp ? fnInp.value : 'Uebersetzung');
        fd.append('format', fmtSel ? fmtSel.value : 'docx');
        fd.append('folder_path', fldSel ? fldSel.value : '');

        const res = await fetch('translate.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        const data = await res.json();

        if (!data || !data.ok) {
            alert(data?.error || 'Speichern fehlgeschlagen.');
            return false;
        }

        if (resBox) {
            resBox.classList.remove('hidden');
            resBox.innerHTML = `
                <span class="font-bold text-emerald-800 dark:text-emerald-300">
                    ✅ Gespeichert als „${escapeHtml(data.file_name)}“ (${escapeHtml(data.size_fmt)})
                </span>
                <div class="flex items-center gap-2">
                    <a href="${escapeHtml(data.open_url)}" class="px-2.5 py-1 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white font-semibold">
                        ✏️ In Office Light öffnen
                    </a>
                    <a href="${escapeHtml(data.folder_url)}" class="px-2.5 py-1 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 text-slate-700 dark:text-slate-200 font-semibold">
                        📁 Im Ordner zeigen
                    </a>
                </div>
            `;
        }
    } catch (e) {
        alert('Netzwerkfehler beim Speichern in der Cloud.');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.textContent = '💾 Speichern';
        }
    }
    return false;
}

function clearSourceText() {
    const srcInp = document.getElementById('sourceTextInput');
    const tgtOut = document.getElementById('targetTextOutput');
    const banner = document.getElementById('loadedDocBanner');
    const docSel = document.getElementById('cloudDocSelect');
    const detBadge = document.getElementById('detectedLangBadge');
    if (srcInp) srcInp.value = '';
    if (tgtOut) tgtOut.value = '';
    if (banner) banner.classList.add('hidden');
    if (docSel) docSel.value = '';
    if (detBadge) detBadge.classList.add('hidden');
    currentLoadedFileName = '';
    currentLoadedFolderPath = '';
    updateTextCounters();
    srcInp?.focus();
}

async function pasteFromClipboard() {
    try {
        const text = await navigator.clipboard.readText();
        const srcInp = document.getElementById('sourceTextInput');
        if (srcInp && text) {
            srcInp.value = text;
            onSourceTextInput();
            triggerTranslationNow(true);
        }
    } catch {
        alert('Bitte fügen Sie den Text direkt mit Strg+V in das linke Textfeld ein.');
    }
}

async function copyTranslationToClipboard() {
    const tgtOut = document.getElementById('targetTextOutput');
    const btn = document.getElementById('btnCopyTranslation');
    if (!tgtOut || !tgtOut.value.trim()) return;
    try {
        await navigator.clipboard.writeText(tgtOut.value);
        if (btn) {
            const orig = btn.innerHTML;
            btn.innerHTML = '<span>✅</span><span>Kopiert!</span>';
            setTimeout(() => { btn.innerHTML = orig; }, 1600);
        }
    } catch {
        tgtOut.select();
        document.execCommand('copy');
    }
}

function speakTranslatorText(side) {
    if (!('speechSynthesis' in window)) {
        alert('Ihr Browser unterstützt leider keine Sprachausgabe.');
        return;
    }
    window.speechSynthesis.cancel();
    const isSource = (side === 'source');
    const textEl = document.getElementById(isSource ? 'sourceTextInput' : 'targetTextOutput');
    if (!textEl || !textEl.value.trim()) return;

    const srcSel = document.getElementById('sourceLangSelect');
    const tgtSel = document.getElementById('targetLangSelect');
    let langCode = isSource
        ? (srcSel?.value === 'auto' ? (srcSel?.getAttribute('data-last-detected') || 'de') : (srcSel?.value || 'de'))
        : (tgtSel?.value || 'en');

    const utter = new SpeechSynthesisUtterance(textEl.value.substring(0, 4000));
    utter.lang = translatorLangsMeta[langCode]?.tts || langCode;
    window.speechSynthesis.speak(utter);
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str || '';
    return div.innerHTML;
}

function getTranslationHistory() {
    try {
        const raw = localStorage.getItem(historyStorageKey);
        if (raw) {
            const parsed = JSON.parse(raw);
            if (Array.isArray(parsed)) return parsed;
        }
    } catch {}
    return [];
}

function addTranslationToHistory(entry) {
    const list = getTranslationHistory().filter(it => !(it.srcText === entry.srcText && it.tgtLang === entry.tgtLang));
    list.unshift(entry);
    try {
        localStorage.setItem(historyStorageKey, JSON.stringify(list.slice(0, 9)));
    } catch {}
    renderTranslationHistory();
}

function clearTranslationHistory() {
    try {
        localStorage.removeItem(historyStorageKey);
    } catch {}
    renderTranslationHistory();
}

function restoreHistoryEntry(idx) {
    const list = getTranslationHistory();
    const item = list[idx];
    if (!item) return;
    const srcInp = document.getElementById('sourceTextInput');
    const tgtOut = document.getElementById('targetTextOutput');
    const tgtSel = document.getElementById('targetLangSelect');
    if (srcInp) srcInp.value = item.fullSrc || item.srcText || '';
    if (tgtOut) tgtOut.value = item.fullTgt || item.tgtText || '';
    if (tgtSel && item.tgtLang && translatorLangsMeta[item.tgtLang]) {
        tgtSel.value = item.tgtLang;
        onTargetLangChange();
    }
    updateTextCounters();
}

function renderTranslationHistory() {
    const box = document.getElementById('translationHistoryList');
    if (!box) return;
    const list = getTranslationHistory();
    if (list.length === 0) {
        box.innerHTML = '<p class="text-slate-400 italic col-span-full">Noch keine Übersetzungen im aktuellen Browser-Verlauf gespeichert.</p>';
        return;
    }
    box.innerHTML = list.map((item, idx) => `
        <button
            type="button"
            onclick="restoreHistoryEntry(${idx})"
            class="text-left p-3 rounded-xl bg-slate-50 dark:bg-slate-800/60 hover:bg-brand-50/60 dark:hover:bg-slate-800 border border-slate-200/80 dark:border-slate-700/80 transition-all cursor-pointer space-y-1"
        >
            <div class="flex items-center justify-between gap-2 text-[10px] font-bold uppercase text-slate-400">
                <span>${escapeHtml((item.srcLang || 'auto').toUpperCase())} &rarr; ${escapeHtml((item.tgtLang || '').toUpperCase())}</span>
                <span>${escapeHtml(item.ts || '')}</span>
            </div>
            <p class="text-slate-700 dark:text-slate-200 font-medium truncate">${escapeHtml(item.srcText)}</p>
            <p class="text-brand-600 dark:text-brand-400 font-semibold truncate">${escapeHtml(item.tgtText)}</p>
        </button>
    `).join('');
}

document.addEventListener('DOMContentLoaded', () => {
    renderTranslationHistory();
    updateSuggestedSaveFilename();
    <?php if ($preselectedFileId > 0): ?>
        loadCloudDocumentForTranslation(<?= (int)$preselectedFileId ?>);
    <?php endif; ?>
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
