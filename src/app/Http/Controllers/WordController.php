<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use PhpOffice\PhpWord\IOFactory;
use Spatie\PdfToText\Pdf;
use App\Models\Document;
use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser;

class WordController extends Controller
{
    public function index()
    {
        return view('app');
    }

    public function convert(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:docx,pdf|max:10240'
        ]);

        $file = $request->file('file');
        $extension = $file->getClientOriginalExtension();
        $html = "";

        if ($extension === 'docx') {
            $phpWord = IOFactory::load($file->getPathname());

            $writer = IOFactory::createWriter($phpWord, 'HTML');
            ob_start();
            $writer->save('php://output');
            $content = ob_get_clean();

            // เอาเฉพาะ body
            if (preg_match('/<body[^>]*>(.*?)<\/body>/is', $content, $m)) {
                $html = $m[1];
            } else {
                $html = $content;
            }

            // normalize ตารางให้ Tiptap
            $html = $this->normalizeDocxHtmlForTiptap($html);

            $html = $this->repairThai($html);
            $html = $this->stripUnderline($html);

            // wrap font
            $html = '<div class="legal-document" style="font-family:\'Sarabun New\',sans-serif;font-size:16pt;line-height:1.5;">'
                . $html
                . '</div>';
        } else if ($extension === 'pdf') {
            $parser = new Parser();
            $pdf = $parser->parseFile($file->getPathname());
            $text = $pdf->getText();

            $text = $this->repairThaiAdvanced($text);

            $lines = explode("\n", $text);
            $processedHtml = "";

            foreach ($lines as $line) {
                $rawLine = $line;
                $line = trim($line, "\r\n");
                $trimmedLine = trim($line);

                if (empty($trimmedLine)) {
                    $processedHtml .= "<p style='margin: 0; min-height: 1em;'>&nbsp;</p>";
                    continue;
                }

                $style = "margin-bottom: 0.1em; line-height: 1.6; clear: both;";
                $isTitle = false;

                // --- 1. ตรวจจับหัวข้อกึ่งกลาง (Center Alignment) ---
                // เช็คจาก Keyword หรือ บรรทัดที่มี Space นำหน้าเยอะๆ (เช่น ส่วนหัว TOR)
                if (
                    preg_match('/^(- ร่าง -|ขอบเขตของงาน|โครงการ|ประจำปีงบประมาณ|TOR|Terms of Reference)/u', $trimmedLine) ||
                    preg_match('/^\(.*\)$/u', $trimmedLine) || // ข้อความในวงเล็บกึ่งกลาง เช่น (Terms of Reference : TOR)
                    (preg_match('/^\s{12,}/u', $rawLine) && mb_strlen($trimmedLine) < 100)
                ) {
                    $style .= " text-align: center; font-weight: bold; font-size: 18pt; display: block; width: 100%; margin-top: 0.5em;";
                    $isTitle = true;
                }

                // --- 2. ตรวจจับลำดับหัวข้อและการเยื้อง (Tab/Indent Alignment) ---

                // หัวข้อหลัก: เลขตัวเดียว หรือ เลขไทยตัวเดียว (เช่น 1. , ๒.)
                else if (preg_match('/^(\d+|[๑-๙]+)\.(?!\d)/u', $trimmedLine)) {
                    $style .= " font-weight: bold; text-align: left; margin-top: 1.2em; font-size: 16pt;";
                    $isTitle = true;
                }

                // หัวข้อย่อยชั้นที่ 1: (เช่น 1.1 , ๑.๑) -> Tab 1
                else if (preg_match('/^(\d+|[๑-๙]+)\.(\d+|[๑-๙]+)\s+/u', $trimmedLine)) {
                    $style .= " padding-left: 3em; text-align: justify;";
                }

                // หัวข้อย่อยชั้นที่ 2: (เช่น 1.1.1 หรือ (1)) -> Tab 2
                else if (preg_match('/^(\d+\.\d+\.\d+|(\(\d+\))|([๑-๙]+\.[๑-๙]+\.[๑-๙]+))/u', $trimmedLine)) {
                    $style .= " padding-left: 5em; text-align: justify;";
                }

                // ย่อหน้าเนื้อหาปกติ (ตรวจจับจากการเคาะ Space นำหน้าบรรทัด)
                else if (preg_match('/^\s{2,10}/u', $rawLine)) {
                    $style .= " padding-left: 3em; text-indent: 2em; text-align: justify;";
                }

                // กรณีบรรทัดต่อจากหัวข้อ (Hanging Indent)
                else {
                    $style .= " text-align: justify;";
                }

                // ทำความสะอาดช่องว่างภายในบรรทัด
                $content = htmlspecialchars($trimmedLine);
                $content = preg_replace_callback('/  +/', function ($match) {
                    return str_repeat('&nbsp;', strlen($match[0]));
                }, $content);

                // สร้าง Output HTML
                if ($isTitle) {
                    $processedHtml .= "<div style='{$style}'>" . $content . "</div>";
                } else {
                    $processedHtml .= "<p style='{$style}'>" . $content . "</p>";
                }
            }

            $html = '<div class="legal-document" style="font-family: \'Sarabun New\', sans-serif; font-size: 16pt; padding: 1in; background: white;">'
                . $processedHtml .
                '</div>';
        }

        return response()->json([
            'content' => $html,
            'type' => $extension
        ]);
    }

    private function normalizeDocxHtmlForTiptap(string $html): string
    {
        if ($html === '') return $html;

        // 1) normalize <table> : merge class + ensure style + ensure tbody
        $html = preg_replace_callback('/<table\b([^>]*)>/i', function ($m) {
            $attrs = $m[1];

            // --- merge class ---
            if (preg_match('/\bclass\s*=\s*"([^"]*)"/i', $attrs, $cm)) {
                $classes = trim($cm[1]);
                // เติม doc-table ถ้ายังไม่มี
                if (!preg_match('/\bdoc-table\b/i', $classes)) {
                    $classes .= ' doc-table';
                }
                $attrs = preg_replace('/\bclass\s*=\s*"[^"]*"/i', 'class="' . $classes . '"', $attrs, 1);
            } else {
                $attrs .= ' class="doc-table"';
            }

            // --- ensure style (merge style ไม่ยัดซ้ำแบบพัง) ---
            $needStyle = "border-collapse:collapse;width:100%;table-layout:fixed;";
            if (preg_match('/\bstyle\s*=\s*"([^"]*)"/i', $attrs, $sm)) {
                $style = $sm[1];
                // เติมเฉพาะที่ยังไม่มี
                foreach (explode(';', $needStyle) as $rule) {
                    $rule = trim($rule);
                    if ($rule === '') continue;
                    $prop = trim(explode(':', $rule)[0]);
                    if ($prop && stripos($style, $prop . ':') === false) {
                        $style .= ';' . $rule;
                    }
                }
                $attrs = preg_replace('/\bstyle\s*=\s*"[^"]*"/i', 'style="' . $style . '"', $attrs, 1);
            } else {
                $attrs .= ' style="' . $needStyle . '"';
            }

            return '<table' . $attrs . '>';
        }, $html);

        // 2) ใส่ <tbody> ถ้าไม่มี (Tiptap ชอบมาก)
        $html = preg_replace_callback('/<table\b[^>]*>.*?<\/table>/is', function ($m) {
            $table = $m[0];
            if (stripos($table, '<tbody') !== false) return $table;

            // ใส่ tbody ครอบเฉพาะ tr (อย่าทับ thead ถ้ามี)
            if (stripos($table, '<thead') !== false) {
                // ถ้ามี thead ให้ห่อเฉพาะส่วนหลัง thead
                $table = preg_replace('/(<\/thead>)/i', '$1<tbody>', $table, 1);
                $table = preg_replace('/<\/table>/i', '</tbody></table>', $table, 1);
                return $table;
            }

            $table = preg_replace('/<table\b([^>]*)>/i', '<table$1><tbody>', $table, 1);
            $table = preg_replace('/<\/table>/i', '</tbody></table>', $table, 1);
            return $table;
        }, $html);

        // 3) ใส่ class td/th แบบ merge (ห้ามทำให้ class ซ้ำ)
        $html = preg_replace_callback('/<(td|th)\b([^>]*)>/i', function ($m) {
            $tag = strtolower($m[1]);
            $attrs = $m[2];
            $need = ($tag === 'th') ? 'doc-th' : 'doc-td';

            if (preg_match('/\bclass\s*=\s*"([^"]*)"/i', $attrs, $cm)) {
                $classes = trim($cm[1]);
                if (!preg_match('/\b' . preg_quote($need, '/') . '\b/i', $classes)) {
                    $classes .= ' ' . $need;
                }
                $attrs = preg_replace('/\bclass\s*=\s*"[^"]*"/i', 'class="' . $classes . '"', $attrs, 1);
            } else {
                $attrs .= ' class="' . $need . '"';
            }

            return '<' . $tag . $attrs . '>';
        }, $html);

        // 4) ลดปัญหา <p> ใน cell: บังคับ margin 0
        $html = preg_replace(
            '/(<(td|th)[^>]*>)\s*<p[^>]*>/i',
            '$1<p style="margin:0; line-height:1.2;">',
            $html
        );

        // 5) ลบ <p> ว่าง
        $html = preg_replace('/<p[^>]*>\s*<\/p>/i', '', $html);

        return $html;
    }


    private function detectElementType($element): string
    {
        if ($element instanceof \PhpOffice\PhpWord\Element\TextRun) {
            return 'TextRun';
        }
        if ($element instanceof \PhpOffice\PhpWord\Element\Text) {
            return 'Text';
        }
        if ($element instanceof \PhpOffice\PhpWord\Element\TextBreak) {
            return 'TextBreak';
        }
        if ($element instanceof \PhpOffice\PhpWord\Element\Table) {
            return 'Table';
        }
        if ($element instanceof \PhpOffice\PhpWord\Element\ListItem) {
            return 'ListItem';
        }
        if ($element instanceof \PhpOffice\PhpWord\Element\Title) {
            return 'Title';
        }
        if ($element instanceof \PhpOffice\PhpWord\Element\Image) {
            return 'Image';
        }
        if ($element instanceof \PhpOffice\PhpWord\Element\Link) {
            return 'Link';
        }
        if ($element instanceof \PhpOffice\PhpWord\Element\PageBreak) {
            return 'PageBreak';
        }

        $class = is_object($element) ? get_class($element) : '';
        if ($class !== '' && (stripos($class, 'Math') !== false || stripos($class, 'OMath') !== false)) {
            return 'Math';
        }

        return $class !== '' ? $class : 'Unknown';
    }

    private function buildElementGraph(\PhpOffice\PhpWord\PhpWord $phpWord): array
    {
        $graph = [];
        $sectionIndex = 0;

        foreach ($phpWord->getSections() as $section) {
            $sectionIndex++;
            $elements = [];

            foreach ($section->getElements() as $element) {
                $elements[] = $this->buildElementGraphNode($element);
            }

            $graph[] = [
                'type' => 'Section',
                'index' => $sectionIndex,
                'elements' => $elements
            ];
        }

        return $graph;
    }

    private function repairThaiAdvanced(string $text): string
    {
        if (empty($text)) return $text;

        // 1. จัดการ "พยัญชนะ + space + านัก/านวน/า" ให้กลายเป็น สระอำ
        // โดยเน้นกลุ่มคำที่พบบ่อยในเอกสารราชการ
        $common_am_words = ['สำ', 'จำ', 'นำ', 'อำ', 'ดำ', 'ตำ', 'ทำ'];
        foreach ($common_am_words as $word) {
            $char = mb_substr($word, 0, 1);
            // แก้ไข: ส [space] า -> สำ, จ [space] า -> จำ
            $text = preg_replace('/' . $char . '\s+า/u', $word, $text);
        }

        // 2. แก้ไขกรณีทั่วไปของสระอำ: [พยัญชนะ] + [space] + [นิคหิต ํ] + [space] + [สระอา า]
        // หรือ [พยัญชนะ] + [space] + า
        $text = preg_replace('/([ก-ฮ])\s+[\x{0E4D}]?\s*า/u', '$1ำ', $text);

        // 3. แก้ไขกรณีมีวงกลม (นิคหิต) แต่ไม่มีสระอาตามมา (ถ้ามี)
        $text = preg_replace('/([ก-ฮ])\s+[\x{0E4D}]/u', '$1ำ', $text);

        // 4. ลบช่องว่างที่แทรกกลางคำ (สระบน/วรรณยุกต์) เช่น "ที่" เป็น "ท ี่"
        $upper_marks = 'ิีึืั็่้๊๋์';
        $text = preg_replace('/([ก-ฮ])\s+([' . $upper_marks . '])/u', '$1$2', $text);

        // 5. ลบช่องว่างที่แทรกระหว่างสระบนกับตัวสะกด เช่น "นั ก" -> "นัก"
        $text = preg_replace('/([' . $upper_marks . '])\s+([ก-ฮ])/u', '$1$2', $text);

        // 6. ลบช่องว่างหลังสระหน้า (เ แ โ ใ ไ) เช่น "เ พื่อ" -> "เพื่อ"
        $text = preg_replace('/([เแโใไ])\s+([ก-ฮ])/u', '$1$2', $text);

        // 7. แก้ไข "ำา" (สระอำซ้ำซ้อน)
        $text = str_replace('ำา', 'ำ', $text);

        // 8. Dictionary เฉพาะจุดสำหรับเคสที่คุณส่งมา
        $dictionary = [
            'ส านัก' => 'สำนัก',
            'จ านวน' => 'จำนวน',
            'น าไป' => 'นำไป',
            'อ านวย' => 'อำนวย',
            'จ าเป็น' => 'จำเป็น',
            'ประจ า' => 'ประจำ'
        ];
        $text = str_replace(array_keys($dictionary), array_values($dictionary), $text);

        return $text;
    }

    private function buildElementGraphNode($element): array
    {
        $type = $this->detectElementType($element);
        $node = [
            'type' => $type,
            'class' => is_object($element) ? get_class($element) : null,
        ];

        if ($element instanceof \PhpOffice\PhpWord\Element\Text) {
            $node['text'] = $element->getText();
        }

        if ($element instanceof \PhpOffice\PhpWord\Element\TextRun) {
            $children = [];
            foreach ($element->getElements() as $child) {
                $children[] = $this->buildElementGraphNode($child);
            }
            $node['children'] = $children;
        }

        if ($element instanceof \PhpOffice\PhpWord\Element\Table) {
            $node['rows'] = count($element->getRows());
        }

        return $node;
    }

    private function processElement($element): string
    {
        if ($element instanceof \PhpOffice\PhpWord\Element\TextRun) {
            return $this->processTextRun($element);
        }
        if ($element instanceof \PhpOffice\PhpWord\Element\Text) {
            return $this->processText($element);
        }
        if ($element instanceof \PhpOffice\PhpWord\Element\TextBreak) {
            return '<br>';
        }
        if ($element instanceof \PhpOffice\PhpWord\Element\Table) {
            return $this->processTable($element);
        }
        if ($element instanceof \PhpOffice\PhpWord\Element\ListItem) {
            return $this->processListItem($element);
        }
        if ($element instanceof \PhpOffice\PhpWord\Element\Title) {
            return $this->processTitle($element);
        }
        if ($element instanceof \PhpOffice\PhpWord\Element\Link) {
            return $this->processLink($element);
        }
        if ($element instanceof \PhpOffice\PhpWord\Element\PageBreak) {
            return '<div style="page-break-after: always;"></div>';
        }

        $type = $this->detectElementType($element);
        if ($type === 'Math') {
            return $this->processUnknownMathElement($element);
        }

        return '';
    }

    private function processTextRun(\PhpOffice\PhpWord\Element\TextRun $textRun): string
    {
        $parts = [];
        foreach ($textRun->getElements() as $child) {
            $parts[] = $this->processElement($child);
        }

        $content = implode('', $parts);
        if ($content === '') {
            return '';
        }

        // Extract paragraph alignment if available
        $align = $this->getParagraphAlignment($textRun);
        $alignStyle = $align ? "text-align: $align;" : '';
        return '<p style="' . $alignStyle . '">' . $content . '</p>';
    }

    private function processText(\PhpOffice\PhpWord\Element\Text $text): string
    {
        $content = htmlspecialchars($text->getText(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $fontStyle = $text->getFontStyle();

        $styles = [];
        $fontName = null;

        if ($fontStyle) {
            if (method_exists($fontStyle, 'isBold') && $fontStyle->isBold()) {
                $content = '<strong>' . $content . '</strong>';
            }
            if (method_exists($fontStyle, 'isItalic') && $fontStyle->isItalic()) {
                $content = '<em>' . $content . '</em>';
            }
            // Ignore underline on import (per plan)
            if (method_exists($fontStyle, 'isStrikethrough') && $fontStyle->isStrikethrough()) {
                $content = '<s>' . $content . '</s>';
            }

            if (method_exists($fontStyle, 'getColor') && $fontStyle->getColor()) {
                $styles[] = 'color: #' . $fontStyle->getColor();
            }
            if (method_exists($fontStyle, 'getSize') && $fontStyle->getSize()) {
                $styles[] = 'font-size: ' . $fontStyle->getSize() . 'pt';
            }
            if (method_exists($fontStyle, 'getName') && $fontStyle->getName()) {
                $fontName = $fontStyle->getName();
                $styles[] = 'font-family: \'{$fontName}\', sans-serif';
            }
        }

        if ($fontName && stripos($fontName, 'Math') !== false) {
            $styles[] = 'font-family: \'Cambria Math\', \'Times New Roman\', serif';
        }

        if (!empty($styles)) {
            return '<span style="' . implode('; ', $styles) . '">' . $content . '</span>';
        }

        return $content;
    }

    private function getParagraphAlignment(\PhpOffice\PhpWord\Element\TextRun $textRun): ?string
    {
        // Try to get paragraph style from the TextRun
        $paragraphStyle = null;
        if (method_exists($textRun, 'getParagraphStyle')) {
            $paragraphStyle = $textRun->getParagraphStyle();
        }
        // Fallback: some PhpWord versions store it in getStyle()
        if (!$paragraphStyle && method_exists($textRun, 'getStyle')) {
            $paragraphStyle = $textRun->getStyle();
        }
        if (!$paragraphStyle) {
            return null;
        }
        if (is_string($paragraphStyle)) {
            // Style name; we cannot resolve without full style registry, skip
            return null;
        }
        if (method_exists($paragraphStyle, 'getAlignment')) {
            $align = $paragraphStyle->getAlignment();
            return match ($align) {
                'left' => 'left',
                'center' => 'center',
                'right' => 'right',
                'both' => 'justify',
                'justify' => 'justify',
                default => null,
            };
        }
        return null;
    }

    private function repairThai(string $html): string
    {
        // Fix common Thai spacing issues, especially ำ
        // ส า -> สำ, [พยัญชนะ] ำ -> [พยัญชนะ]ำ
        $html = preg_replace('/([ก-ฮ])\s+ำ/u', '$1ำ', $html);
        // Also handle standalone ำ with space before
        $html = preg_replace('/\s+ำ/u', 'ำ', $html);
        return $html;
    }

    private function stripUnderline(string $html): string
    {
        // Remove <u> tags and any text-decoration: underline from styles
        $html = preg_replace('/<u>(.*?)<\/u>/us', '$1', $html);
        $html = preg_replace('/text-decoration\s*:\s*underline[^;]*;?/i', '', $html);
        return $html;
    }

    private function processLink(\PhpOffice\PhpWord\Element\Link $link): string
    {
        $text = htmlspecialchars($link->getText(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $url = htmlspecialchars($link->getSource(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<a href="' . $url . '">' . $text . '</a>';
    }

    private function processTable(\PhpOffice\PhpWord\Element\Table $table): string
    {
        $rowsHtml = [];
        foreach ($table->getRows() as $row) {
            $cellsHtml = [];
            foreach ($row->getCells() as $cell) {
                $cellParts = [];
                foreach ($cell->getElements() as $child) {
                    $cellParts[] = $this->processElement($child);
                }
                $cellContent = implode('', $cellParts);
                $cellsHtml[] = '<td class="doc-td" style="border: 1px solid #000; padding: 8px; vertical-align: top;">' . $cellContent . '</td>';
            }
            $rowsHtml[] = '<tr>' . implode('', $cellsHtml) . '</tr>';
        }

        return '<table class="doc-table" style="border-collapse: collapse; width: 100%; margin-bottom: 1em; font-family: \'Sarabun New\', sans-serif;"><tbody>' . implode('', $rowsHtml) . '</tbody></table>';
    }

    private function processListItem(\PhpOffice\PhpWord\Element\ListItem $listItem): string
    {
        $text = htmlspecialchars($listItem->getText(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $depth = $listItem->getDepth();
        $style = 'margin-left: ' . ($depth * 2) . 'em;';
        return '<li style="' . $style . '">' . $text . '</li>';
    }

    private function processTitle(\PhpOffice\PhpWord\Element\Title $title): string
    {
        $text = htmlspecialchars($title->getText(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $depth = $title->getDepth();
        $level = min(max($depth + 1, 1), 6);
        return '<h' . $level . ' style="font-weight: bold; margin: 1em 0 0.5em 0;">' . $text . '</h' . $level . '>';
    }

    private function processUnknownMathElement($element): string
    {
        if (is_object($element) && method_exists($element, 'getText')) {
            $text = htmlspecialchars((string) $element->getText(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            return '<span style="font-family: \'Cambria Math\', \'Times New Roman\', serif;">' . $text . '</span>';
        }

        return '';
    }

    public function store(Request $request)
    {
        $request->validate([
            'content' => 'required',
            'title' => 'nullable|string|max:255'
        ]);

        $document = Document::create([
            'title' => $request->input('title', 'Untitled Document'),
            'content' => $request->input('content')
        ]);

        return response()->json([
            'message' => 'Document saved successfully',
            'id' => $document->id
        ]);
    }
}
