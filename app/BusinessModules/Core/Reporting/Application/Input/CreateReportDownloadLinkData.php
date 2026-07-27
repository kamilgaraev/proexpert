<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Input;

use InvalidArgumentException;

final readonly class CreateReportDownloadLinkData
{
    public function __construct(
        public string $exportId,
        public int $ttlSeconds,
    ) {
        if (preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/', $exportId) !== 1
            || $ttlSeconds < 1
            || $ttlSeconds > 300) {
            throw new InvalidArgumentException('create_report_download_link_data_invalid');
        }
    }
}
