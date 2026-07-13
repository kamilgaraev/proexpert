<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

final readonly class LocalBenchmarkReportOutputStore implements BenchmarkReportOutputStore
{
    public function __construct(private string $root) {}

    public function write(string $locator, string $contents): string
    {
        $normalized = str_replace('\\', '/', $locator);
        if ($normalized === '' || str_starts_with($normalized, '/') || preg_match('/^[A-Za-z]:/', $normalized)
            || str_contains($normalized, '../') || ! preg_match('#^[a-zA-Z0-9._/-]+\.(json|txt)$#', $normalized)) {
            throw new BenchmarkCommandException('output_path_invalid');
        }
        if (! is_dir($this->root) && ! mkdir($this->root, 0750, true) && ! is_dir($this->root)) {
            throw new BenchmarkCommandException('output_root_unavailable');
        }
        $root = realpath($this->root);
        if ($root === false || is_link($this->root)) {
            throw new BenchmarkCommandException('output_root_invalid');
        }
        $path = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $normalized);
        if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0750, true) && ! is_dir(dirname($path))) {
            throw new BenchmarkCommandException('output_directory_unavailable');
        }
        if (file_exists($path) || file_put_contents($path, $contents, LOCK_EX) !== strlen($contents)) {
            throw new BenchmarkCommandException('output_create_failed');
        }

        return $path;
    }
}
