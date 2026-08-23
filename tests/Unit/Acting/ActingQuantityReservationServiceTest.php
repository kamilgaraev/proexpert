<?php

declare(strict_types=1);

namespace Tests\Unit\Acting;

use App\Exceptions\BusinessLogicException;
use App\Services\Acting\ActingQuantityReservationService;
use Tests\TestCase;

final class ActingQuantityReservationServiceTest extends TestCase
{
    public function refreshDatabase(): void
    {
    }

    public function test_scaled_quantity_equal_to_available_quantity_is_accepted(): void
    {
        $service = new ActingQuantityReservationService();

        $service->assertScaledAvailable([10 => 150000], [10 => 150000]);

        self::assertTrue(true);
    }

    public function test_scaled_quantity_above_available_quantity_is_rejected(): void
    {
        $service = new ActingQuantityReservationService();

        $this->expectException(BusinessLogicException::class);
        $service->assertScaledAvailable([10 => 150001], [10 => 150000]);
    }
}
