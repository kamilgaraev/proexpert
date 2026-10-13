<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\LegacyRefundResolutionArithmetic;
use PHPUnit\Framework\TestCase;

final class LegacyRefundResolutionArithmeticTest extends TestCase
{
    public function test_frozen_sale_rate_preserves_fractional_money_and_never_grants_extra_units(): void
    {
        self::assertSame(['units_minor' => 6, 'rounding_remainder_micro_rub' => 2000, 'revoked_units_minor' => 4],
            LegacyRefundResolutionArithmetic::resolve(5, 10, 80, 0, 100));
        self::assertSame(['units_minor' => 0, 'rounding_remainder_micro_rub' => 0, 'revoked_units_minor' => 10],
            LegacyRefundResolutionArithmetic::resolve(0, 10, 80, 0, 0));
    }

    public function test_large_values_use_exact_integer_arithmetic(): void
    {
        self::assertSame(1_250_000_000_000, LegacyRefundResolutionArithmetic::resolve(1_000_000_000_000,
            1_250_000_000_000, 80, 0, 1_000_000_000_000)['units_minor']);
    }

    public function test_refund_and_past_consumption_cannot_together_exceed_the_payment(): void
    {
        self::assertSame(200, LegacyRefundResolutionArithmetic::orderCeiling(1000, 500, 300, 100));
        self::assertSame(250, LegacyRefundResolutionArithmetic::orderCeiling(1000, 700, 50, 100));
        self::assertSame(0, LegacyRefundResolutionArithmetic::orderCeiling(1000, 1000, 50, 100));
        self::assertSame(99, LegacyRefundResolutionArithmetic::orderCeiling(1000, 900, 1, 3));
    }

    public function test_the_available_lot_and_the_order_payment_both_limit_money(): void
    {
        foreach ([[601, 600, 100, 0, 1000], [600, 600, 100, 0, 599], [9, 10, 80, 0, 1000]] as $input) {
            try {
                LegacyRefundResolutionArithmetic::resolve(...$input);
                self::fail('Money above the proved ceiling was accepted.');
            } catch (\DomainException $error) {
                self::assertSame('legacy_estimator_refund_resolution_exceeds_ceiling', $error->getMessage());
            }
        }
    }
}
