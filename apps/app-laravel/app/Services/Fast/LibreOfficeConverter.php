<?php

namespace App\Services\Fast;

use Closure;
use RuntimeException;

class LibreOfficeConverter
{
    /**
     * @param  Closure(array<int, string>): int|null  $commandRunner
     */
    public function __construct(
        private readonly string $binary = 'libreoffice',
        private readonly ?Closure $commandRunner = null,
    ) {}

    public function convertToDocx(string $inputPath): string
    {
        $ext = strtolower(pathinfo($inputPath, PATHINFO_EXTENSION));
        if ($ext === 'docx') {
            return $inputPath;
        }

        return $this->convert($inputPath, 'docx', 'docx');
    }

    public function convertToPdf(string $inputPath): string
    {
        return $this->convert($inputPath, 'pdf', 'pdf');
    }

    private function convert(string $inputPath, string $targetFormat, string $targetExt): string
    {
        if (! file_exists($inputPath)) {
            throw new RuntimeException("Input file does not exist: {$inputPath}");
        }

        $outDir = sys_get_temp_dir().'/libreoffice-'.bin2hex(random_bytes(8));
        if (! is_dir($outDir) && ! mkdir($outDir, 0700, true) && ! is_dir($outDir)) {
            throw new RuntimeException("Unable to create conversion directory: {$outDir}");
        }

        $cmd = [
            $this->binary,
            '--headless',
            '--convert-to', $targetFormat,
            '--outdir', $outDir,
            $inputPath,
        ];

        $exit = $this->runCommand($cmd);
        if ($exit !== 0) {
            throw new RuntimeException("LibreOffice conversion failed (exit {$exit}) for {$inputPath}");
        }

        $base = pathinfo($inputPath, PATHINFO_FILENAME);
        $outPath = "{$outDir}/{$base}.{$targetExt}";

        if (! file_exists($outPath)) {
            throw new RuntimeException("Converted file not found at {$outPath}");
        }

        return $outPath;
    }

    /**
     * @param  array<int, string>  $cmd
     */
    private function runCommand(array $cmd): int
    {
        if ($this->commandRunner !== null) {
            return (int) (($this->commandRunner)($cmd) ?? 0);
        }

        $process = proc_open(
            $cmd,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (! is_resource($process)) {
            return 127;
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process);
    }
}
