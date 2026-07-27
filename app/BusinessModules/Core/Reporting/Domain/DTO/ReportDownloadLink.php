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
        if (filter_var($url, FILTER_VALIDATE_URL) === false || !str_starts_with(strtolower($url), 'https://') || trim($versionId) === '' || $expiresAt <= $issuedAt || ($expiresAt->getTimestamp() - $issuedAt->getTimestamp()) > 300) {
            throw new InvalidArgumentException('report_download_link_invalid');
        }
    }
}
