<?php

declare(strict_types=1);

namespace App\Services\Storage\DTO;

use InvalidArgumentException;

final readonly class CurrentStoredFile
{
    public function __construct(
        public string $key,
        public string $etag,
        public int $sizeBytes,
        public string $sha256,
        public string $mime,
    ) {
        if (
            preg_match('#^org-[1-9][0-9]*/[^\\\\\x00-\x1F\x7F]+$#D', $key) !== 1
            || strlen($key) > 1024
            || str_contains($key, '://')
            || preg_match('#(?:^|/)\.\.(?:/|$)#', $key) === 1
            || ! self::isSafeString($etag)
            || $sizeBytes < 1
            || preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1
            || ! self::isSafeString($mime)
        ) {
            throw new InvalidArgumentException('stored_file_identity_invalid');
        }
    }

    private static function isSafeString(string $value): bool
    {
        return $value !== ''
            && strlen($value) <= 255
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }
}
