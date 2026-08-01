<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\CiEvidence;

use App\BusinessModules\Features\Budgeting\Contracts\PlanFactSourceSnapshotReport;
use LogicException;

final class BudgetPlanFactCiFixtureSource implements PlanFactSourceSnapshotReport
{
    public function reportForProjectScope(array $input, array $projectIds): array
    {
        if ($projectIds !== [10, 20]) {
            throw new LogicException('budget_plan_fact_ci_fixture_scope_invalid');
        }

        return ['filters' => $input, 'period' => ['from' => '2026-01-01', 'to' => '2026-01-31'], 'sources_coverage' => [], 'rows' => [
            ['actual_amount' => 160.0, 'committed_amount' => 0.0, 'currency' => 'RUB', 'drill_down_key' => 'rub', 'forecast_amount' => 160.0, 'group' => ['article' => 'OPEX'], 'plan_amount' => 100.0, 'risk_level' => 'low', 'variance_amount' => 60.0, 'variance_percent' => 60.0],
            ['actual_amount' => 120.0, 'committed_amount' => 0.0, 'currency' => 'USD', 'drill_down_key' => 'usd', 'forecast_amount' => 120.0, 'group' => ['article' => 'INCOME'], 'plan_amount' => 100.0, 'risk_level' => 'low', 'variance_amount' => 20.0, 'variance_percent' => 20.0],
        ]];
    }

    public function drillDownForProjectScope(array $input, array $projectIds): array
    {
        $key = $input['drill_down_key'] ?? null;
        if (! is_string($key) || ! in_array($key, ['rub', 'usd'], true) || $projectIds !== [10, 20]) {
            throw new LogicException('budget_plan_fact_ci_fixture_drill_invalid');
        }

        return ['items' => [[
            'source_type' => 'payment_transaction', 'source_id' => $key === 'rub' ? 101 : 102,
            'date' => '2026-01-15', 'amount' => $key === 'rub' ? 160.0 : 120.0,
            'currency' => strtoupper($key), 'status' => 'completed', 'variance_contribution' => $key === 'rub' ? 60.0 : 20.0,
        ]], 'meta' => ['total' => 1]];
    }
}
