<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\Preprocessing;

use App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\RasterPreprocessingException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;

final readonly class BoundedStorageReader
{
    public function read(FilesystemAdapter|Filesystem $disk, string $key, int $maxBytes): string
    {
        if ($maxBytes < 1) {
            throw new RasterPreprocessingException('invalid_image_size');
        }
        try {
            $size = $disk->size($key);
        } catch (\Throwable) {
            throw new RasterPreprocessingException('source_metadata_unavailable');
        }
        if (! is_int($size) || $size < 1 || $size > $maxBytes) {
            throw new RasterPreprocessingException('invalid_image_size');
        }
        try {
            $stream = $disk->readStream($key);
        } catch (\Throwable) {
            throw new RasterPreprocessingException('source_read_failed');
        }
        if (! is_resource($stream)) {
            throw new RasterPreprocessingException('source_read_failed');
        }
        $content = '';
        try {
            while (! feof($stream)) {
                $remaining = $maxBytes + 1 - strlen($content);
                if ($remaining <= 0) {
                    throw new RasterPreprocessingException('invalid_image_size');
                }
                $chunk = fread($stream, min(65_536, $remaining));
                if ($chunk === false) {
                    throw new RasterPreprocessingException('source_read_failed');
                }
                if ($chunk === '' && ! feof($stream)) {
                    throw new RasterPreprocessingException('source_read_failed');
                }
                $content .= $chunk;
            }
        } finally {
            fclose($stream);
        }
        if (strlen($content) !== $size || $content === '') {
            throw new RasterPreprocessingException('source_size_changed');
        }

        return $content;
    }
}
