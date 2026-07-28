<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services;

use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemException;
use RuntimeException;

class EstimateStructureSnapshotStorage
{
    private const DISK = 's3';

    public function exists(?string $path): bool
    {
        if (!$path) {
            return false;
        }

        try {
            return Storage::disk(self::DISK)->exists($path);
        } catch (FilesystemException) {
            return false;
        }
    }

    /**
     * @return resource
     */
    public function readStream(string $path)
    {
        $stream = Storage::disk(self::DISK)->readStream($path);

        if ($stream === false) {
            throw new RuntimeException('Unable to open estimate structure snapshot stream.');
        }

        return $stream;
    }

    public function put(string $path, string $contents): void
    {
        Storage::disk(self::DISK)->put($path, $contents);
    }

    public function delete(?string $path): void
    {
        if (!$path) {
            return;
        }

        try {
            if ($this->exists($path)) {
                Storage::disk(self::DISK)->delete($path);
            }
        } catch (FilesystemException) {
        }
    }
}
