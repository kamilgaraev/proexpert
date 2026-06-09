<?php

declare(strict_types=1);

namespace Tests\Unit\Budgeting;

use App\BusinessModules\Features\Budgeting\Services\WipForecastPeriodGuard;
use DomainException;
use Tests\TestCase;

final class WipForecastPeriodGuardTest extends TestCase
{
    public function test_closed_period_cannot_be_rewritten(): void
    {
        $this->expectException(DomainException::class);

        (new WipForecastPeriodGuard())->assertWritablePeriod('2026-01', ['2026-01']);
    }

    public function test_open_period_can_be_adjusted(): void
    {
        (new WipForecastPeriodGuard())->assertWritablePeriod('2026-02', ['2026-01']);

        $this->assertTrue(true);
    }
}
