<?php

use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;

require __DIR__.'/../../../vendor/autoload.php';

function buildTestDocx(string $outPath): string
{
    $phpWord = new PhpWord;
    $phpWord->addParagraphStyle('Heading1', ['alignment' => 'center']);
    $phpWord->addTitleStyle(1, ['bold' => true, 'size' => 20]);

    $section = $phpWord->addSection();

    $section->addTitle('ประกาศมหาวิทยาลัยบูรพา', 1);
    $section->addText('เรื่อง หลักเกณฑ์การเบิกค่าใช้จ่าย', ['bold' => true]);

    $section->addText('โดยที่เป็นการสมควรกำหนดหลักเกณฑ์', null, [
        'alignment' => 'justify',
        'indentation' => ['firstLine' => 720],
    ]);

    $textRun = $section->addTextRun();
    $textRun->addText('มาตรา 1 ', ['bold' => true]);
    $textRun->addText('ระเบียบนี้เรียกว่า "ระเบียบทดสอบ"');

    $section->addText('ข้อ 1.1 รายการแรก', null, ['indentation' => ['left' => 720]]);
    $section->addText('ข้อ 1.2 รายการที่สอง', null, ['indentation' => ['left' => 720]]);

    $table = $section->addTable();
    $table->addRow();
    $table->addCell(2000)->addText('หัวคอลัมน์ A');
    $table->addCell(2000)->addText('หัวคอลัมน์ B');
    $table->addRow();
    $table->addCell(2000)->addText('แถว1-A');
    $table->addCell(2000)->addText('แถว1-B');

    IOFactory::createWriter($phpWord, 'Word2007')->save($outPath);

    return $outPath;
}
