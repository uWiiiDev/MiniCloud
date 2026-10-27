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
                // Für Ghostscript unter striktem AppArmor (/etc/apparmor.d/gs) Eingabedateien in /tmp bereitstellen
                $gsInputs = [];
                foreach ($inputPaths as $pIdx => $inP) {
                    $tmpIn = tempnam(sys_get_temp_dir(), 'mc_gs_in_' . $pIdx . '_') . '.pdf';
                    if (@copy($inP, $tmpIn)) {
                        $tempFilesToClean[] = $tmpIn;
                        $gsInputs[] = $tmpIn;
                    }
                }
                $cmd = array_merge([$gsBin, '-dBATCH', '-dNOPAUSE', '-dSAFER', '-q', '-sDEVICE=pdfwrite', '-sOutputFile=' . $outTmp], $gsInputs);
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
        $inTmp  = tempnam(sys_get_temp_dir(), 'mc_pdf_cmp_in_') . '.pdf';
        try {
            @copy($srcPath, $inTmp);
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
                $inTmp,
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
            if (is_file($inTmp)) {
                @unlink($inTmp);
            }
        }
    }

    // ------------------------------------------------------------------------
    // 5a. 🔍 AJAX: PDF-Seiten für die interaktive Schwärzungs-Vorschau rendern
    // ------------------------------------------------------------------------
    if ($action === 'render_redact_preview') {
        header('Content-Type: application/json; charset=utf-8');
        $sourceId = (int)($_POST['source_pdf_id'] ?? 0);

        $stmtF = $pdo->prepare("SELECT * FROM files WHERE id = :id AND user_id = :uid AND deleted_at IS NULL AND is_encrypted = 0 AND mime_type = 'application/pdf' LIMIT 1");
        $stmtF->execute([':id' => $sourceId, ':uid' => $userId]);
        $srcRow = $stmtF->fetch();

        if (!$srcRow || $gsBin === null) {
            echo json_encode(['ok' => false, 'error' => 'PDF-Datei nicht gefunden oder Ghostscript nicht verfügbar.']);
            exit;
        }

        $srcPath = UPLOAD_DIR . basename((string)$srcRow['filename']);
        if (!is_file($srcPath)) {
            echo json_encode(['ok' => false, 'error' => 'Quell-PDF auf dem Server nicht gefunden.']);
            exit;
        }

        $tmpDir = sys_get_temp_dir() . '/mc_redact_prev_' . bin2hex(random_bytes(8));
        if (!@mkdir($tmpDir, 0700, true)) {
            echo json_encode(['ok' => false, 'error' => 'Temporäres Arbeitsverzeichnis konnte nicht erstellt werden.']);
            exit;
        }

        try {
            // Eingabe-PDF in $tmpDir kopieren, damit Ubuntu AppArmor (/etc/apparmor.d/gs) den Lesezugriff erlaubt
            $tmpInputPdf = $tmpDir . '/input.pdf';
            if (!@copy($srcPath, $tmpInputPdf)) {
                echo json_encode(['ok' => false, 'error' => 'Temporäre PDF-Kopie konnte nicht angelegt werden.']);
                exit;
            }

            $errOut = null;
            $cmd = [
                $gsBin,
                '-dBATCH',
                '-dNOPAUSE',
                '-dSAFER',
                '-q',
                '-sDEVICE=jpeg',
                '-dJPEGQ=82',
                '-r120',
                '-dFirstPage=1',
                '-dLastPage=25',
                '-sOutputFile=' . $tmpDir . '/page_%03d.jpg',
                $tmpInputPdf,
            ];
            $code = runSafeProcess($cmd, $errOut);
            $pageFiles = glob($tmpDir . '/page_*.jpg') ?: [];
            sort($pageFiles, SORT_NATURAL);

            if ($code !== 0 || empty($pageFiles)) {
                echo json_encode(['ok' => false, 'error' => 'PDF-Seiten konnten nicht als Vorschau gerendert werden.']);
                exit;
            }

            $pagesOut = [];
            foreach ($pageFiles as $idx => $pFile) {
                $sz = @getimagesize($pFile);
                $raw = @file_get_contents($pFile);
                if ($raw === false || !is_array($sz)) {
                    continue;
                }
                $pagesOut[] = [
                    'page'    => $idx + 1,
                    'width'   => (int)$sz[0],
                    'height'  => (int)$sz[1],
                    'dataUri' => 'data:image/jpeg;base64,' . base64_encode($raw),
                ];
            }

            $baseOrig = preg_replace('/\.pdf$/i', '', (string)$srcRow['original_name']) ?: 'Dokument';
            echo json_encode([
                'ok'            => true,
                'pages'         => $pagesOut,
                'suggestedName' => $baseOrig . '_geschwaerzt.pdf',
                'folder'        => (string)$srcRow['folder_path'] !== '' ? (string)$srcRow['folder_path'] : 'Scans',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        } finally {
            foreach (glob($tmpDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($tmpDir);
        }
    }

    // ------------------------------------------------------------------------
    // 5b. ✏️/⬛ PDF bearbeiten, Text einfügen/ändern/löschen, unterschreiben & schwärzen
    // ------------------------------------------------------------------------
    if ($action === 'apply_redactions') {
        $sourceId       = (int)($_POST['source_pdf_id'] ?? 0);
        $redactionsJson = (string)($_POST['redactions_json'] ?? '{}');
        $redactionsMap  = json_decode($redactionsJson, true);
        if (!is_array($redactionsMap)) {
            $redactionsMap = [];
        }

        $stmtF = $pdo->prepare("SELECT * FROM files WHERE id = :id AND user_id = :uid AND deleted_at IS NULL AND is_encrypted = 0 AND mime_type = 'application/pdf' LIMIT 1");
        $stmtF->execute([':id' => $sourceId, ':uid' => $userId]);
        $srcRow = $stmtF->fetch();

        if (!$srcRow || $gsBin === null) {
            setFlash('error', 'Bitte wähle eine gültige PDF-Datei zum Bearbeiten aus.');
            header('Location: pdf_tools.php#tab-redact');
            exit;
        }

        $srcPath = UPLOAD_DIR . basename((string)$srcRow['filename']);
        if (!is_file($srcPath)) {
            setFlash('error', 'Quell-PDF wurde auf dem Server nicht gefunden.');
            header('Location: pdf_tools.php#tab-redact');
            exit;
        }

        if ($outputName === '') {
            $baseOrig = preg_replace('/\.pdf$/i', '', (string)$srcRow['original_name']) ?: 'Dokument';
            $outputName = $baseOrig . '_bearbeitet.pdf';
        }

        $tmpDir = sys_get_temp_dir() . '/mc_redact_apply_' . bin2hex(random_bytes(8));
        if (!@mkdir($tmpDir, 0700, true)) {
            setFlash('error', 'Temporäres Verzeichnis für die PDF-Bearbeitung konnte nicht angelegt werden.');
            header('Location: pdf_tools.php#tab-redact');
            exit;
        }

        try {
            $tmpInputPdf = $tmpDir . '/input.pdf';
            if (!@copy($srcPath, $tmpInputPdf)) {
                setFlash('error', 'Temporäre PDF-Kopie konnte nicht angelegt werden.');
                header('Location: pdf_tools.php#tab-redact');
                exit;
            }

            $errOut = null;
            // Seiten mit scharfen 200 DPI rastern, damit sämtliche Textlayer unter Schwärzungen/Überschreibungen aufgelöst werden
            $cmd = [
                $gsBin,
                '-dBATCH',
                '-dNOPAUSE',
                '-dSAFER',
                '-q',
                '-sDEVICE=jpeg',
                '-dJPEGQ=92',
                '-r200',
                '-dFirstPage=1',
                '-dLastPage=50',
                '-sOutputFile=' . $tmpDir . '/page_%03d.jpg',
                $tmpInputPdf,
            ];
            $code = runSafeProcess($cmd, $errOut);
            $pageFiles = glob($tmpDir . '/page_*.jpg') ?: [];
            sort($pageFiles, SORT_NATURAL);

            if ($code !== 0 || empty($pageFiles)) {
                setFlash('error', 'PDF-Seiten konnten für die Bearbeitung nicht verarbeitet werden.');
                header('Location: pdf_tools.php#tab-redact');
                exit;
            }

            $fontPaths = [
                'sans' => '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
                'bold' => '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
                'mono' => '/usr/share/fonts/truetype/dejavu/DejaVuSansMono.ttf',
            ];

            $finalPages = [];
            $totalEditsApplied = 0;

            foreach ($pageFiles as $idx => $pFile) {
                $pageNum = (string)($idx + 1);
                $boxes = isset($redactionsMap[$pageNum]) && is_array($redactionsMap[$pageNum])
                    ? $redactionsMap[$pageNum]
                    : [];

                $im = @imagecreatefromjpeg($pFile);
                if ($im === false) {
                    continue;
                }
                imagealphablending($im, true);
                $w = imagesx($im);
                $h = imagesy($im);

                if (!empty($boxes)) {
                    $colBlack = imagecolorallocate($im, 0, 0, 0);
                    $colWhite = imagecolorallocate($im, 255, 255, 255);
                    $colBlue  = imagecolorallocate($im, 29, 78, 216);
                    $colRed   = imagecolorallocate($im, 185, 28, 28);

                    foreach ($boxes as $b) {
                        if (!is_array($b)) {
                            continue;
                        }
                        $itemType = (string)($b['type'] ?? 'redact');
                        $nx = max(0.0, min(1.0, (float)($b['x'] ?? 0.0)));
                        $ny = max(0.0, min(1.0, (float)($b['y'] ?? 0.0)));
                        $nw = max(0.0, min(1.0 - $nx, (float)($b['w'] ?? 0.0)));
                        $nh = max(0.0, min(1.0 - $ny, (float)($b['h'] ?? 0.0)));

                        $x1 = (int)floor($nx * $w);
                        $y1 = (int)floor($ny * $h);
                        $x2 = (int)ceil(($nx + $nw) * $w);
                        $y2 = (int)ceil(($ny + $nh) * $h);

                        // 1. Hintergrund-Rechteck zeichnen (bei Schwärzung, Deckweiß/Löschen oder Text-Überschreiben)
                        $bgMode = (string)($b['color'] ?? 'black');
                        if ($itemType === 'redact' || $bgMode === 'black' || $bgMode === 'white') {
                            if ($x2 > $x1 && $y2 > $y1 && $bgMode !== 'transparent') {
                                $fillCol = ($bgMode === 'white') ? $colWhite : $colBlack;
                                imagefilledrectangle($im, $x1, $y1, $x2, $y2, $fillCol);
                                $totalEditsApplied++;
                            }
                        }

                        // 2. Freihand-Unterschrift (PNG Data-URI) einzeichnen
                        if ($itemType === 'signature' && !empty($b['sigDataUri'])) {
                            $sigUri = (string)$b['sigDataUri'];
                            if (str_starts_with($sigUri, 'data:image/png;base64,')) {
                                $pngBin = base64_decode(substr($sigUri, 22), true);
                                if (is_string($pngBin) && $pngBin !== '') {
                                    $sigIm = @imagecreatefromstring($pngBin);
                                    if ($sigIm !== false) {
                                        $sw = imagesx($sigIm);
                                        $sh = imagesy($sigIm);
                                        $dstW = max(20, $x2 - $x1);
                                        $dstH = max(12, $y2 - $y1);
                                        imagecopyresampled($im, $sigIm, $x1, $y1, 0, 0, $dstW, $dstH, $sw, $sh);
                                        imagedestroy($sigIm);
                                        $totalEditsApplied++;
                                    }
                                }
                            }
                            continue;
                        }

                        // 3. Text einzeichnen (für "Text hinzufügen" & "Text ändern/überschreiben")
                        $textVal = trim((string)($b['text'] ?? ''));
                        if ($textVal !== '') {
                            $textColKey = (string)($b['textColor'] ?? 'black');
                            $textCol = $colBlack;
                            if ($textColKey === 'blue') {
                                $textCol = $colBlue;
                            } elseif ($textColKey === 'red') {
                                $textCol = $colRed;
                            } elseif ($textColKey === 'white') {
                                $textCol = $colWhite;
                            }

                            $fontKey  = (string)($b['fontStyle'] ?? 'sans');
                            $fontFile = $fontPaths[$fontKey] ?? $fontPaths['sans'];
                            // Relative Schriftgröße (bezogen auf Seitenhöhe, Standard ~0.016)
                            $relSize  = max(0.008, min(0.08, (float)($b['fontSizeRel'] ?? 0.016)));
                            $ptSize   = max(8.0, $relSize * $h * 0.76);
                            $lineStep = (int)round($ptSize * 1.42);

                            $lines = preg_split('/\r\n|\r|\n/', $textVal) ?: [$textVal];
                            $curY  = $y1 + (int)round($ptSize * 1.12) + 4;
                            $padX  = $x1 + 6;

                            if (function_exists('imagettftext') && is_file($fontFile)) {
                                foreach ($lines as $ln) {
                                    imagettftext($im, $ptSize, 0.0, $padX, $curY, $textCol, $fontFile, $ln);
                                    $curY += $lineStep;
                                }
                            } else {
                                foreach ($lines as $ln) {
                                    imagestring($im, 5, $padX, $curY - 14, $ln, $textCol);
                                    $curY += 18;
                                }
                            }
                            $totalEditsApplied++;
                        }
                    }
                }

                ob_start();
                imagejpeg($im, null, 90);
                $jpegBytes = (string)ob_get_clean();
                imagedestroy($im);

                if ($jpegBytes !== '') {
                    $finalPages[] = [
                        'jpeg_bytes' => $jpegBytes,
                        'width'      => $w,
                        'height'     => $h,
                    ];
                }
            }

            if (empty($finalPages)) {
                setFlash('error', 'Bearbeitetes PDF konnte nicht erzeugt werden.');
                header('Location: pdf_tools.php#tab-redact');
                exit;
            }

            // Neues PDF zusammenbauen
            $pdfBytes = buildNativePdfFromJpegPages($finalPages, 'original');
            $saved = saveGeneratedPdfToCloud($pdo, $userId, $username, $pdfBytes, $outputName, $targetFolder);

            if (!$saved['ok']) {
                setFlash('error', (string)$saved['error']);
                header('Location: pdf_tools.php#tab-redact');
                exit;
            }

            setFlash(
                'success',
                sprintf(
                    '✅ PDF „%s“ (%d Bearbeitungen/Schwärzungen fest eingebrannt) wurde im Ordner „/%s“ gespeichert!',
                    $saved['filename'],
                    $totalEditsApplied,
                    $saved['folder']
                )
            );
            header('Location: pdf_tools.php?created=' . (int)$saved['file_id'] . '#tab-redact');
            exit;
        } finally {
            foreach (glob($tmpDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($tmpDir);
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
        <button type="button" data-pdf-tab-btn="tab-redact" onclick="switchPdfTab('tab-redact')"
                class="px-4 py-2.5 rounded-xl text-xs sm:text-sm font-bold transition-colors cursor-pointer bg-white dark:bg-slate-900 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-800">
            ✏️ 5. PDF bearbeiten, ausfüllen &amp; schwärzen
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

                    <!-- Verstecktes Kamera-Input (ohne "multiple" + mit capture="environment", damit Android/iOS sofort die Rückkamera öffnet) -->
                    <input
                        type="file"
                        id="scanCameraInput"
                        accept="image/*"
                        capture="environment"
                        onchange="previewSelectedScanImages(this, true)"
                        class="hidden"
                    >

                    <!-- Haupt-Input für das Formular (wird per DataTransfer mit allen Kamera- & Galerie-Bildern befüllt) -->
                    <input
                        type="file"
                        name="scan_images[]"
                        id="scanImagesInput"
                        accept="image/*"
                        multiple
                        onchange="previewSelectedScanImages(this, false)"
                        class="hidden"
                    >

                    <div class="flex flex-wrap items-center gap-2.5">
                        <button
                            type="button"
                            onclick="document.getElementById('scanCameraInput').click()"
                            class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs sm:text-sm font-bold shadow-xs transition-colors cursor-pointer"
                        >
                            <span>📸 Kamera starten (Seite abfotografieren)</span>
                        </button>

                        <button
                            type="button"
                            onclick="document.getElementById('scanImagesInput').click()"
                            class="inline-flex items-center gap-2 px-3.5 py-2.5 rounded-xl bg-white dark:bg-slate-900 hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-800 dark:text-slate-200 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm font-semibold transition-colors cursor-pointer"
                        >
                            <span>🖼️ Aus Galerie / Dateien wählen</span>
                        </button>
                    </div>

                    <p class="text-[11px] text-slate-500 dark:text-slate-400">
                        💡 Tipp: Drücke für jede Dokumentenseite einfach erneut auf <strong>„📸 Kamera starten“</strong> – alle abfotografierten Seiten werden unten nacheinander gesammelt!
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
                            📤 Optional: Weitere PDFs vom Gerät nacheinander hinzufügen
                        </label>
                        <input type="file" name="upload_pdfs[]" id="mergePdfsInput" accept="application/pdf,.pdf" multiple
                               onchange="addSelectedMergePdfs(this)"
                               class="block w-full text-xs text-slate-600 dark:text-slate-300 file:mr-3 file:py-2 file:px-3.5 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-brand-600 file:text-white hover:file:bg-brand-700 cursor-pointer">
                        <p class="text-[11px] text-slate-500 dark:text-slate-400">
                            💡 Du kannst mehrfach nacheinander auf „Dateien auswählen“ klicken – jede neu gewählte PDF wird zur Liste unten hinzugefügt.
                        </p>
                        <div id="mergePreviewStrip" class="hidden pt-2 space-y-1.5"></div>
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

    <!-- =================================================================== -->
    <!-- REITER 5: PDF-EDITOR (TEXT EINFÜGEN/ÄNDERN/LÖSCHEN, UNTERSCHRIFT & SCHWÄRZEN) -->
    <!-- =================================================================== -->
    <section id="tab-redact" data-pdf-tab-panel="tab-redact" class="hidden mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-5">
        <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-3">
            <div>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">✏️ Interaktiver PDF-Editor: Text hinzufügen, ändern, löschen, unterschreiben &amp; schwärzen</h2>
                <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-0.5">
                    Fülle Formulare aus (<strong>🔤 Text hinzufügen</strong>), überschreibe bestehenden Text (<strong>✏️ Text ändern</strong>), entferne Stellen per <strong>🧹 Deckweiß</strong>, setze deine <strong>✍️ Freihand-Unterschrift</strong> oder schwärze vertrauliche Daten <strong>⬛ DSGVO-sicher</strong>. Alle Änderungen lassen sich frei auf der Seite verschieben und werden beim Speichern fest in das neue PDF eingebrannt!
                </p>
            </div>
            <span class="px-2.5 py-1 rounded-full bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300 text-[11px] font-bold shrink-0">
                🛡️ Echte Pixel-Einbrennung (200 DPI)
            </span>
        </div>

        <!-- Schritt 1: PDF auswählen & Vorschau laden -->
        <div class="grid grid-cols-1 sm:grid-cols-12 gap-3 items-end p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700">
            <div class="sm:col-span-8">
                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">1. PDF-Datei aus deiner Cloud auswählen</label>
                <select id="redactPdfSelect" class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white">
                    <option value="">– Bitte PDF zum Bearbeiten auswählen –</option>
                    <?php foreach ($myPdfs as $pdf): ?>
                        <option value="<?= (int)$pdf['id'] ?>">📄 <?= e((string)$pdf['original_name']) ?> (<?= e(formatBytes((int)$pdf['file_size'])) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="sm:col-span-4">
                <button type="button" id="btnLoadRedactPreview" onclick="loadRedactPdfPreview()"
                        class="w-full px-4 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-bold text-xs sm:text-sm shadow-xs transition-colors cursor-pointer">
                    🔍 PDF-Seiten im Editor öffnen
                </button>
            </div>
        </div>

        <div id="redactLoadingBox" class="hidden p-8 rounded-2xl bg-slate-50 dark:bg-slate-800/40 border border-slate-200 dark:border-slate-700 text-center text-xs sm:text-sm font-semibold text-slate-600 dark:text-slate-300">
            ⏳ PDF-Seiten werden für den Editor aufbereitet...
        </div>

        <!-- Schritt 2: Interaktiver PDF-Editor-Arbeitsbereich -->
        <form id="redactApplyForm" action="pdf_tools.php" method="post" class="hidden space-y-5" onsubmit="return prepareRedactionSubmit()">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="apply_redactions">
            <input type="hidden" name="source_pdf_id" id="redactFormSourceId" value="">
            <input type="hidden" name="redactions_json" id="redactFormJson" value="{}">

            <!-- Obere Werkzeugleiste des PDF-Editors -->
            <div class="sticky top-16 z-30 p-3.5 rounded-2xl bg-white/95 dark:bg-slate-900/95 backdrop-blur-xs border border-slate-300 dark:border-slate-700 shadow-md space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span class="text-xs font-bold text-slate-700 dark:text-slate-300 mr-1">Werkzeug:</span>

                        <button type="button" data-editor-tool="add_text" onclick="setPdfEditorTool('add_text')"
                                class="px-3 py-1.5 rounded-xl bg-brand-600 text-white text-xs font-bold ring-2 ring-brand-500 cursor-pointer">
                            🔤 Text hinzufügen
                        </button>

                        <button type="button" data-editor-tool="replace_text" onclick="setPdfEditorTool('replace_text')"
                                class="px-3 py-1.5 rounded-xl bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 border border-slate-300 dark:border-slate-700 text-xs font-bold cursor-pointer">
                            ✏️ Text ändern (Überschreiben)
                        </button>

                        <button type="button" data-editor-tool="erase" onclick="setPdfEditorTool('erase')"
                                class="px-3 py-1.5 rounded-xl bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 border border-slate-300 dark:border-slate-700 text-xs font-bold cursor-pointer">
                            🧹 Text löschen (Deckweiß)
                        </button>

                        <button type="button" data-editor-tool="redact" onclick="setPdfEditorTool('redact')"
                                class="px-3 py-1.5 rounded-xl bg-slate-900 text-white text-xs font-bold cursor-pointer">
                            ⬛ Schwärzen (DSGVO)
                        </button>

                        <button type="button" onclick="openSignaturePadModal()"
                                class="px-3 py-1.5 rounded-xl bg-indigo-50 dark:bg-indigo-950/70 text-indigo-700 dark:text-indigo-300 border border-indigo-300 dark:border-indigo-700 text-xs font-bold cursor-pointer">
                            ✍️ Unterschrift einfügen
                        </button>
                    </div>

                    <div class="flex items-center gap-1.5">
                        <button type="button" onclick="undoLastRedactionBox()"
                                class="px-2.5 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 text-slate-700 dark:text-slate-200 text-xs font-semibold cursor-pointer"
                                title="Letzte Aktion rückgängig">
                            ↩️ Rückgängig
                        </button>
                        <button type="button" onclick="clearAllRedactionBoxes()"
                                class="px-2.5 py-1.5 rounded-xl bg-rose-50 dark:bg-rose-950/60 hover:bg-rose-100 text-rose-700 dark:text-rose-300 text-xs font-semibold cursor-pointer"
                                title="Alle Elemente auf allen Seiten entfernen">
                            🗑️ Alles leeren
                        </button>
                        <span id="redactBoxCounter" class="px-2.5 py-1 rounded-lg bg-slate-100 dark:bg-slate-800 text-xs font-bold text-slate-700 dark:text-slate-200">
                            0 Elemente
                        </span>
                    </div>
                </div>

                <!-- Zusatz-Leiste für Schriftgröße, Schriftart, Farbe & Schnell-Stempel -->
                <div class="flex flex-wrap items-center justify-between gap-2 pt-2 border-t border-slate-200/80 dark:border-slate-800 text-xs">
                    <div class="flex flex-wrap items-center gap-2">
                        <label class="inline-flex items-center gap-1 font-semibold text-slate-600 dark:text-slate-300">
                            <span>Größe:</span>
                            <select id="pdfEditorFontSize" class="px-2 py-1 rounded-lg bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs">
                                <option value="0.012">Klein (10pt)</option>
                                <option value="0.016" selected>Normal (12pt)</option>
                                <option value="0.022">Groß (16pt)</option>
                                <option value="0.030">Überschrift (22pt)</option>
                            </select>
                        </label>

                        <label class="inline-flex items-center gap-1 font-semibold text-slate-600 dark:text-slate-300">
                            <span>Schrift:</span>
                            <select id="pdfEditorFontStyle" class="px-2 py-1 rounded-lg bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs">
                                <option value="sans" selected>Standard (Sans)</option>
                                <option value="bold">Fett (Bold)</option>
                                <option value="mono">Schreibmaschine (Mono)</option>
                            </select>
                        </label>

                        <label class="inline-flex items-center gap-1 font-semibold text-slate-600 dark:text-slate-300">
                            <span>Textfarbe:</span>
                            <select id="pdfEditorTextColor" class="px-2 py-1 rounded-lg bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs">
                                <option value="black" selected>⚫ Schwarz</option>
                                <option value="blue">🔵 Dunkelblau (Füller)</option>
                                <option value="red">🔴 Rot</option>
                            </select>
                        </label>
                    </div>

                    <div class="flex flex-wrap items-center gap-1.5">
                        <span class="text-[11px] font-semibold text-slate-500">Schnell-Stempel:</span>
                        <button type="button" onclick="insertQuickStamp('✓')" class="px-2 py-1 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 text-xs font-bold cursor-pointer" title="Häkchen für Ankreuzfelder">✓ Häkchen</button>
                        <button type="button" onclick="insertQuickStamp('✗')" class="px-2 py-1 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 text-xs font-bold cursor-pointer" title="Kreuz für Ankreuzfelder">✗ Kreuz</button>
                        <button type="button" onclick="insertQuickStamp('<?= date('d.m.Y') ?>')" class="px-2 py-1 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 text-xs font-semibold cursor-pointer">📅 <?= date('d.m.Y') ?></button>
                    </div>
                </div>
            </div>

            <p id="pdfEditorHintText" class="text-xs text-slate-600 dark:text-slate-300 bg-brand-50/70 dark:bg-brand-950/30 border border-brand-200 dark:border-brand-800 px-3.5 py-2 rounded-xl">
                💡 <strong>🔤 Modus „Text hinzufügen“:</strong> Klicke oder ziehe an die gewünschte Stelle auf der PDF-Seite, um neuen Text einzutragen. Du kannst eingefügte Elemente jederzeit mit der Maus / dem Finger verschieben oder per <strong>✏️</strong> nachträglich ändern!
            </p>

            <!-- Seiten-Container -->
            <div id="redactPagesContainer" class="space-y-6 bg-slate-100 dark:bg-slate-950 p-3 sm:p-6 rounded-2xl border border-slate-200 dark:border-slate-800 max-h-[75vh] overflow-y-auto"></div>

            <!-- Zielordner, Dateiname & Speichern -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 items-end pt-2">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Zielordner in der Cloud</label>
                    <input type="text" name="target_folder" id="redactTargetFolder" value="Scans"
                           class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Dateiname des bearbeiteten PDFs</label>
                    <input type="text" name="output_name" id="redactOutputName" placeholder="Dokument_bearbeitet.pdf"
                           class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white">
                </div>
                <div>
                    <button type="submit" class="w-full px-5 py-2.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-bold text-xs sm:text-sm shadow-sm transition-colors cursor-pointer">
                        💾 PDF-Änderungen anwenden &amp; speichern
                    </button>
                </div>
            </div>
        </form>
    </section>
</div>

<!-- MODAL: TEXT EINGEBEN / BEARBEITEN -->
<div id="pdfTextEditModal" class="fixed inset-0 z-50 hidden items-center justify-center p-3 sm:p-4 bg-slate-900/60 backdrop-blur-xs">
    <div class="mc-card bg-white dark:bg-slate-900 w-full max-w-md rounded-2xl border border-slate-200 dark:border-slate-700 shadow-2xl overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
            <h3 id="pdfTextModalTitle" class="text-base font-bold text-slate-900 dark:text-white">🔤 Text in PDF einfügen</h3>
            <button type="button" onclick="closePdfTextModal()" class="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 cursor-pointer">✕</button>
        </div>
        <div class="p-5 space-y-3.5 text-xs sm:text-sm">
            <div>
                <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Text (ein- oder mehrzeilig)</label>
                <textarea id="pdfTextModalInput" rows="3" placeholder="Gewünschten Text hier eingeben..." class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500"></textarea>
            </div>
            <div class="grid grid-cols-3 gap-2">
                <div>
                    <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Schriftgröße</label>
                    <select id="pdfTextModalSize" class="w-full px-2.5 py-1.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700">
                        <option value="0.012">Klein</option>
                        <option value="0.016">Normal</option>
                        <option value="0.022">Groß</option>
                        <option value="0.030">Sehr groß</option>
                    </select>
                </div>
                <div>
                    <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Stil</label>
                    <select id="pdfTextModalStyle" class="w-full px-2.5 py-1.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700">
                        <option value="sans">Standard</option>
                        <option value="bold">Fett</option>
                        <option value="mono">Mono</option>
                    </select>
                </div>
                <div>
                    <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Farbe</label>
                    <select id="pdfTextModalColor" class="w-full px-2.5 py-1.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700">
                        <option value="black">⚫ Schwarz</option>
                        <option value="blue">🔵 Blau</option>
                        <option value="red">🔴 Rot</option>
                    </select>
                </div>
            </div>
            <div>
                <label class="inline-flex items-center gap-2 text-xs text-slate-700 dark:text-slate-300 cursor-pointer">
                    <input type="checkbox" id="pdfTextModalWhiteBg" class="rounded border-slate-300 text-brand-600">
                    <span>⬜ Weißer Hintergrund (überdeckt vorhandenen Text darunter komplett)</span>
                </label>
            </div>
            <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-200 dark:border-slate-800">
                <button type="button" onclick="closePdfTextModal()" class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-semibold cursor-pointer">Abbrechen</button>
                <button type="button" onclick="savePdfTextModal()" class="px-5 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold cursor-pointer">Übernehmen</button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: FREIHAND-UNTERSCHRIFT ZEICHNEN -->
<div id="pdfSignatureModal" class="fixed inset-0 z-50 hidden items-center justify-center p-3 sm:p-4 bg-slate-900/60 backdrop-blur-xs">
    <div class="mc-card bg-white dark:bg-slate-900 w-full max-w-md rounded-2xl border border-slate-200 dark:border-slate-700 shadow-2xl overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
            <h3 class="text-base font-bold text-slate-900 dark:text-white">✍️ Freihand-Unterschrift zeichnen</h3>
            <button type="button" onclick="closeSignaturePadModal()" class="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 cursor-pointer">✕</button>
        </div>
        <div class="p-5 space-y-3 text-xs sm:text-sm">
            <p class="text-xs text-slate-500">Unterschreibe hier mit der Maus oder dem Finger – anschließend kannst du die Unterschrift frei auf der PDF-Seite verschieben:</p>
            <div class="border-2 border-dashed border-slate-300 dark:border-slate-700 rounded-xl bg-white overflow-hidden">
                <canvas id="sigPadCanvas" width="380" height="140" class="w-full h-[140px] block cursor-crosshair" style="touch-action:none;"></canvas>
            </div>
            <div class="flex items-center justify-between pt-2">
                <button type="button" onclick="clearSigPadCanvas()" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-semibold cursor-pointer">🧹 Feld leeren</button>
                <div class="flex items-center gap-2">
                    <button type="button" onclick="closeSignaturePadModal()" class="px-3.5 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-semibold cursor-pointer">Abbrechen</button>
                    <button type="button" onclick="insertSignatureFromPad()" class="px-4 py-1.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold cursor-pointer">✍️ Auf PDF setzen</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const PDF_CSRF_TOKEN = <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
let currentEditorTool  = 'add_text'; // 'add_text' | 'replace_text' | 'erase' | 'redact'
let redactionsByPage   = {}; // { "1": [{type, x, y, w, h, color, text, fontSizeRel, fontStyle, textColor, sigDataUri}], ... }
let redactionHistory   = [];
let activeTextEditMeta = null; // { pageNum, idx, isNew, draftBox }

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

function setPdfEditorTool(tool) {
    currentEditorTool = tool;
    document.querySelectorAll('[data-editor-tool]').forEach(btn => {
        const isAct = btn.getAttribute('data-editor-tool') === tool;
        btn.classList.toggle('ring-2', isAct);
        btn.classList.toggle('ring-brand-500', isAct);
        if (btn.getAttribute('data-editor-tool') !== 'redact') {
            if (isAct) {
                btn.classList.add('bg-brand-600', 'text-white');
                btn.classList.remove('bg-white', 'dark:bg-slate-800', 'text-slate-800', 'dark:text-slate-100');
            } else {
                btn.classList.remove('bg-brand-600', 'text-white');
                btn.classList.add('bg-white', 'dark:bg-slate-800', 'text-slate-800', 'dark:text-slate-100');
            }
        }
    });

    const hintEl = document.getElementById('pdfEditorHintText');
    if (hintEl) {
        if (tool === 'add_text') {
            hintEl.innerHTML = '💡 <strong>🔤 Modus „Text hinzufügen“:</strong> Klicke oder ziehe an die gewünschte Stelle auf der PDF-Seite, um neuen Text (z. B. zum Ausfüllen von Formularen) einzutragen.';
        } else if (tool === 'replace_text') {
            hintEl.innerHTML = '💡 <strong>✏️ Modus „Text ändern (Überschreiben)“:</strong> Ziehe einen Rahmen direkt über das alte Wort oder Datum – der alte Text wird weiß überdeckt und durch deinen neuen Text ersetzt!';
        } else if (tool === 'erase') {
            hintEl.innerHTML = '💡 <strong>🧹 Modus „Text löschen (Deckweiß)“:</strong> Ziehe einen Rahmen über Text oder Elemente, die weiß überdeckt und restlos gelöscht werden sollen.';
        } else {
            hintEl.innerHTML = '💡 <strong>⬛ Modus „Schwärzen (DSGVO)“:</strong> Ziehe schwarze Balken über vertrauliche Daten. Beim Speichern wird der Text darunter physisch aus dem PDF entfernt.';
        }
    }
}

function updateRedactCounter() {
    let total = 0;
    Object.values(redactionsByPage).forEach(arr => {
        if (Array.isArray(arr)) total += arr.length;
    });
    const el = document.getElementById('redactBoxCounter');
    if (el) {
        el.textContent = `${total} ${total === 1 ? 'Element' : 'Elemente'}`;
    }
}

async function loadRedactPdfPreview() {
    const sel = document.getElementById('redactPdfSelect');
    const pdfId = sel ? parseInt(sel.value, 10) : 0;
    if (!pdfId) {
        alert('Bitte wähle zuerst eine PDF-Datei aus deiner Cloud aus.');
        return;
    }

    const loadingBox = document.getElementById('redactLoadingBox');
    const formEl     = document.getElementById('redactApplyForm');
    const container  = document.getElementById('redactPagesContainer');
    const btn        = document.getElementById('btnLoadRedactPreview');

    if (loadingBox) loadingBox.classList.remove('hidden');
    if (formEl) formEl.classList.add('hidden');
    if (btn) btn.disabled = true;

    const fd = new FormData();
    fd.append('csrf_token', PDF_CSRF_TOKEN);
    fd.append('action', 'render_redact_preview');
    fd.append('source_pdf_id', String(pdfId));

    try {
        const resp = await fetch('pdf_tools.php', { method: 'POST', body: fd });
        const data = await resp.json();
        if (!data || !data.ok || !Array.isArray(data.pages)) {
            alert(data?.error || 'PDF-Vorschau konnte nicht geladen werden.');
            return;
        }

        redactionsByPage = {};
        redactionHistory = [];
        updateRedactCounter();

        document.getElementById('redactFormSourceId').value = String(pdfId);
        const suggested = (data.suggestedName || 'Dokument.pdf').replace(/_geschwaerzt\.pdf$/i, '_bearbeitet.pdf');
        document.getElementById('redactOutputName').value   = suggested;
        document.getElementById('redactTargetFolder').value = data.folder || 'Scans';

        container.innerHTML = '';
        data.pages.forEach(p => {
            const pageNum = String(p.page);
            redactionsByPage[pageNum] = [];

            const wrap = document.createElement('div');
            wrap.className = 'space-y-2';

            const header = document.createElement('div');
            header.className = 'flex items-center justify-between text-xs font-bold text-slate-600 dark:text-slate-300 max-w-3xl mx-auto';
            header.innerHTML = `<span>📄 Seite ${pageNum}</span><span class="text-[11px] font-normal text-slate-400">Klicken/Ziehen zum Einfügen · Elemente per Drag &amp; Drop verschiebbar</span>`;

            const stage = document.createElement('div');
            stage.className = 'relative max-w-3xl mx-auto bg-white shadow-md rounded-lg overflow-hidden select-none cursor-crosshair border border-slate-300 dark:border-slate-700';
            stage.style.touchAction = 'none';
            stage.setAttribute('data-redact-page', pageNum);

            const img = document.createElement('img');
            img.src = p.dataUri;
            img.alt = 'Seite ' + pageNum;
            img.draggable = false;
            img.className = 'w-full h-auto block pointer-events-none select-none';

            const overlay = document.createElement('div');
            overlay.className = 'absolute inset-0';
            overlay.setAttribute('data-redact-overlay', pageNum);

            stage.appendChild(img);
            stage.appendChild(overlay);
            wrap.appendChild(header);
            wrap.appendChild(stage);
            container.appendChild(wrap);

            attachRedactionDrawingHandlers(stage, overlay, pageNum);
        });

        if (formEl) formEl.classList.remove('hidden');
    } catch (e) {
        alert('Netzwerkfehler beim Laden der PDF-Seiten.');
    } finally {
        if (loadingBox) loadingBox.classList.add('hidden');
        if (btn) btn.disabled = false;
    }
}

function attachRedactionDrawingHandlers(stage, overlay, pageNum) {
    let isDrawing = false;
    let startX = 0;
    let startY = 0;
    let draftBoxEl = null;

    const getRelCoords = (clientX, clientY) => {
        const rect = stage.getBoundingClientRect();
        const x = Math.max(0, Math.min(1, (clientX - rect.left) / Math.max(1, rect.width)));
        const y = Math.max(0, Math.min(1, (clientY - rect.top) / Math.max(1, rect.height)));
        return { x, y };
    };

    const onStart = (clientX, clientY, target) => {
        if (target && target.closest('[data-editor-item]')) return;
        isDrawing = true;
        const pt = getRelCoords(clientX, clientY);
        startX = pt.x;
        startY = pt.y;

        draftBoxEl = document.createElement('div');
        draftBoxEl.className = 'absolute pointer-events-none border-2 border-brand-500';
        if (currentEditorTool === 'redact') {
            draftBoxEl.style.backgroundColor = '#000000';
            draftBoxEl.style.opacity = '0.85';
        } else if (currentEditorTool === 'erase' || currentEditorTool === 'replace_text') {
            draftBoxEl.style.backgroundColor = '#ffffff';
            draftBoxEl.style.opacity = '0.92';
        } else {
            draftBoxEl.style.backgroundColor = 'rgba(37, 99, 235, 0.08)';
        }
        overlay.appendChild(draftBoxEl);
    };

    const onMove = (clientX, clientY) => {
        if (!isDrawing || !draftBoxEl) return;
        const pt = getRelCoords(clientX, clientY);
        const x = Math.min(startX, pt.x);
        const y = Math.min(startY, pt.y);
        const w = Math.abs(pt.x - startX);
        const h = Math.abs(pt.y - startY);
        draftBoxEl.style.left   = (x * 100) + '%';
        draftBoxEl.style.top    = (y * 100) + '%';
        draftBoxEl.style.width  = (w * 100) + '%';
        draftBoxEl.style.height = (h * 100) + '%';
    };

    const onEnd = (clientX, clientY) => {
        if (!isDrawing) return;
        isDrawing = false;
        if (draftBoxEl) {
            draftBoxEl.remove();
            draftBoxEl = null;
        }
        const pt = getRelCoords(clientX, clientY);
        let x = Math.min(startX, pt.x);
        let y = Math.min(startY, pt.y);
        let w = Math.abs(pt.x - startX);
        let h = Math.abs(pt.y - startY);

        if (!Array.isArray(redactionsByPage[pageNum])) {
            redactionsByPage[pageNum] = [];
        }

        // Im Modus "Text hinzufügen" reicht auch ein einfacher Klick ohne Ziehen
        if (currentEditorTool === 'add_text' || currentEditorTool === 'replace_text') {
            if (w < 0.03) w = 0.24;
            if (h < 0.015) h = 0.035;
            if (x + w > 0.98) x = Math.max(0, 0.98 - w);

            const defaultSize  = parseFloat(document.getElementById('pdfEditorFontSize')?.value || '0.016');
            const defaultStyle = document.getElementById('pdfEditorFontStyle')?.value || 'sans';
            const defaultColor = document.getElementById('pdfEditorTextColor')?.value || 'black';

            openPdfTextModal({
                pageNum,
                idx: -1,
                item: {
                    type: currentEditorTool,
                    x,
                    y,
                    w,
                    h,
                    color: currentEditorTool === 'replace_text' ? 'white' : 'transparent',
                    text: '',
                    fontSizeRel: defaultSize,
                    fontStyle: defaultStyle,
                    textColor: defaultColor
                }
            });
            return;
        }

        if (w < 0.008 || h < 0.005) return;

        redactionsByPage[pageNum].push({
            type: 'redact',
            x,
            y,
            w,
            h,
            color: currentEditorTool === 'erase' ? 'white' : 'black'
        });
        redactionHistory.push(pageNum);
        renderPageRedactionBoxes(pageNum);
        updateRedactCounter();
    };

    stage.addEventListener('mousedown', e => {
        if (e.button !== 0) return;
        if (e.target && e.target.closest('[data-editor-item]')) return;
        e.preventDefault();
        onStart(e.clientX, e.clientY, e.target);
    });
    window.addEventListener('mousemove', e => {
        if (isDrawing) onMove(e.clientX, e.clientY);
    });
    window.addEventListener('mouseup', e => {
        if (isDrawing) onEnd(e.clientX, e.clientY);
    });

    stage.addEventListener('touchstart', e => {
        if (e.touches.length !== 1) return;
        if (e.target && e.target.closest('[data-editor-item]')) return;
        e.preventDefault();
        const t = e.touches[0];
        onStart(t.clientX, t.clientY, e.target);
    }, { passive: false });
    stage.addEventListener('touchmove', e => {
        if (!isDrawing || e.touches.length !== 1) return;
        e.preventDefault();
        const t = e.touches[0];
        onMove(t.clientX, t.clientY);
    }, { passive: false });
    stage.addEventListener('touchend', e => {
        if (!isDrawing) return;
        const t = e.changedTouches[0];
        if (t) onEnd(t.clientX, t.clientY);
    });
}

function renderPageRedactionBoxes(pageNum) {
    const overlay = document.querySelector(`[data-redact-overlay="${pageNum}"]`);
    const stage   = document.querySelector(`[data-redact-page="${pageNum}"]`);
    if (!overlay || !stage) return;
    overlay.innerHTML = '';
    const boxes = redactionsByPage[pageNum] || [];
    const stageH = Math.max(300, stage.clientHeight || 600);

    boxes.forEach((b, idx) => {
        const el = document.createElement('div');
        el.setAttribute('data-editor-item', '1');
        el.className = 'absolute group cursor-move select-none';
        el.style.left   = (b.x * 100) + '%';
        el.style.top    = (b.y * 100) + '%';
        el.style.width  = (b.w * 100) + '%';
        el.style.height = (b.h * 100) + '%';

        if (b.color === 'black') {
            el.style.backgroundColor = '#000000';
            el.style.border = '1px solid #000000';
        } else if (b.color === 'white') {
            el.style.backgroundColor = '#ffffff';
            el.style.border = '1px dashed #64748b';
        } else {
            el.style.backgroundColor = 'transparent';
            el.style.border = '1px dashed rgba(37, 99, 235, 0.55)';
        }

        // Inhalt darstellen: Unterschrift oder Text
        if (b.type === 'signature' && b.sigDataUri) {
            const sigImg = document.createElement('img');
            sigImg.src = b.sigDataUri;
            sigImg.className = 'w-full h-full object-contain pointer-events-none';
            el.appendChild(sigImg);
        } else if (b.text) {
            const txtEl = document.createElement('div');
            txtEl.className = 'w-full h-full px-1 py-0.5 whitespace-pre-line leading-tight pointer-events-none overflow-visible';
            const pxFont = Math.max(9, Math.round((b.fontSizeRel || 0.016) * stageH));
            txtEl.style.fontSize = pxFont + 'px';
            txtEl.style.fontWeight = (b.fontStyle === 'bold') ? '700' : '500';
            txtEl.style.fontFamily = (b.fontStyle === 'mono') ? 'monospace' : 'sans-serif';
            txtEl.style.color = b.textColor === 'blue' ? '#1d4ed8' : (b.textColor === 'red' ? '#b91c1c' : '#000000');
            txtEl.textContent = b.text;
            el.appendChild(txtEl);

            el.ondblclick = (ev) => {
                ev.stopPropagation();
                openPdfTextModal({ pageNum, idx, item: b });
            };
        }

        // Aktions-Buttons oben rechts am Element (Bearbeiten & Löschen)
        const actionsWrap = document.createElement('div');
        actionsWrap.className = 'absolute -top-3 -right-2 flex items-center gap-1 z-20';

        if (b.text !== undefined && b.type !== 'signature') {
            const editBtn = document.createElement('button');
            editBtn.type = 'button';
            editBtn.title = 'Text bearbeiten';
            editBtn.className = 'w-5 h-5 rounded-full bg-brand-600 text-white text-[10px] font-bold flex items-center justify-center shadow-xs hover:scale-110 cursor-pointer';
            editBtn.textContent = '✏️';
            editBtn.onclick = (ev) => {
                ev.stopPropagation();
                openPdfTextModal({ pageNum, idx, item: b });
            };
            actionsWrap.appendChild(editBtn);
        }

        const delBtn = document.createElement('button');
        delBtn.type = 'button';
        delBtn.setAttribute('data-redact-del', '1');
        delBtn.title = 'Element entfernen';
        delBtn.className = 'w-5 h-5 rounded-full bg-rose-600 text-white text-xs font-bold flex items-center justify-center shadow-xs hover:scale-110 cursor-pointer';
        delBtn.innerHTML = '&times;';
        delBtn.onclick = (ev) => {
            ev.stopPropagation();
            redactionsByPage[pageNum].splice(idx, 1);
            renderPageRedactionBoxes(pageNum);
            updateRedactCounter();
        };
        actionsWrap.appendChild(delBtn);
        el.appendChild(actionsWrap);

        // Drag & Drop zum freien Verschieben des Elements auf der Seite
        makeEditorItemDraggable(el, stage, b, pageNum);

        overlay.appendChild(el);
    });
}

function makeEditorItemDraggable(el, stage, itemObj, pageNum) {
    let dragging = false;
    let startClientX = 0;
    let startClientY = 0;
    let origX = 0;
    let origY = 0;

    const startDrag = (cx, cy, target) => {
        if (target && target.closest('button')) return;
        dragging = true;
        startClientX = cx;
        startClientY = cy;
        origX = itemObj.x;
        origY = itemObj.y;
    };

    const moveDrag = (cx, cy) => {
        if (!dragging) return;
        const rect = stage.getBoundingClientRect();
        const dx = (cx - startClientX) / Math.max(1, rect.width);
        const dy = (cy - startClientY) / Math.max(1, rect.height);
        itemObj.x = Math.max(0, Math.min(1 - itemObj.w, origX + dx));
        itemObj.y = Math.max(0, Math.min(1 - itemObj.h, origY + dy));
        el.style.left = (itemObj.x * 100) + '%';
        el.style.top  = (itemObj.y * 100) + '%';
    };

    const endDrag = () => {
        dragging = false;
    };

    el.addEventListener('mousedown', e => {
        if (e.button !== 0) return;
        e.stopPropagation();
        startDrag(e.clientX, e.clientY, e.target);
    });
    window.addEventListener('mousemove', e => {
        if (dragging) moveDrag(e.clientX, e.clientY);
    });
    window.addEventListener('mouseup', () => {
        if (dragging) endDrag();
    });

    el.addEventListener('touchstart', e => {
        if (e.touches.length !== 1) return;
        if (e.target && e.target.closest('button')) return;
        e.stopPropagation();
        e.preventDefault();
        const t = e.touches[0];
        startDrag(t.clientX, t.clientY, e.target);
    }, { passive: false });
    el.addEventListener('touchmove', e => {
        if (!dragging || e.touches.length !== 1) return;
        e.stopPropagation();
        e.preventDefault();
        const t = e.touches[0];
        moveDrag(t.clientX, t.clientY);
    }, { passive: false });
    el.addEventListener('touchend', () => {
        if (dragging) endDrag();
    });
}

function openPdfTextModal(meta) {
    activeTextEditMeta = meta;
    const modal = document.getElementById('pdfTextEditModal');
    if (!modal) return;

    const item = meta.item;
    document.getElementById('pdfTextModalTitle').textContent = (item.color === 'white')
        ? '✏️ Vorhandenen Text weiß überdecken & ersetzen'
        : '🔤 Text in PDF einfügen';
    document.getElementById('pdfTextModalInput').value = item.text || '';
    document.getElementById('pdfTextModalSize').value  = String(item.fontSizeRel || 0.016);
    document.getElementById('pdfTextModalStyle').value = item.fontStyle || 'sans';
    document.getElementById('pdfTextModalColor').value = item.textColor || 'black';
    document.getElementById('pdfTextModalWhiteBg').checked = (item.color === 'white');

    modal.classList.remove('hidden');
    modal.classList.add('flex');
    setTimeout(() => document.getElementById('pdfTextModalInput')?.focus(), 50);
}

function closePdfTextModal() {
    const modal = document.getElementById('pdfTextEditModal');
    if (!modal) return;
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    activeTextEditMeta = null;
}

function savePdfTextModal() {
    if (!activeTextEditMeta) return;
    const txt     = document.getElementById('pdfTextModalInput').value;
    const sizeRel = parseFloat(document.getElementById('pdfTextModalSize').value || '0.016');
    const style   = document.getElementById('pdfTextModalStyle').value || 'sans';
    const col     = document.getElementById('pdfTextModalColor').value || 'black';
    const whiteBg = document.getElementById('pdfTextModalWhiteBg').checked;

    const { pageNum, idx, item } = activeTextEditMeta;
    if (txt.trim() === '' && !whiteBg) {
        closePdfTextModal();
        return;
    }

    item.text        = txt;
    item.fontSizeRel = sizeRel;
    item.fontStyle   = style;
    item.textColor   = col;
    item.color       = whiteBg ? 'white' : 'transparent';

    if (!Array.isArray(redactionsByPage[pageNum])) {
        redactionsByPage[pageNum] = [];
    }
    if (idx >= 0 && redactionsByPage[pageNum][idx]) {
        redactionsByPage[pageNum][idx] = item;
    } else {
        redactionsByPage[pageNum].push(item);
        redactionHistory.push(pageNum);
    }

    closePdfTextModal();
    renderPageRedactionBoxes(pageNum);
    updateRedactCounter();
}

function insertQuickStamp(stampText) {
    const pageKeys = Object.keys(redactionsByPage);
    if (pageKeys.length === 0) {
        alert('Bitte lade zuerst eine PDF-Datei über „🔍 PDF-Seiten im Editor öffnen“.');
        return;
    }
    const pageNum = pageKeys[0];
    redactionsByPage[pageNum].push({
        type: 'add_text',
        x: 0.12,
        y: 0.12,
        w: stampText.length <= 2 ? 0.05 : 0.18,
        h: 0.035,
        color: 'transparent',
        text: stampText,
        fontSizeRel: parseFloat(document.getElementById('pdfEditorFontSize')?.value || '0.016'),
        fontStyle: 'bold',
        textColor: document.getElementById('pdfEditorTextColor')?.value || 'black'
    });
    redactionHistory.push(pageNum);
    renderPageRedactionBoxes(pageNum);
    updateRedactCounter();
}

// --- Freihand-Unterschrift Pad ---
let sigPadInitialized = false;
let sigPadHasStrokes  = false;

function openSignaturePadModal() {
    const pageKeys = Object.keys(redactionsByPage);
    if (pageKeys.length === 0) {
        alert('Bitte lade zuerst eine PDF-Datei über „🔍 PDF-Seiten im Editor öffnen“.');
        return;
    }
    const modal = document.getElementById('pdfSignatureModal');
    if (!modal) return;
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    initSigPadCanvas();
}

function closeSignaturePadModal() {
    const modal = document.getElementById('pdfSignatureModal');
    if (!modal) return;
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

function initSigPadCanvas() {
    const canvas = document.getElementById('sigPadCanvas');
    if (!canvas || sigPadInitialized) return;
    sigPadInitialized = true;
    const ctx = canvas.getContext('2d');
    ctx.strokeStyle = '#1d4ed8';
    ctx.lineWidth   = 2.6;
    ctx.lineCap     = 'round';
    ctx.lineJoin    = 'round';

    let drawing = false;
    const getPos = (cx, cy) => {
        const r = canvas.getBoundingClientRect();
        return {
            x: (cx - r.left) * (canvas.width / Math.max(1, r.width)),
            y: (cy - r.top) * (canvas.height / Math.max(1, r.height))
        };
    };

    canvas.addEventListener('mousedown', e => {
        drawing = true;
        sigPadHasStrokes = true;
        const p = getPos(e.clientX, e.clientY);
        ctx.beginPath();
        ctx.moveTo(p.x, p.y);
    });
    window.addEventListener('mousemove', e => {
        if (!drawing) return;
        const p = getPos(e.clientX, e.clientY);
        ctx.lineTo(p.x, p.y);
        ctx.stroke();
    });
    window.addEventListener('mouseup', () => { drawing = false; });

    canvas.addEventListener('touchstart', e => {
        if (e.touches.length !== 1) return;
        e.preventDefault();
        drawing = true;
        sigPadHasStrokes = true;
        const p = getPos(e.touches[0].clientX, e.touches[0].clientY);
        ctx.beginPath();
        ctx.moveTo(p.x, p.y);
    }, { passive: false });
    canvas.addEventListener('touchmove', e => {
        if (!drawing || e.touches.length !== 1) return;
        e.preventDefault();
        const p = getPos(e.touches[0].clientX, e.touches[0].clientY);
        ctx.lineTo(p.x, p.y);
        ctx.stroke();
    }, { passive: false });
    canvas.addEventListener('touchend', () => { drawing = false; });
}

function clearSigPadCanvas() {
    const canvas = document.getElementById('sigPadCanvas');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    sigPadHasStrokes = false;
}

function insertSignatureFromPad() {
    const canvas = document.getElementById('sigPadCanvas');
    if (!canvas || !sigPadHasStrokes) {
        alert('Bitte zeichne zuerst deine Unterschrift in das Feld.');
        return;
    }
    const dataUri = canvas.toDataURL('image/png');
    const pageKeys = Object.keys(redactionsByPage);
    const pageNum = pageKeys[0] || '1';
    redactionsByPage[pageNum].push({
        type: 'signature',
        x: 0.15,
        y: 0.75,
        w: 0.28,
        h: 0.08,
        color: 'transparent',
        sigDataUri: dataUri
    });
    redactionHistory.push(pageNum);
    closeSignaturePadModal();
    renderPageRedactionBoxes(pageNum);
    updateRedactCounter();
}

function undoLastRedactionBox() {
    while (redactionHistory.length > 0) {
        const pageNum = redactionHistory.pop();
        if (Array.isArray(redactionsByPage[pageNum]) && redactionsByPage[pageNum].length > 0) {
            redactionsByPage[pageNum].pop();
            renderPageRedactionBoxes(pageNum);
            updateRedactCounter();
            return;
        }
    }
}

function clearAllRedactionBoxes() {
    Object.keys(redactionsByPage).forEach(pageNum => {
        redactionsByPage[pageNum] = [];
        renderPageRedactionBoxes(pageNum);
    });
    redactionHistory = [];
    updateRedactCounter();
}

function prepareRedactionSubmit() {
    let total = 0;
    Object.values(redactionsByPage).forEach(arr => {
        if (Array.isArray(arr)) total += arr.length;
    });
    if (total === 0) {
        alert('Bitte füge mindestens einen Text, eine Unterschrift oder eine Schwärzung auf einer Seite hinzu, bevor du speicherst.');
        return false;
    }
    document.getElementById('redactFormJson').value = JSON.stringify(redactionsByPage);
    return true;
}

// --- Nacheinander hinzugefügte Scan-Bilder & Merge-PDFs sammeln (statt zu überschreiben) ---
let accumulatedScanFiles  = [];
let accumulatedMergeFiles = [];

function syncInputFiles(inputId, fileListArray) {
    const input = document.getElementById(inputId);
    if (!input || typeof DataTransfer === 'undefined') return;
    const dt = new DataTransfer();
    fileListArray.forEach(file => dt.items.add(file));
    input.files = dt.files;
}

function previewSelectedScanImages(input, isCameraCapture = false) {
    if (input && input.files && input.files.length > 0) {
        Array.from(input.files).forEach(f => {
            if (accumulatedScanFiles.length < 40) {
                let fileToStore = f;
                if (isCameraCapture && typeof File !== 'undefined') {
                    const extMatch = (f.name || '').match(/\.[a-zA-Z0-9]+$/);
                    const ext = extMatch ? extMatch[0] : '.jpg';
                    const pageNo = accumulatedScanFiles.length + 1;
                    fileToStore = new File([f], `Kamera_Scan_Seite_${pageNo}${ext}`, {
                        type: f.type || 'image/jpeg',
                        lastModified: Date.now()
                    });
                }
                accumulatedScanFiles.push(fileToStore);
            }
        });
        if (isCameraCapture) {
            input.value = '';
        }
        syncInputFiles('scanImagesInput', accumulatedScanFiles);
    }
    renderScanPreviewStrip();
}

function renderScanPreviewStrip() {
    const strip = document.getElementById('scanPreviewStrip');
    if (!strip) return;
    strip.innerHTML = '';
    if (accumulatedScanFiles.length === 0) {
        strip.classList.add('hidden');
        return;
    }
    strip.classList.remove('hidden');
    strip.className = 'pt-2 space-y-1.5';

    const header = document.createElement('div');
    header.className = 'flex items-center justify-between text-[11px] font-bold text-slate-600 dark:text-slate-300 pb-1';
    header.innerHTML = `<span>📑 ${accumulatedScanFiles.length} Seite(n) in der Warteschlange (du kannst weitere hinzufügen):</span>`;
    const clearBtn = document.createElement('button');
    clearBtn.type = 'button';
    clearBtn.className = 'text-rose-600 hover:underline cursor-pointer';
    clearBtn.textContent = '🗑️ Alle leeren';
    clearBtn.onclick = () => {
        accumulatedScanFiles = [];
        const inp = document.getElementById('scanImagesInput');
        if (inp) inp.value = '';
        renderScanPreviewStrip();
    };
    header.appendChild(clearBtn);
    strip.appendChild(header);

    accumulatedScanFiles.forEach((f, idx) => {
        const row = document.createElement('div');
        row.className = 'flex items-center justify-between gap-2 px-3 py-1.5 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 text-xs';

        const label = document.createElement('span');
        label.className = 'font-semibold text-slate-800 dark:text-slate-200 truncate';
        label.textContent = `Seite ${idx + 1}: ${f.name}`;

        const actions = document.createElement('div');
        actions.className = 'flex items-center gap-1 shrink-0';

        if (idx > 0) {
            const upBtn = document.createElement('button');
            upBtn.type = 'button';
            upBtn.className = 'px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 text-[11px] font-bold cursor-pointer';
            upBtn.textContent = '↑';
            upBtn.title = 'Nach oben schieben';
            upBtn.onclick = () => {
                const tmp = accumulatedScanFiles[idx - 1];
                accumulatedScanFiles[idx - 1] = accumulatedScanFiles[idx];
                accumulatedScanFiles[idx] = tmp;
                syncInputFiles('scanImagesInput', accumulatedScanFiles);
                renderScanPreviewStrip();
            };
            actions.appendChild(upBtn);
        }

        if (idx < accumulatedScanFiles.length - 1) {
            const downBtn = document.createElement('button');
            downBtn.type = 'button';
            downBtn.className = 'px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 text-[11px] font-bold cursor-pointer';
            downBtn.textContent = '↓';
            downBtn.title = 'Nach unten schieben';
            downBtn.onclick = () => {
                const tmp = accumulatedScanFiles[idx + 1];
                accumulatedScanFiles[idx + 1] = accumulatedScanFiles[idx];
                accumulatedScanFiles[idx] = tmp;
                syncInputFiles('scanImagesInput', accumulatedScanFiles);
                renderScanPreviewStrip();
            };
            actions.appendChild(downBtn);
        }

        const delBtn = document.createElement('button');
        delBtn.type = 'button';
        delBtn.className = 'px-1.5 py-0.5 rounded bg-rose-50 dark:bg-rose-950/60 text-rose-600 hover:bg-rose-600 hover:text-white text-[11px] font-bold cursor-pointer';
        delBtn.textContent = '✕';
        delBtn.title = 'Diese Seite entfernen';
        delBtn.onclick = () => {
            accumulatedScanFiles.splice(idx, 1);
            if (accumulatedScanFiles.length === 0) {
                const inp = document.getElementById('scanImagesInput');
                if (inp) inp.value = '';
            } else {
                syncInputFiles('scanImagesInput', accumulatedScanFiles);
            }
            renderScanPreviewStrip();
        };
        actions.appendChild(delBtn);

        row.appendChild(label);
        row.appendChild(actions);
        strip.appendChild(row);
    });
}

function addSelectedMergePdfs(input) {
    if (input && input.files && input.files.length > 0) {
        Array.from(input.files).forEach(f => {
            if (accumulatedMergeFiles.length < 30) {
                accumulatedMergeFiles.push(f);
            }
        });
        syncInputFiles('mergePdfsInput', accumulatedMergeFiles);
    }
    renderMergePreviewStrip();
}

function renderMergePreviewStrip() {
    const strip = document.getElementById('mergePreviewStrip');
    if (!strip) return;
    strip.innerHTML = '';
    if (accumulatedMergeFiles.length === 0) {
        strip.classList.add('hidden');
        return;
    }
    strip.classList.remove('hidden');

    accumulatedMergeFiles.forEach((f, idx) => {
        const row = document.createElement('div');
        row.className = 'flex items-center justify-between gap-2 px-3 py-1.5 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 text-xs';

        const label = document.createElement('span');
        label.className = 'font-semibold text-slate-800 dark:text-slate-200 truncate';
        label.textContent = `📄 ${idx + 1}. ${f.name}`;

        const delBtn = document.createElement('button');
        delBtn.type = 'button';
        delBtn.className = 'px-1.5 py-0.5 rounded bg-rose-50 dark:bg-rose-950/60 text-rose-600 hover:bg-rose-600 hover:text-white text-[11px] font-bold cursor-pointer';
        delBtn.textContent = '✕';
        delBtn.onclick = () => {
            accumulatedMergeFiles.splice(idx, 1);
            if (accumulatedMergeFiles.length === 0) {
                const inp = document.getElementById('mergePdfsInput');
                if (inp) inp.value = '';
            } else {
                syncInputFiles('mergePdfsInput', accumulatedMergeFiles);
            }
            renderMergePreviewStrip();
        };

        row.appendChild(label);
        row.appendChild(delBtn);
        strip.appendChild(row);
    });
}

if (window.location.hash) {
    const h = window.location.hash.replace('#', '');
    if (['tab-scan', 'tab-merge', 'tab-split', 'tab-compress', 'tab-redact'].includes(h)) {
        switchPdfTab(h);
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
