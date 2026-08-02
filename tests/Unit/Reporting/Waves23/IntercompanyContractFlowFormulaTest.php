<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Core\MultiOrganization\Reporting\DTO\IntercompanyFlowMetricRow;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\IntercompanyContractFlowFormula;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IntercompanyContractFlowFormulaTest extends TestCase
{
    #[Test]
    public function flow_buckets_reconcile_and_linked_spread_is_not_margin(): void
    {
        $result = (new IntercompanyContractFlowFormula)->aggregate([
            IntercompanyFlowMetricRow::internal(7_000, 'RUB', 450),
            IntercompanyFlowMetricRow::external(2_000, 'RUB'),
            IntercompanyFlowMetricRow::unclassified(1_000, 'RUB'),
        ]);

        self::assertSame(10_000, $result->totalMinor);
        self::assertSame('0.70000000', $result->internalShare);
        self::assertSame(450, $result->linkedSpreadMinor);
        self::assertArrayNotHasKey('margin_minor', $result->toArray());
    }

    #[Test]
    public function zero_total_has_null_shares_and_currencies_are_separate(): void
    {
        $formula = new IntercompanyContractFlowFormula;

        $zero = $formula->aggregate([IntercompanyFlowMetricRow::internal(0, 'RUB')]);
        self::assertNull($zero->internalShare);

        $byCurrency = $formula->totals([
            IntercompanyFlowMetricRow::internal(100, 'RUB'),
            IntercompanyFlowMetricRow::external(200, 'USD'),
        ]);
        self::assertSame(100, $byCurrency['RUB']->totalMinor);
        self::assertSame(200, $byCurrency['USD']->totalMinor);
    }
}
