<?php

namespace App\Services\Fast\Docx;

use DOMDocument;
use DOMElement;
use DOMXPath;

final class WordXml
{
    public const WORD_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    public const REL_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    public const PKG_REL_NS = 'http://schemas.openxmlformats.org/package/2006/relationships';

    public static function createXPath(DOMDocument $document): DOMXPath
    {
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('w', self::WORD_NS);
        $xpath->registerNamespace('r', self::REL_NS);
        $xpath->registerNamespace('pr', self::PKG_REL_NS);

        return $xpath;
    }

    public static function localName(string $qualifiedName): string
    {
        $pos = strrpos($qualifiedName, ':');

        return $pos === false ? $qualifiedName : substr($qualifiedName, $pos + 1);
    }

    public static function wordAttr(?DOMElement $node, string $name): ?string
    {
        if ($node === null) {
            return null;
        }

        $value = $node->getAttributeNS(self::WORD_NS, $name);

        return $value === '' ? null : $value;
    }

    public static function wordRelAttr(?DOMElement $node, string $name): ?string
    {
        if ($node === null) {
            return null;
        }

        $value = $node->getAttributeNS(self::REL_NS, $name);

        return $value === '' ? null : $value;
    }

    public static function packageAttr(?DOMElement $node, string $name): ?string
    {
        if ($node === null) {
            return null;
        }

        $value = $node->getAttribute($name);

        return $value === '' ? null : $value;
    }

    public static function parseIntAttr(?DOMElement $node, string $name): ?int
    {
        $value = self::wordAttr($node, $name);

        return $value === null || $value === '' ? null : (int) $value;
    }
}
