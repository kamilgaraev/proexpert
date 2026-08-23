<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\MachineryOperations\Services;

use DomainException;

final class MachineryOperationInvariant
{
    public static function assertFuelAllowed(
        string $assetStatus,
        string $shiftStatus,
        float $quantity,
        ?float $capacity,
    ): void {
        if (! in_array($assetStatus, ['assigned', 'in_operation'], true) || $shiftStatus !== 'draft') {
            throw new DomainException('fuel_asset_blocked');
        }

        if ($capacity !== null && $quantity > $capacity) {
            throw new DomainException('fuel_capacity_exceeded');
        }
    }

    public static function assertMaintenanceAllowed(
        string $assetStatus,
        bool $hasOpenShift,
        bool $hasOpenMaintenance,
    ): void {
        if ($hasOpenShift || $assetStatus === 'in_operation') {
            throw new DomainException('maintenance_shift_open');
        }

        if ($hasOpenMaintenance || $assetStatus === 'maintenance') {
            throw new DomainException('maintenance_already_open');
        }

        if ($assetStatus === 'archived') {
            throw new DomainException('asset_maintenance_invalid_status');
        }
    }

    public static function assertTransitionAllowed(
        string $transition,
        bool $hasOpenShift,
        bool $hasOpenMaintenance,
        bool $hasActiveAssignment,
    ): void {
        if ($transition === 'archive' && ($hasOpenShift || $hasOpenMaintenance || $hasActiveAssignment)) {
            throw new DomainException('asset_has_active_obligations');
        }

        if ($hasOpenShift) {
            throw new DomainException('asset_has_open_shift');
        }

        if ($hasOpenMaintenance) {
            throw new DomainException('asset_has_open_maintenance');
        }
    }
}
