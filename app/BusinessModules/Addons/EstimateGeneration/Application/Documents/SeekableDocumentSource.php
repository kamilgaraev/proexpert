<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use InvalidArgumentException;

final class SeekableDocumentSource
{
    /** @param resource $handle */
    public function __construct(private mixed $handle, private readonly int $bytes)
    {
        if (! is_resource($handle) || $bytes < 1) {
            throw new InvalidArgumentException('Invalid seekable document source.');
        }
    }

    public function path(): string
    {
        $metadata = stream_get_meta_data($this->handle);
        $path = $metadata['uri'] ?? null;

        if (! is_string($path) || $path === '') {
            throw new InvalidArgumentException('Seekable document source has no path.');
        }

        return $path;
    }

    public function bytes(): int
    {
        return $this->bytes;
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
