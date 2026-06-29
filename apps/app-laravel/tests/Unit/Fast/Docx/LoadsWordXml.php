<?php

namespace Tests\Unit\Fast\Docx;

use App\Services\Fast\Docx\WordXml;
use DOMDocument;
use DOMElement;
use DOMXPath;

trait LoadsWordXml
{
    /**
     * @return array{0: DOMDocument, 1: DOMXPath, 2: DOMElement}
     */
    protected function loadWordFragment(string $innerXml, string $root = 'w:p'): array
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<w:document xmlns:w="'.WordXml::WORD_NS.'"><w:body><'.$root.'>'.$innerXml.'</'.$root.'></w:body></w:document>';

        $document = new DOMDocument;
        $document->loadXML($xml);

        $xpath = WordXml::createXPath($document);
        $node = $xpath->query('//'.$root)->item(0);

        if (! $node instanceof DOMElement) {
            throw new \RuntimeException("Failed to load root {$root}");
        }

        return [$document, $xpath, $node];
    }
}
