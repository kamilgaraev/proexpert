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
        if ($case instanceof BenchmarkCaseData) {
            $root = realpath($case->fixtureRoot());
            $this->assertSafePathComponents($path, $case->fixtureRoot());
            $resolved = realpath($path);
            $expectedHash = $role === 'expected' ? $case->expectedSha256 : $case->inputSha256;
            $prefix = $root === false ? '' : rtrim(str_replace('\\', '/', $root), '/').'/';
            if ($root === false || $resolved === false || is_link($path)
                || ! str_starts_with(str_replace('\\', '/', $resolved), $prefix)
                || ! hash_equals($expectedHash, (string) hash_file('sha256', $resolved))) {
                throw new BenchmarkContractException($role === 'expected' ? 'expected_object_invalid' : 'input_object_invalid');
            }
            $path = $resolved;
        }
        if (! is_int($size) || $size < 1 || $size > $maxBytes) {
            throw new BenchmarkContractException('object_size_invalid');
        }
        $content = @file_get_contents($path);
        if (! is_string($content) || strlen($content) !== $size) {
            throw new BenchmarkContractException('object_read_failed');
        }

        return $content;
    }

    private function assertSafePathComponents(string $path, string $root): void
    {
        $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/');
        $normalizedPath = str_replace('\\', '/', $path);
        if (! str_starts_with($normalizedPath, $normalizedRoot.'/')) {
            throw new BenchmarkContractException('object_path_invalid');
        }
        $current = $normalizedRoot;
        foreach (explode('/', substr($normalizedPath, strlen($normalizedRoot) + 1)) as $component) {
            if ($component === '' || $component === '.' || $component === '..') {
                throw new BenchmarkContractException('object_path_invalid');
            }
            $current .= '/'.$component;
            $stat = @lstat($current);
            if (! is_array($stat) || is_link($current)
                || (PHP_OS_FAMILY !== 'Windows' && @readlink($current) !== false)
                || (((int) ($stat['mode'] ?? 0)) & 0170000) === 0120000) {
                throw new BenchmarkContractException('object_path_invalid');
            }
        }
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
