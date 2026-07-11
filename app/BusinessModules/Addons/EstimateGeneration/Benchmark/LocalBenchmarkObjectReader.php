<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

final class LocalBenchmarkObjectReader implements BenchmarkObjectReader
{
    public function read(BenchmarkCaseData $case, string $role, int $maxBytes): string
    {
        $path = match ($role) {
            'input' => $case->inputPath(),
            'expected' => $case->expectedPath(),
            default => throw new BenchmarkContractException('object_role_invalid'),
        };
        $size = @filesize($path);
        if (! is_int($size) || $size < 1 || $size > $maxBytes) {
            throw new BenchmarkContractException('object_size_invalid');
        }
        $content = @file_get_contents($path);
        if (! is_string($content) || strlen($content) !== $size) {
            throw new BenchmarkContractException('object_read_failed');
        }

        return $content;
    }
}
