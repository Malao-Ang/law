<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use PhpOffice\PhpWord\IOFactory;
use App\Models\Document;
use Illuminate\Support\Facades\Log;

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
            if ($request->boolean('debug')) {
                return response()->json([
                    'graph' => $this->buildElementGraph($phpWord),
                    'type' => $extension
                ]);
            }

            $html = $this->convertDocxToHtmlByElements($phpWord);
            $html = $this->repairThai($html);
            $html = $this->stripUnderline($html);
            // ส่วนใน public function convert
        } else if ($extension === 'pdf') {
            $filePath = $file->getPathname();

            // 1. ใช้ Smalot Parser (มักจะแม่นยำกว่า pdftotext ในเรื่องสระไทย)
            try {
                $parser = new \Smalot\PdfParser\Parser();
                $pdf = $parser->parseFile($filePath);
                $text = $pdf->getText();
            } catch (\Exception $e) {
                // แผนสำรองถ้า Parser พัง
                $escapedPath = escapeshellarg($filePath);
                $text = shell_exec("pdftotext -layout -enc UTF-8 $escapedPath -");
            }

            // 2. ทำความสะอาดข้อความและซ่อมสระไทย
            $text = $this->repairThaiAdvanced($text);

            $html = '<div style="font-family: \'Sarabun New\', sans-serif; font-size: 16pt; line-height: 1.5; white-space: pre-wrap;">'
                . nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))
                . '</div>';

            $html = $this->repairThai($html); // ใช้ฟังก์ชันเดิมที่คุณมีด้วย
        }

        return response()->json([
            'content' => $html,
            'type' => $extension
        ]);
    }

    private function convertDocxToHtmlByElements(\PhpOffice\PhpWord\PhpWord $phpWord): string
    {
        $chunks = [];
        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $chunks[] = $this->processElement($element);
            }
        }

        $html = implode('', $chunks);
        $html = str_replace("\t", '&nbsp;&nbsp;&nbsp;&nbsp;', $html);

        $html = '<div style="font-family: \'Sarabun New\', sans-serif; font-size: 16pt; line-height: 1.5;">' . $html . '</div>';
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
        // 1. แก้ไขปัญหา "ำ" (เกิดจาก ํ + า แยกกัน)
        $text = preg_replace('/\x{0E4D}\s*\x{0E32}/u', 'ำ', $text);

        // 2. แก้สระที่ชอบสลับที่ (เช่น สระบน/วรรณยุกต์ มาก่อนพยัญชนะ)
        // หมายเหตุ: PDF บางตัวเก็บ [พยัญชนะ][วรรณยุกต์][สระ] สลับกัน
        // เราจะใช้ Regex ช่วยดึงสระที่ลอยผิดตำแหน่ง
        $text = preg_replace('/([ก-ฮ])\s+([\x{0E31}\x{0E34}-\x{0E37}\x{0E47}-\x{0E4E}])/u', '$1$2', $text);

        // 3. ลบช่องว่างที่เกินมาในคำไทย (PDF มักจะใส่ space ระหว่างตัวอักษร)
        // ระวัง: อย่าลบ space ทั้งหมดเพราะจะเสีย Layout กฎหมาย
        // แก้เฉพาะจุดที่สระลอยห่างจากพยัญชนะ
        $text = preg_replace('/([ก-ฮ])\s+([ะาำิีึืุูเแโใไ็่้๊๋์])/u', '$1$2', $text);

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
