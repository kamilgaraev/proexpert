<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Domain\Enums\ReportWarningSeverity;
use InvalidArgumentException;

final readonly class ReportWarning
{
    public function __construct(
        public string $code,
        public ReportWarningSeverity $severity,
        public ?string $metric,
        public int $affectedRowCount,
    ) {
        if (preg_match('/^[A-Z][A-Z0-9_]{0,63}$/', $code) !== 1 || ($metric !== null && !self::isSafeIdentifier($metric)) || $affectedRowCount < 0) {
            throw new InvalidArgumentException('report_warning_invalid');
        }
    }

    private static function isSafeIdentifier(string $value): bool
    {
        return preg_match('/^[a-z][a-z0-9_]{0,63}$/', $value) === 1;
    }
}
