<?php

declare(strict_types=1);

namespace App\Services\Storage\DTO;

use InvalidArgumentException;

final readonly class StoredFile
{
    public function __construct(
        public string $organizationPath,
        public string $etag,
        public int $sizeBytes,
        public string $sha256,
        public string $mime,
    ) {
        if (! self::isPrivateRelativePath($organizationPath)
            || ! self::isSafeString($etag)
            || $sizeBytes < 1
            || preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1
            || ! self::isSafeString($mime)) {
            throw new InvalidArgumentException('stored_file_identity_invalid');
        }
    }

    private static function isPrivateRelativePath(string $path): bool
    {
        return $path !== ''
            && strlen($path) <= 1024
            && ! str_starts_with($path, '/')
            && ! str_contains($path, '://')
            && ! preg_match('#(?:^|/)\.\.(?:/|$)#', $path)
            && ! str_contains($path, '\\')
            && preg_match('/[\x00-\x1F\x7F]/', $path) !== 1;
    }

    private static function isSafeString(string $value): bool
    {
        return $value !== ''
            && strlen($value) <= 255
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }
}
