<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ProjectCommandCenter\Services;

use Carbon\CarbonImmutable;

final class ProjectAnalyticsBuilder
{
    /**
     * Builds visual datasets only from facts already resolved for the command center.
     * This prevents a chart from silently using a wider project or organisation scope.
     */
    public function fromFacts(
        array $finance,
        array $delivery,
        array $problems,
        string $period,
        ?string $dateFrom,
        ?string $dateTo,
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
            'risk_trend' => $this->riskTrend($problems, $period, $dateFrom, $dateTo),
            'cost_breakdown' => $canViewFinance
                ? $this->costBreakdown($finance)
                : $this->unavailable($financialReason),
            'work_progress' => $this->workProgress($delivery, $asOf),
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

    private function riskTrend(array $problems, string $period, ?string $dateFrom, ?string $dateTo): array
    {
        $from = $period === 'custom' && $dateFrom !== null ? CarbonImmutable::parse($dateFrom)->startOfDay() : null;
        $to = $period === 'custom' && $dateTo !== null ? CarbonImmutable::parse($dateTo)->endOfDay() : null;
        $byDate = [];

        foreach ($problems['items'] ?? [] as $problem) {
            if (! is_array($problem) || empty($problem['detected_at'])) {
                continue;
            }

            $detectedAt = CarbonImmutable::parse((string) $problem['detected_at']);
            if (($from !== null && $detectedAt->lt($from)) || ($to !== null && $detectedAt->gt($to))) {
                continue;
            }

            $date = $detectedAt->toDateString();
            $byDate[$date] ??= ['critical' => 0, 'risk' => 0, 'attention' => 0];
            $severity = (string) ($problem['severity'] ?? 'attention');
            $byDate[$date][array_key_exists($severity, $byDate[$date]) ? $severity : 'attention']++;
        }

        ksort($byDate);

        return [
            'available' => true,
            'reason_key' => null,
            'labels' => array_keys($byDate),
            'series' => [
                'critical' => array_column($byDate, 'critical'),
                'risk' => array_column($byDate, 'risk'),
                'attention' => array_column($byDate, 'attention'),
            ],
        ];
    }

    private function costBreakdown(array $finance): array
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
            'labels' => ['actual_cost', 'forecast_remaining_cost'],
            'series' => ['amount' => [$actualCost, round(max(0, $forecastCost - $actualCost), 2)]],
        ];
    }

    private function workProgress(array $delivery, CarbonImmutable $asOf): array
    {
        if (($delivery['available'] ?? false) !== true) {
            return $this->unavailable((string) ($delivery['reason_key'] ?? 'project_command_center.delivery.schedule_unavailable'));
        }

        $progress = $this->number($delivery['progress_percent'] ?? null);
        if ($progress === null) {
            return [
                'available' => true,
                'reason_key' => null,
                'labels' => [],
                'series' => ['actual' => []],
            ];
        }

        return [
            'available' => true,
            'reason_key' => null,
            'labels' => [$asOf->toDateString()],
            'series' => ['actual' => [$progress]],
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
