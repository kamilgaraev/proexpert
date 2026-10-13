<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Credits\LegacyEstimatorSalePriceSnapshot;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

final class LegacyEstimatorSalePriceSnapshotTest extends TestCase
{
    private Container $previous;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previous = Container::getInstance();
        $container = new Container;
        $container->instance('config', new Repository(['ai-assistant-credits' => [
            'price_version' => 2, 'unit_minor' => 100, 'rub_per_unit' => 0.18,
            'packs' => ['ai-credits-1000' => ['units_minor' => 100000, 'amount_minor' => 100000],
                'ai-credits-5000' => ['units_minor' => 500000, 'amount_minor' => 450000],
                'ai-credits-10000' => ['units_minor' => 1000000, 'amount_minor' => 800000]]]]));
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previous);
        parent::tearDown();
    }

    public function test_sale_packs_use_their_commercial_price_and_preserve_fractional_cash_remainder(): void
    {
        $base = LegacyEstimatorSalePriceSnapshot::capture('ai-credits-1000');
        self::assertSame(100, $base->salePriceMinorPerUnit);
        self::assertSame(['units_minor' => 50000, 'rounding_remainder_micro_rub' => 0], $base->convert(50000));
        $medium = LegacyEstimatorSalePriceSnapshot::capture('ai-credits-5000');
        self::assertSame(90, $medium->salePriceMinorPerUnit);
        self::assertSame(['units_minor' => 55555, 'rounding_remainder_micro_rub' => 5000], $medium->convert(50000));
        $large = LegacyEstimatorSalePriceSnapshot::capture('ai-credits-10000');
        self::assertSame(80, $large->salePriceMinorPerUnit);
        self::assertSame(['units_minor' => 62500, 'rounding_remainder_micro_rub' => 0], $large->convert(50000));
    }

    public function test_internal_cost_coefficient_never_becomes_a_sale_price_and_the_snapshot_stays_frozen(): void
    {
        $snapshot = LegacyEstimatorSalePriceSnapshot::capture('ai-credits-1000');
        $hash = $snapshot->fingerprint();
        config(['ai-assistant-credits.rub_per_unit' => 0.99]);
        self::assertSame($hash, LegacyEstimatorSalePriceSnapshot::capture('ai-credits-1000')->fingerprint());
        config(['ai-assistant-credits.packs.ai-credits-1000.amount_minor' => 120000, 'ai-assistant-credits.price_version' => 3]);
        self::assertSame(100, $snapshot->salePriceMinorPerUnit);
        self::assertSame($hash, $snapshot->fingerprint());
        self::assertNotSame($hash, LegacyEstimatorSalePriceSnapshot::capture('ai-credits-1000')->fingerprint());
    }

    public function test_zero_is_preserved_and_a_negative_remaining_value_is_rejected(): void
    {
        $snapshot = LegacyEstimatorSalePriceSnapshot::capture('ai-credits-1000');
        self::assertSame(['units_minor' => 0, 'rounding_remainder_micro_rub' => 0], $snapshot->convert(0));
        $this->expectException(\DomainException::class);
        $snapshot->convert(-1);
    }

    public function test_an_unknown_pack_cannot_create_an_invented_sale_rate(): void
    {
        $this->expectException(\DomainException::class);
        LegacyEstimatorSalePriceSnapshot::capture('internal-cost');
    }
}
