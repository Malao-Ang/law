<?php

function buildTestPdf(string $outPath, string $text = 'Hello PDF'): string
{
    $escaped = addcslashes($text, '\\()');
    $stream = "BT\n/F1 24 Tf\n100 700 Td\n";
    if ($text !== '') {
        $stream .= "({$escaped}) Tj\n";
    }
    $stream .= "ET\n";

    $objects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
        4 => '<< /Length '.strlen($stream)." >>\nstream\n{$stream}endstream",
        5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ];

    $pdf = "%PDF-1.4\n";
    $offsets = [];

    foreach ($objects as $id => $body) {
        $offsets[$id] = strlen($pdf);
        $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
    }

    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 6\n";
    $pdf .= "0000000000 65535 f \n";
    for ($id = 1; $id <= 5; $id++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
    }
    $pdf .= "trailer\n<< /Root 1 0 R /Size 6 >>\n";
    $pdf .= "startxref\n{$xrefOffset}\n%%EOF\n";

    file_put_contents($outPath, $pdf);

    return $outPath;
}
