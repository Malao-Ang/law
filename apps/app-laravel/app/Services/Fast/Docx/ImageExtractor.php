<?php

namespace App\Services\Fast\Docx;

use DOMElement;

final class ImageExtractor
{
    private const DRAWING_NS = 'http://schemas.openxmlformats.org/drawingml/2006/main';

    private const WORDPROCESSING_DRAWING_NS = 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing';

    private const VML_NS = 'urn:schemas-microsoft-com:vml';

    private const EMU_PER_CM = 360000;

    /** @var array<string, string> */
    private array $relationships;

    private bool $dirReady = false;

    public function __construct(
        private readonly DocxArchive $archive,
        private readonly string $documentId,
        private readonly string $imagesDir,
    ) {
        $this->relationships = $archive->relationshipMap();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fromParagraph(DOMElement $paragraph): array
    {
        $imageRefs = [];
        $alignment = $this->paragraphAlignment($paragraph);

        foreach ($paragraph->getElementsByTagNameNS(self::DRAWING_NS, 'blip') as $blip) {
            if (! $blip instanceof DOMElement) {
                continue;
            }

            $relId = WordXml::wordRelAttr($blip, 'embed');
            if ($relId !== null) {
                $imageRefs[] = [
                    'relId' => $relId,
                    ...$this->drawingDimensions($blip),
                ];
            }
        }

        foreach ($paragraph->getElementsByTagNameNS(self::VML_NS, 'imagedata') as $imageData) {
            if (! $imageData instanceof DOMElement) {
                continue;
            }

            $relId = WordXml::wordRelAttr($imageData, 'id');
            if ($relId !== null) {
                $imageRefs[] = [
                    'relId' => $relId,
                    ...$this->vmlDimensions($imageData),
                ];
            }
        }

        $images = [];
        foreach ($imageRefs as $imageRef) {
            $meta = $this->writeImage((string) $imageRef['relId']);
            if ($meta !== null) {
                $meta['docx_width_cm'] = $imageRef['widthCm'];
                $meta['docx_height_cm'] = $imageRef['heightCm'];
                $meta['alignment'] = $alignment;
                $images[] = $meta;
            }
        }

        return $images;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function writeImage(string $relId): ?array
    {
        $target = $this->relationships[$relId] ?? null;
        if ($target === null) {
            return null;
        }

        $bytes = $this->archive->binary($target);
        if ($bytes === null) {
            return null;
        }

        $filename = basename($target);
        $this->ensureDir();
        file_put_contents(rtrim($this->imagesDir, '/').'/'.$filename, $bytes);

        return [
            'src_path' => 'images/'.$this->documentId.'/'.$filename,
            'src_url' => '/api/documents/'.$this->documentId.'/images/'.$filename,
            'data_uri' => null,
            'width' => null,
            'height' => null,
            'caption' => null,
        ];
    }

    /**
     * @return array{widthCm: float|null, heightCm: float|null}
     */
    private function drawingDimensions(DOMElement $blip): array
    {
        $drawing = $this->nearestAncestor($blip, 'inline', self::WORDPROCESSING_DRAWING_NS)
            ?? $this->nearestAncestor($blip, 'anchor', self::WORDPROCESSING_DRAWING_NS);
        if (! $drawing instanceof DOMElement) {
            return ['widthCm' => null, 'heightCm' => null];
        }

        foreach ($drawing->getElementsByTagNameNS(self::WORDPROCESSING_DRAWING_NS, 'extent') as $extent) {
            if ($extent instanceof DOMElement) {
                return $this->cmFromExtent($extent);
            }
        }

        foreach ($drawing->getElementsByTagNameNS(self::DRAWING_NS, 'ext') as $extent) {
            if ($extent instanceof DOMElement) {
                return $this->cmFromExtent($extent);
            }
        }

        return ['widthCm' => null, 'heightCm' => null];
    }

    /**
     * @return array{widthCm: float|null, heightCm: float|null}
     */
    private function cmFromExtent(DOMElement $extent): array
    {
        $cx = $extent->getAttribute('cx');
        $cy = $extent->getAttribute('cy');

        return [
            'widthCm' => is_numeric($cx) && (float) $cx > 0 ? round((float) $cx / self::EMU_PER_CM, 2) : null,
            'heightCm' => is_numeric($cy) && (float) $cy > 0 ? round((float) $cy / self::EMU_PER_CM, 2) : null,
        ];
    }

    /**
     * @return array{widthCm: float|null, heightCm: float|null}
     */
    private function vmlDimensions(DOMElement $imageData): array
    {
        $shape = $this->nearestAncestor($imageData, 'shape', self::VML_NS);
        if (! $shape instanceof DOMElement) {
            return ['widthCm' => null, 'heightCm' => null];
        }

        $style = $shape->getAttribute('style');

        return [
            'widthCm' => $this->cssLengthCm($this->cssProperty($style, 'width')),
            'heightCm' => $this->cssLengthCm($this->cssProperty($style, 'height')),
        ];
    }

    private function cssProperty(string $style, string $property): ?string
    {
        if (preg_match('/(?:^|;)\s*'.preg_quote($property, '/').'\s*:\s*([^;]+)/i', $style, $matches) !== 1) {
            return null;
        }

        return trim($matches[1]);
    }

    private function cssLengthCm(?string $value): ?float
    {
        if ($value === null || preg_match('/^([0-9]*\.?[0-9]+)\s*(cm|mm|in|pt|px)$/i', $value, $matches) !== 1) {
            return null;
        }

        $amount = (float) $matches[1];

        return match (strtolower($matches[2])) {
            'cm' => round($amount, 2),
            'mm' => round($amount / 10, 2),
            'in' => round($amount * 2.54, 2),
            'pt' => round($amount / 28.3464567, 2),
            'px' => round(($amount * 0.75) / 28.3464567, 2),
        };
    }

    private function paragraphAlignment(DOMElement $paragraph): ?string
    {
        $xpath = WordXml::createXPath($paragraph->ownerDocument);
        $jc = $xpath->query('./w:pPr/w:jc', $paragraph)?->item(0);

        return $jc instanceof DOMElement ? WordXml::wordAttr($jc, 'val') : null;
    }

    private function nearestAncestor(DOMElement $element, string $localName, string $namespace): ?DOMElement
    {
        $node = $element;
        while ($node->parentNode instanceof DOMElement) {
            $node = $node->parentNode;
            if ($node->localName === $localName && $node->namespaceURI === $namespace) {
                return $node;
            }
        }

        return null;
    }

    private function ensureDir(): void
    {
        if ($this->dirReady) {
            return;
        }

        if (! is_dir($this->imagesDir)) {
            @mkdir($this->imagesDir, 0777, true);
        }

        $this->dirReady = true;
    }
}
