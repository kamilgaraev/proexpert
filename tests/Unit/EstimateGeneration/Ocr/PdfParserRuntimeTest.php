<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Ocr;

use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\PdfParserRuntime;
use Tests\TestCase;

class PdfParserRuntimeTest extends TestCase
{
    public function test_it_temporarily_raises_and_restores_memory_limit(): void
    {
        $previousLimit = ini_get('memory_limit');
        $oneGigabyte = 1024 * 1024 * 1024;

        if ($previousLimit === false) {
            $this->markTestSkipped('Current PHP memory limit cannot be read.');
        }

        $baselineLimit = $previousLimit;

        if ($baselineLimit === '-1' || $this->memoryLimitToBytes($baselineLimit) >= $oneGigabyte) {
            $baselineLimit = '512M';

            if (@ini_set('memory_limit', $baselineLimit) === false) {
                $this->markTestSkipped('Current PHP memory limit cannot be lowered for deterministic check.');
            }
        }

        try {
            config()->set('estimate-generation.ocr.pdf_parser_memory_limit', '1024M');
            $insideLimit = app(PdfParserRuntime::class)->withRaisedMemoryLimit(
                static fn (): string|false => ini_get('memory_limit')
            );

            $this->assertSame('1024M', $insideLimit);
            $this->assertSame($baselineLimit, ini_get('memory_limit'));
        } finally {
            ini_set('memory_limit', $previousLimit);
        }
    }

    private function memoryLimitToBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '-1') {
            return PHP_INT_MAX;
        }

        if (!preg_match('/^([0-9]+)\s*([KMG])?$/i', $value, $matches)) {
            return PHP_INT_MAX;
        }

        $bytes = (int) $matches[1];
        $unit = strtoupper($matches[2] ?? '');

        return match ($unit) {
            'G' => $bytes * 1024 * 1024 * 1024,
            'M' => $bytes * 1024 * 1024,
            'K' => $bytes * 1024,
            default => $bytes,
        };
    }
}
