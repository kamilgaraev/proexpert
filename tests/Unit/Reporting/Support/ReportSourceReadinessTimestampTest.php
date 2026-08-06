<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Support;

use App\Support\Reporting\DeterministicReadinessAccumulator;
use App\Support\Reporting\ReportSourceReadinessFactory;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class ReportSourceReadinessTimestampTest extends TestCase
{
    public function test_accumulator_returns_carbon_verified_timestamp(): void
    {
        $accumulator = new DeterministicReadinessAccumulator;
        $accumulator->eligible(['id' => 1]);
        $accumulator->projected(['id' => 1]);

        $readiness = $accumulator->finish(0, 0, 'accepted-production:v1');

        self::assertInstanceOf(CarbonImmutable::class, $readiness->verifiedAt);
    }

    public function test_factory_returns_carbon_verified_timestamp(): void
    {
        $readiness = (new ReportSourceReadinessFactory)->make(
            [['id' => 1]],
            [['id' => 1]],
            0,
            0,
            'accepted-production:v1',
        );

        self::assertInstanceOf(CarbonImmutable::class, $readiness->verifiedAt);
    }
}
