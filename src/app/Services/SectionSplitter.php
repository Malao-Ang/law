<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Str;

class SectionSplitter
{
    private array $sections = [];
    private int $sortOrder = 0;
    private ?int $currentChapterId = null;
    private ?int $currentPartId = null;

    public function splitHtmlIntoSections(string $html): array
    {
        $this->sections = [];
        $this->sortOrder = 0;
        $this->currentChapterId = null;
        $this->currentPartId = null;

        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $this->processNodes($xpath, $dom->documentElement);

        return $this->sections;
    }

    private function processNodes(DOMXPath $xpath, $node, ?int $parentId = null): void
    {
        if (!$node) return;

        foreach ($node->childNodes as $child) {
            if ($child->nodeType !== XML_ELEMENT_NODE) continue;

            $text = trim($child->textContent);
            if (empty($text)) continue;

            $sectionData = $this->detectSectionType($text, $child);

            if ($sectionData) {
                $sectionId = $this->addSection(
                    $sectionData['type'],
                    $sectionData['number'],
                    $sectionData['label'],
                    $this->getNodeHtml($child),
                    $text,
                    $parentId
                );

                if ($sectionData['type'] === 'chapter') {
                    $this->currentChapterId = $sectionId;
                    $this->currentPartId = null;
                } elseif ($sectionData['type'] === 'part') {
                    $this->currentPartId = $sectionId;
                }
            } else {
                $this->processNodes($xpath, $child, $parentId);
            }
        }
    }

    private function detectSectionType(string $text, $node): ?array
    {
        // หมวด (Chapter)
        if (preg_match('/^หมวด\s*(?:ที่\s*)?([๐-๙0-9]+)\s*(.*)/u', $text, $matches)) {
            return [
                'type' => 'chapter',
                'number' => $this->normalizeThaiNumber($matches[1]),
                'label' => trim($matches[2]),
            ];
        }

        // ส่วนที่ (Part)
        if (preg_match('/^ส่วนที่\s*([๐-๙0-9]+)\s*(.*)/u', $text, $matches)) {
            return [
                'type' => 'part',
                'number' => $this->normalizeThaiNumber($matches[1]),
                'label' => trim($matches[2]),
            ];
        }

        // มาตรา (Section/Article)
        if (preg_match('/^มาตรา\s*([๐-๙0-9]+)\s*(.*)/u', $text, $matches)) {
            return [
                'type' => 'section',
                'number' => $this->normalizeThaiNumber($matches[1]),
                'label' => trim($matches[2]),
            ];
        }

        // ข้อ (Clause) - รูปแบบ "ข้อ X"
        if (preg_match('/^ข้อ\s*([๐-๙0-9]+)\s*(.*)/u', $text, $matches)) {
            return [
                'type' => 'clause',
                'number' => $this->normalizeThaiNumber($matches[1]),
                'label' => trim($matches[2]),
            ];
        }

        // ข้อ (Clause) - รูปแบบ "X." ที่ขึ้นต้นบรรทัด
        if (preg_match('/^([๐-๙0-9]+)\.\s+(.+)/u', $text, $matches)) {
            return [
                'type' => 'clause',
                'number' => $this->normalizeThaiNumber($matches[1]),
                'label' => '',
            ];
        }

        // ข้อย่อย (Sub-clause) - รูปแบบ "(X)" หรือ "X.X"
        if (preg_match('/^\(([๐-๙0-9ก-ฮ]+)\)\s*(.*)/u', $text, $matches)) {
            return [
                'type' => 'sub_clause',
                'number' => $this->normalizeThaiNumber($matches[1]),
                'label' => trim($matches[2]),
            ];
        }

        if (preg_match('/^([๐-๙0-9]+)\.([๐-๙0-9]+)\s+(.*)/u', $text, $matches)) {
            return [
                'type' => 'sub_clause',
                'number' => $this->normalizeThaiNumber($matches[1]) . '.' . $this->normalizeThaiNumber($matches[2]),
                'label' => trim($matches[3]),
            ];
        }

        // บทเฉพาะกาล (Transitional Provisions)
        if (preg_match('/^บทเฉพาะกาล/u', $text)) {
            return [
                'type' => 'schedule',
                'number' => 'บทเฉพาะกาล',
                'label' => '',
            ];
        }

        return null;
    }

    private function normalizeThaiNumber(string $number): string
    {
        $thaiDigits = ['๐', '๑', '๒', '๓', '๔', '๕', '๖', '๗', '๘', '๙'];
        $arabicDigits = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        
        return str_replace($thaiDigits, $arabicDigits, $number);
    }

    private function addSection(
        string $type,
        string $number,
        string $label,
        string $contentHtml,
        string $contentText,
        ?int $parentId
    ): int {
        $sectionId = count($this->sections);

        // กำหนด parent_id ตามลำดับชั้น
        if ($type === 'section' && $this->currentPartId !== null) {
            $parentId = $this->currentPartId;
        } elseif ($type === 'section' && $this->currentChapterId !== null) {
            $parentId = $this->currentChapterId;
        } elseif ($type === 'part' && $this->currentChapterId !== null) {
            $parentId = $this->currentChapterId;
        }

        $this->sections[] = [
            'section_type' => $type,
            'section_number' => $number,
            'section_label' => $label,
            'content_html' => $contentHtml,
            'content_text' => $contentText,
            'sort_order' => $this->sortOrder++,
            'parent_id' => $parentId,
            'temp_id' => $sectionId,
        ];

        return $sectionId;
    }

    private function getNodeHtml($node): string
    {
        $dom = $node->ownerDocument;
        $html = $dom->saveHTML($node);
        return $html ?: '';
    }

    public function detectAndLinkReferences(array $sections): array
    {
        $references = [];

        foreach ($sections as $index => $section) {
            $text = $section['content_text'];

            // ตรวจจับ "ตามมาตรา X"
            if (preg_match_all('/ตามมาตรา\s*([๐-๙0-9]+)/u', $text, $matches)) {
                foreach ($matches[1] as $refNumber) {
                    $normalizedRef = $this->normalizeThaiNumber($refNumber);
                    $targetIndex = $this->findSectionByNumber('section', $normalizedRef, $sections);
                    
                    if ($targetIndex !== null) {
                        $references[] = [
                            'source_temp_id' => $index,
                            'target_temp_id' => $targetIndex,
                            'reference_type' => 'refers_to',
                            'description' => "อ้างอิงถึงมาตรา {$normalizedRef}",
                        ];
                    }
                }
            }

            // ตรวจจับ "แก้ไขเพิ่มเติมโดย"
            if (preg_match('/แก้ไขเพิ่มเติมโดย/u', $text)) {
                $references[] = [
                    'source_temp_id' => $index,
                    'target_temp_id' => null,
                    'reference_type' => 'amends',
                    'description' => 'มีการแก้ไขเพิ่มเติม',
                ];
            }

            // ตรวจจับ "ยกเลิกโดย"
            if (preg_match('/ยกเลิกโดย/u', $text)) {
                $references[] = [
                    'source_temp_id' => $index,
                    'target_temp_id' => null,
                    'reference_type' => 'repeals',
                    'description' => 'ถูกยกเลิก',
                ];
            }
        }

        return $references;
    }

    private function findSectionByNumber(string $type, string $number, array $sections): ?int
    {
        foreach ($sections as $index => $section) {
            if ($section['section_type'] === $type && $section['section_number'] === $number) {
                return $index;
            }
        }
        return null;
    }
}
