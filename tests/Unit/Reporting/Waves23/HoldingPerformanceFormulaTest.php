<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Core\MultiOrganization\Reporting\DTO\HoldingAllocationFact;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingPerformanceFormula;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HoldingPerformanceFormulaTest extends TestCase
{
    #[Test]
    public function holding_total_equals_member_rows_per_currency_and_basis(): void
    {
        $formula = new HoldingPerformanceFormula();
        $rows = [
            HoldingAllocationFact::contracted(1, 10, 100, 'RUB', 12_000),
            HoldingAllocationFact::contracted(1, 11, 100, 'RUB', 8_000),
            HoldingAllocationFact::contracted(1, 12, 100, null, 5_000),
            HoldingAllocationFact::cash(1, 10, 100, 'RUB', 3_000),
        ];

        $totals = $formula->totals(array_map($formula->row(...), $rows));

        self::assertSame(20_000, $totals['currencies']['RUB']['contracted_minor']);
        self::assertSame(3_000, $totals['currencies']['RUB']['cash_minor']);
        self::assertSame(1, $totals['quality']['unknown_currency_count']);
        self::assertSame(5_000, $totals['quality']['excluded_amount_minor']);
    }

    #[Test]
    public function basis_values_never_leak_into_another_basis(): void
    {
        $formula = new HoldingPerformanceFormula();
        $totals = $formula->totals([
            $formula->row(HoldingAllocationFact::contracted(1, 10, 100, 'USD', 500)),
            $formula->row(HoldingAllocationFact::acceptedAccrual(1, 10, 100, 'USD', 300)),
        ]);

        self::assertSame(500, $totals['currencies']['USD']['contracted_minor']);
        self::assertSame(300, $totals['currencies']['USD']['accepted_accrual_minor']);
        self::assertSame(0, $totals['currencies']['USD']['cash_minor']);
    }
}
