<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\Services\CompletedWork\Reporting\AcceptedProduction\DTO\ProductionAcceptanceFact;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\AcceptedProductionFormula;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AcceptedProductionFormulaTest extends TestCase
{
    public function test_accepted_quantity_uses_approved_rate_and_keeps_variances_separate(): void
    {
        $metric = (new AcceptedProductionFormula)->row(new ProductionAcceptanceFact(
            plannedQuantity: '10.000',
            reportedQuantity: '8.000',
            acceptedQuantityDelta: '6.000',
            unitDimension: 'count',
            unitCode: 'piece',
            conversionVersion: 'units-v1',
            approvedRateMinor: 5_000,
            currency: 'RUB',
            currencySource: 'act-line-v3',
        ));

        self::assertSame('-4.000', $metric->acceptedPlanVariance);
        self::assertSame('2.000', $metric->reportedAcceptedVariance);
        self::assertSame(30_000, $metric->acceptedAmountMinor);
    }

    public function test_reversal_keeps_signed_quantity_and_incompatible_money_identity_is_rejected(): void
    {
        $metric = (new AcceptedProductionFormula)->row(new ProductionAcceptanceFact(
            '10.000',
            '8.000',
            '-6.000',
            'count',
            'piece',
            'units-v1',
            5_000,
            'RUB',
            'act-line-v3',
        ));

        self::assertSame(-30_000, $metric->acceptedAmountMinor);

        $this->expectException(InvalidArgumentException::class);
        new ProductionAcceptanceFact(
            '10.000',
            '8.000',
            '6.000',
            'count',
            'piece',
            'units-v1',
            5_000,
            null,
            null,
        );
    }

    public function test_fractional_minor_amount_uses_half_up_rounding(): void
    {
        $metric = (new AcceptedProductionFormula())->row(new ProductionAcceptanceFact(
            '1.000',
            '1.000',
            '0.001',
            'count',
            'piece',
            'unit_1',
            501,
            'RUB',
            'performance_act_line',
        ));

        self::assertSame(1, $metric->acceptedAmountMinor);
    }

    public function test_four_decimal_source_quantity_is_preserved_without_rounding(): void
    {
        $metric = (new AcceptedProductionFormula())->row(new ProductionAcceptanceFact(
            '2.0000',
            '1.5000',
            '1.2345',
            'volume',
            'm3',
            'unit_4',
            20_000,
            'RUB',
            'performance_act_line',
        ));

        self::assertSame('1.2345', $metric->acceptedQuantity);
        self::assertSame('-0.7655', $metric->acceptedPlanVariance);
        self::assertSame('0.2655', $metric->reportedAcceptedVariance);
        self::assertSame(24_690, $metric->acceptedAmountMinor);
    }

    public function test_amount_math_avoids_intermediate_overflow_at_source_precision_limits(): void
    {
        $metric = (new AcceptedProductionFormula())->row(new ProductionAcceptanceFact(
            '99999999999.9999',
            '99999999999.9999',
            '99999999999.9999',
            'volume',
            'm3',
            'unit_4',
            10_000,
            'RUB',
            'performance_act_line',
        ));

        self::assertSame(999_999_999_999_999, $metric->acceptedAmountMinor);
        self::assertSame('1.00000000', $metric->completionRatio);
    }
}
