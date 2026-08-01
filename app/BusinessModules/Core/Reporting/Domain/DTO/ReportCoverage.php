<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use InvalidArgumentException;

final readonly class ReportCoverage
{
    public function __construct(
        public string $numerator,
        public string $denominator,
        public ?string $ratio,
    ) {
        if (!self::isDecimal($numerator) || !self::isDecimal($denominator) || ($ratio !== null && !self::isDecimal($ratio))) {
            throw new InvalidArgumentException('report_coverage_invalid');
        }

        if ((self::isZero($denominator) && $ratio !== null) || (!self::isZero($denominator) && $ratio === null)) {
            throw new InvalidArgumentException('report_coverage_invalid');
        }
    }

    private static function isDecimal(string $value): bool
    {
        return preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/', $value) === 1;
    }

    private static function isZero(string $value): bool
    {
        return preg_match('/^0(?:\.0+)?$/', $value) === 1;
    }
}
