<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportReconciliationStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportWarningSeverity;
use InvalidArgumentException;

final readonly class ReportQuality
{
    public function __construct(
        public ReportQualityStatus $status,
        public ?ReportCoverage $coverage,
        public array $warnings,
        public int $unmatchedCount,
        public ReportReconciliationStatus $reconciliation,
        public array $unknownMetrics,
        public array $excludedSources,
    ) {
        if ($unmatchedCount < 0 || !self::isTypedWarnings($warnings) || !self::isIdentifierList($unknownMetrics) || !self::isIdentifierList($excludedSources)) {
            throw new InvalidArgumentException('report_quality_invalid');
        }

        if ($status === ReportQualityStatus::COMPLETE && (count($unknownMetrics) > 0 || self::hasCriticalWarning($warnings))) {
            throw new InvalidArgumentException('report_quality_invalid');
        }
    }

    private static function isTypedWarnings(array $warnings): bool
    {
        if (!array_is_list($warnings)) {
            return false;
        }

        foreach ($warnings as $warning) {
            if (!$warning instanceof ReportWarning) {
                return false;
            }
        }

        return true;
    }

    private static function isIdentifierList(array $values): bool
    {
        if (!array_is_list($values)) {
            return false;
        }

        $unique = [];
        foreach ($values as $value) {
            if (!is_string($value) || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $value) !== 1 || isset($unique[$value])) {
                return false;
            }

            $unique[$value] = true;
        }

        return true;
    }

    private static function hasCriticalWarning(array $warnings): bool
    {
        foreach ($warnings as $warning) {
            if ($warning->severity === ReportWarningSeverity::CRITICAL) {
                return true;
            }
        }

        return false;
    }
}
