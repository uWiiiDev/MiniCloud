<?php
declare(strict_types=1);

/**
 * MiniCloud – 📄 PDF-Werkzeugkasten & Dokumenten-Scanner (pdf_tools.php)
 *
 * 100 % lokale Verarbeitung auf dem eigenen Server (DSGVO-konform ohne externe Cloud-Dienste):
 * 1. 📸 Dokumenten-Scanner & Bilder-zu-PDF (inkl. Kamera-Scan, S/W-Kontrastfilter, A4-Layout & reinem PHP-PDF-1.4-Generator)
 * 2. 🔗 PDF-Dateien zusammenfügen (Merge mehrerer Cloud-PDFs oder Uploads)
 * 3. ✂️ PDF-Seiten extrahieren / teilen & drehen (Seitenbereiche wie "1-3" als neue PDF speichern)
 * 4. 🗜️ PDF komprimieren (Dateigröße großer Scans für E-Mail-Versand reduzieren)
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/i18n.php';

requireLogin();

$pdo         = getDB();
$appSettings = getAppSettings($pdo);
$userId      = currentUserId();
$username    = (string)($_SESSION['username'] ?? '');

if (($appSettings['enable_pdf_tools'] ?? '1') !== '1' && !isAdmin()) {
    setFlash('warning', 'Das Modul „PDF-Werkzeugkasten & Scanner“ ist aktuell durch den Administrator deaktiviert.');
    header('Location: index.php');
    exit;
}

/**
 * Prüft, ob ein bestimmtes System-Kommando (z. B. gs oder qpdf) auf dem Server ausführbar ist.
 */
function findPdfCliBinary(string $name): ?string
{
    if (!function_exists('proc_open')) {
        return null;
    }
    $candidates = [
        '/usr/bin/' . $name,
        '/usr/local/bin/' . $name,
        '/bin/' . $name,
    ];
    foreach ($candidates as $path) {
        if (@is_file($path) && @is_executable($path)) {
            return $path;
        }
    }
    return null;
}

/**
 * Führt ein CLI-Programm sicher über ein Argument-Array (ohne Shell-Interpolation!) mit Timeout aus.
 *
 * @param list<string> $cmdArgs
 */
function runSafeProcess(array $cmdArgs, ?string &$stderrOut = null): int
{
    if (!function_exists('proc_open') || empty($cmdArgs)) {
        $stderrOut = 'Prozessausführung auf diesem Webspace nicht verfügbar.';
        return -1;
    }
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $pipes = [];
    $proc = @proc_open($cmdArgs, $descriptors, $pipes, sys_get_temp_dir());
    if (!is_resource($proc)) {
        $stderrOut = 'Prozess konnte nicht gestartet werden.';
        return -1;
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($proc);
    $stderrOut = trim((string)$stderr . "\n" . (string)$stdout);
    return $exitCode;
}

/**
 * Erzeugt zu 100 % in reinem PHP (ohne externe Binaries) eine gültige mehrseitige PDF-1.4-Datei
 * aus einem Array von JPEG-Bilddaten.
 *
 * @param list<array{jpeg_bytes: string, width: int, height: int}> $pages
 */
function buildNativePdfFromJpegPages(array $pages, string $layout = 'a4_portrait'): string
{
    $objects = [];
    $pageObjIds = [];
    $nextId = 3; // 1 = Catalog, 2 = Pages

    foreach ($pages as $p) {
        $imgW = max(1, (int)$p['width']);
        $imgH = max(1, (int)$p['height']);
        $jpeg = (string)$p['jpeg_bytes'];
        $jpegLen = strlen($jpeg);

        if ($layout === 'a4_portrait') {
            $pageW = 595.28;
            $pageH = 841.89;
            $margin = 24.0;
        } elseif ($layout === 'a4_landscape') {
            $pageW = 841.89;
            $pageH = 595.28;
            $margin = 24.0;
        } else {
            // Originalformat (72 pt pro 96 px, randlos)
            $pageW = round($imgW * 0.75, 2);
            $pageH = round($imgH * 0.75, 2);
            $margin = 0.0;
        }

        $availW = max(10.0, $pageW - (2.0 * $margin));
        $availH = max(10.0, $pageH - (2.0 * $margin));
        $scale = min($availW / $imgW, $availH / $imgH);
        $drawW = round($imgW * $scale, 2);
        $drawH = round($imgH * $scale, 2);
        $posX  = round(($pageW - $drawW) / 2.0, 2);
        $posY  = round(($pageH - $drawH) / 2.0, 2);

        $imgObjId     = $nextId++;
        $contentObjId = $nextId++;
        $pageObjId    = $nextId++;
        $pageObjIds[] = $pageObjId;

        $objects[$imgObjId] = "<< /Type /XObject /Subtype /Image /Width {$imgW} /Height {$imgH} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length {$jpegLen} >>\nstream\n{$jpeg}\nendstream";

        $contentStream = sprintf("q %.2f 0 0 %.2f %.2f %.2f cm /Im1 Do Q", $drawW, $drawH, $posX, $posY);
        $contentLen = strlen($contentStream);
        $objects[$contentObjId] = "<< /Length {$contentLen} >>\nstream\n{$contentStream}\nendstream";

        $objects[$pageObjId] = sprintf(
            "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] /Resources << /XObject << /Im1 %d 0 R >> >> /Contents %d 0 R >>",
            $pageW,
            $pageH,
            $imgObjId,
            $contentObjId
        );
    }

    $kidsRefs = implode(' ', array_map(static fn(int $id): string => "{$id} 0 R", $pageObjIds));
    $pageCount = count($pageObjIds);

    $allObjects = [
        1 => "<< /Type /Catalog /Pages 2 0 R >>",
        2 => "<< /Type /Pages /Kids [ {$kidsRefs} ] /Count {$pageCount} >>",
    ] + $objects;

    ksort($allObjects);

    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [];
    foreach ($allObjects as $objId => $objBody) {
        $offsets[$objId] = strlen($pdf);
        $pdf .= "{$objId} 0 obj\n{$objBody}\nendobj\n";
    }

    $xrefOffset = strlen($pdf);
    $totalObjs = count($allObjects) + 1;
    $pdf .= "xref\n0 {$totalObjs}\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i < $totalObjs; $i++) {
        $off = $offsets[$i] ?? 0;
        $pdf .= sprintf("%010d 00000 n \n", $off);
    }

    $pdf .= "trailer\n<< /Size {$totalObjs} /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF\n";
    return $pdf;
}

/**
 * Verarbeitet eine Roh-Bilddatei mit PHP GD (optionaler Scan-Filter + Drehung) und gibt JPEG-Bytes + Maße zurück.
 *
 * @return array{jpeg_bytes: string, width: int, height: int}|null
 */
function processImageForScanPdf(string $imagePath, string $filterMode = 'scan_bw', int $rotateDeg = 0): ?array
{
    if (!is_file($imagePath) || !function_exists('imagecreatefromstring')) {
        return null;
    }
    $raw = @file_get_contents($imagePath);
    if ($raw === false || $raw === '') {
        return null;
    }
    $im = @imagecreatefromstring($raw);
    if ($im === false) {
        return null;
    }

    // EXIF-Orientierung von Smartphone-Kameras automatisch korrigieren
    if (function_exists('exif_read_data')) {
        $exif = @exif_read_data($imagePath);
        if (is_array($exif) && !empty($exif['Orientation'])) {
            $ort = (int)$exif['Orientation'];
            if ($ort === 3) {
                $rot = @imagerotate($im, 180, 0);
                if ($rot !== false) {
                    imagedestroy($im);
                    $im = $rot;
                }
            } elseif ($ort === 6) {
                $rot = @imagerotate($im, -90, 0);
                if ($rot !== false) {
                    imagedestroy($im);
                    $im = $rot;
                }
            } elseif ($ort === 8) {
                $rot = @imagerotate($im, 90, 0);
                if ($rot !== false) {
                    imagedestroy($im);
                    $im = $rot;
                }
            }
        }
    }

    // Manuelle Zusatz-Drehung (90, 180, 270 Grad im Uhrzeigersinn)
    if (in_array($rotateDeg, [90, 180, 270], true)) {
        $gdAngle = (360 - $rotateDeg) % 360;
        $white = imagecolorallocate($im, 255, 255, 255);
        $rot = @imagerotate($im, (float)$gdAngle, (int)$white);
        if ($rot !== false) {
            imagedestroy($im);
            $im = $rot;
        }
    }

    // Extrem hochauflösende Handyfotos auf max. 2480 px (A4 bei ~300 DPI) begrenzen, um RAM & PDF-Größe optimal zu halten
    $w = imagesx($im);
    $h = imagesy($im);
    $maxDim = 2480;
    if ($w > $maxDim || $h > $maxDim) {
        $ratio = min($maxDim / $w, $maxDim / $h);
        $newW = max(1, (int)round($w * $ratio));
        $newH = max(1, (int)round($h * $ratio));
        $scaled = imagecreatetruecolor($newW, $newH);
        if ($scaled !== false) {
            $bgWhite = imagecolorallocate($scaled, 255, 255, 255);
            imagefill($scaled, 0, 0, (int)$bgWhite);
            imagecopyresampled($scaled, $im, 0, 0, 0, 0, $newW, $newH, $w, $h);
            imagedestroy($im);
            $im = $scaled;
            $w = $newW;
            $h = $newH;
        }
    }

    // Dokumenten-Scan-Filter anwenden
    if ($filterMode === 'scan_bw') {
        @imagefilter($im, IMG_FILTER_GRAYSCALE);
        @imagefilter($im, IMG_FILTER_BRIGHTNESS, 18);
        @imagefilter($im, IMG_FILTER_CONTRAST, -42);
    } elseif ($filterMode === 'bright_contrast') {
        @imagefilter($im, IMG_FILTER_BRIGHTNESS, 14);
        @imagefilter($im, IMG_FILTER_CONTRAST, -22);
    }

    // Transparenz (z. B. PNG) auf weißen Papierhintergrund legen
    $canvas = imagecreatetruecolor($w, $h);
    if ($canvas !== false) {
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, (int)$white);
        imagecopy($canvas, $im, 0, 0, 0, 0, $w, $h);
        imagedestroy($im);
        $im = $canvas;
    }

    ob_start();
    imagejpeg($im, null, 86);
    $jpegBytes = (string)ob_get_clean();
    imagedestroy($im);

    if ($jpegBytes === '') {
        return null;
    }

    return [
        'jpeg_bytes' => $jpegBytes,
        'width'      => $w,
        'height'     => $h,
    ];
}

/**
 * Speichert eine erzeugte PDF-Binärdatei im Cloud-Speicher des Benutzers (unter Prüfung des Speicherkontingents).
 *
 * @return array{ok: bool, file_id?: int, filename?: string, size?: int, error?: string}
 */
function saveGeneratedPdfToCloud(PDO $pdo, int $userId, string $username, string $pdfBytes, string $desiredName, string $targetFolder): array
{
    $pdfSize = strlen($pdfBytes);
    if ($pdfSize < 64 || !str_starts_with($pdfBytes, '%PDF-')) {
        return ['ok' => false, 'error' => 'Die erzeugte Datei ist kein gültiges PDF-Dokument.'];
    }
    if ($pdfSize > MAX_FILE_SIZE) {
        return ['ok' => false, 'error' => 'Die erzeugte PDF-Datei überschreitet die maximale Dateigröße.'];
    }

    $storage = getUserStorageStats($pdo, $userId);
    if ($pdfSize > $storage['free']) {
        return [
            'ok'    => false,
            'error' => sprintf(
                'Nicht genügend freier Cloud-Speicherplatz (benötigt: %s, frei: %s).',
                formatBytes($pdfSize),
                formatBytes($storage['free'])
            ),
        ];
    }

    ensureUploadDirReady();

    $cleanName = sanitizeOriginalFilename($desiredName);
    if (!str_ends_with(strtolower($cleanName), '.pdf')) {
        $cleanName .= '.pdf';
    }
    $cleanFolder = extractSanitizedFolderPath($targetFolder);

    $storedName = sprintf('mc_%s_%s.bin', uniqid('', true), bin2hex(random_bytes(8)));
    $destPath   = UPLOAD_DIR . $storedName;

    if (@file_put_contents($destPath, $pdfBytes) === false) {
        return ['ok' => false, 'error' => 'PDF-Datei konnte nicht im Upload-Verzeichnis gespeichert werden.'];
    }
    @chmod($destPath, 0640);

    if ($cleanFolder !== '') {
        registerFolderHierarchy($pdo, $userId, $cleanFolder);
    }

    $stmtIns = $pdo->prepare(
        "INSERT INTO files (user_id, filename, original_name, folder_path, file_size, mime_type)
         VALUES (:uid, :fn, :orig, :folder, :sz, 'application/pdf')"
    );
    $stmtIns->execute([
        ':uid'    => $userId,
        ':fn'     => $storedName,
        ':orig'   => $cleanName,
        ':folder' => $cleanFolder,
        ':sz'     => $pdfSize,
    ]);
    $newFileId = (int)$pdo->lastInsertId();

    logAuditEvent($pdo, 'pdf_tool_create', sprintf('PDF erstellt (%s, %s) in „/%s“', $cleanName, formatBytes($pdfSize), $cleanFolder), $userId, $username);

    return [
        'ok'       => true,
        'file_id'  => $newFileId,
        'filename' => $cleanName,
        'folder'   => $cleanFolder,
        'size'     => $pdfSize,
    ];
}

$gsBin   = findPdfCliBinary('gs');
$qpdfBin = findPdfCliBinary('qpdf');

// ============================================================================
// POST-Aktionen verarbeiten
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        setFlash('error', 'Ungültiges Sicherheits-Token (CSRF).');
        header('Location: pdf_tools.php');
        exit;
    }

    $action       = (string)($_POST['action'] ?? '');
    $targetFolder = extractSanitizedFolderPath((string)($_POST['target_folder'] ?? 'Scans'));
    $outputName   = trim((string)($_POST['output_name'] ?? ''));

    // ------------------------------------------------------------------------
    // 1. 📸 Dokumenten-Scanner & Bilder-zu-PDF
    // ------------------------------------------------------------------------
    if ($action === 'scan_to_pdf') {
        $filterMode = (string)($_POST['filter_mode'] ?? 'scan_bw');
        if (!in_array($filterMode, ['scan_bw', 'bright_contrast', 'original'], true)) {
            $filterMode = 'scan_bw';
        }
        $pageLayout = (string)($_POST['page_layout'] ?? 'a4_portrait');
        if (!in_array($pageLayout, ['a4_portrait', 'a4_landscape', 'original'], true)) {
            $pageLayout = 'a4_portrait';
        }
        $rotateDeg = (int)($_POST['rotate_deg'] ?? 0);
        if (!in_array($rotateDeg, [0, 90, 180, 270], true)) {
            $rotateDeg = 0;
        }

        if ($outputName === '') {
            $outputName = 'Scan_' . date('Y-m-d_His') . '.pdf';
        }

        $pagesData = [];

        // 1a. Hochgeladene Kamera-Scans / Geräte-Bilder verarbeiten
        if (!empty($_FILES['scan_images']['tmp_name']) && is_array($_FILES['scan_images']['tmp_name'])) {
            foreach ($_FILES['scan_images']['tmp_name'] as $idx => $tmpPath) {
                $err = (int)($_FILES['scan_images']['error'][$idx] ?? UPLOAD_ERR_NO_FILE);
                if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string)$tmpPath)) {
                    continue;
                }
                $processed = processImageForScanPdf((string)$tmpPath, $filterMode, $rotateDeg);
                if ($processed !== null) {
                    $pagesData[] = $processed;
                }
            }
        }

        // 1b. Ausgewählte Bilder aus der eigenen MiniCloud verarbeiten
        $cloudImgIds = isset($_POST['cloud_image_ids']) && is_array($_POST['cloud_image_ids']) ? $_POST['cloud_image_ids'] : [];
        foreach (array_slice($cloudImgIds, 0, 50) as $rawId) {
            $fid = (int)$rawId;
            if ($fid <= 0) {
                continue;
            }
            $stmtF = $pdo->prepare('SELECT * FROM files WHERE id = :id AND user_id = :uid AND deleted_at IS NULL AND is_encrypted = 0 LIMIT 1');
            $stmtF->execute([':id' => $fid, ':uid' => $userId]);
            $fRow = $stmtF->fetch();
            if (!$fRow) {
                continue;
            }
            $physPath = UPLOAD_DIR . basename((string)$fRow['filename']);
            $processed = processImageForScanPdf($physPath, $filterMode, $rotateDeg);
            if ($processed !== null) {
                $pagesData[] = $processed;
            }
        }

        if (empty($pagesData)) {
            setFlash('error', 'Bitte fotografiere mindestens eine Seite oder wähle mindestens ein gültiges Bild (JPG, PNG, WEBP) aus.');
            header('Location: pdf_tools.php#tab-scan');
            exit;
        }

        $pdfBytes = buildNativePdfFromJpegPages($pagesData, $pageLayout);
        $saved = saveGeneratedPdfToCloud($pdo, $userId, $username, $pdfBytes, $outputName, $targetFolder);

        if (!$saved['ok']) {
            setFlash('error', (string)$saved['error']);
            header('Location: pdf_tools.php#tab-scan');
            exit;
        }

        setFlash(
            'success',
            sprintf(
                '📸 PDF-Dokument „%s“ (%d %s, %s) wurde im Ordner „/%s“ gespeichert!',
                $saved['filename'],
                count($pagesData),
                count($pagesData) === 1 ? 'Seite' : 'Seiten',
                formatBytes((int)$saved['size']),
                $saved['folder']
            )
        );
        header('Location: pdf_tools.php?created=' . (int)$saved['file_id']);
        exit;
    }

    // ------------------------------------------------------------------------
    // 2. 🔗 PDF-Dateien zusammenfügen (Merge)
    // ------------------------------------------------------------------------
    if ($action === 'merge_pdfs') {
        if ($gsBin === null && $qpdfBin === null) {
            setFlash('error', 'Zum Zusammenfügen bestehender PDFs wird Ghostscript (gs) oder qpdf auf dem Server benötigt.');
            header('Location: pdf_tools.php#tab-merge');
            exit;
        }

        if ($outputName === '') {
            $outputName = 'Zusammengefuegt_' . date('Y-m-d_His') . '.pdf';
        }

        $inputPaths = [];
        $tempFilesToClean = [];

        try {
            // Ausgewählte Cloud-PDFs
            $cloudPdfIds = isset($_POST['merge_pdf_ids']) && is_array($_POST['merge_pdf_ids']) ? $_POST['merge_pdf_ids'] : [];
            foreach (array_slice($cloudPdfIds, 0, 30) as $rawId) {
                $fid = (int)$rawId;
                if ($fid <= 0) {
                    continue;
                }
                $stmtF = $pdo->prepare("SELECT * FROM files WHERE id = :id AND user_id = :uid AND deleted_at IS NULL AND is_encrypted = 0 AND mime_type = 'application/pdf' LIMIT 1");
                $stmtF->execute([':id' => $fid, ':uid' => $userId]);
                $fRow = $stmtF->fetch();
                if ($fRow) {
                    $phys = UPLOAD_DIR . basename((string)$fRow['filename']);
                    if (is_file($phys)) {
                        $inputPaths[] = $phys;
                    }
                }
            }

            // Zusätzlich hochgeladene PDF-Dateien
            if (!empty($_FILES['upload_pdfs']['tmp_name']) && is_array($_FILES['upload_pdfs']['tmp_name'])) {
                foreach ($_FILES['upload_pdfs']['tmp_name'] as $idx => $tmpPath) {
                    $err = (int)($_FILES['upload_pdfs']['error'][$idx] ?? UPLOAD_ERR_NO_FILE);
                    if ($err === UPLOAD_ERR_OK && is_uploaded_file((string)$tmpPath)) {
                        $head = (string)@file_get_contents((string)$tmpPath, false, null, 0, 8);
                        if (str_starts_with($head, '%PDF-')) {
                            $inputPaths[] = (string)$tmpPath;
                        }
                    }
                }
            }

            if (count($inputPaths) < 2) {
                setFlash('error', 'Bitte wähle mindestens 2 PDF-Dateien zum Zusammenfügen aus.');
                header('Location: pdf_tools.php#tab-merge');
                exit;
            }

            $outTmp = tempnam(sys_get_temp_dir(), 'mc_pdf_merge_') . '.pdf';
            $tempFilesToClean[] = $outTmp;

            $errOut = null;
            if ($qpdfBin !== null) {
                $cmd = array_merge([$qpdfBin, '--empty', '--pages'], $inputPaths, ['--', $outTmp]);
                $code = runSafeProcess($cmd, $errOut);
            } else {
                $cmd = array_merge([$gsBin, '-dBATCH', '-dNOPAUSE', '-dSAFER', '-q', '-sDEVICE=pdfwrite', '-sOutputFile=' . $outTmp], $inputPaths);
                $code = runSafeProcess($cmd, $errOut);
            }

            if ($code !== 0 || !is_file($outTmp) || filesize($outTmp) < 64) {
                setFlash('error', 'PDF-Dateien konnten nicht zusammengefügt werden.');
                header('Location: pdf_tools.php#tab-merge');
                exit;
            }

            $pdfBytes = (string)file_get_contents($outTmp);
            $saved = saveGeneratedPdfToCloud($pdo, $userId, $username, $pdfBytes, $outputName, $targetFolder);

            if (!$saved['ok']) {
                setFlash('error', (string)$saved['error']);
                header('Location: pdf_tools.php#tab-merge');
                exit;
            }

            setFlash(
                'success',
                sprintf(
                    '🔗 %d PDF-Dateien wurden erfolgreich zu „%s“ (%s) im Ordner „/%s“ zusammengefügt!',
                    count($inputPaths),
                    $saved['filename'],
                    formatBytes((int)$saved['size']),
                    $saved['folder']
                )
            );
            header('Location: pdf_tools.php?created=' . (int)$saved['file_id'] . '#tab-merge');
            exit;
        } finally {
            foreach ($tempFilesToClean as $tf) {
                if (is_file($tf)) {
                    @unlink($tf);
                }
            }
        }
    }

    // ------------------------------------------------------------------------
    // 3. ✂️ PDF-Seiten extrahieren / teilen
    // ------------------------------------------------------------------------
    if ($action === 'extract_pages') {
        $sourceId  = (int)($_POST['source_pdf_id'] ?? 0);
        $firstPage = max(1, (int)($_POST['first_page'] ?? 1));
        $lastPage  = max($firstPage, (int)($_POST['last_page'] ?? $firstPage));

        $stmtF = $pdo->prepare("SELECT * FROM files WHERE id = :id AND user_id = :uid AND deleted_at IS NULL AND is_encrypted = 0 AND mime_type = 'application/pdf' LIMIT 1");
        $stmtF->execute([':id' => $sourceId, ':uid' => $userId]);
        $srcRow = $stmtF->fetch();

        if (!$srcRow) {
            setFlash('error', 'Bitte wähle eine gültige PDF-Datei aus deiner Cloud aus.');
            header('Location: pdf_tools.php#tab-split');
            exit;
        }

        $srcPath = UPLOAD_DIR . basename((string)$srcRow['filename']);
        if (!is_file($srcPath)) {
            setFlash('error', 'Quell-PDF wurde auf dem Server nicht gefunden.');
            header('Location: pdf_tools.php#tab-split');
            exit;
        }

        if ($outputName === '') {
            $baseOrig = preg_replace('/\.pdf$/i', '', (string)$srcRow['original_name']) ?: 'Dokument';
            $outputName = sprintf('%s_S%d-%d.pdf', $baseOrig, $firstPage, $lastPage);
        }

        $outTmp = tempnam(sys_get_temp_dir(), 'mc_pdf_split_') . '.pdf';
        try {
            $errOut = null;
            if ($qpdfBin !== null) {
                $cmd = [$qpdfBin, $srcPath, '--pages', $srcPath, $firstPage . '-' . $lastPage, '--', $outTmp];
                $code = runSafeProcess($cmd, $errOut);
            } elseif ($gsBin !== null) {
                $cmd = [
                    $gsBin,
                    '-dBATCH',
                    '-dNOPAUSE',
                    '-dSAFER',
                    '-q',
                    '-sDEVICE=pdfwrite',
                    '-dFirstPage=' . $firstPage,
                    '-dLastPage=' . $lastPage,
                    '-sOutputFile=' . $outTmp,
                    $srcPath,
                ];
                $code = runSafeProcess($cmd, $errOut);
            } else {
                $code = -1;
            }

            if ($code !== 0 || !is_file($outTmp) || filesize($outTmp) < 64) {
                setFlash('error', 'Der gewählte Seitenbereich (' . $firstPage . '–' . $lastPage . ') konnte nicht extrahiert werden (evtl. hat das PDF weniger Seiten).');
                header('Location: pdf_tools.php#tab-split');
                exit;
            }

            $pdfBytes = (string)file_get_contents($outTmp);
            $saved = saveGeneratedPdfToCloud($pdo, $userId, $username, $pdfBytes, $outputName, $targetFolder);

            if (!$saved['ok']) {
                setFlash('error', (string)$saved['error']);
                header('Location: pdf_tools.php#tab-split');
                exit;
            }

            setFlash(
                'success',
                sprintf(
                    '✂️ Seiten %d–%d wurden als „%s“ (%s) im Ordner „/%s“ gespeichert!',
                    $firstPage,
                    $lastPage,
                    $saved['filename'],
                    formatBytes((int)$saved['size']),
                    $saved['folder']
                )
            );
            header('Location: pdf_tools.php?created=' . (int)$saved['file_id'] . '#tab-split');
            exit;
        } finally {
            if (is_file($outTmp)) {
                @unlink($outTmp);
            }
        }
    }

    // ------------------------------------------------------------------------
    // 4. 🗜️ PDF komprimieren / Dateigröße verkleinern
    // ------------------------------------------------------------------------
    if ($action === 'compress_pdf') {
        $sourceId = (int)($_POST['source_pdf_id'] ?? 0);
        $quality  = (string)($_POST['compress_quality'] ?? 'ebook');
        if (!in_array($quality, ['screen', 'ebook', 'printer'], true)) {
            $quality = 'ebook';
        }

        $stmtF = $pdo->prepare("SELECT * FROM files WHERE id = :id AND user_id = :uid AND deleted_at IS NULL AND is_encrypted = 0 AND mime_type = 'application/pdf' LIMIT 1");
        $stmtF->execute([':id' => $sourceId, ':uid' => $userId]);
        $srcRow = $stmtF->fetch();

        if (!$srcRow || $gsBin === null) {
            setFlash('error', 'Bitte wähle eine gültige PDF-Datei aus.');
            header('Location: pdf_tools.php#tab-compress');
            exit;
        }

        $srcPath = UPLOAD_DIR . basename((string)$srcRow['filename']);
        $origSize = (int)$srcRow['file_size'];

        if ($outputName === '') {
            $baseOrig = preg_replace('/\.pdf$/i', '', (string)$srcRow['original_name']) ?: 'Dokument';
            $outputName = $baseOrig . '_komprimiert.pdf';
        }

        $outTmp = tempnam(sys_get_temp_dir(), 'mc_pdf_cmp_') . '.pdf';
        try {
            $errOut = null;
            $cmd = [
                $gsBin,
                '-dBATCH',
                '-dNOPAUSE',
                '-dSAFER',
                '-q',
                '-sDEVICE=pdfwrite',
                '-dCompatibilityLevel=1.4',
                '-dPDFSETTINGS=/' . $quality,
                '-sOutputFile=' . $outTmp,
                $srcPath,
            ];
            $code = runSafeProcess($cmd, $errOut);

            if ($code !== 0 || !is_file($outTmp) || filesize($outTmp) < 64) {
                setFlash('error', 'PDF konnte nicht komprimiert werden.');
                header('Location: pdf_tools.php#tab-compress');
                exit;
            }

            $pdfBytes = (string)file_get_contents($outTmp);
            $newSize  = strlen($pdfBytes);
            $saved    = saveGeneratedPdfToCloud($pdo, $userId, $username, $pdfBytes, $outputName, $targetFolder);

            if (!$saved['ok']) {
                setFlash('error', (string)$saved['error']);
                header('Location: pdf_tools.php#tab-compress');
                exit;
            }

            $diffPct = $origSize > 0 ? round((1 - ($newSize / $origSize)) * 100) : 0;
            $savingsLabel = $diffPct > 0
                ? sprintf('%s → %s (-%d %%)', formatBytes($origSize), formatBytes($newSize), (int)$diffPct)
                : formatBytes($newSize);

            setFlash(
                'success',
                sprintf(
                    '🗜️ Komprimiertes PDF „%s“ (%s) im Ordner „/%s“ gespeichert!',
                    $saved['filename'],
                    $savingsLabel,
                    $saved['folder']
                )
            );
            header('Location: pdf_tools.php?created=' . (int)$saved['file_id'] . '#tab-compress');
            exit;
        } finally {
            if (is_file($outTmp)) {
                @unlink($outTmp);
            }
        }
    }
}

// Eigene PDF-Dateien und Bilddateien aus der Cloud für die Auswahl laden
$stmtMyPdfs = $pdo->prepare("
    SELECT id, original_name, folder_path, file_size, upload_date
    FROM files
    WHERE user_id = :uid AND deleted_at IS NULL AND is_encrypted = 0 AND mime_type = 'application/pdf'
    ORDER BY upload_date DESC
    LIMIT 100
");
$stmtMyPdfs->execute([':uid' => $userId]);
$myPdfs = $stmtMyPdfs->fetchAll();

$stmtMyImages = $pdo->prepare("
    SELECT id, original_name, folder_path, file_size, upload_date
    FROM files
    WHERE user_id = :uid AND deleted_at IS NULL AND is_encrypted = 0
      AND mime_type IN ('image/jpeg', 'image/png', 'image/webp', 'image/gif')
    ORDER BY upload_date DESC
    LIMIT 100
");
$stmtMyImages->execute([':uid' => $userId]);
$myImages = $stmtMyImages->fetchAll();

$createdFile = null;
if (!empty($_GET['created'])) {
    $stmtCreated = $pdo->prepare('SELECT * FROM files WHERE id = :id AND user_id = :uid AND deleted_at IS NULL LIMIT 1');
    $stmtCreated->execute([':id' => (int)$_GET['created'], ':uid' => $userId]);
    $createdFile = $stmtCreated->fetch() ?: null;
}

$csrfToken = getCsrfToken();
$pageTitle = '📄 PDF-Werkzeugkasten & Scanner';
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-6xl mx-auto space-y-6">
    <!-- Kopfbereich -->
    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-xl sm:text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-rose-100 dark:bg-rose-950 text-rose-600 dark:text-rose-400">📄</span>
                <span>PDF-Werkzeugkasten &amp; Dokumenten-Scanner</span>
            </h1>
            <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-1">
                Briefe &amp; Belege mit dem Smartphone scannen (inkl. S/W-Kontrastfilter), Bilder in PDFs umwandeln sowie PDFs lokal zusammenfügen, teilen und komprimieren.
            </p>
        </div>
        <a href="index.php?folder=Scans" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 text-slate-800 dark:text-slate-200 text-xs sm:text-sm font-semibold transition-colors shrink-0">
            📁 Ordner „Scans“ öffnen &rarr;
        </a>
    </div>

    <?php if ($createdFile !== null): ?>
        <div class="mc-card bg-emerald-50/90 dark:bg-emerald-950/40 rounded-2xl border border-emerald-300 dark:border-emerald-800 p-4 sm:p-5 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div>
                <div class="text-xs font-bold uppercase tracking-wider text-emerald-700 dark:text-emerald-300">✅ Fertiggestelltes PDF-Dokument</div>
                <div class="text-sm sm:text-base font-bold text-slate-900 dark:text-white mt-0.5">
                    📄 <?= e((string)$createdFile['original_name']) ?>
                    <span class="text-xs font-normal text-slate-500">(<?= e(formatBytes((int)$createdFile['file_size'])) ?> · Ordner: /<?= e((string)$createdFile['folder_path']) ?>)</span>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2 shrink-0">
                <a href="download.php?id=<?= (int)$createdFile['id'] ?>&amp;inline=1" target="_blank"
                   class="px-3.5 py-2 rounded-xl bg-white dark:bg-slate-900 border border-emerald-300 dark:border-emerald-700 text-emerald-800 dark:text-emerald-200 text-xs font-bold hover:bg-emerald-100 transition-colors">
                    👁️ PDF im Browser ansehen
                </a>
                <a href="download.php?id=<?= (int)$createdFile['id'] ?>"
                   class="px-3.5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold transition-colors">
                    ⬇️ PDF herunterladen
                </a>
            </div>
        </div>
    <?php endif; ?>

    <!-- 4 Reiter für die PDF-Werkzeuge -->
    <div class="flex flex-wrap gap-2">
        <button type="button" data-pdf-tab-btn="tab-scan" onclick="switchPdfTab('tab-scan')"
                class="px-4 py-2.5 rounded-xl text-xs sm:text-sm font-bold transition-colors cursor-pointer bg-brand-600 text-white">
            📸 1. Dokumenten-Scanner &amp; Bilder &rarr; PDF
        </button>
        <button type="button" data-pdf-tab-btn="tab-merge" onclick="switchPdfTab('tab-merge')"
                class="px-4 py-2.5 rounded-xl text-xs sm:text-sm font-bold transition-colors cursor-pointer bg-white dark:bg-slate-900 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-800">
            🔗 2. PDFs zusammenfügen
        </button>
        <button type="button" data-pdf-tab-btn="tab-split" onclick="switchPdfTab('tab-split')"
                class="px-4 py-2.5 rounded-xl text-xs sm:text-sm font-bold transition-colors cursor-pointer bg-white dark:bg-slate-900 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-800">
            ✂️ 3. Seiten extrahieren / teilen
        </button>
        <button type="button" data-pdf-tab-btn="tab-compress" onclick="switchPdfTab('tab-compress')"
                class="px-4 py-2.5 rounded-xl text-xs sm:text-sm font-bold transition-colors cursor-pointer bg-white dark:bg-slate-900 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-800">
            🗜️ 4. PDF komprimieren
        </button>
    </div>

    <!-- =================================================================== -->
    <!-- REITER 1: DOKUMENTEN-SCANNER & BILDER ZU PDF                        -->
    <!-- =================================================================== -->
    <section id="tab-scan" data-pdf-tab-panel="tab-scan" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-5">
        <div>
            <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">📸 Dokumenten-Scanner &amp; Bilder-zu-PDF-Konverter</h2>
            <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-0.5">
                Fotografiere Briefe, Rechnungen oder Belege direkt mit der Smartphone-Kamera oder wähle Bilder aus – der integrierte <strong>🖨️ S/W-Dokumentenfilter</strong> entfernt graue Schatten und sorgt für gestochen scharfen Text auf DIN-A4-Seiten.
            </p>
        </div>

        <form action="pdf_tools.php" method="post" enctype="multipart/form-data" class="space-y-5">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="scan_to_pdf">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- Kamera-Scan / Datei-Upload -->
                <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border-2 border-dashed border-brand-300 dark:border-brand-800 space-y-3">
                    <div class="text-xs sm:text-sm font-bold text-slate-900 dark:text-white">
                        📷 Seiten mit Kamera aufnehmen oder Bilder vom Gerät wählen
                    </div>
                    <input
                        type="file"
                        name="scan_images[]"
                        id="scanImagesInput"
                        accept="image/jpeg,image/png,image/webp,image/gif"
                        multiple
                        onchange="previewSelectedScanImages(this)"
                        class="block w-full text-xs text-slate-600 dark:text-slate-300 file:mr-3 file:py-2 file:px-3.5 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-brand-600 file:text-white hover:file:bg-brand-700 cursor-pointer"
                    >
                    <p class="text-[11px] text-slate-500 dark:text-slate-400">
                        💡 Auf dem Smartphone öffnet sich auf Wunsch direkt die Kamera. Du kannst auch mehrere Fotos gleichzeitig markieren.
                    </p>
                    <div id="scanPreviewStrip" class="hidden pt-2 flex flex-wrap gap-2"></div>
                </div>

                <!-- Optional: Vorhandene Bilder aus der Cloud einbinden -->
                <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 space-y-2">
                    <div class="text-xs sm:text-sm font-bold text-slate-900 dark:text-white">
                        ☁️ Oder Bilder aus deiner MiniCloud hinzufügen (optional)
                    </div>
                    <?php if (empty($myImages)): ?>
                        <p class="text-xs text-slate-400 py-4">Keine Bilddateien in deiner Cloud gefunden.</p>
                    <?php else: ?>
                        <div class="max-h-40 overflow-y-auto space-y-1.5 pr-1">
                            <?php foreach ($myImages as $img): ?>
                                <label class="flex items-center justify-between gap-2 p-2 rounded-xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 text-xs cursor-pointer hover:border-brand-400">
                                    <span class="flex items-center gap-2 min-w-0">
                                        <input type="checkbox" name="cloud_image_ids[]" value="<?= (int)$img['id'] ?>" class="rounded text-brand-600">
                                        <span class="truncate font-medium text-slate-800 dark:text-slate-200"><?= e((string)$img['original_name']) ?></span>
                                    </span>
                                    <span class="text-[11px] text-slate-400 shrink-0"><?= e(formatBytes((int)$img['file_size'])) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Scan-Optionen (Filter, Seitenformat, Drehung, Zielordner, Dateiname) -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Scan-Optimierung</label>
                    <select name="filter_mode" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white">
                        <option value="scan_bw">🖨️ S/W Dokumenten-Scan (Hoher Kontrast)</option>
                        <option value="bright_contrast">☀️ Farbe aufhellen &amp; Kontrast+</option>
                        <option value="original">🎨 Original (Unverändert)</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Seitenformat</label>
                    <select name="page_layout" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white">
                        <option value="a4_portrait">📄 DIN A4 Hochformat</option>
                        <option value="a4_landscape">📄 DIN A4 Querformat</option>
                        <option value="original">🖼️ Originalgröße (Randlos)</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Seiten drehen</label>
                    <select name="rotate_deg" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white">
                        <option value="0">0° (Automatisch per EXIF)</option>
                        <option value="90">↻ 90° im Uhrzeigersinn</option>
                        <option value="180">↻ 180° auf den Kopf</option>
                        <option value="270">↺ 270° (90° links)</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Zielordner in der Cloud</label>
                    <input type="text" name="target_folder" value="Scans" placeholder="z. B. Scans oder Belege/2026"
                           class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Dateiname des PDFs</label>
                    <input type="text" name="output_name" placeholder="Scan_<?= date('Y-m-d') ?>.pdf"
                           class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white">
                </div>
            </div>

            <div class="flex justify-end">
                <button type="submit" class="px-5 py-2.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-bold text-xs sm:text-sm shadow-sm transition-colors cursor-pointer">
                    📸 PDF erzeugen &amp; in Cloud speichern
                </button>
            </div>
        </form>
    </section>

    <!-- =================================================================== -->
    <!-- REITER 2: PDF-DATEIEN ZUSAMMENFÜGEN (MERGE)                         -->
    <!-- =================================================================== -->
    <section id="tab-merge" data-pdf-tab-panel="tab-merge" class="hidden mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-5">
        <div>
            <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">🔗 Mehrere PDF-Dateien zu einem PDF zusammenfügen</h2>
            <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-0.5">
                Wähle mindestens 2 PDF-Dateien aus deiner Cloud (oder lade weitere PDFs vom Gerät hoch), um sie zu einer einzigen Sammel-PDF zu verbinden.
            </p>
        </div>

        <form action="pdf_tools.php" method="post" enctype="multipart/form-data" class="space-y-5">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="merge_pdfs">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 space-y-2">
                    <div class="text-xs sm:text-sm font-bold text-slate-900 dark:text-white">
                        ☁️ PDF-Dateien aus deiner Cloud auswählen
                    </div>
                    <?php if (empty($myPdfs)): ?>
                        <p class="text-xs text-slate-400 py-4">Noch keine PDF-Dateien in deiner Cloud vorhanden.</p>
                    <?php else: ?>
                        <div class="max-h-56 overflow-y-auto space-y-1.5 pr-1">
                            <?php foreach ($myPdfs as $pdf): ?>
                                <label class="flex items-center justify-between gap-2 p-2.5 rounded-xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 text-xs cursor-pointer hover:border-brand-400">
                                    <span class="flex items-center gap-2 min-w-0">
                                        <input type="checkbox" name="merge_pdf_ids[]" value="<?= (int)$pdf['id'] ?>" class="rounded text-brand-600">
                                        <span class="truncate font-semibold text-slate-800 dark:text-slate-200">📄 <?= e((string)$pdf['original_name']) ?></span>
                                    </span>
                                    <span class="text-[11px] text-slate-400 shrink-0"><?= e(formatBytes((int)$pdf['file_size'])) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="space-y-4">
                    <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 space-y-2">
                        <label class="block text-xs sm:text-sm font-bold text-slate-900 dark:text-white">
                            📤 Optional: Weitere PDFs vom Gerät hinzufügen
                        </label>
                        <input type="file" name="upload_pdfs[]" accept="application/pdf,.pdf" multiple
                               class="block w-full text-xs text-slate-600 dark:text-slate-300 file:mr-3 file:py-2 file:px-3.5 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-brand-600 file:text-white hover:file:bg-brand-700 cursor-pointer">
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Zielordner</label>
                            <input type="text" name="target_folder" value="Scans"
                                   class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Neuer Dateiname</label>
                            <input type="text" name="output_name" placeholder="Gesamt_<?= date('Y-m-d') ?>.pdf"
                                   class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white">
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex justify-end">
                <button type="submit" class="px-5 py-2.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-bold text-xs sm:text-sm shadow-sm transition-colors cursor-pointer">
                    🔗 Ausgewählte PDFs zusammenfügen
                </button>
            </div>
        </form>
    </section>

    <!-- =================================================================== -->
    <!-- REITER 3: PDF-SEITEN EXTRAHIEREN / TEILEN                           -->
    <!-- =================================================================== -->
    <section id="tab-split" data-pdf-tab-panel="tab-split" class="hidden mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-5">
        <div>
            <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">✂️ Einzelne Seiten aus einem PDF extrahieren</h2>
            <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-0.5">
                Speichere eine bestimmte Seite oder einen Seitenbereich (z. B. Seite 1 bis 3) eines umfangreichen PDFs als eigenes, schlankes Dokument ab.
            </p>
        </div>

        <form action="pdf_tools.php" method="post" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="extract_pages">

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
                <div class="sm:col-span-2">
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Quell-PDF in deiner Cloud *</label>
                    <select name="source_pdf_id" required class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white">
                        <option value="">– Bitte PDF auswählen –</option>
                        <?php foreach ($myPdfs as $pdf): ?>
                            <option value="<?= (int)$pdf['id'] ?>">📄 <?= e((string)$pdf['original_name']) ?> (<?= e(formatBytes((int)$pdf['file_size'])) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Von Seite *</label>
                    <input type="number" name="first_page" min="1" max="5000" value="1" required
                           class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Bis Seite *</label>
                    <input type="number" name="last_page" min="1" max="5000" value="1" required
                           class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Zielordner</label>
                    <input type="text" name="target_folder" value="Scans"
                           class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white">
                </div>
            </div>

            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pt-2">
                <div class="w-full sm:w-72">
                    <input type="text" name="output_name" placeholder="Optionaler Dateiname (z.B. Auszug.pdf)"
                           class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white">
                </div>
                <button type="submit" class="px-5 py-2.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-bold text-xs sm:text-sm shadow-sm transition-colors cursor-pointer">
                    ✂️ Seiten extrahieren &amp; speichern
                </button>
            </div>
        </form>
    </section>

    <!-- =================================================================== -->
    <!-- REITER 4: PDF KOMPRIMIEREN / VERKLEINERN                            -->
    <!-- =================================================================== -->
    <section id="tab-compress" data-pdf-tab-panel="tab-compress" class="hidden mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-5">
        <div>
            <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">🗜️ PDF-Dateigröße komprimieren (z. B. für E-Mail-Anhänge)</h2>
            <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-0.5">
                Reduziert die Auflösung eingebetteter Bilder in großen PDF-Scans, ohne das Originaldokument zu überschreiben.
            </p>
        </div>

        <form action="pdf_tools.php" method="post" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="compress_pdf">

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="sm:col-span-2">
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Quell-PDF in deiner Cloud *</label>
                    <select name="source_pdf_id" required class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white">
                        <option value="">– Bitte PDF auswählen –</option>
                        <?php foreach ($myPdfs as $pdf): ?>
                            <option value="<?= (int)$pdf['id'] ?>">📄 <?= e((string)$pdf['original_name']) ?> (<?= e(formatBytes((int)$pdf['file_size'])) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Kompressions-Stufe</label>
                    <select name="compress_quality" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white">
                        <option value="ebook">📧 E-Mail / Standard (150 DPI – empfohlen)</option>
                        <option value="screen">📱 Maximale Ersparnis (72 DPI – sehr klein)</option>
                        <option value="printer">🖨️ Hohe Druckqualität (300 DPI)</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Zielordner</label>
                    <input type="text" name="target_folder" value="Scans"
                           class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white">
                </div>
            </div>

            <div class="flex justify-end">
                <button type="submit" class="px-5 py-2.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-bold text-xs sm:text-sm shadow-sm transition-colors cursor-pointer">
                    🗜️ PDF jetzt komprimieren
                </button>
            </div>
        </form>
    </section>
</div>

<script>
function switchPdfTab(tabId) {
    document.querySelectorAll('[data-pdf-tab-panel]').forEach(p => {
        p.classList.toggle('hidden', p.getAttribute('data-pdf-tab-panel') !== tabId);
    });
    document.querySelectorAll('[data-pdf-tab-btn]').forEach(b => {
        const active = b.getAttribute('data-pdf-tab-btn') === tabId;
        b.className = active
            ? 'px-4 py-2.5 rounded-xl text-xs sm:text-sm font-bold transition-colors cursor-pointer bg-brand-600 text-white'
            : 'px-4 py-2.5 rounded-xl text-xs sm:text-sm font-bold transition-colors cursor-pointer bg-white dark:bg-slate-900 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-800';
    });
}

function previewSelectedScanImages(input) {
    const strip = document.getElementById('scanPreviewStrip');
    if (!strip) return;
    strip.innerHTML = '';
    if (!input.files || input.files.length === 0) {
        strip.classList.add('hidden');
        return;
    }
    strip.classList.remove('hidden');
    Array.from(input.files).slice(0, 12).forEach((f, idx) => {
        const badge = document.createElement('div');
        badge.className = 'px-2.5 py-1 rounded-lg bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 text-[11px] font-semibold text-slate-700 dark:text-slate-200';
        badge.textContent = `Seite ${idx + 1}: ${f.name}`;
        strip.appendChild(badge);
    });
}

if (window.location.hash) {
    const h = window.location.hash.replace('#', '');
    if (['tab-scan', 'tab-merge', 'tab-split', 'tab-compress'].includes(h)) {
        switchPdfTab(h);
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
