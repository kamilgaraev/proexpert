<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Observability;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class FailurePrivacyBoundaryTest extends TestCase
{
    #[Test]
    public function entire_module_never_reads_raw_throwable_diagnostics(): void
    {
        $root = dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration';
        $violations = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            self::assertIsString($source);
            foreach (['->getMessage()', '->getTrace()', '->getTraceAsString()', "'trace' =>", '"trace" =>'] as $forbidden) {
                if (str_contains($source, $forbidden)) {
                    $violations[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1)).':'.$forbidden;
                }
            }
            preg_match_all('/Log::(?:error|warning|info|debug)\s*\(.*?\);/s', $source, $logs);
            foreach ($logs[0] ?? [] as $logCall) {
                foreach (['prompt', 'filename', 'file_name', 'path', 'token', 'authorization', 'api_key', 'request', 'response', 'content', 'error', 'message'] as $key) {
                    if (str_contains(strtolower($logCall), "'{$key}' =>") || str_contains(strtolower($logCall), '"'.$key.'" =>')) {
                        $violations[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1)).':log-'.$key;
                    }
                }
                if (str_contains($logCall, '$request->')) {
                    $violations[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1)).':log-request-value';
                }
            }
        }

        self::assertSame([], $violations, implode("\n", $violations));
    }
}
