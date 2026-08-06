<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ReportDownloadLink
{
    public function __construct(
        public string $url,
        public string $storageKey,
        public DateTimeImmutable $issuedAt,
        public DateTimeImmutable $expiresAt,
    ) {
        $parts = preg_match('/[\x00-\x1F\x7F]/', $url) === 1 ? false : parse_url($url);

        if (! is_array($parts) || filter_var($url, FILTER_VALIDATE_URL) === false || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || ! is_string($parts['host'] ?? null) || $parts['host'] === '' || isset($parts['user']) || isset($parts['pass']) || ! self::isPrivateRelativePath($storageKey) || $expiresAt <= $issuedAt || $expiresAt > $issuedAt->modify('+300 seconds')) {
            throw new InvalidArgumentException('report_download_link_invalid');
        }
    }

    private static function isPrivateRelativePath(string $path): bool
    {
        return $path !== '' && strlen($path) <= 1024 && ! str_starts_with($path, '/')
            && ! str_contains($path, '://') && preg_match('#(?:^|/)\.\.(?:/|$)#', $path) !== 1
            && ! str_contains($path, '\\') && preg_match('/[\x00-\x1F\x7F]/', $path) !== 1;
    }
}
