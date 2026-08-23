<?php

declare(strict_types=1);

namespace Tests\Unit\MachineryOperations;

use App\BusinessModules\Features\MachineryOperations\Services\MachineryShiftInvariant;
use DomainException;
use PHPUnit\Framework\TestCase;

final class MachineryShiftInvariantTest extends TestCase
{
    public function test_start_rejects_an_existing_open_shift(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('shift_already_open');

        MachineryShiftInvariant::assertStartAllowed('assigned', true, 100.0, 100.0);
    }

    public function test_start_rejects_a_counter_below_the_current_asset_value(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('meter_start_before_current');

        MachineryShiftInvariant::assertStartAllowed('assigned', false, 99.99, 100.0);
    }

    public function test_finish_is_allowed_only_once(): void
    {
        MachineryShiftInvariant::assertFinishAllowed('draft');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('shift_finish_invalid_status');

        MachineryShiftInvariant::assertFinishAllowed('completed');
    }

    public function test_actual_value_is_derived_from_counter_delta_with_schema_precision(): void
    {
        self::assertSame(8.25, MachineryShiftInvariant::actualFromMeters(200.0, 208.25));
    }

    public function test_end_counter_cannot_be_lower_than_start(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('meter_end_before_start');

        MachineryShiftInvariant::actualFromMeters(200.0, 199.99);
    }

    public function test_critical_inspection_blocks_operation(): void
    {
        self::assertTrue(MachineryShiftInvariant::inspectionBlocksOperation('serviceable', [
            ['severity' => 'critical'],
        ]));
        self::assertTrue(MachineryShiftInvariant::inspectionBlocksOperation('unavailable', []));
        self::assertFalse(MachineryShiftInvariant::inspectionBlocksOperation('restricted', []));
    }

    public function test_unknown_inspection_result_is_rejected(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('inspection_result_invalid');

        MachineryShiftInvariant::inspectionBlocksOperation('unknown', []);
    }
}
