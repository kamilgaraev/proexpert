<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Support;

use RuntimeException;

final class DesignViewerFileIntegrity
{
    public static function forPath(string $path): array
    {
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Prepared BIM file is not readable.');
        }

        try {
            return self::forStream($stream);
        } finally {
            fclose($stream);
        }
    }

    public static function forStream(mixed $stream): array
    {
        if (! is_resource($stream)) {
            throw new RuntimeException('Prepared BIM file is not readable.');
        }

        $hash = hash_init('sha256');
        $size = 0;
        while (! feof($stream)) {
            $chunk = fread($stream, 1024 * 1024);
            if ($chunk === false) {
                throw new RuntimeException('Prepared BIM file could not be read.');
            }
            if ($chunk === '' && ! feof($stream)) {
                throw new RuntimeException('Prepared BIM file stream did not advance.');
            }
            $size += strlen($chunk);
            hash_update($hash, $chunk);
        }

        if ($size <= 0) {
            throw new RuntimeException('Prepared BIM file is empty.');
        }

        return ['sha256' => hash_final($hash), 'size' => $size];
    }
}
