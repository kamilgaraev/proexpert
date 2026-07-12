<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

use App\Services\Storage\FileService;

final readonly class FileServiceAcceptanceBenchmarkObjectStore implements BenchmarkPrivateObjectStore
{
    public function __construct(private FileService $files) {}

    public function read(string $path, int $maxBytes): string
    {
        if (! preg_match('#^org-[1-9][0-9]*/estimate-generation/benchmarks/acceptance/[a-zA-Z0-9._/-]+$#', $path)
            || str_contains($path, '..') || $maxBytes < 1 || $maxBytes > 64_000_000) {
            throw new BenchmarkContractException('private_object_path_invalid');
        }
        $stream = $this->files->disk()->readStream($path);
        if (! is_resource($stream)) {
            throw new BenchmarkContractException('private_object_unavailable');
        }
        $contents = '';
        try {
            while (! feof($stream)) {
                $chunk = fread($stream, min(65_536, $maxBytes + 1 - strlen($contents)));
                if (! is_string($chunk)) {
                    throw new BenchmarkContractException('private_object_read_failed');
                }
                $contents .= $chunk;
                if (strlen($contents) > $maxBytes) {
                    throw new BenchmarkContractException('private_object_too_large');
                }
            }
        } finally {
            fclose($stream);
        }
        if ($contents === '') {
            throw new BenchmarkContractException('private_object_empty');
        }

        return $contents;
    }
}
