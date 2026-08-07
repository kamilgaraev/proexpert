<?php

declare(strict_types=1);

namespace Tests\Unit\Budgeting\Reporting\Portfolio;

use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\ProjectPortfolioHealthSourceComponent;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\ProjectPortfolioHealthSourceTupleAssembler;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProjectPortfolioHealthSourceTupleAssemblerTest extends TestCase
{
    #[Test]
    public function it_builds_an_order_stable_watermark_for_one_exact_four_source_tuple(): void
    {
        $assembler = new ProjectPortfolioHealthSourceTupleAssembler;
        $components = [
            new ProjectPortfolioHealthSourceComponent('project_margin', 'margin-1', str_repeat('a', 64), 'margin.v1', '2026-08-04T00:00:00+00:00'),
            new ProjectPortfolioHealthSourceComponent('budget_plan_fact', 'plan-1', str_repeat('b', 64), 'plan.v1', '2026-08-04T00:00:00+00:00'),
            new ProjectPortfolioHealthSourceComponent('wip_completion_forecast', 'wip-1', str_repeat('c', 64), 'wip.v1', '2026-08-04T00:00:00+00:00'),
            new ProjectPortfolioHealthSourceComponent('portfolio_liquidity', 'liquidity-1', str_repeat('d', 64), 'liquidity.v1', '2026-08-04T00:00:00+00:00'),
        ];

        $first = $assembler->assemble($components);
        $second = $assembler->assemble(array_reverse($components));

        self::assertTrue($first->isReady());
        self::assertSame($first->watermark, $second->watermark);
        self::assertSame(hash('sha256', CanonicalJson::encode($first->canonicalIdentity())), $first->watermark);
    }

    #[Test]
    public function it_fails_closed_for_missing_duplicate_or_invalid_owner_components(): void
    {
        $assembler = new ProjectPortfolioHealthSourceTupleAssembler;
        $margin = new ProjectPortfolioHealthSourceComponent('project_margin', 'margin-1', str_repeat('a', 64), 'margin.v1', '2026-08-04T00:00:00+00:00');
        $plan = new ProjectPortfolioHealthSourceComponent('budget_plan_fact', 'plan-1', str_repeat('b', 64), 'plan.v1', '2026-08-04T00:00:00+00:00');
        $wip = new ProjectPortfolioHealthSourceComponent('wip_completion_forecast', 'wip-1', str_repeat('c', 64), 'wip.v1', '2026-08-04T00:00:00+00:00');
        $liquidity = new ProjectPortfolioHealthSourceComponent('portfolio_liquidity', 'liquidity-1', str_repeat('d', 64), 'liquidity.v1', '2026-08-04T00:00:00+00:00');

        self::assertFalse($assembler->assemble([$margin, $plan, $wip])->isReady());
        $duplicate = new ProjectPortfolioHealthSourceComponent('project_margin', 'margin-2', str_repeat('e', 64), 'margin.v1', '2026-08-04T00:00:00+00:00');
        self::assertFalse($assembler->assemble([$margin, $duplicate, $plan, $wip, $liquidity])->isReady());
        self::assertSame(
            $assembler->assemble([$margin, $duplicate, $plan, $wip, $liquidity])->watermark,
            $assembler->assemble([$duplicate, $margin, $plan, $wip, $liquidity])->watermark,
        );
        self::assertFalse($assembler->assemble([$margin, $plan, $wip, $liquidity], [['code' => 'source_projection_gap']])->isReady());
    }
}
