<?php

declare(strict_types=1);

namespace Tests\Unit\MachineryOperations;

use App\BusinessModules\Features\MachineryOperations\Services\MachineryOperationInvariant;
use DomainException;
use PHPUnit\Framework\TestCase;

final class MachineryOperationInvariantTest extends TestCase
{
    public function test_fuel_requires_an_open_shift_and_operational_asset(): void
    {
        MachineryOperationInvariant::assertFuelAllowed('in_operation', 'draft', 50.0, 200.0);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('fuel_asset_blocked');

        MachineryOperationInvariant::assertFuelAllowed('maintenance', 'draft', 50.0, 200.0);
    }

    public function test_fuel_cannot_exceed_configured_capacity(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('fuel_capacity_exceeded');

        MachineryOperationInvariant::assertFuelAllowed('in_operation', 'draft', 201.0, 200.0);
    }

    public function test_maintenance_cannot_start_during_an_open_shift(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('maintenance_shift_open');

        MachineryOperationInvariant::assertMaintenanceAllowed('in_operation', true, false);
    }

    public function test_second_open_maintenance_order_is_rejected(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('maintenance_already_open');

        MachineryOperationInvariant::assertMaintenanceAllowed('available', false, true);
    }

    public function test_project_transfer_and_return_are_blocked_by_open_shift(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('asset_has_open_shift');

        MachineryOperationInvariant::assertTransitionAllowed('reassign', true, false, true);
    }

    public function test_archive_is_blocked_by_any_active_obligation(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('asset_has_active_obligations');

        MachineryOperationInvariant::assertTransitionAllowed('archive', false, true, false);
    }
}
