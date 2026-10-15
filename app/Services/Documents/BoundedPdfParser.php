<?php

declare(strict_types=1);

namespace App\Services\Documents;

use RuntimeException;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

final class BoundedPdfParser
{
    public function inspectContent(string $content, bool $includeText = true): array
    {
        if (strlen($content) > 25_000_000) {
            throw new RuntimeException('pdf_parser_input_limit_exceeded');
        }
        $file = tmpfile();
        if (!is_resource($file)) {
            throw new RuntimeException('pdf_parser_spool_failed');
        }
        try {
            if (fwrite($file, $content) !== strlen($content)) {
                throw new RuntimeException('pdf_parser_spool_failed');
            }
            fflush($file);

            return $this->inspectFile(stream_get_meta_data($file)['uri'], $includeText);
        } finally {
            fclose($file);
        }
    }

    public function inspectFile(string $path, bool $includeText = true): array
    {
        $size = filesize($path);
        if ($size === false || $size > 25_000_000 || !is_file($path)) {
            throw new RuntimeException('pdf_parser_input_limit_exceeded');
        }
        $php = (new PhpExecutableFinder)->find(false);
        if ($php === false) {
            throw new RuntimeException('pdf_parser_runtime_unavailable');
        }
        $process = new Process([
            $php, '-d', 'memory_limit=128M', '-d', 'max_execution_time=12',
            __DIR__.'/parse-pdf.php', $path, $includeText ? 'text' : 'metadata',
        ]);
        $process->setTimeout(15);
        $outputBytes = 0;
        try {
            $process->run(function (string $type, string $buffer) use (&$outputBytes, $process): void {
                $outputBytes += strlen($buffer);
                if ($outputBytes > 8_000_000) {
                    $process->stop(0);
                    throw new RuntimeException('pdf_parser_output_limit_exceeded');
                }
            });
            if (!$process->isSuccessful()) {
                throw new RuntimeException('pdf_parser_failed');
            }
            $result = json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($result) || !isset($result['page_count'], $result['pages'], $result['details'])) {
                throw new RuntimeException('pdf_parser_result_invalid');
            }

            return $result;
        } finally {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
    }
}
