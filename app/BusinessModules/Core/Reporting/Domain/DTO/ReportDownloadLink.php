<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ReportDownloadLink
{
    public function __construct(
        public string $url,
        public string $versionId,
        public DateTimeImmutable $issuedAt,
        public DateTimeImmutable $expiresAt,
    ) {
        $parts = preg_match('/[\x00-\x1F\x7F]/', $url) === 1 ? false : parse_url($url);

        if (!is_array($parts) || filter_var($url, FILTER_VALIDATE_URL) === false || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || !is_string($parts['host'] ?? null) || $parts['host'] === '' || isset($parts['user']) || isset($parts['pass']) || trim($versionId) === '' || $expiresAt <= $issuedAt || $expiresAt > $issuedAt->modify('+300 seconds')) {
            throw new InvalidArgumentException('report_download_link_invalid');
        }
    }
}
