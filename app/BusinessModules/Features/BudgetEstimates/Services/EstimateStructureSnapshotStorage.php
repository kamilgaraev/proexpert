<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services;

use App\Services\Storage\FileService;
use League\Flysystem\FilesystemException;
use RuntimeException;

class EstimateStructureSnapshotStorage
{
    public function __construct(
        private readonly FileService $files,
    ) {}

    public function exists(?string $path): bool
    {
        if (! $path) {
            return false;
        }

        try {
            return $this->files->existsCurrent($path);
        } catch (FilesystemException) {
            return false;
        }
    }

    /**
     * @return resource
     */
    public function readStream(string $path)
    {
        return $this->files->readCurrent($path);
    }

    /** @param array<string, mixed> $payload */
    public function putJson(string $path, array $payload): void
    {
        $stream = fopen('php://temp/maxmemory:2097152', 'w+b');
        if (! is_resource($stream)) {
            throw new RuntimeException('estimate_snapshot_stream_unavailable');
        }

        $hash = hash_init('sha256');

        try {
            $this->writeJsonValue($stream, $hash, $payload);
            rewind($stream);

            $this->files->putPrivate(
                $path,
                $stream,
                'application/json',
                hash_final($hash),
            );
        } finally {
            fclose($stream);
        }
    }

    public function delete(?string $path): void
    {
        try {
            if (! $path || ! $this->exists($path)) {
                return;
            }

            $this->files->deleteCurrent($path);
        } catch (FilesystemException|\InvalidArgumentException) {
        }
    }

    /**
     * @param  resource  $stream
     */
    private function writeJsonValue($stream, \HashContext $hash, mixed $value): void
    {
        if (! is_array($value)) {
            $this->writeFragment(
                $stream,
                $hash,
                json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            );

            return;
        }

        $isList = array_is_list($value);
        $this->writeFragment($stream, $hash, $isList ? '[' : '{');

        $first = true;
        foreach ($value as $key => $item) {
            if (! $first) {
                $this->writeFragment($stream, $hash, ',');
            }
            $first = false;

            if (! $isList) {
                $this->writeFragment(
                    $stream,
                    $hash,
                    json_encode((string) $key, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).':',
                );
            }

            $this->writeJsonValue($stream, $hash, $item);
        }

        $this->writeFragment($stream, $hash, $isList ? ']' : '}');
    }

    /**
     * @param  resource  $stream
     */
    private function writeFragment($stream, \HashContext $hash, string $fragment): void
    {
        $offset = 0;
        $length = strlen($fragment);

        while ($offset < $length) {
            $written = fwrite($stream, substr($fragment, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('estimate_snapshot_stream_write_failed');
            }

            $offset += $written;
        }

        hash_update($hash, $fragment);
    }
}
