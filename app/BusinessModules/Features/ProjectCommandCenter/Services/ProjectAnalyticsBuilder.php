<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ProjectCommandCenter\Services;

use Carbon\CarbonImmutable;

final class ProjectAnalyticsBuilder
{
    /**
     * Builds only datasets supported by the resolved command-center facts.
     * Historical risk and schedule snapshots are intentionally unavailable until their sources exist.
     */
    public function fromFacts(
        array $finance,
        CarbonImmutable $asOf,
    ): array {
        $financialReason = (string) ($finance['reason_key'] ?? 'project_command_center.finance.access_restricted');
        $canViewFinance = ($finance['available'] ?? false) === true;

        return [
            'plan_vs_fact' => $canViewFinance
                ? $this->planVsFact($finance, $asOf)
                : $this->unavailable($financialReason),
            'cash_flow' => $canViewFinance
                ? $this->cashFlow($finance)
                : $this->unavailable($financialReason),
            'risk_trend' => $this->unavailable('project_command_center.analytics.risk_trend_history_unavailable'),
            'cost_outlook' => $canViewFinance
                ? $this->costOutlook($finance)
                : $this->unavailable($financialReason),
            'work_progress' => $this->unavailable('project_command_center.analytics.work_progress_history_unavailable'),
        ];
    }

    private function planVsFact(array $finance, CarbonImmutable $asOf): array
    {
        $evm = $finance['evm'] ?? [];
        if (($evm['available'] ?? false) !== true) {
            return $this->unavailable((string) ($evm['reason_key'] ?? 'project_command_center.finance.actual_cost_unavailable'));
        }

        $plan = $this->number($evm['plan_total_cost'] ?? null);
        $fact = $this->number($evm['actual_cost'] ?? null);
        $forecast = $this->number($evm['forecast_total_cost'] ?? null);
        if ($plan === null || $fact === null || $forecast === null) {
            return $this->unavailable('project_command_center.finance.actual_cost_unavailable');
        }

        return [
            'available' => true,
            'reason_key' => null,
            'labels' => [$asOf->toDateString()],
            'series' => [
                'plan' => [$plan],
                'fact' => [$fact],
                'forecast' => [$forecast],
            ],
        ];
    }

    private function cashFlow(array $finance): array
    {
        $cashFlow = $finance['cash_flow'] ?? [];
        if (($cashFlow['available'] ?? false) !== true) {
            return $this->unavailable((string) ($cashFlow['reason_key'] ?? 'project_command_center.finance.payment_schedule_unavailable'));
        }

        $labels = [];
        $incoming = [];
        $outgoing = [];
        $net = [];

        foreach ($cashFlow['projections'] ?? [] as $projection) {
            if (! is_array($projection) || ! isset($projection['days'])) {
                continue;
            }

            $labels[] = (string) $projection['days'];
            $incoming[] = $this->number($projection['incoming'] ?? null);
            $outgoing[] = $this->number($projection['outgoing'] ?? null);
            $net[] = $this->number($projection['net'] ?? null);
        }

        return [
            'available' => true,
            'reason_key' => null,
            'labels' => $labels,
            'series' => compact('incoming', 'outgoing', 'net'),
        ];
    }

    private function costOutlook(array $finance): array
    {
        $evm = $finance['evm'] ?? [];
        $actualCost = $this->number($evm['actual_cost'] ?? null);
        $forecastCost = $this->number($evm['forecast_total_cost'] ?? null);
        if (($evm['available'] ?? false) !== true || $actualCost === null || $forecastCost === null) {
            return $this->unavailable((string) ($evm['reason_key'] ?? 'project_command_center.finance.actual_cost_unavailable'));
        }

        return [
            'available' => true,
            'reason_key' => null,
            'title_key' => 'project_command_center.analytics.cost_outlook',
            'labels' => ['actual_cost', 'forecast_remaining_cost'],
            'series' => ['amount' => [$actualCost, round(max(0, $forecastCost - $actualCost), 2)]],
        ];
    }

    private function unavailable(string $reasonKey): array
    {
        return ['available' => false, 'reason_key' => $reasonKey];
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? round((float) $value, 2) : null;
    }
}
