<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Quality;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class EstimateBudgetScope
{
    /** @return array<string, mixed> */
    public function project(array $draft, string|int|float $directCosts): array
    {
        if (($draft['generation_contract'] ?? null) !== 'most_ordinary_estimate:v1') {
            return $this->legacyProject($draft, (float) $directCosts);
        }

        $direct = BigDecimal::of((string) $directCosts)->toScale(2, RoundingMode::HalfUp);
        $overhead = $this->component($draft, 'overhead');
        $profit = $this->component($draft, 'profit');
        $commercialBudget = $overhead['status'] === 'calculated' && $profit['status'] === 'calculated'
            ? [
                'status' => 'calculated',
                'amount' => (string) $direct->plus($overhead['amount'])->plus($profit['amount'])->toScale(2, RoundingMode::HalfUp),
            ]
            : ['status' => 'not_calculated', 'amount' => null];
        $completenessStatus = (string) ($draft['completeness']['status'] ?? 'review_required');

        return [
            'direct_costs' => (string) $direct,
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

    /** @return array<string, mixed> */
    private function legacyProject(array $draft, float $directCosts): array
    {
        $overhead = $this->legacyComponent($draft, 'overhead');
        $profit = $this->legacyComponent($draft, 'profit');
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

    /** @return array{status: string, amount: ?string} */
    private function component(array $draft, string $key): array
    {
        $component = is_array($draft['budget_calculation'][$key] ?? null)
            ? $draft['budget_calculation'][$key]
            : [];
        $amount = $component['amount'] ?? null;

        if (($component['status'] ?? null) !== 'calculated' || (! is_string($amount) && ! is_int($amount))) {
            return ['status' => 'not_calculated', 'amount' => null];
        }

        try {
            $decimal = BigDecimal::of((string) $amount);
        } catch (\Throwable) {
            return ['status' => 'not_calculated', 'amount' => null];
        }
        if ($decimal->isLessThanOrEqualTo(BigDecimal::zero())) {
            return ['status' => 'not_calculated', 'amount' => null];
        }

        return ['status' => 'calculated', 'amount' => (string) $decimal->toScale(2, RoundingMode::HalfUp)];
    }

    /** @return array{status: string, amount: ?float} */
    private function legacyComponent(array $draft, string $key): array
    {
        $component = is_array($draft['budget_calculation'][$key] ?? null)
            ? $draft['budget_calculation'][$key]
            : [];
        $amount = $component['amount'] ?? null;

        if (($component['status'] ?? null) !== 'calculated' || ! is_numeric($amount) || (float) $amount <= 0) {
            return ['status' => 'not_calculated', 'amount' => null];
        }

        return ['status' => 'calculated', 'amount' => round((float) $amount, 2)];
    }
}
