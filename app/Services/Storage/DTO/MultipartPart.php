<?php

declare(strict_types=1);

namespace App\Services\Storage\DTO;

use InvalidArgumentException;

final readonly class MultipartPart
{
    public function __construct(
        public string $organizationPath,
        public string $uploadId,
        public int $number,
        public string $etag,
        public int $sizeBytes,
        public string $checksumSha256,
    ) {
        if (
            preg_match('#^org-[1-9][0-9]*/[^\\\\\x00-\x1F\x7F]+$#D', $organizationPath) !== 1
            || ! self::isSafeString($uploadId, 1024)
            || strtolower(trim($uploadId)) === 'null'
            || $number < 1
            || $number > 10000
            || ! self::isSafeString($etag, 255)
            || $sizeBytes < 1
            || preg_match('/^[a-f0-9]{64}$/D', $checksumSha256) !== 1
        ) {
            throw new InvalidArgumentException('multipart_part_invalid');
        }
    }

    private static function isSafeString(string $value, int $maxLength): bool
    {
        return $value !== ''
            && strlen($value) <= $maxLength
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }
}
