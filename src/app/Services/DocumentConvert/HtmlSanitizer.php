<?php

namespace App\Services\DocumentConvert;

class HtmlSanitizer
{
    public function wrapLegalDocument(string $innerHtml, array $opts = []): string
    {
        $padding = $opts['padding'] ?? '0';
        $bg      = $opts['background'] ?? 'transparent';

        return '<div class="legal-document" style="font-family:\'Sarabun New\',sans-serif;font-size:16pt;line-height:1.5;padding:'.$padding.';background:'.$bg.';">'
            . $innerHtml
            . '</div>';
    }

    public function repairThaiHtml(string $html): string
    {
        // แก้ๆ สำหรับ HTML (คงแบบเดิม)
        $html = preg_replace('/([ก-ฮ])\s+า/u', '$1ำ', $html);
        $html = preg_replace('/\s+า/u', 'ำ', $html);
        return $html;
    }

    public function repairThaiTextAdvanced(string $text): string
    {
        if ($text === '') return $text;

        $common_am_words = ['สำ', 'จำ', 'นำ', 'อำ', 'ดำ', 'ตำ', 'ทำ', 'กำ', 'ลำ', 'คำ', 'บำ'];
        foreach ($common_am_words as $word) {
            $char = mb_substr($word, 0, 1);
            $text = preg_replace('/' . $char . '\s+า/u', $word, $text);
        }

        $text = preg_replace('/([ก-ฮ])\s+[\x{0E4D}]?\s*า/u', '$1ำ', $text);
        $text = preg_replace('/([ก-ฮ])\s+[\x{0E4D}]/u', '$1ำ', $text);
        $text = preg_replace('/([ก-ฮ])\s+ำ/u', '$1ำ', $text);
        $text = preg_replace('/([ก-ฮ])\s+็/u', '$1็', $text);

        $upper_marks = 'ิีึืัุู็่้๊๋์ํ';
        $text = preg_replace('/([ก-ฮ])\s+([' . $upper_marks . '])/u', '$1$2', $text);
        $text = preg_replace('/([' . $upper_marks . '])\s+([ก-ฮ])/u', '$1$2', $text);
        $text = preg_replace('/([' . $upper_marks . '])\s+([' . $upper_marks . '])/u', '$1$2', $text);
        
        $text = preg_replace('/([เแโใไ])\s+([ก-ฮ])/u', '$1$2', $text);
        $text = preg_replace('/([ก-ฮ])\s+([เแโใไ])/u', '$2$1', $text);

        $text = str_replace(['ำา', 'าำ'], 'ำ', $text);
        $text = preg_replace('/([ก-ฮ])\s+ะ/u', '$1ะ', $text);
        $text = preg_replace('/([ก-ฮ])\s+ๅ/u', '$1ๅ', $text);

        $dictionary = [
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
        return str_replace(array_keys($dictionary), array_values($dictionary), $text);
    }

    public function stripUnderline(string $html): string
    {
        $html = preg_replace('/<u>(.*?)<\/u>/us', '$1', $html);
        $html = preg_replace('/text-decoration\s*:\s*underline[^;]*;?/i', '', $html);
        return $html;
    }

    // === ของเดิมคุณย้ายมาไว้ที่นี่ให้อ่านง่าย (แค่ย้ายที่ไม่เปลี่ยน logic) ===
    public function normalizeDocxHtmlForTiptap(string $html): string
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

            // ใส่ tbody ครอบเฉพาะส่วนหลังad (อย่าทับเad ถ้ามี)
            if (stripos($table, '<thead') !== false) {
                // ถ้ามีthead ให้ส่วนหลังad
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
}
