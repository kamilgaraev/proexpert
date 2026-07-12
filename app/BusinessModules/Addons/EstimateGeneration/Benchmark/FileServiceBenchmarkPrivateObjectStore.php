<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

use App\Services\Storage\FileService;

final readonly class FileServiceBenchmarkPrivateObjectStore implements BenchmarkImmutableObjectStore
{
    public function __construct(private FileService $files) {}

    public function read(string $path, int $maxBytes): string
    {
        return $this->describe($path, $maxBytes)->body;
    }

    public function describe(string $path, int $maxBytes): BenchmarkPrivateObject
    {
        $this->assertPath($path, $maxBytes);
        try {
            $descriptor = $this->files->describeVersion($path, null, $maxBytes);
        } catch (\Throwable) {
            throw new BenchmarkContractException('private_object_unavailable');
        }
        $contents = $descriptor['body'];
        if (strlen($contents) > $maxBytes) {
            throw new BenchmarkContractException('private_object_too_large');
        }
        if ($contents === '') {
            throw new BenchmarkContractException('private_object_empty');
        }

        return new BenchmarkPrivateObject(
            $path,
            $contents,
            strlen($contents),
            hash('sha256', $contents),
            $descriptor['etag'], $descriptor['version_id'], $descriptor['content_type'],
        );
    }

    public function putImmutable(string $path, string $body, string $contentType): BenchmarkPrivateObject
    {
        $this->assertPath($path, strlen($body));
        $expectedHash = hash('sha256', $body);
        try {
            $stored = $this->files->putImmutable($path, $body, $contentType);
        } catch (\Throwable) {
            throw new BenchmarkContractException('private_object_write_failed');
        }
        if (! hash_equals($expectedHash, $stored['sha256']) || $stored['size'] !== strlen($body)
            || $stored['content_type'] !== $contentType || ! is_string($stored['version_id'])
            || trim($stored['version_id']) === '') {
            throw new BenchmarkContractException($stored['created'] ? 'private_object_write_integrity_mismatch' : 'private_object_immutable_conflict');
        }

        return new BenchmarkPrivateObject($path, $stored['body'], $stored['size'], $stored['sha256'],
            $stored['etag'], $stored['version_id'], $stored['content_type'], $stored['created']);
    }

    public function removeCreated(BenchmarkPrivateObject $object): void
    {
        if ($object->created) {
            $this->files->removeImmutable($object->path, $object->versionId);
        }
    }

    private function assertPath(string $path, int $maxBytes): void
    {
        if (! preg_match('#^org-[1-9][0-9]*/estimate-generation/benchmarks/[0-9a-f-]{36}/[a-f0-9]{64}\.json$#', $path)
            || $maxBytes < 1 || $maxBytes > 64_000_000) {
            throw new BenchmarkContractException('private_object_path_invalid');
        }
    }
}
