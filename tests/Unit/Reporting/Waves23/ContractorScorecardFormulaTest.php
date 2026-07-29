<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\DTO\ContractorComponentSignal;
use App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\Services\ContractorScorecardFormula;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ContractorScorecardFormulaTest extends TestCase
{
    #[Test]
    public function components_keep_source_units_and_never_emit_composite(): void
    {
        $formula = new ContractorScorecardFormula();
        $review = $formula->component('marketplace_review', 'score_0_5', [
            new ContractorComponentSignal('4.0', true),
            new ContractorComponentSignal('5.0', true),
        ]);
        $otif = $formula->component('supply_otif', 'ratio', [
            new ContractorComponentSignal('0.8', true),
            new ContractorComponentSignal('1.0', true),
        ]);

        self::assertSame('4.50000000', $review->mean);
        self::assertSame('0.90000000', $otif->mean);
        self::assertNotSame($review->unitCode, $otif->unitCode);
        self::assertArrayNotHasKey('composite_score', $formula->serialize([$review, $otif]));
    }

    #[Test]
    public function missing_observations_produce_unknown_mean_and_zero_coverage(): void
    {
        $metric = (new ContractorScorecardFormula())->component(
            'quality_defect_rate',
            'ratio',
            [new ContractorComponentSignal(null, true)],
        );

        self::assertNull($metric->mean);
        self::assertSame(0, $metric->sampleSize);
        self::assertSame(1, $metric->eligibleCount);
        self::assertSame('0.00000000', $metric->coverage);
    }

    #[Test]
    public function source_unit_bounds_are_enforced(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('contractor_component_signal_out_of_bounds');

        (new ContractorScorecardFormula())->component(
            'marketplace_review',
            'score_0_5',
            [new ContractorComponentSignal('5.1', true)],
        );
    }
}
