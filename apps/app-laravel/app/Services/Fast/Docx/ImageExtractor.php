<?php

namespace App\Services\Fast\Docx;

use DOMElement;

final class ImageExtractor
{
    private const DRAWING_NS = 'http://schemas.openxmlformats.org/drawingml/2006/main';

    private const VML_NS = 'urn:schemas-microsoft-com:vml';

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
        $relIds = [];

        foreach ($paragraph->getElementsByTagNameNS(self::DRAWING_NS, 'blip') as $blip) {
            if (! $blip instanceof DOMElement) {
                continue;
            }

            $relId = WordXml::wordRelAttr($blip, 'embed');
            if ($relId !== null) {
                $relIds[] = $relId;
            }
        }

        foreach ($paragraph->getElementsByTagNameNS(self::VML_NS, 'imagedata') as $imageData) {
            if (! $imageData instanceof DOMElement) {
                continue;
            }

            $relId = WordXml::wordRelAttr($imageData, 'id');
            if ($relId !== null) {
                $relIds[] = $relId;
            }
        }

        $images = [];
        foreach ($relIds as $relId) {
            $meta = $this->writeImage($relId);
            if ($meta !== null) {
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
