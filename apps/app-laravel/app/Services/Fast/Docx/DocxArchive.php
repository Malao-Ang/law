<?php

namespace App\Services\Fast\Docx;

use DOMDocument;
use RuntimeException;
use ZipArchive;

final class DocxArchive
{
    private ZipArchive $zip;

    public function __construct(private readonly string $docxPath)
    {
        $this->zip = new ZipArchive;

        if ($this->zip->open($docxPath) !== true) {
            throw new RuntimeException("Unable to open DOCX archive: {$docxPath}");
        }
    }

    public function __destruct()
    {
        $this->zip->close();
    }

    public function documentXml(): DOMDocument
    {
        return $this->loadXml('word/document.xml');
    }

    public function numberingXml(): ?DOMDocument
    {
        if (! $this->exists('word/numbering.xml')) {
            return null;
        }

        return $this->loadXml('word/numbering.xml');
    }

    /**
     * @return array<string, string>
     */
    public function relationshipMap(): array
    {
        $path = 'word/_rels/document.xml.rels';
        if (! $this->exists($path)) {
            return [];
        }

        $document = $this->loadXml($path);
        $xpath = WordXml::createXPath($document);
        $map = [];

        foreach ($xpath->query('/*[local-name()="Relationships"]/*[local-name()="Relationship"]') ?: [] as $node) {
            if (! $node instanceof \DOMElement) {
                continue;
            }

            $type = $node->getAttribute('Type');
            if ($type === '') {
                continue;
            }

            $target = ltrim($node->getAttribute('Target'), './');
            if ($target === '') {
                continue;
            }

            if (! str_starts_with($target, 'word/')) {
                $target = 'word/'.$target;
            }

            $map[$node->getAttribute('Id')] = $target;
        }

        return $map;
    }

    public function exists(string $path): bool
    {
        return $this->zip->locateName($path) !== false;
    }

    private function loadXml(string $path): DOMDocument
    {
        $content = $this->zip->getFromName($path);
        if (! is_string($content)) {
            throw new RuntimeException("Missing XML entry: {$path}");
        }

        $document = new DOMDocument;
        $document->preserveWhiteSpace = true;
        $document->formatOutput = false;
        $document->loadXML($content);

        return $document;
    }
}
