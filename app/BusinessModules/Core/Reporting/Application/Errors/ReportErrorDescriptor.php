<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Errors;

use InvalidArgumentException;

final readonly class ReportErrorDescriptor
{
    public function __construct(
        public ReportErrorCode $code,
        public int $httpStatus,
        public bool $retryable,
        public string $translationKey,
    ) {
        if ($httpStatus < 400 || $httpStatus > 599) {
            throw new InvalidArgumentException('report_error_http_status_invalid');
        }

        if (!str_starts_with($translationKey, 'reports.errors.')) {
            throw new InvalidArgumentException('report_error_translation_key_invalid');
        }
    }
}
