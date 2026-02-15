<?php

namespace App\Services\DocumentConvert;

use Smalot\PdfParser\Parser;

class PdfToHtmlConverter
{
    public function __construct(
        private readonly HtmlSanitizer $sanitizer
    ) {}

    public function convert(string $path): string
    {
        $parser = new Parser();
        $pdf = $parser->parseFile($path);
        $text = $pdf->getText();

        $text = $this->sanitizer->repairThaiTextAdvanced($text);

        $lines = preg_split("/\R/u", $text) ?: [];
        $html  = $this->buildHtmlFromLines($lines);

        return $this->sanitizer->wrapLegalDocument($html, [
            'padding' => '1in',
            'background' => 'white',
        ]);
    }

    private function buildHtmlFromLines(array $lines): string
    {
        $out = '';

        foreach ($lines as $rawLine) {
            $trimmed = trim((string)$rawLine);

            if ($trimmed === '') {
                $out .= "<p class='p-empty'>&nbsp;</p>";
                continue;
            }

            $block = $this->detectPdfBlock($rawLine, $trimmed);
            $content = $this->escapeAndPreserveSpaces($trimmed);

            if ($block['tag'] === 'div') {
                $out .= "<div class='{$block['class']}' style='{$block['style']}'>{$content}</div>";
            } else {
                $out .= "<p class='{$block['class']}' style='{$block['style']}'>{$content}</p>";
            }
        }

        return $out;
    }

    /**
     * โฟักสเอกสารราชการไทย: หัวข้อหลัก / ข้อ / (๑) / ย่อหน้าเยื้อง
     * ตัวอย่างที่เจอในไฟล์ของคุณ เช่น "- ร่าง -", "ประกาศสำนักหอสมุด ...", "เรื่อง ...", เส้นคั่น, หัวข้อ "ข้อ ๑ …", และ bullet "(๑) …"
     */
    private function detectPdfBlock(string $rawLine, string $trimmed): array
    {
        $style = "margin-bottom:0.1em; line-height:1.6; clear:both;";
        $class = "p-normal";
        $tag   = "p";

        // 1) หัวเรื่องกึ่งกลาง (center + bold)
        $isCenterKeyword =
            preg_match('/^-\s*ร่าง\s*-\s*$/u', $trimmed) ||
            preg_match('/^ประกาศ(สำนัก|มหาวิทยาลัย)/u', $trimmed) ||
            preg_match('/^เรื่อง\s+/u', $trimmed) ||
            preg_match('/^-{3,}$/u', $trimmed) ||
            preg_match('/^\(.*\)$/u', $trimmed); // ข้อความในวงเล็บกึ่งกลาง เช่น (Terms of Reference : TOR)

        $hasLeadingSpaces = preg_match('/^\s{10,}/u', $rawLine) === 1;

        if (($isCenterKeyword && mb_strlen($trimmed) < 140) || ($hasLeadingSpaces && mb_strlen($trimmed) < 100)) {
            $tag = "div";
            $class = "h-center";
            $style .= "text-align:center;font-weight:700;font-size:18pt;width:100%;margin-top:0.5em;";
            return compact('tag','class','style');
        }

        // 2) หัวข้อหลัก: "ข้อ ๑ …" หรือ "1."
        if (preg_match('/^ข้อ\s*[๐-๙0-9]+\b/u', $trimmed) || preg_match('/^(\d+|[๑-๙]+)\.(?!\d)/u', $trimmed)) {
            $tag = "div";
            $class = "h-main";
            $style .= "font-weight:700;text-align:left;margin-top:1.0em;font-size:16pt;";
            return compact('tag','class','style');
        }

        // 3) ลำดับย่อยแบบ (๑) หรือ (1)
        if (preg_match('/^\((\d+|[๐-๙]+)\)/u', $trimmed) || preg_match('/^\(([๑-๙]+)\)/u', $trimmed)) {
            $class = "p-bullet";
            $style .= "padding-left:3em;text-indent:-1.2em;text-align:justify;";
            return compact('tag','class','style');
        }

        // 4) หัวข้อย่อยชั้นที่ 1.1 / 1.1.1
        if (preg_match('/^\d+\.\d+(\.\d+)?\s+/u', $trimmed) || preg_match('/^[๑-๙]+\.[๑-๙]+(\.[๑-๙]+)?\s+/u', $trimmed)) {
            $class = "p-sub";
            $style .= "padding-left:3em;text-align:justify;";
            return compact('tag','class','style');
        }

        // 5) ย่อหน้าเยื้อง (ดูจากช่องว่างหน้า line)
        if (preg_match('/^\s{2,10}/u', $rawLine)) {
            $class = "p-indent";
            $style .= "padding-left:3em;text-indent:2em;text-align:justify;";
            return compact('tag','class','style');
        }

        // กรณีบรรทัดต่อจากหัวข้อ (Hanging Indent)
        else {
            $style .= "text-align:justify;";
        }

        // ทำความสะอาดช่องว่างภายในบรรทัด
        $content = htmlspecialchars($trimmed);
        $content = preg_replace_callback('/  +/', function ($match) {
            return str_repeat('&nbsp;', strlen($match[0]));
        }, $content);

        // สร้าง Output HTML
        if ($tag === 'div') {
            return compact('tag','class','style');
        } else {
            return compact('tag','class','style');
        }
    }

    private function escapeAndPreserveSpaces(string $text): string
    {
        $content = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return preg_replace_callback('/  +/', fn($m) => str_repeat('&nbsp;', strlen($m[0])), $content);
    }
}
