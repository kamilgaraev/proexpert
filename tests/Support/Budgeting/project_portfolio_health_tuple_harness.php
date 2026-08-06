<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/app/BusinessModules/Features/Budgeting/Reporting/Portfolio/ProjectPortfolioHealthSourceComponent.php';
require dirname(__DIR__, 3).'/app/BusinessModules/Core/Reporting/Support/CanonicalJson.php';
require dirname(__DIR__, 3).'/app/BusinessModules/Features/Budgeting/Reporting/Portfolio/ProjectPortfolioHealthSourceTuple.php';
require dirname(__DIR__, 3).'/app/BusinessModules/Features/Budgeting/Reporting/Portfolio/ProjectPortfolioHealthSourceTupleAssembler.php';

use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\ProjectPortfolioHealthSourceComponent;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\ProjectPortfolioHealthSourceTupleAssembler;

$tuple = (new ProjectPortfolioHealthSourceTupleAssembler)->assemble([
    new ProjectPortfolioHealthSourceComponent('project_margin', 'margin-1', str_repeat('a', 64), 'margin.v1', '2026-08-04T00:00:00+00:00'),
    new ProjectPortfolioHealthSourceComponent('budget_plan_fact', 'plan-1', str_repeat('b', 64), 'plan.v1', '2026-08-04T00:00:00+00:00'),
    new ProjectPortfolioHealthSourceComponent('wip_completion_forecast', 'wip-1', str_repeat('c', 64), 'wip.v1', '2026-08-04T00:00:00+00:00'),
    new ProjectPortfolioHealthSourceComponent('portfolio_liquidity', 'liquidity-1', str_repeat('d', 64), 'liquidity.v1', '2026-08-04T00:00:00+00:00'),
]);

if (! $tuple->isReady() || strlen($tuple->watermark) !== 64) {
    throw new RuntimeException('tuple_harness_failed');
}
