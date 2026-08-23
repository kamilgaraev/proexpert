<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\MachineryOperations\Services;

use DomainException;

final class MachineryShiftInvariant
{
    public const OPEN_STATUSES = ['draft', 'completed'];

    public static function assertStartAllowed(
        string $assetStatus,
        bool $hasOpenShift,
        ?float $meterStart,
        ?float $currentMeter,
    ): void {
        if (! in_array($assetStatus, ['assigned', 'in_operation'], true)) {
            throw new DomainException('shift_asset_not_operational');
        }

        if ($hasOpenShift) {
            throw new DomainException('shift_already_open');
        }

        if ($meterStart !== null && $currentMeter !== null && $meterStart < $currentMeter) {
            throw new DomainException('meter_start_before_current');
        }
    }

    public static function assertFinishAllowed(string $status): void
    {
        if ($status !== 'draft') {
            throw new DomainException('shift_finish_invalid_status');
        }
    }

    public static function assertSubmitAllowed(string $status): void
    {
        if ($status !== 'completed') {
            throw new DomainException('shift_submit_invalid_status');
        }
    }

    public static function actualFromMeters(float $meterStart, float $meterEnd): float
    {
        if ($meterEnd < $meterStart) {
            throw new DomainException('meter_end_before_start');
        }

        return round($meterEnd - $meterStart, 2);
    }

    public static function inspectionBlocksOperation(string $result, array $defects): bool
    {
        if (! in_array($result, ['serviceable', 'restricted', 'unavailable'], true)) {
            throw new DomainException('inspection_result_invalid');
        }

        if ($result === 'unavailable') {
            return true;
        }

        foreach ($defects as $defect) {
            if (($defect['severity'] ?? null) === 'critical') {
                return true;
            }
        }

        return false;
    }
}
