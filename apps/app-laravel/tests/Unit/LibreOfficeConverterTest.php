<?php

namespace Tests\Unit;

use App\Services\Fast\LibreOfficeConverter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class LibreOfficeConverterTest extends TestCase
{
    public function test_throws_when_input_file_missing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Input file does not exist');

        $converter = new LibreOfficeConverter('libreoffice');
        $converter->convertToDocx('/nonexistent/file.doc');
    }

    public function test_returns_existing_docx_unchanged(): void
    {
        $tmpDir = sys_get_temp_dir().'/libreoffice-test-'.uniqid('', true);
        mkdir($tmpDir);
        $docxPath = $tmpDir.'/already.docx';
        file_put_contents($docxPath, 'fake docx');

        $converter = new LibreOfficeConverter('libreoffice');
        $result = $converter->convertToDocx($docxPath);

        $this->assertSame($docxPath, $result);

        unlink($docxPath);
        rmdir($tmpDir);
    }

    public function test_builds_correct_command_for_doc(): void
    {
        $tmpDir = sys_get_temp_dir().'/libreoffice-cmd-test-'.uniqid('', true);
        mkdir($tmpDir);
        $docPath = $tmpDir.'/test.doc';
        file_put_contents($docPath, 'fake doc');

        $captured = '';
        $converter = new LibreOfficeConverter(
            binary: 'libreoffice',
            commandRunner: function (array $cmd) use (&$captured): int {
                $captured = implode(' ', $cmd);
                $base = pathinfo($cmd[count($cmd) - 1], PATHINFO_FILENAME);
                $outDir = $cmd[array_search('--outdir', $cmd, true) + 1];
                file_put_contents("{$outDir}/{$base}.docx", 'converted');

                return 0;
            },
        );

        $result = $converter->convertToDocx($docPath);

        $this->assertStringContainsString('libreoffice', $captured);
        $this->assertStringContainsString('--headless', $captured);
        $this->assertStringContainsString('--convert-to docx', $captured);
        $this->assertFileExists($result);
        $this->assertStringEndsWith('.docx', $result);

        @unlink($result);
        @unlink($docPath);
        @rmdir(dirname($result));
        @rmdir($tmpDir);
    }
}
