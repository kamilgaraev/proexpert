<?php

declare(strict_types=1);

namespace App\Services\Storage\DTO;

use InvalidArgumentException;

final readonly class MultipartUpload
{
    public const MIN_PART_SIZE_BYTES = 5 * 1024 * 1024;

    public const MAX_PART_SIZE_BYTES = 64 * 1024 * 1024;

    public array $metadata;

    public function __construct(
        public string $organizationPath,
        public string $uploadId,
        public string $mime,
        public int $partSizeBytes,
        array $metadata,
    ) {
        if (
            preg_match('#^org-[1-9][0-9]*/[^\\\\\x00-\x1F\x7F]+$#D', $organizationPath) !== 1
            || str_contains($organizationPath, '://')
            || preg_match('#(?:^|/)\.\.(?:/|$)#', $organizationPath) === 1
            || ! self::isSafeString($uploadId, 1024)
            || ! self::isSafeString($mime, 255)
            || $partSizeBytes < self::MIN_PART_SIZE_BYTES
            || $partSizeBytes > self::MAX_PART_SIZE_BYTES
            || ! self::isMetadata($metadata)
        ) {
            throw new InvalidArgumentException('multipart_upload_invalid');
        }

        $this->metadata = $metadata;
    }

    private static function isMetadata(array $metadata): bool
    {
        foreach ($metadata as $key => $value) {
            if (
                ! is_string($key)
                || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $key) !== 1
                || ! is_string($value)
                || ! self::isSafeString($value, 2048)
            ) {
                return false;
            }
        }

        return true;
    }

    private static function isSafeString(string $value, int $maxLength): bool
    {
        return $value !== ''
            && strlen($value) <= $maxLength
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }
}
