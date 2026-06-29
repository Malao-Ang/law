<?php

require_once __DIR__.'/../../../vendor/autoload.php';

/**
 * Write a minimal .docx archive with verbatim document and optional numbering XML.
 */
function buildRawDocx(string $outPath, string $bodyInner, ?string $numberingInner = null): string
{
    $wordNs = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';

    $documentXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<w:document '.$wordNs.'><w:body>'.$bodyInner.'</w:body></w:document>';

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        .'<Default Extension="xml" ContentType="application/xml"/>'
        .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        .($numberingInner !== null
            ? '<Override PartName="/word/numbering.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml"/>'
            : '')
        .'</Types>';

    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
        .'</Relationships>';

    $documentRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        .($numberingInner !== null
            ? '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering" Target="numbering.xml"/>'
            : '')
        .'</Relationships>';

    $zip = new ZipArchive;
    if ($zip->open($outPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException("Cannot create docx at {$outPath}");
    }

    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rootRels);
    $zip->addFromString('word/document.xml', $documentXml);
    $zip->addFromString('word/_rels/document.xml.rels', $documentRels);

    if ($numberingInner !== null) {
        $numberingXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:numbering '.$wordNs.'>'.$numberingInner.'</w:numbering>';
        $zip->addFromString('word/numbering.xml', $numberingXml);
    }

    $zip->close();

    return $outPath;
}
