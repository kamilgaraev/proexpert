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

        $disk = $this->files->disk();
        $contentType = $disk->mimeType($path);
        $provider = $this->providerMetadata($path);

        return new BenchmarkPrivateObject(
            $path,
            $contents,
            strlen($contents),
            hash('sha256', $contents),
            $provider['etag'],
            $provider['version_id'],
            is_string($contentType) && $contentType !== '' ? $contentType : 'application/json',
        );
    }

    public function putImmutable(string $path, string $body, string $contentType): BenchmarkPrivateObject
    {
        $this->assertPath($path, strlen($body));
        $expectedHash = hash('sha256', $body);
        $disk = $this->files->disk();
        if ($disk->exists($path)) {
            $existing = $this->describe($path, strlen($body));
            if (! hash_equals($expectedHash, $existing->sha256)) {
                throw new BenchmarkContractException('private_object_immutable_conflict');
            }

            return $existing;
        }
        if (! $disk->put($path, $body, ['visibility' => 'private', 'ContentType' => $contentType])) {
            throw new BenchmarkContractException('private_object_write_failed');
        }
        $stored = $this->describe($path, strlen($body));
        if (! hash_equals($expectedHash, $stored->sha256)) {
            throw new BenchmarkContractException('private_object_write_integrity_mismatch');
        }

        return $stored;
    }

    private function assertPath(string $path, int $maxBytes): void
    {
        if (! preg_match('#^org-[1-9][0-9]*/estimate-generation/benchmarks/[0-9a-f-]{36}/[a-f0-9]{64}\.json$#', $path)
            || $maxBytes < 1 || $maxBytes > 64_000_000) {
            throw new BenchmarkContractException('private_object_path_invalid');
        }
    }

    /** @return array{etag: ?string, version_id: ?string} */
    private function providerMetadata(string $path): array
    {
        $disk = $this->files->disk();
        $adapter = $disk->getAdapter();
        $config = $disk->getConfig();
        if (! method_exists($adapter, 'getClient') || ! is_string($config['bucket'] ?? null)) {
            return ['etag' => null, 'version_id' => null];
        }
        try {
            $result = $adapter->getClient()->headObject(['Bucket' => $config['bucket'], 'Key' => $path]);

            return [
                'etag' => is_string($result['ETag'] ?? null) ? trim($result['ETag'], '"') : null,
                'version_id' => is_string($result['VersionId'] ?? null) ? $result['VersionId'] : null,
            ];
        } catch (\Throwable) {
            return ['etag' => null, 'version_id' => null];
        }
    }
}
