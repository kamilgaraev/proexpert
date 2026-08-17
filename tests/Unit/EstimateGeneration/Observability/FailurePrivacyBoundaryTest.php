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
    public function module_never_persists_or_logs_raw_throwable_diagnostics(): void
    {
        $root = dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration';
        $violations = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            self::assertIsString($source);
            foreach (['->getTrace()', '->getTraceAsString()', "'trace' =>", '"trace" =>'] as $forbidden) {
                if (str_contains($source, $forbidden)) {
                    $violations[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1)).':'.$forbidden;
                }
            }
            if (str_contains($source, '$exception->responseBody')) {
                $violations[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1)).':response-body-persistence';
            }
            if (preg_match('/(?<!->)\breport\(\$(?:e|exception|throwable)\b/', $source) === 1) {
                $violations[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1)).':raw-exception-reporting';
            }
            if (str_ends_with(str_replace('\\', '/', $file->getPathname()), 'EstimateSourceImportService.php')) {
                foreach (["'source_file' => \$sourceFile", "'raw_fragment' => \$rawFragment"] as $forbiddenWrite) {
                    if (str_contains($source, $forbiddenWrite)) {
                        $violations[] = 'Normatives/Services/Import/EstimateSourceImportService.php:'.$forbiddenWrite;
                    }
                }
            }
            preg_match_all('/Log::(?:error|warning|info|debug)\s*\(.*?\);/s', $source, $logs);
            foreach ($logs[0] ?? [] as $logCall) {
                foreach (['->getMessage()', '->getTrace()', '->getTraceAsString()', '->responseBody'] as $forbiddenDiagnostic) {
                    if (str_contains($logCall, $forbiddenDiagnostic)) {
                        $violations[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1)).':log-raw-diagnostic';
                    }
                }
                if (preg_match('/\$request->(?:all|input|validated|file|getContent)\s*\(/', $logCall) === 1) {
                    $violations[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1)).':log-request-value';
                }
            }
        }

        self::assertSame([], $violations, implode("\n", $violations));
    }
}
