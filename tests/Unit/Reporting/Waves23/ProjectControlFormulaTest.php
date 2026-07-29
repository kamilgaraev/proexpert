<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\DTO\ProjectControlAmounts;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Services\ProjectControlFormula;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ProjectControlFormulaTest extends TestCase
{
    public function test_evm_derives_variances_indices_and_approved_eac(): void
    {
        $row = (new ProjectControlFormula)->calculate(new ProjectControlAmounts(
            bacMinor: 10_000,
            pvMinor: 4_000,
            evMinor: 3_500,
            acMinor: 3_000,
            approvedEtcMinor: 4_500,
            currency: 'RUB',
        ));

        self::assertSame(-500, $row->svMinor);
        self::assertSame(500, $row->cvMinor);
        self::assertSame('0.87500000', $row->spi);
        self::assertSame('1.16666667', $row->cpi);
        self::assertSame(7_500, $row->eacMinor);
    }

    public function test_zero_denominators_are_unknown(): void
    {
        $row = (new ProjectControlFormula)->calculate(
            new ProjectControlAmounts(0, 0, 0, 0, null, 'RUB'),
        );

        self::assertNull($row->spi);
        self::assertNull($row->cpi);
        self::assertNull($row->eacMinor);
    }

    public function test_totals_derive_indices_after_aggregation_and_reject_mixed_currency(): void
    {
        $formula = new ProjectControlFormula;
        $total = $formula->total([
            $formula->calculate(new ProjectControlAmounts(100, 40, 20, 10, 30, 'RUB')),
            $formula->calculate(new ProjectControlAmounts(200, 60, 50, 40, 60, 'RUB')),
        ]);

        self::assertSame(300, $total->bacMinor);
        self::assertSame(100, $total->pvMinor);
        self::assertSame(70, $total->evMinor);
        self::assertSame('0.70000000', $total->spi);
        self::assertSame('1.40000000', $total->cpi);

        $this->expectException(InvalidArgumentException::class);
        $formula->total([
            $formula->calculate(new ProjectControlAmounts(100, 40, 20, 10, 30, 'RUB')),
            $formula->calculate(new ProjectControlAmounts(100, 40, 20, 10, 30, 'USD')),
        ]);
    }
}
