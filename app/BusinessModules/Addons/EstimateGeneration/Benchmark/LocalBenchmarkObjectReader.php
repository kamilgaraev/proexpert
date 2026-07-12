<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

final class LocalBenchmarkObjectReader implements BenchmarkObjectReader
{
    public function __construct(private readonly ?string $fixtureRoot = null) {}

    public function read(BenchmarkCaseData|BenchmarkPredictionCaseData $case, string $role, int $maxBytes): string
    {
        $path = match ($role) {
            'input' => $case instanceof BenchmarkCaseData
                ? $case->inputPath()
                : $this->predictionInputPath($case),
            'expected' => $case instanceof BenchmarkCaseData
                ? $case->expectedPath()
                : throw new BenchmarkContractException('prediction_expected_read_forbidden'),
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

    private function predictionInputPath(BenchmarkPredictionCaseData $case): string
    {
        $root = $this->fixtureRoot === null ? false : realpath($this->fixtureRoot);
        if ($root === false) {
            throw new BenchmarkContractException('prediction_fixture_root_unavailable');
        }
        $path = realpath($root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $case->inputLocator));
        $prefix = rtrim(str_replace('\\', '/', $root), '/').'/';
        if ($path === false || ! str_starts_with(str_replace('\\', '/', $path), $prefix)
            || is_link($path) || ! hash_equals($case->inputSha256, (string) hash_file('sha256', $path))) {
            throw new BenchmarkContractException('prediction_input_invalid');
        }

        return $path;
    }
}
