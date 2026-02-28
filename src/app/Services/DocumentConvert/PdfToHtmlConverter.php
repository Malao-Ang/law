<?php

namespace App\Services\DocumentConvert;

use RuntimeException;
use Symfony\Component\Process\Process;

class PdfToHtmlConverter
{
    public function convert(string $path): string
    {
        $outputDir = sys_get_temp_dir();
        $outputName = uniqid('pdf_', true);
        $outputPath = $outputDir.DIRECTORY_SEPARATOR.$outputName;

        // Command: pdftohtml -c (complex) -s (single file) -i (ignore images) -noframes [input] [output_prefix]
        // Note: -i ignores extracting images, but -c relies on background images for lines sometimes.
        // We will try -c -s -noframes first (allowing images might be needed for table lines).
        // Actually, let's try -s -i -noframes (Simple mode) first?
        // The plan said "-c (Complex)". Complex is definitely better for "looking like PDF".

        $cmd = [
            'pdftohtml',
            '-c',           // Complex output
            '-s',           // Single file
            '-i',           // Ignore images (prevent extracting hundreds of image files)
            '-noframes',    // No frameset
            $path,
            $outputPath,
        ];

        $process = new Process($cmd);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('pdftohtml failed: '.$process->getErrorOutput());
        }

        // pdftohtml with -s creates [output_name].html
        $generatedFile = $outputPath.'.html';

        if (! file_exists($generatedFile)) {
            // Fallback check
            throw new RuntimeException("pdftohtml did not generate expected file: {$generatedFile}");
        }

        $html = file_get_contents($generatedFile);

        // Cleanup
        @unlink($generatedFile);

        $body = $this->extractBody($html);
        return $this->repairThaiHtml($body);
    }

    private function extractBody(string $html): string
    {
        if (preg_match('/<body[^>]*>(.*?)<\/body>/is', $html, $matches)) {
            return $matches[1];
        }

        return $html;
    }

    /**
     * Repair Thai text in HTML output from pdftohtml.
     *
     * pdftohtml often decomposes Thai sara am (ำ U+0E33) into:
     *   - nikhahit (ํ U+0E4D) + sara aa (า U+0E32)
     *   - or consonant + space + sara aa
     * This method reassembles them correctly.
     */
    private function repairThaiHtml(string $html): string
    {
        // --- Pass 1: Fix dictionary of known common words FIRST (highest precision) ---
        $dictionary = [
            'ส านัก'  => 'สำนัก',
            'จ านวน'  => 'จำนวน',
            'น าไป'   => 'นำไป',
            'น ามา'   => 'นำมา',
            'อ านวย'  => 'อำนวย',
            'จ าเป็น' => 'จำเป็น',
            'ประจ า'  => 'ประจำ',
            'ก าหนด'  => 'กำหนด',
            'ก ากับ'  => 'กำกับ',
            'ล าดับ'  => 'ลำดับ',
            'ค าสั่ง' => 'คำสั่ง',
            'ท าการ'  => 'ทำการ',
            'ท าให้'  => 'ทำให้',
            'ด าเนิน' => 'ดำเนิน',
            'ด าริ'   => 'ดำริ',
            'บ าบัด'  => 'บำบัด',
            'บ ารุง'  => 'บำรุง',
            'ต าแหน่ง'=> 'ตำแหน่ง',
            'ต าบล'   => 'ตำบล',
            'ต ารา'   => 'ตำรา',
            'ต ารวจ'  => 'ตำรวจ',
            'ค าถาม'  => 'คำถาม',
            'ค าตอบ'  => 'คำตอบ',
            'ค าอธิบาย'=> 'คำอธิบาย',
            'ค าชี้แจง'=> 'คำชี้แจง',
            'ค าขอ'   => 'คำขอ',
            'ค าร้อง'  => 'คำร้อง',
            'ค ารับรอง'=> 'คำรับรอง',
            'ค าแนะน า'=> 'คำแนะนำ',
            'ก ารผลิต' => 'การผลิต',
            'ก ารด าเนิน'=> 'การดำเนิน',
            'ก ารก าหนด'=> 'การกำหนด',
            'ก ารจ า'  => 'การจำ',
            'บริก าร'  => 'บริการ',
            'ก ารบริก าร'=> 'การบริการ',
        ];
        $html = str_replace(array_keys($dictionary), array_values($dictionary), $html);

        // --- Pass 2: Fix decomposed sara am: nikhahit(U+0E4D) + sara aa(U+0E32) → sara am(U+0E33) ---
        // This is the canonical Unicode decomposition that some PDF extractors produce.
        $html = str_replace("\u{0E4D}\u{0E32}", 'ำ', $html);

        // --- Pass 3: Fix consonant + space(s) + nikhahit + space(s) + sara aa ---
        $html = preg_replace('/([ก-ฮ])[ \t]*\x{0E4D}[ \t]*า/u', '$1ำ', $html);

        // --- Pass 4: Fix consonant + space + sara aa → sara am ---
        // Use space/tab only (not \s which would cross HTML tag boundaries via newlines)
        $html = preg_replace('/([ก-ฮ])[ \t]+า(?=[^"a-zA-Z]|$)/u', '$1ำ', $html);

        // --- Pass 5: Fix consonant + space + sara am (already correct char but spaced) ---
        $html = preg_replace('/([ก-ฮ])[ \t]+ำ/u', '$1ำ', $html);

        // --- Pass 6: Fix other combining marks / tone marks separated by spaces ---
        // upper diacritics: sara i,ii,ue,uee,a,u,uu,mai tai khu,mai ek,mai tho,mai tri,mai jattawa,thanthakat,nikhahit
        $up = 'ิีึืัุู็่้๊๋์ํ';
        $html = preg_replace('/([ก-ฮ])[ \t]+([' . $up . '])/u', '$1$2', $html);
        $html = preg_replace('/([' . $up . '])[ \t]+([ก-ฮ])/u', '$1$2', $html);
        $html = preg_replace('/([' . $up . '])[ \t]+([' . $up . '])/u', '$1$2', $html);

        // --- Pass 7: Fix leading vowels (เ แ โ ใ ไ) separated from consonant ---
        $html = preg_replace('/([เแโใไ])[ \t]+([ก-ฮ])/u', '$1$2', $html);

        // --- Pass 8: Fix sara ะ and ๅ separated ---
        $html = preg_replace('/([ก-ฮ])[ \t]+ะ/u', '$1ะ', $html);
        $html = preg_replace('/([ก-ฮ])[ \t]+ๅ/u', '$1ๅ', $html);

        // --- Pass 9: Deduplicate accidental double ำ ---
        $html = str_replace(['ำา', 'าำ', 'ำำ'], 'ำ', $html);

        return $html;
    }
}
