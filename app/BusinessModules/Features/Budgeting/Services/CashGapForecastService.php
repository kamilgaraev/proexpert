<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Services;

use App\BusinessModules\Features\Budgeting\DTOs\CashGapForecastContext;
use App\BusinessModules\Features\Budgeting\DTOs\CashGapForecastDay;
use App\BusinessModules\Features\Budgeting\DTOs\CashGapForecastItem;
use App\BusinessModules\Features\Budgeting\DTOs\CashGapForecastResult;
use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;

use function trans_message;

final class CashGapForecastService
{
    public const RISK_LOW = 'low';
    public const RISK_MEDIUM = 'medium';
    public const RISK_HIGH = 'high';
    public const RISK_CRITICAL = 'critical';

    private const CALCULATION_VERSION = '2026-06-08';

    /**
     * @param list<CashGapForecastItem> $items
     */
    public function forecast(CashGapForecastContext $context, array $items): CashGapForecastResult
    {
        $this->assertContext($context);

        $daily = $this->emptyDailyBuckets($context);
        $includedItems = 0;

        foreach ($items as $item) {
            if (!$context->resolvedFilters()->matches($item)) {
                continue;
            }

            $scheduledDate = $this->forecastDate($context, $item);
            if ($scheduledDate === null || !array_key_exists($scheduledDate, $daily)) {
                continue;
            }

            $amount = $this->forecastAmount($context, $item);
            if ($amount <= 0.0) {
                continue;
            }

            $includedItems++;
            $this->applyItem($daily[$scheduledDate], $item, $amount, $scheduledDate);
        }

        $days = $this->buildDays($context, $daily);
        $totals = $this->totals($context, $days);
        $cashGap = $this->cashGap($days);
        $drivers = $this->topDrivers($days);

        return new CashGapForecastResult(
            context: $context,
            days: $days,
            totals: $totals,
            cashGap: $cashGap,
            riskLevel: $this->overallRiskLevel($cashGap, $totals),
            explanation: $this->overallExplanation($cashGap, $totals),
            drivers: $drivers,
            meta: [
                'calculation_version' => self::CALCULATION_VERSION,
                'input_items' => count($items),
                'included_items' => $includedItems,
                'excluded_items' => count($items) - $includedItems,
                'source_of_truth' => [
                    'management_budget' => 'prohelper',
                    'accounting' => '1c',
                ],
                'scenario_policy' => $this->scenarioPolicy($context),
            ],
        );
    }

    private function assertContext(CashGapForecastContext $context): void
    {
        if ($context->endDate() < $context->startDate()) {
            throw new InvalidArgumentException(trans_message('budgeting.cash_gap.errors.period_invalid'));
        }

        if (!in_array($context->scenario, [
            CashGapForecastContext::SCENARIO_OPTIMISTIC,
            CashGapForecastContext::SCENARIO_BASE,
            CashGapForecastContext::SCENARIO_STRESS,
        ], true)) {
            throw new InvalidArgumentException(trans_message('budgeting.cash_gap.errors.scenario_invalid'));
        }
    }

    private function emptyDailyBuckets(CashGapForecastContext $context): array
    {
        $daily = [];
        $date = $context->startDate();
        $endDate = $context->endDate();

        while ($date <= $endDate) {
            $daily[$date->format('Y-m-d')] = [
                'inflows' => 0.0,
                'outflows' => 0.0,
                'reserved_outflows' => 0.0,
                'overdue_inflows' => 0.0,
                'overdue_outflows' => 0.0,
                'drivers' => [],
            ];
            $date = $date->add(new DateInterval('P1D'));
        }

        return $daily;
    }

    private function forecastDate(CashGapForecastContext $context, CashGapForecastItem $item): ?string
    {
        $date = $item->isOverdueInflow() || $item->isOverdueOutflow()
            ? $context->startDate()
            : new DateTimeImmutable($item->date);

        if ($item->isInflow() && !$item->isActual() && !$item->isOverdueInflow()) {
            if ($context->scenario === CashGapForecastContext::SCENARIO_STRESS) {
                $date = $date->add(new DateInterval('P' . $context->stressInflowDelayDays . 'D'));
            }

            if (
                $context->scenario === CashGapForecastContext::SCENARIO_OPTIMISTIC
                && $context->optimisticInflowAdvanceDays > 0
            ) {
                $date = $date->sub(new DateInterval('P' . $context->optimisticInflowAdvanceDays . 'D'));
            }
        }

        if ($date < $context->startDate() || $date > $context->endDate()) {
            return null;
        }

        return $date->format('Y-m-d');
    }

    private function forecastAmount(CashGapForecastContext $context, CashGapForecastItem $item): float
    {
        $amount = max(0.0, $item->amount);

        if ($item->isOutflow() || $item->isActual() || $item->isOverdueInflow()) {
            return $this->money($amount);
        }

        $probability = $this->probability($item->probability);

        if ($context->scenario === CashGapForecastContext::SCENARIO_OPTIMISTIC) {
            $probability = min(1.0, $probability + $context->optimisticInflowProbabilityLift);
        }

        if ($context->scenario === CashGapForecastContext::SCENARIO_STRESS) {
            $probability *= max(0.0, $context->stressInflowProbabilityFactor);
        }

        return $this->money($amount * $probability);
    }

    private function applyItem(array &$bucket, CashGapForecastItem $item, float $amount, string $date): void
    {
        if ($item->isOverdueInflow()) {
            $bucket['overdue_inflows'] += $amount;
            $bucket['drivers'][] = $this->driver($item, $amount, 0.0, $date);
            return;
        }

        if ($item->isOverdueOutflow()) {
            $bucket['overdue_outflows'] += $amount;
            $bucket['drivers'][] = $this->driver($item, $amount, -$amount, $date);
            return;
        }

        if ($item->isReservedOutflow()) {
            $bucket['reserved_outflows'] += $amount;
            $bucket['drivers'][] = $this->driver($item, $amount, -$amount, $date);
            return;
        }

        if ($item->isInflow()) {
            $bucket['inflows'] += $amount;
            $bucket['drivers'][] = $this->driver($item, $amount, $amount, $date);
            return;
        }

        $bucket['outflows'] += $amount;
        $bucket['drivers'][] = $this->driver($item, $amount, -$amount, $date);
    }

    private function driver(CashGapForecastItem $item, float $amount, float $balanceImpact, string $date): array
    {
        return [
            'type' => $item->bucket,
            'direction' => $item->direction,
            'date' => $date,
            'original_date' => $item->originalDate,
            'amount' => $this->money($amount),
            'balance_impact' => $this->money($balanceImpact),
            'probability' => $this->probability($item->probability),
            'source' => [
                'type' => $item->sourceType,
                'id' => $item->sourceId,
            ],
            'description' => $item->description,
        ];
    }

    private function buildDays(CashGapForecastContext $context, array $daily): array
    {
        $days = [];
        $openingBalance = $this->money($context->openingBalance);

        foreach ($daily as $date => $bucket) {
            $inflows = $this->money((float) $bucket['inflows']);
            $outflows = $this->money((float) $bucket['outflows']);
            $reservedOutflows = $this->money((float) $bucket['reserved_outflows']);
            $overdueInflows = $this->money((float) $bucket['overdue_inflows']);
            $overdueOutflows = $this->money((float) $bucket['overdue_outflows']);
            $closingBalance = $this->money($openingBalance + $inflows - $outflows - $reservedOutflows - $overdueOutflows);
            $cashGap = $this->money(max(0.0, -$closingBalance));
            $riskLevel = $this->dailyRiskLevel($cashGap, $overdueInflows, $overdueOutflows, $reservedOutflows);

            $days[] = new CashGapForecastDay(
                date: (string) $date,
                openingBalance: $openingBalance,
                inflows: $inflows,
                outflows: $outflows,
                reservedOutflows: $reservedOutflows,
                overdueInflows: $overdueInflows,
                overdueOutflows: $overdueOutflows,
                closingBalance: $closingBalance,
                cashGap: $cashGap,
                riskLevel: $riskLevel,
                explanation: $this->dailyExplanation($cashGap, $overdueInflows, $overdueOutflows),
                drivers: $bucket['drivers'],
            );

            $openingBalance = $closingBalance;
        }

        return $days;
    }

    private function totals(CashGapForecastContext $context, array $days): array
    {
        $lastDay = $days[count($days) - 1] ?? null;

        return [
            'opening_balance' => $this->money($context->openingBalance),
            'inflows' => $this->sumDays($days, 'inflows'),
            'outflows' => $this->sumDays($days, 'outflows'),
            'reserved_outflows' => $this->sumDays($days, 'reservedOutflows'),
            'overdue_inflows' => $this->sumDays($days, 'overdueInflows'),
            'overdue_outflows' => $this->sumDays($days, 'overdueOutflows'),
            'closing_balance' => $lastDay instanceof CashGapForecastDay ? $lastDay->closingBalance : $this->money($context->openingBalance),
        ];
    }

    private function cashGap(array $days): array
    {
        $gapDays = array_values(array_filter(
            $days,
            static fn (CashGapForecastDay $day): bool => $day->cashGap > 0.0
        ));

        $minClosingBalance = $days === []
            ? 0.0
            : min(array_map(static fn (CashGapForecastDay $day): float => $day->closingBalance, $days));

        return [
            'has_gap' => $gapDays !== [],
            'first_gap_date' => $gapDays[0]->date ?? null,
            'negative_days' => count($gapDays),
            'max_gap_amount' => $gapDays === []
                ? 0.0
                : max(array_map(static fn (CashGapForecastDay $day): float => $day->cashGap, $gapDays)),
            'min_closing_balance' => $this->money($minClosingBalance),
        ];
    }

    private function topDrivers(array $days): array
    {
        $drivers = [];

        foreach ($days as $day) {
            foreach ($day->drivers as $driver) {
                if ((float) $driver['balance_impact'] < 0.0 || $day->cashGap > 0.0) {
                    $drivers[] = $driver;
                }
            }
        }

        usort(
            $drivers,
            static fn (array $left, array $right): int => abs((float) $right['balance_impact']) <=> abs((float) $left['balance_impact'])
        );

        return array_slice($drivers, 0, 10);
    }

    private function overallRiskLevel(array $cashGap, array $totals): string
    {
        if ($cashGap['has_gap'] === true) {
            return self::RISK_CRITICAL;
        }

        if ($totals['overdue_outflows'] > 0.0 || $totals['overdue_inflows'] > 0.0) {
            return self::RISK_HIGH;
        }

        if ($totals['reserved_outflows'] > 0.0) {
            return self::RISK_MEDIUM;
        }

        return self::RISK_LOW;
    }

    private function dailyRiskLevel(float $cashGap, float $overdueInflows, float $overdueOutflows, float $reservedOutflows): string
    {
        if ($cashGap > 0.0) {
            return self::RISK_CRITICAL;
        }

        if ($overdueOutflows > 0.0 || $overdueInflows > 0.0) {
            return self::RISK_HIGH;
        }

        if ($reservedOutflows > 0.0) {
            return self::RISK_MEDIUM;
        }

        return self::RISK_LOW;
    }

    private function dailyExplanation(float $cashGap, float $overdueInflows, float $overdueOutflows): array
    {
        return [
            'summary' => $cashGap > 0.0
                ? trans_message('budgeting.cash_gap.explanations.day_gap')
                : trans_message('budgeting.cash_gap.explanations.day_clear'),
            'has_overdue_inflows' => $overdueInflows > 0.0,
            'has_overdue_outflows' => $overdueOutflows > 0.0,
        ];
    }

    private function overallExplanation(array $cashGap, array $totals): array
    {
        return [
            'summary' => $cashGap['has_gap'] === true
                ? trans_message('budgeting.cash_gap.explanations.gap_detected')
                : trans_message('budgeting.cash_gap.explanations.no_gap'),
            'first_gap_date' => $cashGap['first_gap_date'],
            'negative_days' => $cashGap['negative_days'],
            'max_gap_amount' => $cashGap['max_gap_amount'],
            'min_closing_balance' => $cashGap['min_closing_balance'],
            'overdue_inflows' => $totals['overdue_inflows'],
            'overdue_outflows' => $totals['overdue_outflows'],
        ];
    }

    private function scenarioPolicy(CashGapForecastContext $context): array
    {
        return [
            'scenario' => $context->scenario,
            'stress_inflow_delay_days' => $context->stressInflowDelayDays,
            'stress_inflow_probability_factor' => $context->stressInflowProbabilityFactor,
            'optimistic_inflow_probability_lift' => $context->optimisticInflowProbabilityLift,
            'optimistic_inflow_advance_days' => $context->optimisticInflowAdvanceDays,
        ];
    }

    private function sumDays(array $days, string $property): float
    {
        return $this->money(array_sum(array_map(
            static fn (CashGapForecastDay $day): float => $day->{$property},
            $days
        )));
    }

    private function probability(float $probability): float
    {
        return round(min(1.0, max(0.0, $probability)), 6);
    }

    private function money(float $amount): float
    {
        return round($amount, 2);
    }
}
