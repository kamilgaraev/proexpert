<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Storage;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;

class EstimateSourceStorageService
{
    private const ROOT_PREFIX = 'estimate-sources/';

    /**
     * @return array<int, string>
     */
    public function listFiles(string $bucket, string $prefix): array
    {
        $normalizedPrefix = $this->normalizePath($prefix);
        $this->guardEstimateSourcePath($normalizedPrefix);

        $files = $this->disk($bucket)->files($normalizedPrefix, true);

        sort($files);

        return array_values($files);
    }

    /**
     * @return resource
     */
    public function openReadStream(string $bucket, string $key)
    {
        $normalizedKey = $this->normalizePath($key);
        $this->guardEstimateSourcePath($normalizedKey);

        $stream = $this->disk($bucket)->readStream($normalizedKey);

        if ($stream === false) {
            throw new RuntimeException("Unable to open estimate source file stream: {$bucket}:{$normalizedKey}");
        }

        return $stream;
    }

    public function disk(string $bucket): Filesystem
    {
        if (array_key_exists($bucket, (array) Config::get('filesystems.disks', []))) {
            return Storage::disk($bucket);
        }

        $s3Config = (array) Config::get('filesystems.disks.s3', []);

        if (($s3Config['bucket'] ?? null) === $bucket) {
            return Storage::disk('s3');
        }

        if (($s3Config['driver'] ?? null) === 's3') {
            $s3Config['bucket'] = $bucket;

            return Storage::build($s3Config);
        }

        throw new InvalidArgumentException("Unable to resolve estimate source bucket or disk: {$bucket}");
    }

    private function guardEstimateSourcePath(string $path): void
    {
        if (!str_starts_with($path, self::ROOT_PREFIX)) {
            throw new InvalidArgumentException(
                sprintf('Estimate source path must start with "%s". Given: "%s"', self::ROOT_PREFIX, $path)
            );
        }
    }

    private function normalizePath(string $path): string
    {
        $normalized = trim(str_replace('\\', '/', $path));

        if ($normalized === '') {
            throw new InvalidArgumentException('Estimate source path must not be empty.');
        }

        return ltrim($normalized, '/');
    }
}
