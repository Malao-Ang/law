<?php

namespace App\Services\Fast\Docx;

use DOMElement;

final class ParagraphParser
{
    private const FIRST_LINE_INDENT_DEFAULT_TWIPS = 720;

    /**
     * @return array<string, mixed>|null
     */
    public function parse(DOMElement $paragraph, int $readingOrder, NumberingResolver $numberingResolver): ?array
    {
        $text = $this->extractText($paragraph);
        $layout = $this->extractLayout($paragraph);
        $styleId = $this->paragraphStyleId($paragraph);
        $numberingInfo = $numberingResolver->resolve($paragraph, $layout);

        if ($numberingInfo !== null) {
            $text = $numberingInfo['prefix'].' '.$text;
            $layout = $numberingInfo['layout'];
        }

        if (trim($text) === '') {
            return null;
        }

        if ($numberingInfo === null && ($layout['indent_first_line'] ?? null) === null && str_starts_with($text, "\t")) {
            $text = ltrim($text, "\t");
            $layout['indent_first_line'] = self::FIRST_LINE_INDENT_DEFAULT_TWIPS;
            $layout['first_line_inferred'] = 'leading_tab';
        }

        return [
            'text' => $text,
            'layout' => $layout,
            'type' => $this->classify(
                text: $text,
                layout: $layout,
                readingOrder: $readingOrder,
                hasNumbering: $numberingInfo !== null,
                styleId: $styleId,
            ),
            'formatting' => $this->extractFormatting($paragraph),
            'numbering' => $numberingInfo['meta'] ?? null,
        ];
    }

    public function extractText(DOMElement $paragraph): string
    {
        $parts = [];

        foreach ($paragraph->getElementsByTagNameNS(WordXml::WORD_NS, '*') as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $tag = $node->localName;
            if ($tag === 't') {
                $parts[] = $node->textContent;
            } elseif ($tag === 'tab') {
                $parts[] = "\t";
            } elseif ($tag === 'br' || $tag === 'cr') {
                $parts[] = "\n";
            }
        }

        return str_replace("\u{00A0}", ' ', trim(implode('', $parts), "\n"));
    }

    /**
     * @return array<string, mixed>
     */
    public function extractLayout(DOMElement $paragraph): array
    {
        $xpath = WordXml::createXPath($paragraph->ownerDocument);
        $pPr = $xpath->query('./w:pPr', $paragraph)?->item(0);

        $layout = [
            'bbox' => null,
            'reading_order' => null,
            'alignment' => null,
            'indent_left' => null,
            'indent_first_line' => null,
            'indent_hanging' => null,
            'indent_level' => null,
            'tabs' => [],
            'spacing_before' => null,
            'spacing_after' => null,
            'line_spacing' => null,
            'first_line_inferred' => null,
        ];

        if (! $pPr instanceof DOMElement) {
            return $layout;
        }

        $jc = $xpath->query('./w:jc', $pPr)?->item(0);
        if ($jc instanceof DOMElement) {
            $layout['alignment'] = WordXml::wordAttr($jc, 'val');
        }

        $ind = $xpath->query('./w:ind', $pPr)?->item(0);
        if ($ind instanceof DOMElement) {
            $layout['indent_left'] = WordXml::parseIntAttr($ind, 'left');
            $layout['indent_first_line'] = WordXml::parseIntAttr($ind, 'firstLine');
            $layout['indent_hanging'] = WordXml::parseIntAttr($ind, 'hanging');
        }

        $spacing = $xpath->query('./w:spacing', $pPr)?->item(0);
        if ($spacing instanceof DOMElement) {
            $layout['spacing_before'] = WordXml::parseIntAttr($spacing, 'before');
            $layout['spacing_after'] = WordXml::parseIntAttr($spacing, 'after');
            $layout['line_spacing'] = WordXml::parseIntAttr($spacing, 'line');
        }

        foreach ($xpath->query('./w:tabs/w:tab', $pPr) ?: [] as $tab) {
            if (! $tab instanceof DOMElement) {
                continue;
            }

            $position = WordXml::parseIntAttr($tab, 'pos');
            if ($position === null) {
                continue;
            }

            $layout['tabs'][] = [
                'position' => $position,
                'type' => $this->mapTabType(WordXml::wordAttr($tab, 'val')),
            ];
        }

        return $layout;
    }

    public function alignmentForParagraph(DOMElement $paragraph): ?string
    {
        return $this->extractLayout($paragraph)['alignment'];
    }

    private function mapTabType(?string $value): string
    {
        return match ($value) {
            'center' => 'center',
            'right' => 'right',
            'decimal' => 'decimal',
            default => 'left',
        };
    }

    private function paragraphStyleId(DOMElement $paragraph): ?string
    {
        $xpath = WordXml::createXPath($paragraph->ownerDocument);
        $style = $xpath->query('./w:pPr/w:pStyle', $paragraph)?->item(0);

        return $style instanceof DOMElement ? WordXml::wordAttr($style, 'val') : null;
    }

    /**
     * @return array<string, bool>
     */
    private function extractFormatting(DOMElement $paragraph): array
    {
        $bold = false;
        $italic = false;
        $underline = false;

        foreach ($paragraph->getElementsByTagNameNS(WordXml::WORD_NS, 'r') as $run) {
            if (! $run instanceof DOMElement) {
                continue;
            }

            $rPr = null;
            foreach ($run->childNodes as $child) {
                if ($child instanceof DOMElement && $child->localName === 'rPr') {
                    $rPr = $child;
                    break;
                }
            }
            if (! $rPr instanceof DOMElement) {
                continue;
            }

            if ($this->hasOnFormatting($rPr, 'b')) {
                $bold = true;
            }
            if ($this->hasOnFormatting($rPr, 'i')) {
                $italic = true;
            }
            if ($this->hasOnFormatting($rPr, 'u')) {
                $underline = true;
            }
        }

        return ['bold' => $bold, 'italic' => $italic, 'underline' => $underline];
    }

    private function hasOnFormatting(DOMElement $rPr, string $name): bool
    {
        foreach ($rPr->childNodes as $child) {
            if (! $child instanceof DOMElement || $child->localName !== $name) {
                continue;
            }

            $val = WordXml::wordAttr($child, 'val');

            return ! in_array($val, ['false', '0', 'none'], true);
        }

        return false;
    }

    private function classify(string $text, array $layout, int $readingOrder, bool $hasNumbering, ?string $styleId): string
    {
        $stripped = trim($text);

        if ($hasNumbering) {
            return 'list_item';
        }

        if (is_string($styleId) && preg_match('/^(heading\d+|title)/i', $styleId) === 1) {
            return $readingOrder <= 4 ? 'title' : 'section_header';
        }

        if (($layout['alignment'] ?? null) === 'center') {
            return $readingOrder <= 4 ? 'title' : 'section_header';
        }

        if (preg_match('/^(มาตรา\s+[๐-๙0-9]+(?:\/[๐-๙0-9]+)?)\b/u', $stripped) === 1) {
            return 'section_header';
        }

        if (preg_match('/^ข้อ\s*[๐-๙0-9]+(?:\.[๐-๙0-9]+)*/u', $stripped) === 1) {
            return 'section_header';
        }

        if (preg_match('/^(\(([๐-๙0-9]+|[ก-ฮ]|[a-zA-Z])\)|[๐-๙0-9]+(?:\.[๐-๙0-9]+)+\.?\s|[๐-๙0-9]+\.\s|-|•)/u', $stripped) === 1) {
            return 'list_item';
        }

        return 'paragraph';
    }
}
