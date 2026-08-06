<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Storage;

use RuntimeException;
use Throwable;

final class TemporaryEstimateSourceFile
{
    public static function fromContents(string $contents, string $extension, string $prefix): string
    {
        $source = fopen('php://temp', 'w+b');
        if (! is_resource($source)) {
            throw new RuntimeException('temporary_estimate_source_unavailable');
        }

        $written = fwrite($source, $contents);
        if ($written !== strlen($contents) || fseek($source, 0) !== 0) {
            fclose($source);

            throw new RuntimeException('temporary_estimate_source_write_failed');
        }

        return self::fromStream($source, $extension, $prefix);
    }

    /** @param resource $source */
    public static function fromStream($source, string $extension, string $prefix): string
    {
        $extension = strtolower($extension);

        if (! is_resource($source) || preg_match('/^[a-z0-9]{1,16}$/D', $extension) !== 1) {
            if (is_resource($source)) {
                fclose($source);
            }

            throw new RuntimeException('temporary_estimate_source_invalid');
        }

        $basePath = tempnam(sys_get_temp_dir(), $prefix);
        if (! is_string($basePath)) {
            fclose($source);

            throw new RuntimeException('temporary_estimate_source_unavailable');
        }

        $targetPath = $basePath.'.'.$extension;
        $target = null;

        try {
            if (! rename($basePath, $targetPath)) {
                throw new RuntimeException('temporary_estimate_source_write_failed');
            }

            $target = fopen($targetPath, 'w+b');
            if (! is_resource($target)) {
                throw new RuntimeException('temporary_estimate_source_write_failed');
            }

            $copied = stream_copy_to_stream($source, $target);
            if (! is_int($copied) || $copied < 1 || ! fflush($target)) {
                throw new RuntimeException('temporary_estimate_source_write_failed');
            }

            fclose($target);
            $target = null;
            fclose($source);

            return $targetPath;
        } catch (Throwable $exception) {
            if (is_resource($target)) {
                fclose($target);
            }
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_file($basePath)) {
                @unlink($basePath);
            }
            if (is_file($targetPath)) {
                @unlink($targetPath);
            }

            throw new RuntimeException('temporary_estimate_source_write_failed', 0, $exception);
        }
    }
}
