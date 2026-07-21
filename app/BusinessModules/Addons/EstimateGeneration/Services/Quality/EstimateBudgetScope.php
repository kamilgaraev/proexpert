<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Quality;

final class EstimateBudgetScope
{
    /** @return array<string, mixed> */
    public function project(array $draft, float $directCosts): array
    {
        $overhead = $this->component($draft, 'overhead');
        $profit = $this->component($draft, 'profit');
        $commercialBudget = $overhead['status'] === 'calculated' && $profit['status'] === 'calculated'
            ? [
                'status' => 'calculated',
                'amount' => round($directCosts + $overhead['amount'] + $profit['amount'], 2),
            ]
            : ['status' => 'not_calculated', 'amount' => null];
        $completenessStatus = (string) ($draft['completeness']['status'] ?? 'review_required');

        return [
            'direct_costs' => round($directCosts, 2),
            'overhead' => $overhead,
            'profit' => $profit,
            'commercial_budget' => $commercialBudget,
            'claim' => match ($completenessStatus) {
                'full_confirmed_scope' => $commercialBudget['status'] === 'calculated'
                    ? 'commercial_budget'
                    : 'confirmed_direct_costs',
                'confirmed_scope_only' => 'confirmed_scope_only',
                default => 'review_required',
            },
        ];
    }

    /** @return array{status: string, amount: ?float} */
    private function component(array $draft, string $key): array
    {
        $component = is_array($draft['budget_calculation'][$key] ?? null)
            ? $draft['budget_calculation'][$key]
            : [];
        $amount = $component['amount'] ?? null;

        if (($component['status'] ?? null) !== 'calculated' || ! is_numeric($amount) || (float) $amount < 0) {
            return ['status' => 'not_calculated', 'amount' => null];
        }

        return ['status' => 'calculated', 'amount' => round((float) $amount, 2)];
    }
}
