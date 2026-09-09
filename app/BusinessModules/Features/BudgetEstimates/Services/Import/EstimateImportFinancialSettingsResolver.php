<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import;

class EstimateImportFinancialSettingsResolver
{
    public function resolveImportedTotals(array $footer): ?array
    {
        $total = (float) ($footer['total_estimate_cost'] ?? 0);
        $overhead = (float) ($footer['overhead_cost'] ?? 0);
        $profit = (float) ($footer['profit_cost'] ?? 0);

        if ($total <= 0 || ($overhead <= 0 && $profit <= 0)) {
            return null;
        }

        return [
            'total_amount' => $total,
            'total_overhead_costs' => $overhead,
            'total_estimated_profit' => $profit,
            'total_direct_costs' => round($total - $overhead - $profit, 2),
        ];
    }

    public function resolve(array $settings): array
    {
        $mode = $settings['financial_mode'] ?? 'plain';

        if ($mode === 'organization_defaults' || $mode === 'custom') {
            return [
                'vat_rate' => $this->rate($settings['vat_rate'] ?? 0),
                'overhead_rate' => $this->rate($settings['overhead_rate'] ?? 0),
                'profit_rate' => $this->rate($settings['profit_rate'] ?? 0),
                'preserve_imported_totals' => false,
            ];
        }

        return [
            'vat_rate' => 0.0,
            'overhead_rate' => 0.0,
            'profit_rate' => 0.0,
            'preserve_imported_totals' => true,
        ];
    }

    private function rate(mixed $value): float
    {
        $rate = is_numeric($value) ? (float) $value : 0.0;

        return max(0.0, min(100.0, $rate));
    }
}
