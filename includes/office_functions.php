<?php
declare(strict_types=1);

/**
 * MiniCloud Office Light – Reine PHP-Hilfsfunktionen für Word (.docx), Excel (.xlsx), CSV und Textdateien.
 * Benötigt keinerlei externe Server oder CDNs (nutzt ausschließlich PHP ZipArchive & DOMDocument).
 */

/**
 * Prüft, ob eine Datei anhand ihrer Endung von MiniCloud Office Light unterstützt wird,
 * und gibt den Editor-Typ zurück ('word', 'sheet', 'slides', 'text' oder null).
 */
function getOfficeEditorType(string $filename): ?string
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return match ($ext) {
        'docx' => 'word',
        'xlsx', 'csv' => 'sheet',
        'pptx' => 'slides',
        'txt', 'md', 'html', 'htm' => 'text',
        default => null,
    };
}

/**
 * Liefert den passenden MIME-Typ für neue oder gespeicherte Office-Light-Dateien.
 */
function getOfficeMimeType(string $filename): string
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return match ($ext) {
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'csv'  => 'text/csv',
        'md'   => 'text/markdown',
        'html', 'htm' => 'text/html',
        default => 'text/plain',
    };
}

// ============================================================================
// 1. WORD (.DOCX) LESEN & SCHREIBEN (OpenXML <-> HTML)
// ============================================================================

/**
 * Liest eine .docx-Datei und wandelt den Inhalt (Überschriften, Absätze, Formatierungen, Listen, Tabellen)
 * in sauberes HTML für den Rich-Text-Editor um.
 */
function readDocxAsHtml(string $filePath): string
{
    if (!is_file($filePath) || !class_exists('ZipArchive')) {
        return '<p></p>';
    }

    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        return '<p></p>';
    }

    $xmlContent = $zip->getFromName('word/document.xml');
    $relsContent = $zip->getFromName('word/_rels/document.xml.rels');
    $zip->close();

    if ($xmlContent === false || trim($xmlContent) === '') {
        return '<p></p>';
    }

    // Hyperlink-Relationen auslesen (rId -> URL)
    $relsMap = [];
    if (is_string($relsContent) && $relsContent !== '') {
        $relsDom = new DOMDocument();
        if (@$relsDom->loadXML($relsContent, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            foreach ($relsDom->getElementsByTagName('Relationship') as $relNode) {
                if ($relNode instanceof DOMElement) {
                    $id = $relNode->getAttribute('Id');
                    $target = $relNode->getAttribute('Target');
                    if ($id !== '' && $target !== '' && preg_match('/^https?:\/\/|^mailto:/i', $target)) {
                        $relsMap[$id] = $target;
                    }
                }
            }
        }
    }

    $dom = new DOMDocument();
    if (!@$dom->loadXML($xmlContent, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
        return '<p></p>';
    }

    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
    $xpath->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

    $bodyNodes = $xpath->query('//w:body/*');
    if ($bodyNodes === false || $bodyNodes->length === 0) {
        return '<p></p>';
    }

    $htmlParts = [];
    $inList = false;

    foreach ($bodyNodes as $node) {
        if (!$node instanceof DOMElement) {
            continue;
        }

        if ($node->localName === 'p') {
            $isListItem = $xpath->query('.//w:numPr', $node)->length > 0;
            if ($isListItem && !$inList) {
                $htmlParts[] = '<ul>';
                $inList = true;
            } elseif (!$isListItem && $inList) {
                $htmlParts[] = '</ul>';
                $inList = false;
            }

            $pHtml = convertDocxParagraphNode($node, $xpath, $relsMap, $isListItem);
            if ($pHtml !== '') {
                $htmlParts[] = $pHtml;
            }
        } elseif ($node->localName === 'tbl') {
            if ($inList) {
                $htmlParts[] = '</ul>';
                $inList = false;
            }
            $htmlParts[] = convertDocxTableNode($node, $xpath, $relsMap);
        }
    }

    if ($inList) {
        $htmlParts[] = '</ul>';
    }

    $result = implode("\n", $htmlParts);
    return trim($result) !== '' ? $result : '<p></p>';
}

/**
 * Hilfsfunktion: Konvertiert einen einzelnen <w:p>-Knoten in HTML (<p>, <h1>..<h3>, <li>).
 *
 * @param array<string, string> $relsMap
 */
function convertDocxParagraphNode(DOMElement $pNode, DOMXPath $xpath, array $relsMap, bool $isListItem): string
{
    $tag = $isListItem ? 'li' : 'p';
    $styleNodes = $xpath->query('./w:pPr/w:pStyle', $pNode);
    if (!$isListItem && $styleNodes !== false && $styleNodes->length > 0) {
        $styleElem = $styleNodes->item(0);
        $styleVal = $styleElem instanceof DOMElement ? strtolower($styleElem->getAttribute('w:val')) : '';
        if (str_contains($styleVal, 'heading1') || $styleVal === 'titel' || $styleVal === 'title' || $styleVal === 'berschrift1' || $styleVal === 'h1') {
            $tag = 'h1';
        } elseif (str_contains($styleVal, 'heading2') || $styleVal === 'subtitle' || $styleVal === 'berschrift2' || $styleVal === 'h2') {
            $tag = 'h2';
        } elseif (str_contains($styleVal, 'heading3') || $styleVal === 'berschrift3' || $styleVal === 'h3') {
            $tag = 'h3';
        } elseif (str_contains($styleVal, 'quote') || str_contains($styleVal, 'zitat')) {
            $tag = 'blockquote';
        }
    }

    $alignStyle = '';
    $jcNodes = $xpath->query('./w:pPr/w:jc', $pNode);
    if ($jcNodes !== false && $jcNodes->length > 0) {
        $jcElem = $jcNodes->item(0);
        $jcVal = $jcElem instanceof DOMElement ? strtolower($jcElem->getAttribute('w:val')) : '';
        if ($jcVal === 'center') {
            $alignStyle = ' style="text-align:center;"';
        } elseif ($jcVal === 'right' || $jcVal === 'end') {
            $alignStyle = ' style="text-align:right;"';
        } elseif ($jcVal === 'both' || $jcVal === 'distribute') {
            $alignStyle = ' style="text-align:justify;"';
        }
    }

    $inner = '';
    foreach ($pNode->childNodes as $child) {
        if (!$child instanceof DOMElement) {
            continue;
        }
        if ($child->localName === 'r') {
            $inner .= convertDocxRunNode($child, $xpath);
        } elseif ($child->localName === 'hyperlink') {
            $rId = $child->getAttribute('r:id');
            $href = ($rId !== '' && isset($relsMap[$rId])) ? $relsMap[$rId] : '';
            $linkText = '';
            foreach ($child->childNodes as $hChild) {
                if ($hChild instanceof DOMElement && $hChild->localName === 'r') {
                    $linkText .= convertDocxRunNode($hChild, $xpath);
                }
            }
            if ($href !== '' && $linkText !== '') {
                $inner .= '<a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" target="_blank" rel="noopener noreferrer">' . $linkText . '</a>';
            } else {
                $inner .= $linkText;
            }
        }
    }

    if ($inner === '') {
        return $isListItem ? '' : '<p><br></p>';
    }

    return sprintf('<%s%s>%s</%s>', $tag, $alignStyle, $inner, $tag);
}

/**
 * Hilfsfunktion: Konvertiert einen <w:r>-Textlauf inklusive Formatierungen (Fett, Kursiv, Unterstrichen, Durchgestrichen, Farbe).
 */
function convertDocxRunNode(DOMElement $rNode, DOMXPath $xpath): string
{
    $textParts = [];
    foreach ($rNode->childNodes as $child) {
        if (!$child instanceof DOMElement) {
            continue;
        }
        if ($child->localName === 't') {
            $textParts[] = htmlspecialchars($child->textContent, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        } elseif ($child->localName === 'br') {
            $textParts[] = '<br>';
        } elseif ($child->localName === 'tab') {
            $textParts[] = '&emsp;';
        }
    }

    $html = implode('', $textParts);
    if ($html === '') {
        return '';
    }

    $isBold = false;
    $bNodes = $xpath->query('./w:rPr/w:b', $rNode);
    if ($bNodes !== false && $bNodes->length > 0) {
        $bElem = $bNodes->item(0);
        $val = $bElem instanceof DOMElement ? strtolower($bElem->getAttribute('w:val')) : '';
        $isBold = ($val !== '0' && $val !== 'false');
    }

    $isItalic = false;
    $iNodes = $xpath->query('./w:rPr/w:i', $rNode);
    if ($iNodes !== false && $iNodes->length > 0) {
        $iElem = $iNodes->item(0);
        $val = $iElem instanceof DOMElement ? strtolower($iElem->getAttribute('w:val')) : '';
        $isItalic = ($val !== '0' && $val !== 'false');
    }

    $isUnderline = false;
    $uNodes = $xpath->query('./w:rPr/w:u', $rNode);
    if ($uNodes !== false && $uNodes->length > 0) {
        $uElem = $uNodes->item(0);
        $val = $uElem instanceof DOMElement ? strtolower($uElem->getAttribute('w:val')) : '';
        $isUnderline = ($val !== 'none' && $val !== '0' && $val !== 'false');
    }

    $isStrike = false;
    $sNodes = $xpath->query('./w:rPr/w:strike', $rNode);
    if ($sNodes !== false && $sNodes->length > 0) {
        $sElem = $sNodes->item(0);
        $val = $sElem instanceof DOMElement ? strtolower($sElem->getAttribute('w:val')) : '';
        $isStrike = ($val !== '0' && $val !== 'false');
    }

    $colorNodes = $xpath->query('./w:rPr/w:color', $rNode);
    if ($colorNodes !== false && $colorNodes->length > 0) {
        $cElem = $colorNodes->item(0);
        $cVal = $cElem instanceof DOMElement ? $cElem->getAttribute('w:val') : '';
        if (preg_match('/^[0-9A-Fa-f]{6}$/', $cVal) && strtoupper($cVal) !== '000000') {
            $html = '<span style="color:#' . strtoupper($cVal) . ';">' . $html . '</span>';
        }
    }

    if ($isStrike) {
        $html = '<s>' . $html . '</s>';
    }
    if ($isUnderline) {
        $html = '<u>' . $html . '</u>';
    }
    if ($isItalic) {
        $html = '<em>' . $html . '</em>';
    }
    if ($isBold) {
        $html = '<strong>' . $html . '</strong>';
    }

    return $html;
}

/**
 * Hilfsfunktion: Konvertiert eine <w:tbl>-Tabelle in HTML.
 *
 * @param array<string, string> $relsMap
 */
function convertDocxTableNode(DOMElement $tblNode, DOMXPath $xpath, array $relsMap): string
{
    $rowsHtml = [];
    $trNodes = $xpath->query('./w:tr', $tblNode);
    if ($trNodes === false) {
        return '';
    }

    foreach ($trNodes as $rowIndex => $trNode) {
        if (!$trNode instanceof DOMElement) {
            continue;
        }
        $cellsHtml = [];
        $tcNodes = $xpath->query('./w:tc', $trNode);
        if ($tcNodes !== false) {
            foreach ($tcNodes as $tcNode) {
                if (!$tcNode instanceof DOMElement) {
                    continue;
                }
                $cellParts = [];
                $pNodes = $xpath->query('./w:p', $tcNode);
                if ($pNodes !== false) {
                    foreach ($pNodes as $pNode) {
                        if ($pNode instanceof DOMElement) {
                            $pHtml = convertDocxParagraphNode($pNode, $xpath, $relsMap, false);
                            // Innerhalb von Tabellenzellen kompakten Text bevorzugen
                            $pHtml = preg_replace('/^<p>(.*)<\/p>$/s', '$1', $pHtml) ?? $pHtml;
                            if ($pHtml !== '<br>') {
                                $cellParts[] = $pHtml;
                            }
                        }
                    }
                }
                $cellTag = $rowIndex === 0 ? 'th' : 'td';
                $cellsHtml[] = sprintf('<%s>%s</%s>', $cellTag, implode('<br>', $cellParts), $cellTag);
            }
        }
        $rowsHtml[] = '<tr>' . implode('', $cellsHtml) . '</tr>';
    }

    return '<table><tbody>' . implode('', $rowsHtml) . '</tbody></table>';
}

/**
 * Schreibt sauberes HTML aus dem Rich-Text-Editor als standardkonforme .docx-Datei (OpenXML).
 */
function writeHtmlAsDocx(string $html, string $targetPath): bool
{
    if (!class_exists('ZipArchive')) {
        return false;
    }

    $dom = new DOMDocument('1.0', 'UTF-8');
    $wrappedHtml = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>';
    @$dom->loadHTML($wrappedHtml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);

    $bodyList = $dom->getElementsByTagName('body');
    $bodyElem = $bodyList->length > 0 ? $bodyList->item(0) : null;

    $bodyXmlParts = [];
    if ($bodyElem instanceof DOMElement) {
        foreach ($bodyElem->childNodes as $child) {
            $bodyXmlParts[] = convertHtmlNodeToDocxXml($child);
        }
    }

    $bodyInnerXml = implode("\n", array_filter($bodyXmlParts, static fn($s) => $s !== ''));
    if (trim($bodyInnerXml) === '') {
        $bodyInnerXml = '<w:p><w:r><w:t></w:t></w:r></w:p>';
    }

    $documentXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<w:body>'
        . $bodyInnerXml
        . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1417" w:right="1417" w:bottom="1417" w:left="1417" w:header="708" w:footer="708" w:gutter="0"/></w:sectPr>'
        . '</w:body></w:document>';

    $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Calibri"/><w:sz w:val="22"/><w:szCs w:val="22"/></w:rPr></w:rPrDefault>'
        . '<w:pPrDefault><w:pPr><w:spacing w:after="160" w:line="259" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>'
        . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/></w:style>'
        . '<w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:basedOn w:val="Normal"/><w:qFormat/><w:pPr><w:spacing w:before="240" w:after="120"/></w:pPr><w:rPr><w:b/><w:sz w:val="36"/><w:szCs w:val="36"/><w:color w:val="1E293B"/></w:rPr></w:style>'
        . '<w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="heading 2"/><w:basedOn w:val="Normal"/><w:qFormat/><w:pPr><w:spacing w:before="200" w:after="100"/></w:pPr><w:rPr><w:b/><w:sz w:val="28"/><w:szCs w:val="28"/><w:color w:val="334155"/></w:rPr></w:style>'
        . '<w:style w:type="paragraph" w:styleId="Heading3"><w:name w:val="heading 3"/><w:basedOn w:val="Normal"/><w:qFormat/><w:pPr><w:spacing w:before="160" w:after="80"/></w:pPr><w:rPr><w:b/><w:sz w:val="24"/><w:szCs w:val="24"/><w:color w:val="475569"/></w:rPr></w:style>'
        . '<w:style w:type="paragraph" w:styleId="Quote"><w:name w:val="Quote"/><w:basedOn w:val="Normal"/><w:qFormat/><w:pPr><w:ind w:left="720"/></w:pPr><w:rPr><w:i/><w:color w:val="475569"/></w:rPr></w:style>'
        . '</w:styles>';

    $numberingXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<w:numbering xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:abstractNum w:abstractNumId="1"><w:multiLevelType w:val="hybridMultilevel"/>'
        . '<w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="bullet"/><w:lvlText w:val="•"/><w:lvlJc w:val="left"/><w:pPr><w:ind w:left="720" w:hanging="360"/></w:pPr></w:lvl>'
        . '</w:abstractNum>'
        . '<w:abstractNum w:abstractNumId="2"><w:multiLevelType w:val="hybridMultilevel"/>'
        . '<w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="decimal"/><w:lvlText w:val="%1."/><w:lvlJc w:val="left"/><w:pPr><w:ind w:left="720" w:hanging="360"/></w:pPr></w:lvl>'
        . '</w:abstractNum>'
        . '<w:num w:numId="1"><w:abstractNumId w:val="1"/></w:num>'
        . '<w:num w:numId="2"><w:abstractNumId w:val="2"/></w:num>'
        . '</w:numbering>';

    $contentTypesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
        . '<Override PartName="/word/numbering.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml"/>'
        . '</Types>';

    $relsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
        . '</Relationships>';

    $docRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '<Relationship Id="rIdNumbering" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering" Target="numbering.xml"/>'
        . '</Relationships>';

    $tmpFile = tempnam(sys_get_temp_dir(), 'mc_docx_');
    if ($tmpFile === false) {
        return false;
    }

    $zip = new ZipArchive();
    if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($tmpFile);
        return false;
    }

    $zip->addFromString('[Content_Types].xml', $contentTypesXml);
    $zip->addFromString('_rels/.rels', $relsXml);
    $zip->addFromString('word/_rels/document.xml.rels', $docRelsXml);
    $zip->addFromString('word/styles.xml', $stylesXml);
    $zip->addFromString('word/numbering.xml', $numberingXml);
    $zip->addFromString('word/document.xml', $documentXml);
    $zip->close();

    if (!@rename($tmpFile, $targetPath)) {
        if (!@copy($tmpFile, $targetPath)) {
            @unlink($tmpFile);
            return false;
        }
        @unlink($tmpFile);
    }
    @chmod($targetPath, 0640);
    return true;
}

/**
 * Konvertiert einen HTML-DOM-Knoten rekursiv in WordprocessingML (<w:p>, <w:tbl>).
 */
function convertHtmlNodeToDocxXml(DOMNode $node): string
{
    if ($node instanceof DOMText) {
        $txt = trim($node->wholeText);
        if ($txt === '') {
            return '';
        }
        return '<w:p>' . buildDocxRunXml($txt, []) . '</w:p>';
    }

    if (!$node instanceof DOMElement) {
        return '';
    }

    $tag = strtolower($node->tagName);

    if (in_array($tag, ['h1', 'h2', 'h3', 'p', 'div', 'blockquote'], true)) {
        // Prüfen, ob ein <div> weitere Block-Elemente umschließt
        if ($tag === 'div') {
            $hasBlockChildren = false;
            foreach ($node->childNodes as $c) {
                if ($c instanceof DOMElement && in_array(strtolower($c->tagName), ['p', 'div', 'h1', 'h2', 'h3', 'ul', 'ol', 'table', 'blockquote'], true)) {
                    $hasBlockChildren = true;
                    break;
                }
            }
            if ($hasBlockChildren) {
                $parts = [];
                foreach ($node->childNodes as $c) {
                    $parts[] = convertHtmlNodeToDocxXml($c);
                }
                return implode("\n", array_filter($parts));
            }
        }

        $pStyle = match ($tag) {
            'h1' => '<w:pStyle w:val="Heading1"/>',
            'h2' => '<w:pStyle w:val="Heading2"/>',
            'h3' => '<w:pStyle w:val="Heading3"/>',
            'blockquote' => '<w:pStyle w:val="Quote"/>',
            default => '',
        };

        $jcXml = '';
        $styleAttr = strtolower($node->getAttribute('style'));
        $alignAttr = strtolower($node->getAttribute('align'));
        if (str_contains($styleAttr, 'text-align:center') || str_contains($styleAttr, 'text-align: center') || $alignAttr === 'center') {
            $jcXml = '<w:jc w:val="center"/>';
        } elseif (str_contains($styleAttr, 'text-align:right') || str_contains($styleAttr, 'text-align: right') || $alignAttr === 'right') {
            $jcXml = '<w:jc w:val="right"/>';
        } elseif (str_contains($styleAttr, 'text-align:justify') || str_contains($styleAttr, 'text-align: justify') || $alignAttr === 'justify') {
            $jcXml = '<w:jc w:val="both"/>';
        }

        $pPr = ($pStyle !== '' || $jcXml !== '') ? '<w:pPr>' . $pStyle . $jcXml . '</w:pPr>' : '';
        $runsXml = collectInlineRunsXml($node, []);
        return '<w:p>' . $pPr . $runsXml . '</w:p>';
    }

    if ($tag === 'ul' || $tag === 'ol') {
        $numId = $tag === 'ol' ? '2' : '1';
        $itemsXml = [];
        foreach ($node->childNodes as $li) {
            if ($li instanceof DOMElement && strtolower($li->tagName) === 'li') {
                $runsXml = collectInlineRunsXml($li, []);
                $itemsXml[] = '<w:p><w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="' . $numId . '"/></w:numPr></w:pPr>' . $runsXml . '</w:p>';
            }
        }
        return implode("\n", $itemsXml);
    }

    if ($tag === 'table') {
        $rowsXml = [];
        $trNodes = $node->getElementsByTagName('tr');
        foreach ($trNodes as $trIdx => $tr) {
            $cellsXml = [];
            foreach ($tr->childNodes as $tc) {
                if ($tc instanceof DOMElement && in_array(strtolower($tc->tagName), ['td', 'th'], true)) {
                    $isHeader = (strtolower($tc->tagName) === 'th' || $trIdx === 0);
                    $runsXml = collectInlineRunsXml($tc, $isHeader ? ['bold' => true] : []);
                    $shdXml = $isHeader ? '<w:shd w:val="clear" w:color="auto" w:fill="F1F5F9"/>' : '';
                    $cellsXml[] = '<w:tc><w:tcPr><w:tcW w:w="2800" w:type="dxa"/>' . $shdXml . '</w:tcPr><w:p>' . $runsXml . '</w:p></w:tc>';
                }
            }
            if (!empty($cellsXml)) {
                $rowsXml[] = '<w:tr>' . implode('', $cellsXml) . '</w:tr>';
            }
        }
        if (empty($rowsXml)) {
            return '';
        }
        $tblPr = '<w:tblPr><w:tblW w:w="5000" w:type="pct"/>'
            . '<w:tblBorders>'
            . '<w:top w:val="single" w:sz="4" w:space="0" w:color="CBD5E1"/>'
            . '<w:left w:val="single" w:sz="4" w:space="0" w:color="CBD5E1"/>'
            . '<w:bottom w:val="single" w:sz="4" w:space="0" w:color="CBD5E1"/>'
            . '<w:right w:val="single" w:sz="4" w:space="0" w:color="CBD5E1"/>'
            . '<w:insideH w:val="single" w:sz="4" w:space="0" w:color="CBD5E1"/>'
            . '<w:insideV w:val="single" w:sz="4" w:space="0" w:color="CBD5E1"/>'
            . '</w:tblBorders></w:tblPr>';
        return '<w:tbl>' . $tblPr . implode('', $rowsXml) . '</w:tbl>';
    }

    if ($tag === 'hr') {
        return '<w:p><w:pPr><w:pBdr><w:bottom w:val="single" w:sz="6" w:space="1" w:color="CBD5E1"/></w:pBdr></w:pPr></w:p>';
    }

    $runsXml = collectInlineRunsXml($node, []);
    return $runsXml !== '' ? '<w:p>' . $runsXml . '</w:p>' : '';
}

/**
 * Sammelt Inline-Textläufe (<w:r>) innerhalb eines Absatzes oder Listenelements.
 *
 * @param array<string, mixed> $format
 */
function collectInlineRunsXml(DOMNode $parent, array $format): string
{
    $runs = [];
    foreach ($parent->childNodes as $child) {
        if ($child instanceof DOMText) {
            $text = $child->wholeText;
            if ($text !== '') {
                $runs[] = buildDocxRunXml($text, $format);
            }
        } elseif ($child instanceof DOMElement) {
            $tag = strtolower($child->tagName);
            if ($tag === 'br') {
                $runs[] = '<w:r><w:br/></w:r>';
                continue;
            }
            $nextFormat = $format;
            if ($tag === 'b' || $tag === 'strong') {
                $nextFormat['bold'] = true;
            } elseif ($tag === 'i' || $tag === 'em') {
                $nextFormat['italic'] = true;
            } elseif ($tag === 'u') {
                $nextFormat['underline'] = true;
            } elseif ($tag === 's' || $tag === 'strike' || $tag === 'del') {
                $nextFormat['strike'] = true;
            }
            $styleAttr = $child->getAttribute('style');
            if ($styleAttr !== '' && preg_match('/color\s*:\s*#([0-9a-fA-F]{6})/', $styleAttr, $m)) {
                $nextFormat['color'] = strtoupper($m[1]);
            }
            $runs[] = collectInlineRunsXml($child, $nextFormat);
        }
    }
    return implode('', $runs);
}

/**
 * Erzeugt ein einzelnes <w:r>-Element mit XML-maskiertem Text und Formatierung.
 *
 * @param array<string, mixed> $format
 */
function buildDocxRunXml(string $text, array $format): string
{
    $rPrParts = [];
    if (!empty($format['bold'])) {
        $rPrParts[] = '<w:b/>';
    }
    if (!empty($format['italic'])) {
        $rPrParts[] = '<w:i/>';
    }
    if (!empty($format['underline'])) {
        $rPrParts[] = '<w:u w:val="single"/>';
    }
    if (!empty($format['strike'])) {
        $rPrParts[] = '<w:strike/>';
    }
    if (!empty($format['color']) && preg_match('/^[0-9A-F]{6}$/', (string)$format['color'])) {
        $rPrParts[] = '<w:color w:val="' . $format['color'] . '"/>';
    }

    $rPrXml = !empty($rPrParts) ? '<w:rPr>' . implode('', $rPrParts) . '</w:rPr>' : '';
    // Ungültige Steuerzeichen für XML entfernen
    $cleanText = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text) ?? $text;
    $escaped = htmlspecialchars($cleanText, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    return '<w:r>' . $rPrXml . '<w:t xml:space="preserve">' . $escaped . '</w:t></w:r>';
}

// ============================================================================
// 2. EXCEL (.XLSX) & CSV LESEN & SCHREIBEN (OpenXML <-> JSON-Sheets)
// ============================================================================

/**
 * Wandelt einen 0-basierten Spaltenindex (0 -> A, 25 -> Z, 26 -> AA) in einen Excel-Spaltenbuchstaben um.
 */
function colIndexToLetters(int $index): string
{
    $letters = '';
    $index++;
    while ($index > 0) {
        $mod = ($index - 1) % 26;
        $letters = chr(65 + $mod) . $letters;
        $index = intdiv($index - $mod, 26);
    }
    return $letters;
}

/**
 * Wandelt einen Excel-Spaltenbuchstaben (A -> 0, Z -> 25, AA -> 26) in einen 0-basierten Spaltenindex um.
 */
function colLettersToIndex(string $letters): int
{
    $letters = strtoupper(trim($letters));
    $len = strlen($letters);
    $num = 0;
    for ($i = 0; $i < $len; $i++) {
        $char = ord($letters[$i]);
        if ($char >= 65 && $char <= 90) {
            $num = $num * 26 + ($char - 64);
        }
    }
    return max(0, $num - 1);
}

/**
 * Liest eine .xlsx- oder .csv-Datei und gibt ein Array von Tabellenblättern zurück:
 * [ ['name' => 'Tabelle1', 'rows' => [ ['A1', 'B1'], ['A2', 'B2'] ]], ... ]
 * Formeln werden mit führendem '=' zurückgegeben (z. B. '=SUM(A1:A5)').
 *
 * @return array<int, array{name: string, rows: array<int, array<int, string>>}>
 */
function readSpreadsheetData(string $filePath, string $originalName): array
{
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($ext === 'csv') {
        return readCsvAsSheets($filePath);
    }
    return readXlsxAsSheets($filePath);
}

/**
 * Liest eine CSV-Datei (erkennt automatisch ';' oder ',' als Trennzeichen).
 *
 * @return array<int, array{name: string, rows: array<int, array<int, string>>}>
 */
function readCsvAsSheets(string $filePath): array
{
    if (!is_file($filePath)) {
        return [['name' => 'Tabelle1', 'rows' => array_fill(0, 15, array_fill(0, 8, ''))]];
    }

    $raw = (string)@file_get_contents($filePath);
    // UTF-8 BOM entfernen
    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        $raw = substr($raw, 3);
    }

    $firstLine = strtok($raw, "\r\n");
    $delimiter = (is_string($firstLine) && substr_count($firstLine, ';') >= substr_count($firstLine, ',')) ? ';' : ',';

    $rows = [];
    $maxCols = 8;
    $fp = @fopen('php://memory', 'r+');
    if ($fp !== false) {
        fwrite($fp, $raw);
        rewind($fp);
        while (($data = fgetcsv($fp, 0, $delimiter, '"', '')) !== false) {
            if ($data === [null]) {
                continue;
            }
            $row = array_map(static fn($v) => (string)($v ?? ''), $data);
            $maxCols = max($maxCols, count($row));
            $rows[] = $row;
        }
        fclose($fp);
    }

    $totalRows = max(15, count($rows) + 5);
    $normalizedRows = [];
    for ($r = 0; $r < $totalRows; $r++) {
        $rowArr = $rows[$r] ?? [];
        $normalizedRows[] = array_pad($rowArr, $maxCols, '');
    }

    return [['name' => 'CSV-Daten', 'rows' => $normalizedRows]];
}

/**
 * Liest eine OpenXML .xlsx-Datei inklusive mehrerer Tabellenblätter, sharedStrings und Formeln.
 *
 * @return array<int, array{name: string, rows: array<int, array<int, string>>}>
 */
function readXlsxAsSheets(string $filePath): array
{
    $defaultEmpty = [['name' => 'Tabelle1', 'rows' => array_fill(0, 18, array_fill(0, 8, ''))]];
    if (!is_file($filePath) || !class_exists('ZipArchive')) {
        return $defaultEmpty;
    }

    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        return $defaultEmpty;
    }

    // 1. sharedStrings.xml einlesen
    $sharedStrings = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if (is_string($ssXml) && $ssXml !== '') {
        $ssDom = new DOMDocument();
        if (@$ssDom->loadXML($ssXml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            foreach ($ssDom->getElementsByTagName('si') as $siNode) {
                $textParts = [];
                foreach ($siNode->getElementsByTagName('t') as $tNode) {
                    $textParts[] = $tNode->textContent;
                }
                $sharedStrings[] = implode('', $textParts);
            }
        }
    }

    // 2. workbook.xml & workbook.xml.rels für Blattnamen und Zieldateien auslesen
    $relsMap = [];
    $wbRelsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if (is_string($wbRelsXml) && $wbRelsXml !== '') {
        $relsDom = new DOMDocument();
        if (@$relsDom->loadXML($wbRelsXml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            foreach ($relsDom->getElementsByTagName('Relationship') as $relNode) {
                if ($relNode instanceof DOMElement) {
                    $id = $relNode->getAttribute('Id');
                    $target = ltrim($relNode->getAttribute('Target'), '/');
                    if (!str_starts_with($target, 'xl/')) {
                        $target = 'xl/' . $target;
                    }
                    $relsMap[$id] = $target;
                }
            }
        }
    }

    $sheetsMeta = [];
    $wbXml = $zip->getFromName('xl/workbook.xml');
    if (is_string($wbXml) && $wbXml !== '') {
        $wbDom = new DOMDocument();
        if (@$wbDom->loadXML($wbXml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            foreach ($wbDom->getElementsByTagName('sheet') as $idx => $sheetNode) {
                if ($sheetNode instanceof DOMElement) {
                    $name = $sheetNode->getAttribute('name') ?: ('Tabelle' . ($idx + 1));
                    $rId = $sheetNode->getAttribute('r:id');
                    if ($rId === '') {
                        $rId = $sheetNode->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
                    }
                    $targetPath = ($rId !== '' && isset($relsMap[$rId]))
                        ? $relsMap[$rId]
                        : ('xl/worksheets/sheet' . ($idx + 1) . '.xml');
                    $sheetsMeta[] = ['name' => $name, 'path' => $targetPath];
                }
            }
        }
    }

    if (empty($sheetsMeta)) {
        $sheetsMeta[] = ['name' => 'Tabelle1', 'path' => 'xl/worksheets/sheet1.xml'];
    }

    // 3. Jedes Tabellenblatt parsen
    $sheetsResult = [];
    foreach ($sheetsMeta as $sheetInfo) {
        $sheetXml = $zip->getFromName($sheetInfo['path']);
        if (!is_string($sheetXml) || $sheetXml === '') {
            continue;
        }

        $sDom = new DOMDocument();
        if (!@$sDom->loadXML($sheetXml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            continue;
        }

        $grid = [];
        $maxRow = 14;
        $maxCol = 7;

        foreach ($sDom->getElementsByTagName('row') as $rIdxFallback => $rowNode) {
            if (!$rowNode instanceof DOMElement) {
                continue;
            }
            $rowNumAttr = (int)$rowNode->getAttribute('r');
            $rIdx = $rowNumAttr > 0 ? ($rowNumAttr - 1) : $rIdxFallback;
            $maxRow = max($maxRow, $rIdx);

            $cColFallback = 0;
            foreach ($rowNode->getElementsByTagName('c') as $cNode) {
                if (!$cNode instanceof DOMElement) {
                    continue;
                }
                $ref = $cNode->getAttribute('r');
                if ($ref !== '' && preg_match('/^([A-Z]+)(\d+)$/i', $ref, $m)) {
                    $cIdx = colLettersToIndex($m[1]);
                    $rIdx = max(0, ((int)$m[2]) - 1);
                } else {
                    $cIdx = $cColFallback;
                }
                $cColFallback = $cIdx + 1;
                $maxCol = max($maxCol, $cIdx);
                $maxRow = max($maxRow, $rIdx);

                $type = $cNode->getAttribute('t');
                $fNodes = $cNode->getElementsByTagName('f');
                $vNodes = $cNode->getElementsByTagName('v');
                $isNodes = $cNode->getElementsByTagName('is');

                $cellValue = '';
                if ($fNodes->length > 0 && trim($fNodes->item(0)->textContent) !== '') {
                    $formulaStr = ltrim(trim($fNodes->item(0)->textContent), '=');
                    $cellValue = '=' . $formulaStr;
                } elseif ($type === 's' && $vNodes->length > 0) {
                    $ssIdx = (int)$vNodes->item(0)->textContent;
                    $cellValue = $sharedStrings[$ssIdx] ?? '';
                } elseif ($type === 'inlineStr' && $isNodes->length > 0) {
                    $cellValue = $isNodes->item(0)->textContent;
                } elseif ($type === 'b' && $vNodes->length > 0) {
                    $cellValue = trim($vNodes->item(0)->textContent) === '1' ? 'WAHR' : 'FALSCH';
                } elseif ($vNodes->length > 0) {
                    $cellValue = $vNodes->item(0)->textContent;
                }

                if ($cellValue !== '') {
                    $grid[$rIdx][$cIdx] = $cellValue;
                }
            }
        }

        // Sicherheitsobergrenze gegen riesige Leer-Bereiche
        $maxRow = min(500, max(15, $maxRow + 3));
        $maxCol = min(52, max(8, $maxCol + 2));

        $rowsMatrix = [];
        for ($r = 0; $r <= $maxRow; $r++) {
            $rowArr = [];
            for ($c = 0; $c <= $maxCol; $c++) {
                $rowArr[] = (string)($grid[$r][$c] ?? '');
            }
            $rowsMatrix[] = $rowArr;
        }

        $sheetsResult[] = [
            'name' => $sheetInfo['name'],
            'rows' => $rowsMatrix,
        ];
    }

    $zip->close();
    return !empty($sheetsResult) ? $sheetsResult : $defaultEmpty;
}

/**
 * Schreibt Tabellenblatt-Daten (Array aus Sheets) als standardkonforme .xlsx- oder .csv-Datei.
 *
 * @param array<int, array{name?: string, rows?: array<int, array<int, scalar>>}> $sheets
 */
function writeSpreadsheetData(array $sheets, string $targetPath, string $originalName): bool
{
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($ext === 'csv') {
        $firstSheetRows = isset($sheets[0]['rows']) && is_array($sheets[0]['rows']) ? $sheets[0]['rows'] : [];
        return writeSheetsAsCsv($firstSheetRows, $targetPath);
    }
    return writeSheetsAsXlsx($sheets, $targetPath);
}

/**
 * Schreibt das erste Tabellenblatt als UTF-8 CSV (mit BOM und Semikolon für direkten deutschen Excel-Import).
 *
 * @param array<int, array<int, scalar>> $rows
 */
function writeSheetsAsCsv(array $rows, string $targetPath): bool
{
    // Letzte nicht-leere Zeile und Spalte ermitteln
    $lastNonEmptyRow = -1;
    $lastNonEmptyCol = -1;
    foreach ($rows as $rIdx => $row) {
        if (!is_array($row)) {
            continue;
        }
        foreach ($row as $cIdx => $val) {
            if (trim((string)$val) !== '') {
                $lastNonEmptyRow = max($lastNonEmptyRow, (int)$rIdx);
                $lastNonEmptyCol = max($lastNonEmptyCol, (int)$cIdx);
            }
        }
    }

    $fp = @fopen($targetPath, 'wb');
    if ($fp === false) {
        return false;
    }

    // UTF-8 BOM schreiben, damit Microsoft Excel Umlaute sofort erkennt
    fwrite($fp, "\xEF\xBB\xBF");

    if ($lastNonEmptyRow >= 0 && $lastNonEmptyCol >= 0) {
        for ($r = 0; $r <= $lastNonEmptyRow; $r++) {
            $line = [];
            for ($c = 0; $c <= $lastNonEmptyCol; $c++) {
                $line[] = (string)($rows[$r][$c] ?? '');
            }
            fputcsv($fp, $line, ';', '"', '');
        }
    }

    fclose($fp);
    @chmod($targetPath, 0640);
    return true;
}

/**
 * Erzeugt eine 100 % standardkonforme OpenXML .xlsx-Datei mit beliebig vielen Tabellenblättern,
 * sharedStrings, Zahlenwerten und Excel-Formeln.
 *
 * @param array<int, array{name?: string, rows?: array<int, array<int, scalar>>}> $sheets
 */
function writeSheetsAsXlsx(array $sheets, string $targetPath): bool
{
    if (!class_exists('ZipArchive')) {
        return false;
    }

    if (empty($sheets)) {
        $sheets = [['name' => 'Tabelle1', 'rows' => [['']]]];
    }

    $sharedStrings = [];
    $sharedStringMap = [];
    $worksheetXmls = [];
    $sheetNames = [];

    foreach (array_values($sheets) as $sIdx => $sheet) {
        $rawName = trim((string)($sheet['name'] ?? ('Tabelle' . ($sIdx + 1))));
        // Ungültige Zeichen in Excel-Tabellennamen bereinigen
        $cleanName = preg_replace('/[\\\\\/\?\*\:\[\]]/', '_', $rawName) ?: ('Tabelle' . ($sIdx + 1));
        $cleanName = mb_substr($cleanName, 0, 31, 'UTF-8');
        if (in_array($cleanName, $sheetNames, true)) {
            $cleanName = mb_substr($cleanName, 0, 27, 'UTF-8') . '_' . ($sIdx + 1);
        }
        $sheetNames[] = $cleanName;

        $rows = isset($sheet['rows']) && is_array($sheet['rows']) ? $sheet['rows'] : [];
        $rowsXmlParts = [];

        foreach ($rows as $rIdx => $row) {
            if (!is_array($row)) {
                continue;
            }
            $rowNum = ((int)$rIdx) + 1;
            $cellsXmlParts = [];

            foreach ($row as $cIdx => $rawVal) {
                $val = (string)$rawVal;
                if ($val === '') {
                    continue;
                }

                $colLetters = colIndexToLetters((int)$cIdx);
                $cellRef = $colLetters . $rowNum;

                if (str_starts_with($val, '=') && strlen($val) > 1) {
                    // Formelzelle (deutsche Funktionsnamen für Excel OpenXML automatisch übersetzen)
                    $formula = substr($val, 1);
                    $formula = str_ireplace(
                        ['SUMME(', 'MITTELWERT(', 'ANZAHL(', 'RUNDEN(', 'WENN('],
                        ['SUM(', 'AVERAGE(', 'COUNT(', 'ROUND(', 'IF('],
                        $formula
                    );
                    $formula = str_replace(';', ',', $formula);
                    $cleanFormula = htmlspecialchars($formula, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                    $cellsXmlParts[] = '<c r="' . $cellRef . '"><f>' . $cleanFormula . '</f></c>';
                } elseif (is_numeric($val) && !preg_match('/^0\d+$/', $val)) {
                    // Numerische Zelle
                    $cellsXmlParts[] = '<c r="' . $cellRef . '"><v>' . $val . '</v></c>';
                } else {
                    // Textzelle über sharedStrings
                    $cleanText = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $val) ?? $val;
                    if (!isset($sharedStringMap[$cleanText])) {
                        $sharedStringMap[$cleanText] = count($sharedStrings);
                        $sharedStrings[] = $cleanText;
                    }
                    $ssId = $sharedStringMap[$cleanText];
                    $cellsXmlParts[] = '<c r="' . $cellRef . '" t="s"><v>' . $ssId . '</v></c>';
                }
            }

            if (!empty($cellsXmlParts)) {
                $rowsXmlParts[] = '<row r="' . $rowNum . '">' . implode('', $cellsXmlParts) . '</row>';
            }
        }

        $worksheetXmls[$sIdx] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheetViews><sheetView workbookViewId="0"/></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="15" defaultColWidth="14"/>'
            . '<sheetData>' . implode('', $rowsXmlParts) . '</sheetData>'
            . '</worksheet>';
    }

    // sharedStrings.xml bauen
    $ssItemsXml = [];
    foreach ($sharedStrings as $str) {
        $escaped = htmlspecialchars($str, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $ssItemsXml[] = '<si><t xml:space="preserve">' . $escaped . '</t></si>';
    }
    $sharedStringsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($sharedStrings) . '" uniqueCount="' . count($sharedStrings) . '">'
        . implode('', $ssItemsXml)
        . '</sst>';

    // workbook.xml & workbook.xml.rels & [Content_Types].xml bauen
    $wbSheetsXml = [];
    $wbRelsXmlParts = [];
    $ctOverridesXml = [
        '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>',
        '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>',
        '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>',
    ];

    foreach ($sheetNames as $idx => $sName) {
        $sheetNum = $idx + 1;
        $rId = 'rIdSheet' . $sheetNum;
        $escapedName = htmlspecialchars($sName, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $wbSheetsXml[] = '<sheet name="' . $escapedName . '" sheetId="' . $sheetNum . '" r:id="' . $rId . '"/>';
        $wbRelsXmlParts[] = '<Relationship Id="' . $rId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $sheetNum . '.xml"/>';
        $ctOverridesXml[] = '<Override PartName="/xl/worksheets/sheet' . $sheetNum . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    }

    $wbRelsXmlParts[] = '<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
    $wbRelsXmlParts[] = '<Relationship Id="rIdSharedStrings" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';

    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets>' . implode('', $wbSheetsXml) . '</sheets>'
        . '</workbook>';

    $workbookRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . implode('', $wbRelsXmlParts)
        . '</Relationships>';

    $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>'
        . '</styleSheet>';

    $contentTypesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . implode('', $ctOverridesXml)
        . '</Types>';

    $rootRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $tmpFile = tempnam(sys_get_temp_dir(), 'mc_xlsx_');
    if ($tmpFile === false) {
        return false;
    }

    $zip = new ZipArchive();
    if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($tmpFile);
        return false;
    }

    $zip->addFromString('[Content_Types].xml', $contentTypesXml);
    $zip->addFromString('_rels/.rels', $rootRelsXml);
    $zip->addFromString('xl/workbook.xml', $workbookXml);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRelsXml);
    $zip->addFromString('xl/styles.xml', $stylesXml);
    $zip->addFromString('xl/sharedStrings.xml', $sharedStringsXml);

    foreach ($worksheetXmls as $idx => $wsXml) {
        $zip->addFromString('xl/worksheets/sheet' . ($idx + 1) . '.xml', $wsXml);
    }

    $zip->close();

    if (!@rename($tmpFile, $targetPath)) {
        if (!@copy($tmpFile, $targetPath)) {
            @unlink($tmpFile);
            return false;
        }
        @unlink($tmpFile);
    }
    @chmod($targetPath, 0640);
    return true;
}

// ============================================================================
// 3. POWERPOINT LIGHT (.PPTX) LESEN & SCHREIBEN (OpenXML PresentationML)
// ============================================================================

/**
 * Liest eine .pptx-Präsentation aus und liefert ein strukturiertes Array mit Theme & Folien.
 * Unterstützt sowohl in MiniCloud erstellte Präsentationen (inkl. Layouts, 2-Spalten, Bildern & Notizen)
 * als auch extern in Microsoft PowerPoint / LibreOffice Impress erstellte .pptx-Dateien.
 *
 * @return array{theme: string, transition: string, slides: array<int, array<string, mixed>>}
 */
function readPptxData(string $filePath): array
{
    $defaultDeck = [
        'theme'      => 'light',
        'transition' => 'fade',
        'slides'     => [
            [
                'layout'     => 'title',
                'title'      => 'Präsentationstitel',
                'subtitle'   => 'Untertitel oder Name des Vortragenden',
                'body'       => '',
                'col2'       => '',
                'stat_val'   => '100 %',
                'image_id'   => 0,
                'notes'      => '',
            ],
        ],
    ];

    if (!is_file($filePath) || !class_exists('ZipArchive')) {
        return $defaultDeck;
    }

    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        return $defaultDeck;
    }

    // 1. Prüfen, ob unsere verlustfreie MiniCloud-Metadatendatei im PPTX-Archiv eingebettet ist
    $mcJson = $zip->getFromName('ppt/minicloud_slides.json');
    if (is_string($mcJson) && $mcJson !== '') {
        $decoded = json_decode($mcJson, true);
        if (is_array($decoded) && !empty($decoded['slides']) && is_array($decoded['slides'])) {
            $zip->close();
            return [
                'theme'      => in_array(($decoded['theme'] ?? 'light'), ['light', 'navy', 'emerald', 'amber', 'rose', 'slate'], true) ? $decoded['theme'] : 'light',
                'transition' => in_array(($decoded['transition'] ?? 'fade'), ['fade', 'slide', 'zoom', 'none'], true) ? $decoded['transition'] : 'fade',
                'slides'     => array_values($decoded['slides']),
            ];
        }
    }

    // 2. Fallback für extern hochgeladene PowerPoint-Dateien: ppt/slides/slide1..N.xml parsen
    $slideFiles = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string)$zip->getNameIndex($i);
        if (preg_match('#^ppt/slides/slide(\d+)\.xml$#i', $name, $m)) {
            $slideFiles[(int)$m[1]] = $name;
        }
    }

    if (empty($slideFiles)) {
        $zip->close();
        return $defaultDeck;
    }

    ksort($slideFiles, SORT_NUMERIC);
    $parsedSlides = [];

    foreach ($slideFiles as $slideNum => $entryName) {
        $xml = $zip->getFromName($entryName);
        if (!is_string($xml) || trim($xml) === '') {
            continue;
        }

        $dom = new DOMDocument();
        if (!@$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            continue;
        }

        $xp = new DOMXPath($dom);
        $xp->registerNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');
        $xp->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');

        $titleText = '';
        $bodyLines = [];

        $shapes = $xp->query('//p:sp');
        if ($shapes !== false) {
            foreach ($shapes as $sIdx => $spNode) {
                if (!$spNode instanceof DOMElement) {
                    continue;
                }
                $phNode = $xp->query('.//p:nvSpPr/p:nvPr/p:ph', $spNode)->item(0);
                $phType = ($phNode instanceof DOMElement) ? strtolower($phNode->getAttribute('type')) : '';

                $paragraphs = $xp->query('.//a:p', $spNode);
                $shapeLines = [];
                if ($paragraphs !== false) {
                    foreach ($paragraphs as $pNode) {
                        $tNodes = $xp->query('.//a:t', $pNode);
                        $line = '';
                        if ($tNodes !== false) {
                            foreach ($tNodes as $tNode) {
                                $line .= $tNode->textContent;
                            }
                        }
                        $line = trim($line);
                        if ($line !== '') {
                            $shapeLines[] = $line;
                        }
                    }
                }

                if (empty($shapeLines)) {
                    continue;
                }

                if ($titleText === '' && ($phType === 'title' || $phType === 'ctrtitle' || $sIdx === 0)) {
                    $titleText = implode(' – ', $shapeLines);
                } else {
                    foreach ($shapeLines as $sl) {
                        $bodyLines[] = $sl;
                    }
                }
            }
        }

        // Sprechernotizen (falls vorhanden) aus ppt/notesSlides/notesSlideN.xml lesen
        $notesText = '';
        $notesXml = $zip->getFromName('ppt/notesSlides/notesSlide' . $slideNum . '.xml');
        if (is_string($notesXml) && $notesXml !== '') {
            $nDom = new DOMDocument();
            if (@$nDom->loadXML($notesXml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                $nXp = new DOMXPath($nDom);
                $nXp->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
                $nTexts = [];
                foreach ($nXp->query('//a:t') as $nt) {
                    $val = trim($nt->textContent);
                    if ($val !== '' && !ctype_digit($val)) {
                        $nTexts[] = $val;
                    }
                }
                $notesText = implode("\n", $nTexts);
            }
        }

        $parsedSlides[] = [
            'layout'   => ($slideNum === 1 && count($bodyLines) <= 2) ? 'title' : 'bullets',
            'title'    => $titleText !== '' ? $titleText : ('Folie ' . $slideNum),
            'subtitle' => ($slideNum === 1 && !empty($bodyLines)) ? implode(' · ', $bodyLines) : '',
            'body'     => ($slideNum === 1 && count($bodyLines) <= 2) ? '' : implode("\n", $bodyLines),
            'col2'     => '',
            'stat_val' => '100 %',
            'image_id' => 0,
            'notes'    => $notesText,
        ];
    }

    $zip->close();
    if (empty($parsedSlides)) {
        return $defaultDeck;
    }

    return [
        'theme'      => 'light',
        'transition' => 'fade',
        'slides'     => $parsedSlides,
    ];
}

/**
 * Erzeugt eine standardkonforme OpenXML PowerPoint-Datei (.pptx) im 16:9-Breitbildformat,
 * kompatibel mit Microsoft PowerPoint, LibreOffice Impress und Apple Keynote.
 *
 * @param array<string, mixed> $deckData
 */
function writePptxData(array $deckData, string $targetPath): bool
{
    if (!class_exists('ZipArchive')) {
        return false;
    }

    $theme = in_array(($deckData['theme'] ?? 'light'), ['light', 'navy', 'emerald', 'amber', 'rose', 'slate'], true)
        ? (string)$deckData['theme']
        : 'light';
    $transition = in_array(($deckData['transition'] ?? 'fade'), ['fade', 'slide', 'zoom', 'none'], true)
        ? (string)$deckData['transition']
        : 'fade';

    $rawSlides = isset($deckData['slides']) && is_array($deckData['slides']) ? $deckData['slides'] : [];
    $cleanSlides = [];

    foreach ($rawSlides as $idx => $s) {
        if (!is_array($s)) {
            continue;
        }
        $layout = (string)($s['layout'] ?? 'bullets');
        if (!in_array($layout, ['title', 'bullets', 'two_col', 'image_text', 'big_stat', 'section'], true)) {
            $layout = 'bullets';
        }
        $cleanSlides[] = [
            'id'       => (string)($s['id'] ?? ('s_' . ($idx + 1))),
            'layout'   => $layout,
            'title'    => mb_substr(trim((string)($s['title'] ?? ('Folie ' . ($idx + 1)))), 0, 250),
            'subtitle' => mb_substr(trim((string)($s['subtitle'] ?? '')), 0, 350),
            'body'     => mb_substr(trim((string)($s['body'] ?? '')), 0, 5000),
            'col2'     => mb_substr(trim((string)($s['col2'] ?? '')), 0, 5000),
            'stat_val' => mb_substr(trim((string)($s['stat_val'] ?? '')), 0, 80),
            'imageId'  => max(0, (int)($s['imageId'] ?? $s['image_id'] ?? 0)),
            'notes'    => mb_substr(trim((string)($s['notes'] ?? '')), 0, 4000),
        ];
    }

    if (empty($cleanSlides)) {
        $cleanSlides[] = [
            'layout'   => 'title',
            'title'    => 'Neue Präsentation',
            'subtitle' => '',
            'body'     => '',
            'col2'     => '',
            'stat_val' => '',
            'image_id' => 0,
            'notes'    => '',
        ];
    }

    // Farbpalette passend zum gewählten Präsentations-Design
    $themeColors = [
        'light'   => ['bg' => 'FFFFFF', 'title' => '0F172A', 'body' => '334155', 'accent' => '2563EB'],
        'navy'    => ['bg' => '0F172A', 'title' => 'F8FAFC', 'body' => 'CBD5E1', 'accent' => '38BDF8'],
        'emerald' => ['bg' => '064E3B', 'title' => 'ECFDF5', 'body' => 'A7F3D0', 'accent' => '34D399'],
        'amber'   => ['bg' => 'FFFBEB', 'title' => '78350F', 'body' => '92400E', 'accent' => 'D97706'],
        'rose'    => ['bg' => 'FFF1F2', 'title' => '881337', 'body' => '9F1239', 'accent' => 'E11D48'],
        'slate'   => ['bg' => '18181B', 'title' => 'FAFAFA', 'body' => 'D4D4D8', 'accent' => 'A855F7'],
    ];
    $pal = $themeColors[$theme] ?? $themeColors['light'];

    $tmpFile = tempnam(sys_get_temp_dir(), 'mc_pptx_');
    if ($tmpFile === false) {
        return false;
    }

    $zip = new ZipArchive();
    if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($tmpFile);
        return false;
    }

    $ctOverrides = [
        '<Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/>',
        '<Override PartName="/ppt/slideMasters/slideMaster1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideMaster+xml"/>',
        '<Override PartName="/ppt/slideLayouts/slideLayout1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideLayout+xml"/>',
        '<Override PartName="/ppt/theme/theme1.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/>',
    ];
    $presRels = [
        '<Relationship Id="rIdMaster1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="slideMasters/slideMaster1.xml"/>',
        '<Relationship Id="rIdTheme1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="theme/theme1.xml"/>',
    ];
    $sldIdList = [];

    foreach ($cleanSlides as $idx => $sl) {
        $slideNum = $idx + 1;
        $rId      = 'rIdSlide' . $slideNum;
        $sldId    = 256 + $idx;

        $ctOverrides[] = '<Override PartName="/ppt/slides/slide' . $slideNum . '.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/>';
        $presRels[]    = '<Relationship Id="' . $rId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide' . $slideNum . '.xml"/>';
        $sldIdList[]   = '<p:sldId id="' . $sldId . '" r:id="' . $rId . '"/>';

        $escTitle = htmlspecialchars($sl['title'], ENT_XML1 | ENT_QUOTES, 'UTF-8');

        // Textzeilen für den Hauptbereich zusammenstellen
        $bodyLines = [];
        if ($sl['layout'] === 'title' || $sl['layout'] === 'section') {
            if ($sl['subtitle'] !== '') {
                $bodyLines = preg_split('/\r\n|\r|\n/', $sl['subtitle']) ?: [];
            }
        } elseif ($sl['layout'] === 'big_stat') {
            if ($sl['stat_val'] !== '') {
                $bodyLines[] = $sl['stat_val'];
            }
            if ($sl['subtitle'] !== '') {
                $bodyLines[] = $sl['subtitle'];
            }
        } else {
            if ($sl['body'] !== '') {
                foreach (preg_split('/\r\n|\r|\n/', $sl['body']) ?: [] as $ln) {
                    if (trim($ln) !== '') {
                        $bodyLines[] = '• ' . ltrim(trim($ln), '•-* ');
                    }
                }
            }
            if ($sl['layout'] === 'two_col' && $sl['col2'] !== '') {
                $bodyLines[] = '';
                foreach (preg_split('/\r\n|\r|\n/', $sl['col2']) ?: [] as $ln2) {
                    if (trim($ln2) !== '') {
                        $bodyLines[] = '• ' . ltrim(trim($ln2), '•-* ');
                    }
                }
            }
        }

        if (empty($bodyLines)) {
            $bodyLines[] = '';
        }

        $bodyParagraphsXml = '';
        foreach ($bodyLines as $bLine) {
            $escLine = htmlspecialchars($bLine, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $fontSize = ($sl['layout'] === 'big_stat' && $bLine === $sl['stat_val']) ? 5400 : 2200;
            $bodyParagraphsXml .= '<a:p><a:r><a:rPr lang="de-DE" sz="' . $fontSize . '" dirty="0"><a:solidFill><a:srgbClr val="' . $pal['body'] . '"/></a:solidFill></a:rPr><a:t>' . $escLine . '</a:t></a:r></a:p>';
        }

        $titleY  = ($sl['layout'] === 'title' || $sl['layout'] === 'section') ? 2100000 : 650000;
        $titleSz = ($sl['layout'] === 'title' || $sl['layout'] === 'section') ? 4400 : 3400;
        $bodyY   = ($sl['layout'] === 'title' || $sl['layout'] === 'section') ? 3600000 : 1850000;

        $slideXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<p:sld xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
            . '<p:cSld>'
            . '<p:bg><p:bgPr><a:solidFill><a:srgbClr val="' . $pal['bg'] . '"/></a:solidFill><a:effectLst/></p:bgPr></p:bg>'
            . '<p:spTree>'
            . '<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
            . '<p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>'
            // Shape 1: Folientitel
            . '<p:sp>'
            . '<p:nvSpPr><p:cNvPr id="2" name="Title 1"/><p:cNvSpPr><a:spLocks noGrp="1"/></p:cNvSpPr><p:nvPr><p:ph type="title"/></p:nvPr></p:nvSpPr>'
            . '<p:spPr><a:xfrm><a:off x="800000" y="' . $titleY . '"/><a:ext cx="10592000" cy="1100000"/></a:xfrm></p:spPr>'
            . '<p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:r><a:rPr lang="de-DE" sz="' . $titleSz . '" b="1" dirty="0"><a:solidFill><a:srgbClr val="' . $pal['title'] . '"/></a:solidFill></a:rPr><a:t>' . $escTitle . '</a:t></a:r></a:p></p:txBody>'
            . '</p:sp>'
            // Shape 2: Haupttext / Aufzählung
            . '<p:sp>'
            . '<p:nvSpPr><p:cNvPr id="3" name="Content Placeholder 2"/><p:cNvSpPr><a:spLocks noGrp="1"/></p:cNvSpPr><p:nvPr><p:ph idx="1"/></p:nvPr></p:nvSpPr>'
            . '<p:spPr><a:xfrm><a:off x="800000" y="' . $bodyY . '"/><a:ext cx="10592000" cy="4300000"/></a:xfrm></p:spPr>'
            . '<p:txBody><a:bodyPr/><a:lstStyle/>' . $bodyParagraphsXml . '</p:txBody>'
            . '</p:sp>'
            . '</p:spTree>'
            . '</p:cSld>'
            . '<p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr>'
            . '</p:sld>';

        $slideRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>'
            . '</Relationships>';

        $zip->addFromString('ppt/slides/slide' . $slideNum . '.xml', $slideXml);
        $zip->addFromString('ppt/slides/_rels/slide' . $slideNum . '.xml.rels', $slideRelsXml);
    }

    // Minimalistisches SlideLayout & SlideMaster & Theme für volle PowerPoint/LibreOffice-Kompatibilität
    $slideLayoutXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<p:sldLayout xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" type="titleAndContent" preserve="1">'
        . '<p:cSld name="Title and Content"><p:spTree>'
        . '<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
        . '<p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>'
        . '</p:spTree></p:cSld>'
        . '<p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr>'
        . '</p:sldLayout>';

    $slideLayoutRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="../slideMasters/slideMaster1.xml"/>'
        . '</Relationships>';

    $slideMasterXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<p:sldMaster xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
        . '<p:cSld><p:spTree>'
        . '<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
        . '<p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>'
        . '</p:spTree></p:cSld>'
        . '<p:clrMap bg1="lt1" tx1="dk1" bg2="lt2" tx2="dk2" accent1="accent1" accent2="accent2" accent3="accent3" accent4="accent4" accent5="accent5" accent6="accent6" hlink="hlink" folHlink="folHlink"/>'
        . '<p:sldLayoutIdLst><p:sldLayoutId id="2147483649" r:id="rId1"/></p:sldLayoutIdLst>'
        . '</p:sldMaster>';

    $slideMasterRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="../theme/theme1.xml"/>'
        . '</Relationships>';

    $themeXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" name="MiniCloud Theme">'
        . '<a:themeElements>'
        . '<a:clrScheme name="MiniCloud"><a:dk1><a:srgbClr val="0F172A"/></a:dk1><a:lt1><a:srgbClr val="FFFFFF"/></a:lt1><a:dk2><a:srgbClr val="334155"/></a:dk2><a:lt2><a:srgbClr val="F8FAFC"/></a:lt2><a:accent1><a:srgbClr val="2563EB"/></a:accent1><a:accent2><a:srgbClr val="059669"/></a:accent2><a:accent3><a:srgbClr val="D97706"/></a:accent3><a:accent4><a:srgbClr val="E11D48"/></a:accent4><a:accent5><a:srgbClr val="7C3AED"/></a:accent5><a:accent6><a:srgbClr val="0891B2"/></a:accent6><a:hlink><a:srgbClr val="2563EB"/></a:hlink><a:folHlink><a:srgbClr val="7C3AED"/></a:folHlink></a:clrScheme>'
        . '<a:fontScheme name="MiniCloud"><a:majorFont><a:latin typeface="Calibri"/></a:majorFont><a:minorFont><a:latin typeface="Calibri"/></a:minorFont></a:fontScheme>'
        . '<a:fmtScheme name="MiniCloud"><a:fillStyleLst><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:fillStyleLst><a:lnStyleLst><a:ln w="9525"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:ln></a:lnStyleLst><a:effectStyleLst><a:effectStyle><a:effectLst/></a:effectStyle></a:effectStyleLst><a:bgFillStyleLst><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:bgFillStyleLst></a:fmtScheme>'
        . '</a:themeElements>'
        . '</a:theme>';

    $presentationXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<p:presentation xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" saveSubsetFonts="1">'
        . '<p:sldMasterIdLst><p:sldMasterId id="2147483648" r:id="rIdMaster1"/></p:sldMasterIdLst>'
        . '<p:sldIdLst>' . implode('', $sldIdList) . '</p:sldIdLst>'
        . '<p:sldSz cx="12192000" cy="6858000" type="screen16x9"/>'
        . '<p:notesSz cx="6858000" cy="9144000"/>'
        . '</p:presentation>';

    $presentationRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . implode('', $presRels)
        . '</Relationships>';

    $contentTypesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Default Extension="json" ContentType="application/json"/>'
        . implode('', $ctOverrides)
        . '</Types>';

    $rootRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="ppt/presentation.xml"/>'
        . '</Relationships>';

    $zip->addFromString('[Content_Types].xml', $contentTypesXml);
    $zip->addFromString('_rels/.rels', $rootRelsXml);
    $zip->addFromString('ppt/presentation.xml', $presentationXml);
    $zip->addFromString('ppt/_rels/presentation.xml.rels', $presentationRelsXml);
    $zip->addFromString('ppt/slideLayouts/slideLayout1.xml', $slideLayoutXml);
    $zip->addFromString('ppt/slideLayouts/_rels/slideLayout1.xml.rels', $slideLayoutRelsXml);
    $zip->addFromString('ppt/slideMasters/slideMaster1.xml', $slideMasterXml);
    $zip->addFromString('ppt/slideMasters/_rels/slideMaster1.xml.rels', $slideMasterRelsXml);
    $zip->addFromString('ppt/theme/theme1.xml', $themeXml);

    // Verlustfreie MiniCloud-Metadaten für 1:1 Roundtrip im Browser einbetten
    $zip->addFromString('ppt/minicloud_slides.json', json_encode([
        'theme'      => $theme,
        'transition' => $transition,
        'slides'     => $cleanSlides,
    ], JSON_UNESCAPED_UNICODE));

    $zip->close();

    if (!@rename($tmpFile, $targetPath)) {
        if (!@copy($tmpFile, $targetPath)) {
            @unlink($tmpFile);
            return false;
        }
        @unlink($tmpFile);
    }
    @chmod($targetPath, 0640);
    return true;
}
