<?php

namespace App\Services;

use ZipArchive;
use SimpleXMLElement;

class DocxParser
{
    private ZipArchive $zip;
    private ?SimpleXMLElement $documentXml = null;
    private ?SimpleXMLElement $stylesXml = null;
    private ?SimpleXMLElement $numberingXml = null;
    
    private array $counters = [];
    private array $styles = [];
    private array $numbering = [];

    public function __construct(string $filePath)
    {
        $this->zip = new ZipArchive();
        if ($this->zip->open($filePath) !== true) {
            throw new \Exception('Cannot open DOCX file');
        }

        $this->loadXmlFiles();
        $this->parseStyles();
        $this->parseNumbering();
    }

    private function loadXmlFiles(): void
    {
        $documentXml = $this->zip->getFromName('word/document.xml');
        $stylesXml = $this->zip->getFromName('word/styles.xml');
        $numberingXml = $this->zip->getFromName('word/numbering.xml');

        $this->documentXml = simplexml_load_string($documentXml);
        $this->stylesXml = $stylesXml ? simplexml_load_string($stylesXml) : null;
        $this->numberingXml = $numberingXml ? simplexml_load_string($numberingXml) : null;
    }

    private function parseStyles(): void
    {
        if (!$this->stylesXml) return;

        foreach ($this->stylesXml->xpath('//w:style') as $style) {
            $styleId = (string) $style['w:styleId'];
            $this->styles[$styleId] = [
                'name' => (string) $style['w:name'] ?? '',
                'basedOn' => (string) $style['w:basedOn'] ?? '',
                'jc' => (string) $style->xpath('.//w:jc')[0]['w:val'] ?? 'left',
                'indent' => (string) $style->xpath('.//w:ind')[0]['w:left'] ?? '0',
                'firstLine' => (string) $style->xpath('.//w:ind')[0]['w:firstLine'] ?? '0',
                'rFonts' => (string) $style->xpath('.//w:rFonts')[0]['w:ascii'] ?? 'Times New Roman',
                'sz' => (string) $style->xpath('.//w:sz')[0]['w:val'] ?? '24',
            ];
        }
    }

    private function parseNumbering(): void
    {
        if (!$this->numberingXml) return;

        foreach ($this->numberingXml->xpath('//w:num') as $num) {
            $numId = (string) $num['w:numId'];
            foreach ($num->xpath('.//w:lvl') as $lvl) {
                $ilvl = (string) $lvl['w:ilvl'];
                $this->numbering[$numId][$ilvl] = [
                    'format' => (string) $lvl->xpath('.//w:numFmt')[0]['w:val'] ?? 'decimal',
                    'start' => (int) $lvl->xpath('.//w:start')[0]['w:val'] ?? 1,
                    'text' => (string) $lvl->xpath('.//w:lvlText')[0]['w:val'] ?? '%1.',
                ];
            }
        }
    }

    public function toHtml(): string
    {
        $html = '<div class="docx-document">';
        
        foreach ($this->documentXml->xpath('//w:body/*') as $element) {
            $html .= $this->processElement($element);
        }
        
        $html .= '</div>';
        
        // Apply Thai text fixes
        $html = $this->fixThaiText($html);
        
        return $html;
    }

    private function processElement(SimpleXMLElement $element): string
    {
        switch ($element->getName()) {
            case 'p':
                return $this->processParagraph($element);
            case 'tbl':
                return $this->processTable($element);
            default:
                return '';
        }
    }

    private function processParagraph(SimpleXMLElement $paragraph): string
    {
        $pPr = $paragraph->xpath('.//w:pPr')[0] ?? null;
        $style = $this->getParagraphStyle($pPr);
        
        $text = '';
        $hasTab = false;
        
        foreach ($paragraph->xpath('.//w:r') as $run) {
            $runText = $this->processRun($run);
            $text .= $runText;
            
            // Check for tab
            if ($run->xpath('.//w:tab')) {
                $hasTab = true;
            }
        }

        // Handle numbering
        $numPr = $pPr->xpath('.//w:numPr')[0] ?? null;
        if ($numPr) {
            $numId = (string) $numPr->xpath('.//w:numId')[0]['w:val'] ?? '0';
            $ilvl = (string) $numPr->xpath('.//w:ilvl')[0]['w:val'] ?? '0';
            $number = $this->getNumberingLabel($numId, $ilvl);
            $text = $number . ' ' . $text;
        }

        $attributes = $this->buildStyleAttributes($style);
        
        return "<p style=\"{$attributes}\">{$text}</p>";
    }

    private function processRun(SimpleXMLElement $run): string
    {
        $text = '';
        $rPr = $run->xpath('.//w:rPr')[0] ?? null;
        
        foreach ($run->xpath('.//w:t') as $t) {
            $text .= htmlspecialchars((string) $t);
        }
        
        // Handle tabs
        foreach ($run->xpath('.//w:tab') as $tab) {
            $pos = (int) ($tab['w:pos'] ?? 720);
            $width = $this->twipToInch($pos);
            $text .= "<span class=\"docx-tab\" style=\"display:inline-block;width:{$width}in\"></span>";
        }
        
        // Apply text formatting
        if ($rPr) {
            $text = $this->applyTextFormatting($text, $rPr);
        }
        
        return $text;
    }

    private function processTable(SimpleXMLElement $table): string
    {
        $html = '<table class="docx-table">';
        
        // Process table grid
        $tblGrid = $table->xpath('.//w:tblGrid')[0] ?? null;
        $colgroup = '<colgroup>';
        
        if ($tblGrid) {
            foreach ($tblGrid->xpath('.//w:gridCol') as $col) {
                $width = (int) ($col['w:w'] ?? 1000);
                $widthInch = $this->twipToInch($width);
                $colgroup .= "<col style=\"width:{$widthInch}in\">";
            }
        }
        $colgroup .= '</colgroup>';
        
        $html .= $colgroup;
        
        // Process table rows
        foreach ($table->xpath('.//w:tr') as $row) {
            $html .= $this->processTableRow($row);
        }
        
        $html .= '</table>';
        return $html;
    }

    private function processTableRow(SimpleXMLElement $row): string
    {
        $html = '<tr>';
        
        foreach ($row->xpath('.//w:tc') as $cell) {
            $html .= $this->processTableCell($cell);
        }
        
        $html .= '</tr>';
        return $html;
    }

    private function processTableCell(SimpleXMLElement $cell): string
    {
        $tcPr = $cell->xpath('.//w:tcPr')[0] ?? null;
        
        // Handle merged cells
        $vMerge = $tcPr->xpath('.//w:vMerge')[0] ?? null;
        $rowspan = '';
        $colspan = '';
        
        if ($vMerge) {
            if (isset($vMerge['w:val']) && (string) $vMerge['w:val'] === 'restart') {
                // This is the start of a vertical merge
                $rowspan = $this->calculateRowspan($cell);
            } elseif (!isset($vMerge['w:val'])) {
                // Continuation of vertical merge
                return '<td style="display:none;"></td>';
            }
        }
        
        $content = '';
        foreach ($cell->xpath('.//w:p') as $paragraph) {
            $content .= $this->processParagraph($paragraph);
        }
        
        $attributes = "border:1px solid #000;padding:6pt;vertical-align:top;";
        if ($rowspan) $attributes .= "rowspan:{$rowspan};";
        if ($colspan) $attributes .= "colspan:{$colspan};";
        
        return "<td style=\"{$attributes}\">{$content}</td>";
    }

    private function getParagraphStyle(?SimpleXMLElement $pPr): array
    {
        $style = [];
        
        if (!$pPr) {
            return [
                'jc' => 'left',
                'indent' => '0',
                'firstLine' => '0',
                'font' => 'Times New Roman',
                'size' => '24'
            ];
        }
        
        $jc = $pPr->xpath('.//w:jc')[0] ?? null;
        $style['jc'] = $jc ? (string) $jc['w:val'] : 'left';
        
        $ind = $pPr->xpath('.//w:ind')[0] ?? null;
        if ($ind) {
            $style['indent'] = (string) ($ind['w:left'] ?? '0');
            $style['firstLine'] = (string) ($ind['w:firstLine'] ?? '0');
        }
        
        $rFonts = $pPr->xpath('.//w:rFonts')[0] ?? null;
        $style['font'] = $rFonts ? (string) ($rFonts['w:ascii'] ?? 'Times New Roman') : 'Times New Roman';
        
        $sz = $pPr->xpath('.//w:sz')[0] ?? null;
        $style['size'] = $sz ? (string) $sz['w:val'] : '24';
        
        return $style;
    }

    private function buildStyleAttributes(array $style): string
    {
        $attributes = [];
        
        // Text alignment
        $jc = $style['jc'] ?? 'left';
        $attributes[] = "text-align:{$jc}";
        
        // Indentation
        if (isset($style['indent']) && $style['indent'] !== '0') {
            $left = $this->twipToInch((int) $style['indent']);
            $attributes[] = "margin-left:{$left}in";
        }
        
        if (isset($style['firstLine']) && $style['firstLine'] !== '0') {
            $firstLine = $this->twipToInch((int) $style['firstLine']);
            $attributes[] = "text-indent:{$firstLine}in";
        }
        
        // Font
        $font = $style['font'] ?? 'Times New Roman';
        $size = $style['size'] ?? '24';
        $sizePt = $this->twipToPt((int) $size);
        
        $attributes[] = "font-family:'{$font}',sans-serif";
        $attributes[] = "font-size:{$sizePt}pt";
        $attributes[] = "line-height:1.6";
        
        return implode(';', $attributes);
    }

    private function applyTextFormatting(string $text, SimpleXMLElement $rPr): string
    {
        $formatted = $text;
        
        // Bold
        $b = $rPr->xpath('.//w:b')[0] ?? null;
        if ($b) {
            $formatted = "<strong>{$formatted}</strong>";
        }
        
        // Italic
        $i = $rPr->xpath('.//w:i')[0] ?? null;
        if ($i) {
            $formatted = "<em>{$formatted}</em>";
        }
        
        // Underline
        $u = $rPr->xpath('.//w:u')[0] ?? null;
        if ($u) {
            $formatted = "<u>{$formatted}</u>";
        }
        
        return $formatted;
    }

    private function getNumberingLabel(string $numId, string $ilvl): string
    {
        $level = (int) $ilvl;
        
        // Initialize counter if not exists
        if (!isset($this->counters[$numId])) {
            $this->counters[$numId] = [];
        }
        
        if (!isset($this->counters[$numId][$level])) {
            $this->counters[$numId][$level] = 0;
        }
        
        // Reset lower level counters
        for ($i = $level + 1; $i < 10; $i++) {
            $this->counters[$numId][$i] = 0;
        }
        
        // Increment current level counter
        $this->counters[$numId][$level]++;
        
        // Build label based on numbering definition
        $numDef = $this->numbering[$numId][$ilvl] ?? null;
        if (!$numDef) {
            return implode('.', array_slice($this->counters[$numId], 0, $level + 1));
        }
        
        $format = $numDef['format'];
        $counters = array_slice($this->counters[$numId], 0, $level + 1);
        
        switch ($format) {
            case 'decimal':
                return implode('.', $counters) . '.';
            case 'lowerLetter':
                return chr(96 + end($counters)) . '.';
            case 'upperLetter':
                return chr(64 + end($counters)) . '.';
            default:
                return $numDef['text'] ?? '%1.';
        }
    }

    private function calculateRowspan(SimpleXMLElement $cell): int
    {
        // This is simplified - in real implementation, you'd need to track
        // the actual rowspan by counting consecutive vMerge elements
        return 2;
    }

    private function twipToInch(int $twip): float
    {
        return round($twip / 1440, 3);
    }

    private function twipToPt(int $twip): float
    {
        return round($twip / 2, 1);
    }

    private function fixThaiText(string $text): string
    {
        // Fix common Thai spacing issues
        $text = preg_replace('/([ก-ฮ])\s+า/u', '$1ำ', $text);
        $text = preg_replace('/\s+า/u', 'ำ', $text);
        $text = preg_replace('/([ก-ฮ])\s+[\x{0E4D}]?\s*า/u', '$1ำ', $text);
        $text = preg_replace('/([ก-ฮ])\s+[\x{0E4D}]/u', '$1ำ', $text);
        
        // Fix spacing around Thai vowels
        $upper_marks = 'ิีึืั็่้๊๋์';
        $text = preg_replace('/([ก-ฮ])\s+([' . $upper_marks . '])/u', '$1$2', $text);
        $text = preg_replace('/([' . $upper_marks . '])\s+([ก-ฮ])/u', '$1$2', $text);
        $text = preg_replace('/([เแโใไ])\s+([ก-ฮ])/u', '$1$2', $text);
        
        return $text;
    }

    public function __destruct()
    {
        $this->zip->close();
    }
}
