<?php

declare(strict_types=1);

namespace App\Services\Storage\DTO;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class TemporaryFileLink
{
    public function __construct(
        public string $url,
        public string $versionId,
        public DateTimeImmutable $expiresAt,
    ) {
        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (
            filter_var($url, FILTER_VALIDATE_URL) === false
            || ! in_array($scheme, ['http', 'https'], true)
            || ! self::isSafeString($versionId)
            || $expiresAt <= new DateTimeImmutable()
        ) {
            throw new InvalidArgumentException('temporary_file_link_invalid');
        }
    }

    private static function isSafeString(string $value): bool
    {
        return $value !== ''
            && strlen($value) <= 255
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }
}
