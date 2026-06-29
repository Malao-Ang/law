<?php

namespace App\Services\Fast\Docx;

use DOMDocument;
use DOMElement;

final class NumberingResolver
{
    /**
     * @var array<int, array{levels: array<int, array<string, int|string|null>>}>
     */
    private array $abstractNums = [];

    /**
     * @var array<int, array{abstractNumId: int, overrides: array<int, array<string, int|string|null>>}>
     */
    private array $numMap = [];

    /**
     * @var array<string, int>
     */
    private array $counters = [];

    public function __construct(?DOMDocument $numberingXml)
    {
        if ($numberingXml === null) {
            return;
        }

        $xpath = WordXml::createXPath($numberingXml);

        foreach ($xpath->query('/w:numbering/w:abstractNum') ?: [] as $abstractNum) {
            if (! $abstractNum instanceof DOMElement) {
                continue;
            }

            $abstractNumId = WordXml::wordAttr($abstractNum, 'abstractNumId');
            if ($abstractNumId === null) {
                continue;
            }

            $levels = [];
            foreach ($xpath->query('./w:lvl', $abstractNum) ?: [] as $level) {
                if (! $level instanceof DOMElement) {
                    continue;
                }

                $ilvl = WordXml::wordAttr($level, 'ilvl');
                if ($ilvl === null) {
                    continue;
                }

                $levels[(int) $ilvl] = $this->parseLevel($level, $xpath);
            }

            $this->abstractNums[(int) $abstractNumId] = ['levels' => $levels];
        }

        foreach ($xpath->query('/w:numbering/w:num') ?: [] as $num) {
            if (! $num instanceof DOMElement) {
                continue;
            }

            $numId = WordXml::wordAttr($num, 'numId');
            $abstractNumRef = $xpath->query('./w:abstractNumId', $num)?->item(0);
            $abstractNumId = $abstractNumRef instanceof DOMElement ? WordXml::wordAttr($abstractNumRef, 'val') : null;
            if ($numId === null || $abstractNumId === null) {
                continue;
            }

            $overrides = [];
            foreach ($xpath->query('./w:lvlOverride', $num) ?: [] as $override) {
                if (! $override instanceof DOMElement) {
                    continue;
                }

                $ilvl = WordXml::wordAttr($override, 'ilvl');
                if ($ilvl === null) {
                    continue;
                }

                $overrideData = [];
                $start = $xpath->query('./w:startOverride', $override)?->item(0);
                if ($start instanceof DOMElement) {
                    $overrideData['start'] = (int) (WordXml::wordAttr($start, 'val') ?? '1');
                }

                $numFmt = $xpath->query('./w:numFmt', $override)?->item(0);
                if ($numFmt instanceof DOMElement) {
                    $overrideData['numFmt'] = WordXml::wordAttr($numFmt, 'val');
                }

                $lvlText = $xpath->query('./w:lvlText', $override)?->item(0);
                if ($lvlText instanceof DOMElement) {
                    $overrideData['lvlText'] = WordXml::wordAttr($lvlText, 'val');
                }

                if ($overrideData !== []) {
                    $overrides[(int) $ilvl] = $overrideData;
                }
            }

            $this->numMap[(int) $numId] = [
                'abstractNumId' => (int) $abstractNumId,
                'overrides' => $overrides,
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $layout
     * @return array{prefix: string, layout: array<string, mixed>, meta: array<string, int|string>}|null
     */
    public function resolve(DOMElement $paragraph, array $layout): ?array
    {
        $xpath = WordXml::createXPath($paragraph->ownerDocument);
        $numIdNode = $xpath->query('./w:pPr/w:numPr/w:numId', $paragraph)?->item(0);
        $ilvlNode = $xpath->query('./w:pPr/w:numPr/w:ilvl', $paragraph)?->item(0);
        if (! $numIdNode instanceof DOMElement || ! $ilvlNode instanceof DOMElement) {
            return null;
        }

        $numId = (int) (WordXml::wordAttr($numIdNode, 'val') ?? '0');
        $ilvl = (int) (WordXml::wordAttr($ilvlNode, 'val') ?? '0');
        $numDef = $this->numMap[$numId] ?? null;
        if ($numDef === null) {
            return null;
        }

        $abstractNum = $this->abstractNums[$numDef['abstractNumId']] ?? null;
        if ($abstractNum === null) {
            return null;
        }

        $levelInfo = $abstractNum['levels'][$ilvl] ?? null;
        if ($levelInfo === null) {
            return null;
        }

        if (isset($numDef['overrides'][$ilvl])) {
            $levelInfo = array_merge($levelInfo, $numDef['overrides'][$ilvl]);
        }

        foreach (array_keys($this->counters) as $key) {
            [$seenNumId, $seenLevel] = array_map('intval', explode(':', $key, 2));
            if ($seenNumId === $numId && $seenLevel > $ilvl) {
                unset($this->counters[$key]);
            }
        }

        $counterKey = $this->counterKey($numId, $ilvl);
        $currentNum = ($this->counters[$counterKey] ?? ((int) ($levelInfo['start'] ?? 1) - 1)) + 1;
        $this->counters[$counterKey] = $currentNum;

        $prefix = $this->formatNumberingPrefix(
            $currentNum,
            (string) ($levelInfo['numFmt'] ?? 'decimal'),
            (string) ($levelInfo['lvlText'] ?? '%1.'),
            $numId,
            $ilvl,
        );

        $resolvedLayout = $layout;
        foreach (['indent_left', 'indent_hanging', 'indent_first_line'] as $key) {
            if (($resolvedLayout[$key] ?? null) === null && ($levelInfo[$key] ?? null) !== null) {
                $resolvedLayout[$key] = $levelInfo[$key];
            }
        }

        return [
            'prefix' => $prefix,
            'layout' => $resolvedLayout,
            'meta' => [
                'numId' => $numId,
                'ilvl' => $ilvl,
                'numFmt' => (string) ($levelInfo['numFmt'] ?? 'decimal'),
                'generated_prefix' => $prefix,
                'current_num' => $currentNum,
            ],
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    private function parseLevel(DOMElement $level, \DOMXPath $xpath): array
    {
        $start = $xpath->query('./w:start', $level)?->item(0);
        $numFmt = $xpath->query('./w:numFmt', $level)?->item(0);
        $lvlText = $xpath->query('./w:lvlText', $level)?->item(0);
        $ind = $xpath->query('./w:pPr/w:ind', $level)?->item(0);

        return [
            'start' => (int) ($start instanceof DOMElement ? (WordXml::wordAttr($start, 'val') ?? '1') : '1'),
            'numFmt' => $numFmt instanceof DOMElement ? (WordXml::wordAttr($numFmt, 'val') ?? 'decimal') : 'decimal',
            'lvlText' => $lvlText instanceof DOMElement ? (WordXml::wordAttr($lvlText, 'val') ?? '%1.') : '%1.',
            'indent_left' => $ind instanceof DOMElement ? WordXml::parseIntAttr($ind, 'left') : null,
            'indent_hanging' => $ind instanceof DOMElement ? WordXml::parseIntAttr($ind, 'hanging') : null,
            'indent_first_line' => $ind instanceof DOMElement ? WordXml::parseIntAttr($ind, 'firstLine') : null,
        ];
    }

    private function counterKey(int $numId, int $ilvl): string
    {
        return $numId.':'.$ilvl;
    }

    private function formatNumberingPrefix(int $num, string $numFmt, string $lvlText, int $numId, int $ilvl): string
    {
        $result = $lvlText;

        for ($level = 1; $level <= 9; $level++) {
            $placeholder = '%'.$level;
            if (! str_contains($result, $placeholder)) {
                continue;
            }

            $targetIlvl = $level === ($ilvl + 1) ? $ilvl : $level - 1;
            $targetLevelInfo = $this->levelInfo($numId, $targetIlvl);
            $targetNum = $this->counters[$this->counterKey($numId, $targetIlvl)] ?? 1;
            $targetFmt = (string) ($targetLevelInfo['numFmt'] ?? $numFmt);

            $result = str_replace($placeholder, $this->formatOrdinal($targetNum, $targetFmt, $targetIlvl), $result);
        }

        return $result;
    }

    /**
     * @return array<string, int|string|null>
     */
    private function levelInfo(int $numId, int $ilvl): array
    {
        $numDef = $this->numMap[$numId] ?? null;
        if ($numDef === null) {
            return [];
        }

        $levelInfo = $this->abstractNums[$numDef['abstractNumId']]['levels'][$ilvl] ?? [];
        if (isset($numDef['overrides'][$ilvl])) {
            $levelInfo = array_merge($levelInfo, $numDef['overrides'][$ilvl]);
        }

        return $levelInfo;
    }

    private function formatOrdinal(int $num, string $numFmt, int $ilvl): string
    {
        return match ($numFmt) {
            'thaiNumbers' => strtr((string) $num, ['0' => '๐', '1' => '๑', '2' => '๒', '3' => '๓', '4' => '๔', '5' => '๕', '6' => '๖', '7' => '๗', '8' => '๘', '9' => '๙']),
            'thaiLetters' => $this->thaiLetter($num),
            'bullet' => ['•', '◦', '▪', '▫', '■', '□', '▪', '▫'][min($ilvl, 7)],
            'lowerLetter' => $num >= 1 && $num <= 26 ? chr(ord('a') + $num - 1) : (string) $num,
            'upperLetter' => $num >= 1 && $num <= 26 ? chr(ord('A') + $num - 1) : (string) $num,
            'lowerRoman' => strtolower($this->roman($num)),
            'upperRoman' => $this->roman($num),
            default => (string) $num,
        };
    }

    private function thaiLetter(int $num): string
    {
        $letters = preg_split('//u', 'กขคงจฉชซฌญฎฏฐฑฒณดตถทธนบปผพภมยรลวศษสหฬอฮ', -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return $letters[$num - 1] ?? (string) $num;
    }

    private function roman(int $num): string
    {
        $map = [
            'M' => 1000,
            'CM' => 900,
            'D' => 500,
            'CD' => 400,
            'C' => 100,
            'XC' => 90,
            'L' => 50,
            'XL' => 40,
            'X' => 10,
            'IX' => 9,
            'V' => 5,
            'IV' => 4,
            'I' => 1,
        ];

        $result = '';
        foreach ($map as $glyph => $value) {
            while ($num >= $value) {
                $result .= $glyph;
                $num -= $value;
            }
        }

        return $result === '' ? '0' : $result;
    }
}
