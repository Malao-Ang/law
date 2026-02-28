<?php

namespace App\Services\DocumentConvert;

use DOMDocument;
use DOMXPath;
use ZipArchive;

class DocxToHtmlConverter
{
    private array $numberingMap = []; // [numId][ilvl] => ['type'=>'ol|ul','format'=>'decimal|bullet', 'lvlText'=>'%1.%2', 'start'=>1]

    private array $abstractMap = []; // [abstractNumId][ilvl] => same info

    private array $rels = [];         // [rId] => target (e.g. "media/image1.png")

    private array $stylesMap = [];    // [styleId] => ['align'=>?string, 'ind'=>array, 'basedOn'=>?string]

    private ?ZipArchive $zip = null;

    public function convert(string $path): string
    {
        $this->zip = new ZipArchive;

        if ($this->zip->open($path) !== true) {
            throw new \Exception('Cannot open DOCX file');
        }

        try {
            $documentXml = $this->zip->getFromName('word/document.xml');
            $numberingXml = $this->zip->getFromName('word/numbering.xml') ?: null;
            $stylesXml    = $this->zip->getFromName('word/styles.xml') ?: null;
            $relsXml      = $this->zip->getFromName('word/_rels/document.xml.rels') ?: null;

            if (! $documentXml) {
                throw new \Exception('Invalid DOCX structure: missing word/document.xml');
            }

            if ($numberingXml) {
                $this->loadNumbering($numberingXml);
            }

            if ($stylesXml) {
                $this->loadStyles($stylesXml);
            }

            if ($relsXml) {
                $this->loadRels($relsXml);
            }

            $dom = new DOMDocument;
            $dom->preserveWhiteSpace = true;
            $dom->loadXML($documentXml);

            $htmlBody = $this->parseWordXml($dom);

        } finally {
            $this->zip->close();
            $this->zip = null;
        }

        // wrapper + CSS ที่ช่วยให้ “อ่านไทย” ได้เหมือนเอกสารราชการมากขึ้น
        $css = $this->defaultCss();

        return <<<HTML
<div class="legal-document">
<style>{$css}</style>
{$htmlBody}
</div>
HTML;
    }

    private function loadRels(string $xml): void
    {
        $dom = new DOMDocument;
        $dom->loadXML($xml);
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');

        foreach ($xp->query('//r:Relationship') as $rel) {
            /** @var \DOMElement $rel */
            $id = $rel->getAttribute('Id');
            $target = $rel->getAttribute('Target');
            $this->rels[$id] = $target;
        }
    }

    private function getMediaBase64(string $rId): ?string
    {
        if (! isset($this->rels[$rId])) {
            return null;
        }

        $target = $this->rels[$rId];
        // Target often looks like "media/image1.png" or "word/media/image1.png" depending on relative path
        // In word/_rels/document.xml.rels, targets are usually relative to word/ directory.
        // So "media/image1.png" -> "word/media/image1.png" inside the zip.

        $zipPath = 'word/'.$target;

        // Sometimes it is absolute-ish?
        if (strpos($target, '/') === 0) {
            $zipPath = ltrim($target, '/'); // Remove leading slash
        }

        $content = $this->zip->getFromName($zipPath);
        if ($content === false) {
            // Try without 'word/' prefix just in case
            $content = $this->zip->getFromName($target);
        }

        if ($content === false) {
            return null;
        }

        $ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'bmp' => 'image/bmp',
            'svg' => 'image/svg+xml',
            default => 'application/octet-stream'
        };

        return 'data:'.$mime.';base64,'.base64_encode($content);
    }

    // ---------------------------
    //  Numbering (word/numbering.xml)
    // ---------------------------

    private function firstEl(\DOMNodeList|false $list): ?\DOMElement
    {
        if (! $list instanceof \DOMNodeList || $list->length === 0) {
            return null;
        }

        $node = $list->item(0);

        return $node instanceof \DOMElement ? $node : null;
    }

    private function els(\DOMNodeList|false $list): array
    {
        if (! $list instanceof \DOMNodeList || $list->length < 1) {
            return [];
        }

        $out = [];
        foreach ($list as $n) {
            if ($n instanceof \DOMElement) {
                $out[] = $n;
            }
        }

        return $out;
    }

    private const W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private function attr(?\DOMElement $el, string $localName): ?string
    {
        if (! $el instanceof \DOMElement) {
            return null;
        }

        $v = $el->getAttributeNS(self::W_NS, $localName);

        return $v !== '' ? $v : null;
    }

    private function attrVal(\DOMXPath $xp, \DOMElement $parent, string $childTag, string $attrName): ?string
    {
        $nodes = $xp->query($childTag, $parent);
        if ($nodes->length === 0) {
            return null;
        }
        
        $child = $nodes->item(0);
        if (!$child instanceof \DOMElement) {
            return null;
        }
        
        return $this->attr($child, $attrName);
    }

    private function loadStyles(string $xml): void
    {
        $dom = new \DOMDocument;
        $dom->preserveWhiteSpace = false;
        $dom->loadXML($xml);

        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('w', self::W_NS);

        foreach ($this->els($xp->query('//w:style[@w:type="paragraph"]')) as $styleEl) {
            $styleId = $this->attr($styleEl, 'styleId');
            if (! $styleId) {
                continue;
            }

            $basedOnEl = $this->firstEl($xp->query('w:basedOn', $styleEl));
            $basedOn   = $basedOnEl ? $this->attr($basedOnEl, 'val') : null;

            // alignment from pPr/jc
            $jcEl = $this->firstEl($xp->query('w:pPr/w:jc', $styleEl));
            $jcVal = $jcEl ? $this->attr($jcEl, 'val') : null;
            $align = match ($jcVal) {
                'center'                        => 'center',
                'right'                         => 'right',
                'left'                          => 'left',
                'both', 'justify', 'distribute' => 'justify',
                default                         => null,
            };

            // indentation from pPr/ind
            $indEl = $this->firstEl($xp->query('w:pPr/w:ind', $styleEl));
            $ind   = ['left' => 0, 'hanging' => 0, 'firstLine' => 0];
            if ($indEl) {
                $ind['left']      = (int) ((int) $this->attr($indEl, 'left')      / 20);
                $ind['hanging']   = (int) ((int) $this->attr($indEl, 'hanging')   / 20);
                $ind['firstLine'] = (int) ((int) $this->attr($indEl, 'firstLine') / 20);
            }

            $this->stylesMap[$styleId] = [
                'align'   => $align,
                'ind'     => $ind,
                'basedOn' => $basedOn,
            ];
        }
    }

    private function resolveStyleAlign(string $styleId, int $depth = 0): ?string
    {
        if ($depth > 8 || ! isset($this->stylesMap[$styleId])) {
            return null;
        }
        $style = $this->stylesMap[$styleId];
        if ($style['align'] !== null) {
            return $style['align'];
        }
        if ($style['basedOn']) {
            return $this->resolveStyleAlign($style['basedOn'], $depth + 1);
        }
        return null;
    }

    private function resolveStyleInd(string $styleId, int $depth = 0): array
    {
        $empty = ['left' => 0, 'hanging' => 0, 'firstLine' => 0];
        if ($depth > 8 || ! isset($this->stylesMap[$styleId])) {
            return $empty;
        }
        $style = $this->stylesMap[$styleId];
        $ind   = $style['ind'];
        if ($ind['left'] !== 0 || $ind['hanging'] !== 0 || $ind['firstLine'] !== 0) {
            return $ind;
        }
        if ($style['basedOn']) {
            return $this->resolveStyleInd($style['basedOn'], $depth + 1);
        }
        return $empty;
    }

    private function getPStyleId(DOMXPath $xp, $pNode): ?string
    {
        $pStyleEl = $this->firstEl($xp->query('.//w:pPr/w:pStyle', $pNode));
        return $pStyleEl ? $this->attr($pStyleEl, 'val') : null;
    }

    private function loadNumbering(string $xml): void
    {
        $dom = new \DOMDocument;
        $dom->preserveWhiteSpace = false;
        $dom->loadXML($xml);

        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('w', self::W_NS);

        // 1) abstractNum -> level definition
        foreach ($this->els($xp->query('//w:abstractNum')) as $abs) {
            $absId = $this->attr($abs, 'abstractNumId');   // ✅ w:abstractNumId
            if (! $absId) {
                continue;
            }

            foreach ($this->els($xp->query('w:lvl', $abs)) as $lvl) {
                $ilvl = $this->attr($lvl, 'ilvl');         // ✅ w:ilvl
                if ($ilvl === null) {
                    continue;
                }

                $numFmt = $this->attrVal($xp, $lvl, 'w:numFmt', 'val') ?? 'decimal';
                $lvlText = $this->attrVal($xp, $lvl, 'w:lvlText', 'val') ?? '';
                $start = (int) ($this->attrVal($xp, $lvl, 'w:start', 'val') ?? 1);

                $type = ($numFmt === 'bullet') ? 'ul' : 'ol';

                $this->abstractMap[$absId][(int) $ilvl] = [
                    'type' => $type,
                    'format' => $numFmt,
                    'lvlText' => $lvlText,
                    'start' => $start,
                ];
            }
        }

        // 2) numId -> abstractNumId binding
        foreach ($this->els($xp->query('//w:num')) as $num) {
            $numId = $this->attr($num, 'numId'); // ✅ w:numId
            if (! $numId) {
                continue;
            }

            $absIdEl = $this->firstEl($xp->query('w:abstractNumId', $num));
            if (! $absIdEl) {
                continue;
            }

            $absId = $this->attr($absIdEl, 'val'); // ✅ w:val
            if (! $absId) {
                continue;
            }

            if (! isset($this->abstractMap[$absId])) {
                continue;
            }

            foreach ($this->abstractMap[$absId] as $ilvl => $def) {
                $this->numberingMap[$numId][$ilvl] = $def;
            }
        }
    }

    // ---------------------------
    //  Parse document.xml
    // ---------------------------
    private function parseWordXml(DOMDocument $dom): string
    {
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $xp->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
        $xp->registerNamespace('wp', 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing');
        $xp->registerNamespace('pic', 'http://schemas.openxmlformats.org/drawingml/2006/picture');
        $xp->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

        $out = '';

        // list stack: each item ['type'=>'ol|ul','ilvl'=>int,'numId'=>string,'counter'=>int,'start'=>int]
        $listStack = [];

        foreach ($xp->query('//w:body/*') as $node) {
            $name = $node->nodeName;

            if ($name === 'w:tbl') {
                // ก่อนเจอตาราง ปิด list ที่ค้าง
                $out .= $this->closeAllLists($listStack);
                $out .= $this->parseTable($xp, $node);

                continue;
            }

            if ($name === 'w:p') {
                $pInfo = $this->readParagraphInfo($xp, $node);

                // --- Heading Center ---
                // ถ้า align center และ bold/size เด่น => ทำเป็น heading block
                if ($pInfo['isCenterHeading']) {
                    $out .= $this->closeAllLists($listStack);
                    $out .= $this->renderCenterHeading($pInfo['html'], $pInfo['style']);

                    continue;
                }

                // --- Numbering / Lists ---
                if ($pInfo['num']) {
                    $numId = $pInfo['num']['numId'];
                    $ilvl = $pInfo['num']['ilvl'];

                    $def = $this->numberingMap[$numId][$ilvl] ?? null;
                    $type = $def['type'] ?? 'ol';
                    $start = $def['start'] ?? 1;

                    // ปรับ stack ให้ตรงระดับ (ilvl)
                    $out .= $this->syncListStack($listStack, $ilvl, $type, $numId, $start);

                    // เพิ่ม counter ในระดับนี้
                    $topIndex = count($listStack) - 1;
                    if ($topIndex >= 0) {
                        $listStack[$topIndex]['counter']++;
                    }

                    $liStyle = $this->liStyleFromIndent($pInfo['indent'], $ilvl);

                    $out .= '<li style="'.$liStyle.'">'.$pInfo['html'].'</li>';

                    continue;
                }

                // paragraph ปกติ => ปิด list ก่อน
                $out .= $this->closeAllLists($listStack);
                $out .= $this->renderParagraph($pInfo['html'], $pInfo['style']);

                continue;
            }
        }

        // ปิด list ที่เหลือ
        $out .= $this->closeAllLists($listStack);

        return $out;
    }

    private function readParagraphInfo(DOMXPath $xp, $pNode): array
    {
        $align = $this->getAlignment($xp, $pNode); // left|center|right|justify|null
        $ind = $this->getIndentation($xp, $pNode);

        $html = $this->parseParagraphRuns($xp, $pNode);
        $plain = $this->stripTags($html);

        $isCenterHeading = ($align === 'center');

        $num = $this->getNumbering($xp, $pNode);

        $style = [
            'text-align' => $align ?: 'justify', // Default to justify for formal look, or left if preferred
            'margin' => '0 0 0.35em 0',
            'line-height' => '1.75',
            'position' => 'relative', // Para positioning
        ];

        // ย่อหน้าไทย & Indentation logic
        if (! $num) {
            $padLeft = $ind['left'];
            $textIndent = $ind['firstLine'];

            if ($ind['hanging'] > 0) {
                $padLeft += $ind['hanging'];
                $textIndent = -1 * $ind['hanging'];
            }

            // Thai generic first line indent (approx 2.5cm -> 70pt or 36pt standard)
            // If no explicit indent but it looks like a body paragraph, maybe auto-indent?
            // For now, respect DOCX values.
            // If explicit FirstLine is set, use it.

            if ($padLeft > 0) {
                $style['padding-left'] = $padLeft.'pt';
            }
            if ($textIndent != 0) {
                $style['text-indent'] = $textIndent.'pt';
            }
        }

        return [
            'align' => $align,
            'indent' => $ind,
            'html' => $html,
            'plain' => $plain,
            'isCenterHeading' => $isCenterHeading,
            'num' => $num,
            'style' => $style,
        ];
    }

    private function parseParagraphRuns(DOMXPath $xp, $pNode): string
    {
        $parts = [];

        foreach ($xp->query('.//w:r|.//w:hyperlink', $pNode) as $r) {
            if ($r->nodeName === 'w:hyperlink') {
                // hyperlink ภายใน
                $parts[] = $this->parseHyperlink($xp, $r);

                continue;
            }

            $parts[] = $this->parseRun($xp, $r);
        }

        $html = implode('', $parts);

        // แก้ spacing ภาษาไทยแบบ aggressive ก่อน render (ให้เป็น 100% อ่านได้)
        $html = $this->repairThaiInHtml($html);

        // strip underline (ถ้าไม่อยากให้ underline จาก Word ติดมา)
        $html = $this->stripUnderline($html);

        return $html;
    }

    private function parseHyperlink(DOMXPath $xp, $linkNode): string
    {
        // ใน DOCX url จริงมักอยู่ใน rels (นี่เป็นเวอร์ชันง่าย: เก็บเฉพาะ text)
        $inner = [];
        foreach ($xp->query('.//w:r', $linkNode) as $r) {
            $inner[] = $this->parseRun($xp, $r);
        }

        return '<span class="doc-link">'.implode('', $inner).'</span>';
    }

    private function parseRun(DOMXPath $xp, $rNode): string
    {
        $isBold = $xp->query('.//w:rPr/w:b', $rNode)->length > 0;
        $isItalic = $xp->query('.//w:rPr/w:i', $rNode)->length > 0;

        $texts = [];

        foreach ($rNode->childNodes as $child) {
            if ($child->nodeName === 'w:t') {
                $t = $child->nodeValue ?? '';
                $t = $this->repairThaiText($t);
                $texts[] = htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            } elseif ($child->nodeName === 'w:tab') {
                $texts[] = '<span class="doc-tab" style="display:inline-block; min-width: 48px;">&nbsp;</span>';
            } elseif ($child->nodeName === 'w:br') {
                $texts[] = '<br>';
            } elseif ($child->nodeName === 'w:drawing') {
                $texts[] = $this->parseDrawing($xp, $child);
            }
        }

        $content = implode('', $texts);

        if ($content === '') {
            return '';
        }

        if ($isBold) {
            $content = '<strong>'.$content.'</strong>';
        }
        if ($isItalic) {
            $content = '<em>'.$content.'</em>';
        }

        return $content;
    }

    private function parseDrawing(DOMXPath $xp, \DOMElement $drawingNode): string
    {
        $blips = $xp->query('.//a:blip', $drawingNode);
        if ($blips->length === 0) {
            return '';
        }

        /** @var \DOMElement $blip */
        $blip = $blips->item(0);
        $rId = $blip->getAttribute('r:embed');
        if (! $rId) {
            return '';
        }

        $base64 = $this->getMediaBase64($rId);
        if (! $base64) {
            return '';
        }

        // Extract dimensions from wp:extent
        $extent = $xp->query('.//wp:extent', $drawingNode)->item(0);
        $style = 'max-width: 100%; height: auto;'; // default responsive

        if ($extent instanceof \DOMElement) {
            $cx = (int) $extent->getAttribute('cx');
            $cy = (int) $extent->getAttribute('cy');
            // Convert EMUs to px. 1px = 9525 EMUs (approx 96 DPI).
            $w = round($cx / 9525);
            $h = round($cy / 9525);
            if ($w > 0 && $h > 0) {
                $style = "width:{$w}px;height:{$h}px;";
            }
        }

        return '<img src="'.$base64.'" style="'.$style.'" alt="Extended Image">';
    }

    private function el(\DOMNodeList $list): ?\DOMElement
    {
        if ($list->length < 1) {
            return null;
        }
        $n = $list->item(0);

        return ($n instanceof \DOMElement) ? $n : null;
    }

    private function getAlignment(DOMXPath $xp, $pNode): ?string
    {
        // 1) Direct override on the paragraph
        $jcEl = $this->el($xp->query('.//w:pPr/w:jc', $pNode));
        $val  = $this->attr($jcEl, 'val');
        $direct = match ($val) {
            'center'                        => 'center',
            'right'                         => 'right',
            'left'                          => 'left',
            'both', 'justify', 'distribute' => 'justify',
            default                         => null,
        };
        if ($direct !== null) {
            return $direct;
        }

        // 2) Fall back to paragraph style (styles.xml)
        $styleId = $this->getPStyleId($xp, $pNode);
        if ($styleId) {
            return $this->resolveStyleAlign($styleId);
        }

        return null;
    }

    private function getIndentation(\DOMXPath $xp, \DOMElement $pNode): array
    {
        // 1) Direct override on the paragraph
        $indEl = $this->firstEl($xp->query('.//w:pPr/w:ind', $pNode));
        if ($indEl) {
            return [
                'left'      => (int) ((int) $this->attr($indEl, 'left')      / 20),
                'hanging'   => (int) ((int) $this->attr($indEl, 'hanging')   / 20),
                'firstLine' => (int) ((int) $this->attr($indEl, 'firstLine') / 20),
            ];
        }

        // 2) Fall back to paragraph style (styles.xml)
        $styleId = $this->getPStyleId($xp, $pNode);
        if ($styleId) {
            return $this->resolveStyleInd($styleId);
        }

        return ['left' => 0, 'hanging' => 0, 'firstLine' => 0];
    }

    private function getNumbering(DOMXPath $xp, $pNode): ?array
    {
        $numPrEl = $this->el($xp->query('.//w:pPr/w:numPr', $pNode));
        if (! $numPrEl) {
            return null;
        }

        $ilvlEl = $this->el($xp->query('.//w:pPr/w:numPr/w:ilvl', $pNode));
        $numIdEl = $this->el($xp->query('.//w:pPr/w:numPr/w:numId', $pNode));

        $ilvlVal = $this->attr($ilvlEl, 'val');
        $numIdVal = $this->attr($numIdEl, 'val');

        if ($ilvlVal === null || $numIdVal === null) {
            return null;
        }

        return [
            'ilvl' => (int) $ilvlVal,
            'numId' => (string) $numIdVal,
        ];
    }

    private function renderParagraph(string $innerHtml, array $style): string
    {
        $styleStr = $this->styleToString($style);
        // ถ้าเป็น empty บรรทัด ให้คง space ไว้
        if (trim($this->stripTags($innerHtml)) === '') {
            return "<p class=\"doc-empty\" style=\"{$styleStr}\">&nbsp;</p>";
        }

        return "<p style=\"{$styleStr}\">{$innerHtml}</p>";
    }

    private function renderCenterHeading(string $innerHtml, array $style): string
    {
        $style['text-align'] = 'center';
        $style['margin'] = '0.75em 0 0.5em 0';

        $styleStr = $this->styleToString($style);

        return "<div class=\"doc-center-heading\" style=\"{$styleStr}\">{$innerHtml}</div>";
    }

    // ---------------------------
    // Lists stack
    // ---------------------------
    private function syncListStack(array &$stack, int $targetIlvl, string $type, string $numId, int $start): string
    {
        $html = '';

        // ลด stack ถ้าระดับลึกเกิน
        while (count($stack) > $targetIlvl + 1) {
            $top = array_pop($stack);
            $html .= "</{$top['type']}>";
        }

        // ถ้ายังไม่มี level นี้ ให้เปิดเพิ่มจนถึง ilvl
        while (count($stack) < $targetIlvl + 1) {
            $openType = (count($stack) === $targetIlvl) ? $type : 'ol';
            $openNumId = $numId;
            $openStart = $start;

            $stack[] = [
                'type' => $openType,
                'ilvl' => count($stack),
                'numId' => $openNumId,
                'counter' => 0,
                'start' => $openStart,
            ];

            $listStyle = $this->listStyleByLevel(count($stack) - 1);
            $startAttr = ($openType === 'ol' && $openStart !== 1) ? ' start="'.$openStart.'"' : '';
            $html .= "<{$openType}{$startAttr} style=\"{$listStyle}\">";
        }

        // ถ้า type เปลี่ยนในระดับเดียวกัน ปิดแล้วเปิดใหม่
        $topIndex = count($stack) - 1;
        if ($topIndex >= 0 && $stack[$topIndex]['type'] !== $type) {
            $old = array_pop($stack);
            $html .= "</{$old['type']}>";
            $stack[] = [
                'type' => $type,
                'ilvl' => $targetIlvl,
                'numId' => $numId,
                'counter' => 0,
                'start' => $start,
            ];
            $listStyle = $this->listStyleByLevel($targetIlvl);
            $startAttr = ($type === 'ol' && $start !== 1) ? ' start="'.$start.'"' : '';
            $html .= "<{$type}{$startAttr} style=\"{$listStyle}\">";
        }

        return $html;
    }

    private function closeAllLists(array &$stack): string
    {
        $html = '';
        while (! empty($stack)) {
            $top = array_pop($stack);
            $html .= "</{$top['type']}>";
        }

        return $html;
    }

    private function listStyleByLevel(int $level): string
    {
        // ให้ list อ่านแบบเอกสารไทย (เว้นระยะซ้ายตาม level)
        $pad = 24 + ($level * 28);

        return "margin:0 0 0.35em 0;padding-left:{$pad}px;line-height:1.75;";
    }

    private function liStyleFromIndent(array $ind, int $level): string
    {
        // $ind has [left, hanging, firstLine]
        // Usually list items have hanging indent.
        // We want to align the bullet/number with the text flow.

        $pad = 0;
        if ($ind['left'] > 0) {
            $pad = $ind['left'];
        }

        // Add level offset
        $levelOffset = $level * 10;
        $totalLeft = $pad + $levelOffset;

        return "margin:0 0 0.25em 0; padding-left:{$totalLeft}pt; text-align:justify;";
    }

    // ---------------------------
    // Tables with vMerge + gridSpan (colspan)
    // ---------------------------
    private function parseTable(DOMXPath $xp, $tableNode): string
    {
        // 1) สร้าง row matrix ก่อน
        $rows = [];
        foreach ($xp->query('w:tr', $tableNode) as $tr) {
            $row = [];
            foreach ($xp->query('w:tc', $tr) as $tc) {
                $colspan = 1;
                $gridSpan = $xp->query('.//w:tcPr/w:gridSpan', $tc);
                if ($gridSpan->length > 0) {
                    /** @var \DOMElement $el */
                    $el = $gridSpan->item(0);
                    $v = $el instanceof \DOMElement ? $el->getAttribute('w:val') : '';

                    if ($v !== '') {
                        $colspan = (int) $v;
                    }
                }

                // vMerge: restart | continue(null val)
                $vMergeNode = $xp->query('.//w:tcPr/w:vMerge', $tc);
                $vMerge = null;
                if ($vMergeNode->length > 0) {
                    /** @var \DOMElement $el */
                    $el = $vMergeNode->item(0);
                    $v = $el instanceof \DOMElement ? $el->getAttribute('w:val') : '';
                    $vMerge = ($v === 'restart') ? 'restart' : 'continue';
                }

                // cell content: รวม paragraph ใน cell
                $cellHtml = '';
                foreach ($xp->query('.//w:p', $tc) as $p) {
                    $pInfo = $this->readParagraphInfo($xp, $p);
                    // ใน cell ใช้ <div> margin 0
                    $cellHtml .= '<div class="cell-p">'.$pInfo['html'].'</div>';
                }

                // ถ้า cell ว่างจริง ๆ ให้คงความสูง
                if (trim($this->stripTags($cellHtml)) === '') {
                    $cellHtml = '&nbsp;';
                }

                // --- High Fidelity: Cell Shading & Alignment ---
                $style = '';

                // Shading (Background Color)
                $shd = $this->firstEl($xp->query('.//w:tcPr/w:shd', $tc));
                if ($shd) {
                    $fill = $this->attr($shd, 'fill');
                    if ($fill && $fill !== 'auto' && $fill !== '') {
                        $style .= "background-color: #{$fill};";
                    }
                }

                // Vertical Align
                $vAlignEl = $this->firstEl($xp->query('.//w:tcPr/w:vAlign', $tc));
                if ($vAlignEl) {
                    $val = $this->attr($vAlignEl, 'val');
                    $cssVal = match ($val) {
                        'center' => 'middle',
                        'bottom' => 'bottom',
                        default => 'top'
                    };
                    if ($cssVal !== 'top') {
                        $style .= "vertical-align: {$cssVal};";
                    }
                }

                $row[] = [
                    'html' => $cellHtml,
                    'colspan' => $colspan,
                    'vMerge' => $vMerge, // null|restart|continue
                    'rowspan' => 1,
                    'skip' => false,
                    'style' => $style,
                ];
            }
            $rows[] = $row;
        }

        // 2) คำนวณ rowspan จาก vMerge โดย track active merge per column index
        $active = []; // colIndex => ['r'=>rowIndex,'c'=>cellIndexRef,'remainingCols'=>n, 'cellRef'=>&...]
        // เพื่อ handle colspan: เราจะ map active ตาม col index ทีละช่อง

        // ต้อง render ตาม col grid index
        // loop row -> loop cell -> assign startCol index
        $gridStarts = []; // [rowIndex][cellIndex] => startCol

        foreach ($rows as $ri => &$row) {
            $col = 0;
            foreach ($row as $ci => &$cell) {
                // หา start col โดยข้าม col ที่ถูก occupy จาก active merge? (Word จะยังมี tc มา แต่ vMerge continue)
                $gridStarts[$ri][$ci] = $col;
                $col += $cell['colspan'];
            }
        }
        unset($row, $cell);

        foreach ($rows as $ri => &$row) {
            foreach ($row as $ci => &$cell) {
                $startCol = $gridStarts[$ri][$ci];
                $span = $cell['colspan'];
                $v = $cell['vMerge'];

                if ($v === 'continue') {
                    // merge ต่อกับด้านบน: เพิ่ม rowspan ให้ cell ต้นทาง แล้ว skip ตัวนี้
                    for ($k = 0; $k < $span; $k++) {
                        $colKey = $startCol + $k;
                        if (isset($active[$colKey])) {
                            $origin = &$active[$colKey]; // ['cellRef'=>&...]
                            $origin['cellRef']['rowspan'] += 1;
                            $cell['skip'] = true;
                        }
                    }
                    // active ยังคงอยู่เหมือนเดิม
                } elseif ($v === 'restart') {
                    // เริ่ม merge ใหม่: set active ให้ col range ชี้ไป cell นี้
                    for ($k = 0; $k < $span; $k++) {
                        $colKey = $startCol + $k;
                        $active[$colKey] = [
                            'cellRef' => &$cell,
                        ];
                    }
                } else {
                    // ไม่ merge: clear active ใน col range
                    for ($k = 0; $k < $span; $k++) {
                        $colKey = $startCol + $k;
                        unset($active[$colKey]);
                    }
                }
            }
        }
        unset($row, $cell);

        // 3) render HTML table
        $html = '<table class="doc-table"><tbody>';

        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                if ($cell['skip']) {
                    continue;
                }

                $attrs = [];
                if ($cell['colspan'] > 1) {
                    $attrs[] = 'colspan="'.$cell['colspan'].'"';
                }
                if ($cell['rowspan'] > 1) {
                    $attrs[] = 'rowspan="'.$cell['rowspan'].'"';
                }

                if (! empty($cell['style'])) {
                    $attrs[] = 'style="'.$cell['style'].'"';
                }

                $attrStr = empty($attrs) ? '' : ' '.implode(' ', $attrs);

                $html .= '<td class="doc-td"'.$attrStr.'>'.$cell['html'].'</td>';
            }
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';

        return $html;
    }

    // ---------------------------
    // Thai fixes + utils
    // ---------------------------
    private function repairThaiInHtml(string $html): string
    {
        // ทำเฉพาะ text node แบบง่าย: ใช้ regex ช่วยกับเคสเอกสารราชการที่เจอบ่อย
        
        // 1) แก้ ำ ที่แยก: ส า -> สำ, จ า -> จำ (รวมทุกรูปแบบ)
        $html = preg_replace('/([ก-ฮ])\s+า/u', '$1ำ', $html);
        $html = preg_replace('/([ก-ฮ])\s+ำ/u', '$1ำ', $html);
        
        // 2) แก้ไมม้วน (ไม้หันอากาศ) ที่แยก: ก ็ -> ก็
        $html = preg_replace('/([ก-ฮ])\s+็/u', '$1็', $html);
        
        // 3) ลบช่องว่างคั่นระหว่างพยัญชนะกับวรรณยุกต์/สระบน (ครอบคลุมทุกตัว)
        $upper = 'ิีึืัุู็่้๊๋์ํ';
        $html = preg_replace('/([ก-ฮ])\s+(['.$upper.'])/u', '$1$2', $html);
        $html = preg_replace('/(['.$upper.'])\s+([ก-ฮ])/u', '$1$2', $html);
        
        // 4) แก้สระบนที่ซ้อนกัน (เช่น ิ ้ -> ิ้)
        $html = preg_replace('/(['.$upper.'])\s+(['.$upper.'])/u', '$1$2', $html);

        // 5) ลบช่องว่างหลังสระหน้า เ แ โ ใ ไ
        $html = preg_replace('/([เแโใไ])\s+([ก-ฮ])/u', '$1$2', $html);
        
        // 6) ลบช่องว่างก่อนสระหน้า (กรณีพยัญชนะ + space + สระหน้า)
        $html = preg_replace('/([ก-ฮ])\s+([เแโใไ])/u', '$2$1', $html);

        // 7) แก้ "ำา" และ "าำ"
        $html = str_replace(['ำา', 'าำ'], 'ำ', $html);
        
        // 8) แก้สระ ะ ที่แยก
        $html = preg_replace('/([ก-ฮ])\s+ะ/u', '$1ะ', $html);
        
        // 9) แก้สระ ๅ (ไม้ยมก) ที่แยก
        $html = preg_replace('/([ก-ฮ])\s+ๅ/u', '$1ๅ', $html);

        return $html;
    }

    private function repairThaiText(string $t): string
    {
        if ($t === '') {
            return $t;
        }

        // เคสคำราชการที่ชอบแตก (เพิ่มเติม)
        $dict = [
            'ส านัก' => 'สำนัก',
            'จ านวน' => 'จำนวน',
            'น าไป' => 'นำไป',
            'อ านวย' => 'อำนวย',
            'จ าเป็น' => 'จำเป็น',
            'ประจ า' => 'ประจำ',
            'ก าหนด' => 'กำหนด',
            'ล าดับ' => 'ลำดับ',
            'ค าสั่ง' => 'คำสั่ง',
            'ท าการ' => 'ทำการ',
            'ด าเนิน' => 'ดำเนิน',
            'บ าบัด' => 'บำบัด',
            'ต าแหน่ง' => 'ตำแหน่ง',
            'ห าม' => 'ห้าม',
            'ค ่า' => 'ค่า',
            'ท ี่' => 'ที่',
            'ก ็' => 'ก็',
            'เพ ื่อ' => 'เพื่อ',
            'ต ้อง' => 'ต้อง',
            'ม ี' => 'มี',
            'ให ้' => 'ให้',
        ];
        $t = str_replace(array_keys($dict), array_values($dict), $t);

        // fix spacing ทั่วไป (เหมือน repairThaiInHtml)
        $t = preg_replace('/([ก-ฮ])\s+า/u', '$1ำ', $t);
        $t = preg_replace('/([ก-ฮ])\s+ำ/u', '$1ำ', $t);
        $t = preg_replace('/([ก-ฮ])\s+็/u', '$1็', $t);
        
        $upper = 'ิีึืัุู็่้๊๋์ํ';
        $t = preg_replace('/([ก-ฮ])\s+(['.$upper.'])/u', '$1$2', $t);
        $t = preg_replace('/(['.$upper.'])\s+([ก-ฮ])/u', '$1$2', $t);
        $t = preg_replace('/(['.$upper.'])\s+(['.$upper.'])/u', '$1$2', $t);
        
        $t = preg_replace('/([เแโใไ])\s+([ก-ฮ])/u', '$1$2', $t);
        $t = preg_replace('/([ก-ฮ])\s+([เแโใไ])/u', '$2$1', $t);
        
        $t = str_replace(['ำา', 'าำ'], 'ำ', $t);
        $t = preg_replace('/([ก-ฮ])\s+ะ/u', '$1ะ', $t);
        $t = preg_replace('/([ก-ฮ])\s+ๅ/u', '$1ๅ', $t);

        return $t;
    }

    private function stripUnderline(string $html): string
    {
        $html = preg_replace('/<u>(.*?)<\/u>/us', '$1', $html);
        $html = preg_replace('/text-decoration\s*:\s*underline[^;]*;?/i', '', $html);

        return $html;
    }

    private function stripTags(string $html): string
    {
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }

    private function styleToString(array $style): string
    {
        $pairs = [];
        foreach ($style as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $pairs[] = $k.':'.$v;
        }

        return implode(';', $pairs);
    }

    private function defaultCss(): string
    {
        // จุดสำคัญของ “Thai layout 100%”: ให้ word-break ถูก, spacing ดี, tab แสดงผล, ตารางแน่น
        return <<<'CSS'
.legal-document{
  font-family: "Sarabun", "Sarabun New", "TH Sarabun New", sans-serif;
  font-size: 16pt;
  line-height: 1.75;
  padding: 1in;
  background: #fff;
  color: #111;
  word-break: break-word;
  overflow-wrap: anywhere;
}

.legal-document p{
  margin: 0 0 0.35em 0;
  text-align: justify;
}

.legal-document .doc-empty{
  min-height: 1em;
}

.legal-document .doc-center-heading{
  width: 100%;
}

.legal-document .doc-tab{
  display: inline-block;
  width: 2.2em; /* ปรับได้: ให้ใกล้เคียง tab เอกสาร */
}

.legal-document .doc-table{
  width: 100%;
  border-collapse: collapse;
  table-layout: fixed;
  margin: 0.75em 0;
}

.legal-document .doc-td{
  border: 1px solid #000;
  padding: 10px 12px;
  vertical-align: top;
}

.legal-document .cell-p{
  margin: 0;
  line-height: 1.6;
}

.legal-document strong{ font-weight: 700; }
.legal-document em{ font-style: italic; }

CSS;
    }
}
