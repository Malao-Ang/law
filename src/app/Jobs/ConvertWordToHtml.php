<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use App\Models\Document;
use PhpOffice\PhpWord\IOFactory;
use Smalot\PdfParser\Parser;
use App\Services\DocxParser;

class ConvertWordToHtml implements ShouldQueue
{
    use Queueable;

    public $tries = 3;
    public $backoff = [30, 60, 120]; // seconds

    public function __construct(
        public int $documentId,
        public string $filePath,
        public string $originalFilename,
        public string $fileType
    ) {}

    public function handle(): void
    {
        try {
            $document = Document::findOrFail($this->documentId);
            $html = '';

            if ($this->fileType === 'docx') {
                $html = $this->convertDocxToHtml($this->filePath);
            } elseif ($this->fileType === 'pdf') {
                $html = $this->convertPdfToHtml($this->filePath);
            }

            // Update document with converted content
            $document->update([
                'content' => $html,
                'original_filename' => $this->originalFilename,
                'file_type' => $this->fileType
            ]);

            Log::info('Document converted successfully', [
                'document_id' => $this->documentId,
                'file_type' => $this->fileType
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to convert document', [
                'document_id' => $this->documentId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Mark document as failed
            if (isset($document)) {
                $document->update([
                    'content' => '<p>Failed to convert document. Please try again.</p>',
                    'original_filename' => $this->originalFilename,
                    'file_type' => $this->fileType
                ]);
            }

            throw $e; // Re-throw to trigger queue retry mechanism
        }
    }

    private function convertDocxToHtml(string $filePath): string
    {
        try {
            $parser = new DocxParser($filePath);
            $html = $parser->toHtml();
        } catch (\Exception $e) {
            Log::warning('DocxParser failed, falling back to PhpWord', ['error' => $e->getMessage()]);
            
            // Fallback to PhpWord
            $phpWord = IOFactory::load($filePath);

            $writer = IOFactory::createWriter($phpWord, 'HTML');
            ob_start();
            $writer->save('php://output');
            $content = ob_get_clean();

            // Extract body content
            if (preg_match('/<body[^>]*>(.*?)<\/body>/is', $content, $m)) {
                $html = $m[1];
            } else {
                $html = $content;
            }

            // Apply the same normalization as in WordController
            $html = $this->normalizeHtmlForCKEditor($html);
            $html = $this->repairThai($html);
            $html = $this->stripUnderline($html);
            $html = $this->cleanHtml($html);
        }

        // Wrap with legal document styling
        return '<div class="legal-document" style="font-family:\'Sarabun New\',sans-serif;font-size:16pt;line-height:1.5;">'
            . $html
            . '</div>';
    }

    private function convertPdfToHtml(string $filePath): string
    {
        $parser = new Parser();
        $pdf = $parser->parseFile($filePath);
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

            // Detection logic for titles and formatting (same as WordController)
            if (
                preg_match('/^(- ร่าง -|ขอบเขตของงาน|โครงการ|ประจำปีงบประมาณ|TOR|Terms of Reference)/u', $trimmedLine) ||
                preg_match('/^\(.*\)$/u', $trimmedLine) ||
                (preg_match('/^\s{12,}/u', $rawLine) && mb_strlen($trimmedLine) < 100)
            ) {
                $style .= " text-align: center; font-weight: bold; font-size: 18pt; display: block; width: 100%; margin-top: 0.5em;";
                $isTitle = true;
            } elseif (preg_match('/^(\d+|[๑-๙]+)\.(?!\d)/u', $trimmedLine)) {
                $style .= " font-weight: bold; text-align: left; margin-top: 1.2em; font-size: 16pt;";
                $isTitle = true;
            } elseif (preg_match('/^(\d+|[๑-๙]+)\.(\d+|[๑-๙]+)\s+/u', $trimmedLine)) {
                $style .= " padding-left: 3em; text-align: justify;";
            } elseif (preg_match('/^(\d+\.\d+\.\d+|(\(\d+\))|([๑-๙]+\.[๑-๙]+\.[๑-๙]+))/u', $trimmedLine)) {
                $style .= " padding-left: 5em; text-align: justify;";
            } elseif (preg_match('/^\s{2,10}/u', $rawLine)) {
                $style .= " padding-left: 3em; text-indent: 2em; text-align: justify;";
            } else {
                $style .= " text-align: justify;";
            }

            $content = htmlspecialchars($trimmedLine);
            $content = preg_replace_callback('/  +/', function ($match) {
                return str_repeat('&nbsp;', strlen($match[0]));
            }, $content);

            if ($isTitle) {
                $processedHtml .= "<div style='{$style}'>" . $content . "</div>";
            } else {
                $processedHtml .= "<p style='{$style}'>" . $content . "</p>";
            }
        }

        return '<div class="legal-document" style="font-family: \'Sarabun New\', sans-serif; font-size: 16pt; padding: 1in; background: white;">'
            . $processedHtml .
            '</div>';
    }

    // Helper methods (copied from WordController)
    private function normalizeHtmlForCKEditor(string $html): string
    {
        if ($html === '') return $html;

        // Same implementation as WordController
        $html = preg_replace_callback('/<table\b([^>]*)>/i', function ($m) {
            $attrs = $m[1];

            if (preg_match('/\bclass\s*=\s*"([^"]*)"/i', $attrs, $cm)) {
                $classes = trim($cm[1]);
                if (!preg_match('/\bck-table\b/i', $classes)) {
                    $classes .= ' ck-table';
                }
                $attrs = preg_replace('/\bclass\s*=\s*"[^"]*"/i', 'class="' . $classes . '"', $attrs, 1);
            } else {
                $attrs .= ' class="ck-table"';
            }

            $needStyle = "border-collapse:collapse;width:100%;table-layout:fixed;";
            if (preg_match('/\bstyle\s*=\s*"([^"]*)"/i', $attrs, $sm)) {
                $style = $sm[1];
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

        return $html;
    }

    private function repairThai(string $html): string
    {
        $html = preg_replace('/([ก-ฮ])\s+ำ/u', '$1ำ', $html);
        $html = preg_replace('/\s+ำ/u', 'ำ', $html);
        return $html;
    }

    private function stripUnderline(string $html): string
    {
        $html = preg_replace('/<u>(.*?)<\/u>/us', '$1', $html);
        $html = preg_replace('/text-decoration\s*:\s*underline[^;]*;?/i', '', $html);
        return $html;
    }

    private function cleanHtml(string $html): string
    {
        if (class_exists('Mews\Purifier\Facades\Purifier')) {
            try {
                return \Mews\Purifier\Facades\Purifier::clean($html, [
                    'HTML.Allowed' => 'div,p,span,strong,b,em,i,u,ul,ol,li,table,thead,tbody,tr,td,th,br,h1,h2,h3,h4,h5,h6',
                    'HTML.AllowedAttributes' => 'style,class',
                    'CSS.AllowedProperties' => 'font-family,font-size,line-height,text-align,margin,padding,width,height,border,color,background,font-weight,text-decoration',
                    'AutoFormat.RemoveEmpty' => true,
                    'AutoFormat.RemoveSpansWithoutAttributes' => true,
                ]);
            } catch (\Exception $e) {
                Log::warning('HTML Purifier failed in job', ['error' => $e->getMessage()]);
            }
        }

        $html = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $html);
        $html = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html);
        $html = preg_replace('/on\w+="[^"]*"/i', '', $html);
        $html = preg_replace('/javascript:/i', '', $html);
        $html = preg_replace('/vbscript:/i', '', $html);

        return $html;
    }

    private function repairThaiAdvanced(string $text): string
    {
        if (empty($text)) return $text;

        // Same implementation as WordController
        $common_am_words = ['สำ', 'จำ', 'นำ', 'อำ', 'ดำ', 'ตำ', 'ทำ'];
        foreach ($common_am_words as $word) {
            $char = mb_substr($word, 0, 1);
            $text = preg_replace('/' . $char . '\s+า/u', $word, $text);
        }

        $text = preg_replace('/([ก-ฮ])\s+[\x{0E4D}]?\s*า/u', '$1ำ', $text);
        $text = preg_replace('/([ก-ฮ])\s+[\x{0E4D}]/u', '$1ำ', $text);

        $upper_marks = 'ิีึืั็่้๊๋์';
        $text = preg_replace('/([ก-ฮ])\s+([' . $upper_marks . '])/u', '$1$2', $text);
        $text = preg_replace('/([' . $upper_marks . '])\s+([ก-ฮ])/u', '$1$2', $text);
        $text = preg_replace('/([เแโใไ])\s+([ก-ฮ])/u', '$1$2', $text);

        $text = str_replace('ำา', 'ำ', $text);

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
}
