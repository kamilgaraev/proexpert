<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Services;

use App\BusinessModules\Features\Budgeting\DTOs\CashGapForecastContext;
use App\BusinessModules\Features\Budgeting\DTOs\CashGapForecastDay;
use App\BusinessModules\Features\Budgeting\DTOs\CashGapForecastItem;
use App\BusinessModules\Features\Budgeting\DTOs\CashGapForecastResult;
use App\BusinessModules\Features\Budgeting\DTOs\CashGapScenarioAdjustment;
use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

use function trans_message;

final class CashGapForecastService
{
    public const RISK_LOW = 'low';
    public const RISK_MEDIUM = 'medium';
    public const RISK_HIGH = 'high';
    public const RISK_CRITICAL = 'critical';

    private const CALCULATION_VERSION = '2026-06-08';

    private const BUCKET_DIRECTIONS = [
        CashGapForecastItem::BUCKET_ACTUAL_INFLOW => CashGapForecastItem::DIRECTION_INFLOW,
        CashGapForecastItem::BUCKET_PLANNED_INFLOW => CashGapForecastItem::DIRECTION_INFLOW,
        CashGapForecastItem::BUCKET_OVERDUE_INFLOW => CashGapForecastItem::DIRECTION_INFLOW,
        CashGapForecastItem::BUCKET_ACTUAL_OUTFLOW => CashGapForecastItem::DIRECTION_OUTFLOW,
        CashGapForecastItem::BUCKET_APPROVED_OUTFLOW => CashGapForecastItem::DIRECTION_OUTFLOW,
        CashGapForecastItem::BUCKET_SCHEDULED_OUTFLOW => CashGapForecastItem::DIRECTION_OUTFLOW,
        CashGapForecastItem::BUCKET_RESERVED_OUTFLOW => CashGapForecastItem::DIRECTION_OUTFLOW,
        CashGapForecastItem::BUCKET_OVERDUE_OUTFLOW => CashGapForecastItem::DIRECTION_OUTFLOW,
        CashGapForecastItem::BUCKET_MANUAL_ADJUSTMENT => null,
    ];

    /**
     * @param list<CashGapForecastItem> $items
     */
    public function forecast(CashGapForecastContext $context, array $items): CashGapForecastResult
    {
        $this->assertContext($context);

        $daily = $this->emptyDailyBuckets($context);
        $includedItems = 0;
        $forecastItems = $this->forecastItems($context, $items);

        foreach ($forecastItems as $item) {
            $scheduledDate = $this->forecastDate($context, $item);
            if ($scheduledDate === null || !array_key_exists($scheduledDate, $daily)) {
                continue;
            }

            $amount = $this->forecastAmount($context, $item);
            if ($amount <= 0.0) {
                continue;
            }

            $includedItems++;
            $this->applyItem(
                $daily[$scheduledDate],
                $item,
                $amount,
                $scheduledDate,
                $this->effectiveProbability($context, $item),
                $this->originalDate($item, $scheduledDate),
            );
        }

        $days = $this->buildDays($context, $daily);
        $totals = $this->totals($context, $days);
        $cashGap = $this->cashGap($days);
        $drivers = $this->topDrivers($days);
        $signals = $this->signals($context, $days, $cashGap, $drivers);

        return new CashGapForecastResult(
            context: $context,
            days: $days,
            totals: $totals,
            cashGap: $cashGap,
            riskLevel: $this->overallRiskLevel($cashGap, $totals),
            explanation: $this->overallExplanation($cashGap, $totals),
            drivers: $drivers,
            signals: $signals,
            meta: [
                'calculation_version' => self::CALCULATION_VERSION,
                'input_items' => count($items),
                'included_items' => $includedItems,
                'excluded_items' => max(0, count($items) - $includedItems),
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
            CashGapForecastContext::SCENARIO_PESSIMISTIC,
            CashGapForecastContext::SCENARIO_STRESS,
            CashGapForecastContext::SCENARIO_CUSTOM,
        ], true)) {
            throw new InvalidArgumentException(trans_message('budgeting.cash_gap.errors.scenario_invalid'));
        }

        if ($context->resolvedFilters()->organizationId === null) {
            throw new InvalidArgumentException(trans_message('budgeting.cash_gap.errors.organization_required'));
        }

        if (
            $context->stressInflowDelayDays < 0
            || $context->optimisticInflowAdvanceDays < 0
            || $context->stressInflowProbabilityFactor < 0.0
            || $context->stressInflowProbabilityFactor > 1.0
            || $context->optimisticInflowProbabilityLift < 0.0
            || $context->optimisticInflowProbabilityLift > 1.0
        ) {
            throw new InvalidArgumentException(trans_message('budgeting.cash_gap.errors.scenario_policy_invalid'));
        }
    }

    /**
     * @param list<CashGapForecastItem> $items
     * @return list<CashGapForecastItem>
     */
    private function forecastItems(CashGapForecastContext $context, array $items): array
    {
        $filtered = [];

        foreach ($this->applyScenarioAdjustments($context, $items) as $item) {
            if (!$context->resolvedFilters()->matches($item)) {
                continue;
            }

            $this->assertItem($item);
            $filtered[] = $item;
        }

        return $this->deduplicateItems($filtered);
    }

    /**
     * @param list<CashGapForecastItem> $items
     * @return list<CashGapForecastItem>
     */
    private function applyScenarioAdjustments(CashGapForecastContext $context, array $items): array
    {
        $adjustments = $this->normalizedAdjustments($context);

        if ($adjustments === []) {
            return $items;
        }

        $adjustedItems = [];

        foreach ($items as $item) {
            $this->assertItem($item);
            $adjustedItem = $this->applyAdjustmentsToItem($item, $adjustments);

            if ($adjustedItem instanceof CashGapForecastItem) {
                $adjustedItems[] = $adjustedItem;
            }
        }

        return array_merge($adjustedItems, $this->temporaryScenarioItems($context, $adjustments));
    }

    /**
     * @return list<CashGapScenarioAdjustment>
     */
    private function normalizedAdjustments(CashGapForecastContext $context): array
    {
        $adjustments = [];

        foreach ($context->scenarioAdjustments as $adjustment) {
            if (is_array($adjustment)) {
                $adjustment = CashGapScenarioAdjustment::fromArray($adjustment);
            }

            if (!$adjustment instanceof CashGapScenarioAdjustment) {
                throw new InvalidArgumentException(trans_message('budgeting.cash_gap.errors.scenario_adjustment_invalid'));
            }

            $this->assertAdjustment($adjustment);
            $adjustments[] = $adjustment;
        }

        return $adjustments;
    }

    /**
     * @param list<CashGapScenarioAdjustment> $adjustments
     */
    private function applyAdjustmentsToItem(CashGapForecastItem $item, array $adjustments): ?CashGapForecastItem
    {
        $adjusted = $item;

        foreach ($adjustments as $adjustment) {
            if (!$adjustment->targets($adjusted)) {
                continue;
            }

            if ($adjustment->action === CashGapScenarioAdjustment::ACTION_EXCLUDE_PAYMENT) {
                return null;
            }

            if ($adjustment->action === CashGapScenarioAdjustment::ACTION_RESCHEDULE_PAYMENT) {
                $adjusted = $this->copyItem($adjusted, [
                    'date' => (string) $adjustment->date,
                    'originalDate' => $adjusted->originalDate ?? $adjusted->date,
                    'drillDown' => $this->scenarioDrillDown($adjusted, $adjustment),
                ]);
                continue;
            }

            if (
                $adjustment->action === CashGapScenarioAdjustment::ACTION_CHANGE_INFLOW_PROBABILITY
                && $adjusted->isInflow()
                && !$adjusted->isActual()
            ) {
                $adjusted = $this->copyItem($adjusted, [
                    'probability' => (float) $adjustment->probability,
                    'drillDown' => $this->scenarioDrillDown($adjusted, $adjustment),
                ]);
            }
        }

        return $adjusted;
    }

    /**
     * @param list<CashGapScenarioAdjustment> $adjustments
     * @return list<CashGapForecastItem>
     */
    private function temporaryScenarioItems(CashGapForecastContext $context, array $adjustments): array
    {
        $items = [];
        $filters = $context->resolvedFilters();

        foreach ($adjustments as $index => $adjustment) {
            if (!in_array($adjustment->action, [
                CashGapScenarioAdjustment::ACTION_ADD_TEMPORARY_INFLOW,
                CashGapScenarioAdjustment::ACTION_ADD_TEMPORARY_FINANCING,
            ], true)) {
                continue;
            }

            $description = $adjustment->description
                ?? trans_message('budgeting.cash_gap.scenario.temporary_financing');
            $currency = mb_strtoupper($adjustment->currency ?? $filters->currency ?? 'RUB');

            $items[] = new CashGapForecastItem(
                date: (string) $adjustment->date,
                direction: CashGapForecastItem::DIRECTION_INFLOW,
                bucket: CashGapForecastItem::BUCKET_MANUAL_ADJUSTMENT,
                amount: (float) $adjustment->amount,
                probability: $adjustment->probability ?? 1.0,
                organizationId: $filters->organizationId,
                projectId: $filters->projectId,
                counterpartyId: $filters->counterpartyId,
                budgetArticleId: $filters->budgetArticleId,
                responsibilityCenterId: $filters->responsibilityCenterId,
                currency: $currency,
                sourceType: 'cash_gap_scenario_adjustment',
                sourceId: $index + 1,
                description: $description,
                cashFlowKey: 'cash-gap-scenario:' . sha1(json_encode($adjustment->toArray()) ?: (string) $index),
                drillDown: [
                    'type' => 'cash_gap_scenario_adjustment',
                    'label' => $description,
                    'reason' => $adjustment->reason,
                ],
            );
        }

        return $items;
    }

    private function assertAdjustment(CashGapScenarioAdjustment $adjustment): void
    {
        if (!in_array($adjustment->action, [
            CashGapScenarioAdjustment::ACTION_RESCHEDULE_PAYMENT,
            CashGapScenarioAdjustment::ACTION_CHANGE_INFLOW_PROBABILITY,
            CashGapScenarioAdjustment::ACTION_EXCLUDE_PAYMENT,
            CashGapScenarioAdjustment::ACTION_ADD_TEMPORARY_INFLOW,
            CashGapScenarioAdjustment::ACTION_ADD_TEMPORARY_FINANCING,
        ], true)) {
            throw new InvalidArgumentException(trans_message('budgeting.cash_gap.errors.scenario_adjustment_invalid'));
        }

        $hasTarget = $adjustment->cashFlowKey !== null
            || ($adjustment->sourceType !== null && $adjustment->sourceId !== null);

        if (
            in_array($adjustment->action, [
                CashGapScenarioAdjustment::ACTION_RESCHEDULE_PAYMENT,
                CashGapScenarioAdjustment::ACTION_CHANGE_INFLOW_PROBABILITY,
                CashGapScenarioAdjustment::ACTION_EXCLUDE_PAYMENT,
            ], true)
            && !$hasTarget
        ) {
            throw new InvalidArgumentException(trans_message('budgeting.cash_gap.errors.scenario_adjustment_invalid'));
        }

        if (
            $adjustment->action === CashGapScenarioAdjustment::ACTION_RESCHEDULE_PAYMENT
            && $adjustment->date === null
        ) {
            throw new InvalidArgumentException(trans_message('budgeting.cash_gap.errors.scenario_adjustment_invalid'));
        }

        if (
            $adjustment->action === CashGapScenarioAdjustment::ACTION_CHANGE_INFLOW_PROBABILITY
            && ($adjustment->probability === null || $adjustment->probability < 0.0 || $adjustment->probability > 1.0)
        ) {
            throw new InvalidArgumentException(trans_message('budgeting.cash_gap.errors.scenario_adjustment_invalid'));
        }

        if (in_array($adjustment->action, [
            CashGapScenarioAdjustment::ACTION_ADD_TEMPORARY_INFLOW,
            CashGapScenarioAdjustment::ACTION_ADD_TEMPORARY_FINANCING,
        ], true)) {
            if ($adjustment->date === null || $adjustment->amount === null || $adjustment->amount <= 0.0) {
                throw new InvalidArgumentException(trans_message('budgeting.cash_gap.errors.scenario_adjustment_invalid'));
            }

            if ($adjustment->probability !== null && ($adjustment->probability < 0.0 || $adjustment->probability > 1.0)) {
                throw new InvalidArgumentException(trans_message('budgeting.cash_gap.errors.scenario_adjustment_invalid'));
            }
        }
    }

    private function assertItem(CashGapForecastItem $item): void
    {
        if (
            !in_array($item->direction, [
                CashGapForecastItem::DIRECTION_INFLOW,
                CashGapForecastItem::DIRECTION_OUTFLOW,
            ], true)
            || !array_key_exists($item->bucket, self::BUCKET_DIRECTIONS)
        ) {
            throw new InvalidArgumentException(trans_message('budgeting.cash_gap.errors.item_invalid'));
        }

        $bucketDirection = self::BUCKET_DIRECTIONS[$item->bucket];
        if ($bucketDirection !== null && $bucketDirection !== $item->direction) {
            throw new InvalidArgumentException(trans_message('budgeting.cash_gap.errors.item_invalid'));
        }

        $this->date($item->date);
    }

    /**
     * @param list<CashGapForecastItem> $items
     * @return list<CashGapForecastItem>
     */
    private function deduplicateItems(array $items): array
    {
        $itemsWithoutKey = [];
        $itemsByKey = [];
        $prioritiesByKey = [];

        foreach ($items as $item) {
            $key = $item->normalizedCashFlowKey();
            if ($key === null) {
                $itemsWithoutKey[] = $item;
                continue;
            }

            $priority = $this->deduplicationPriority($item);
            if (!array_key_exists($key, $itemsByKey) || $priority > $prioritiesByKey[$key]) {
                $itemsByKey[$key] = $item;
                $prioritiesByKey[$key] = $priority;
            }
        }

        return array_values(array_merge($itemsWithoutKey, array_values($itemsByKey)));
    }

    private function deduplicationPriority(CashGapForecastItem $item): int
    {
        return match ($item->bucket) {
            CashGapForecastItem::BUCKET_ACTUAL_INFLOW,
            CashGapForecastItem::BUCKET_ACTUAL_OUTFLOW => 100,
            CashGapForecastItem::BUCKET_OVERDUE_OUTFLOW => 90,
            CashGapForecastItem::BUCKET_SCHEDULED_OUTFLOW,
            CashGapForecastItem::BUCKET_APPROVED_OUTFLOW => 80,
            CashGapForecastItem::BUCKET_PLANNED_INFLOW => 70,
            CashGapForecastItem::BUCKET_OVERDUE_INFLOW => 60,
            CashGapForecastItem::BUCKET_MANUAL_ADJUSTMENT => 50,
            CashGapForecastItem::BUCKET_RESERVED_OUTFLOW => 40,
            default => 10,
        };
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
            : $this->date($item->date);

        if ($item->isInflow() && !$item->isActual() && !$item->isOverdueInflow()) {
            if ($this->isPessimisticScenario($context)) {
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

        return $this->money($amount * $this->effectiveProbability($context, $item));
    }

    private function effectiveProbability(CashGapForecastContext $context, CashGapForecastItem $item): float
    {
        if ($item->isOutflow() || $item->isActual()) {
            return 1.0;
        }

        $probability = $this->probability($item->probability);

        if ($item->isOverdueInflow()) {
            return $probability;
        }

        if ($context->scenario === CashGapForecastContext::SCENARIO_OPTIMISTIC) {
            $probability = min(1.0, $probability + $context->optimisticInflowProbabilityLift);
        }

        if ($this->isPessimisticScenario($context)) {
            $probability *= $context->stressInflowProbabilityFactor;
        }

        return $this->probability($probability);
    }

    private function applyItem(
        array &$bucket,
        CashGapForecastItem $item,
        float $amount,
        string $date,
        float $effectiveProbability,
        ?string $originalDate,
    ): void
    {
        if ($item->isOverdueInflow()) {
            $bucket['overdue_inflows'] += $amount;
            $bucket['drivers'][] = $this->driver($item, $amount, 0.0, $date, $effectiveProbability, $originalDate);
            return;
        }

        if ($item->isOverdueOutflow()) {
            $bucket['overdue_outflows'] += $amount;
            $bucket['drivers'][] = $this->driver($item, $amount, -$amount, $date, $effectiveProbability, $originalDate);
            return;
        }

        if ($item->isReservedOutflow()) {
            $bucket['reserved_outflows'] += $amount;
            $bucket['drivers'][] = $this->driver($item, $amount, -$amount, $date, $effectiveProbability, $originalDate);
            return;
        }

        if ($item->isInflow()) {
            $bucket['inflows'] += $amount;
            $bucket['drivers'][] = $this->driver($item, $amount, $amount, $date, $effectiveProbability, $originalDate);
            return;
        }

        $bucket['outflows'] += $amount;
        $bucket['drivers'][] = $this->driver($item, $amount, -$amount, $date, $effectiveProbability, $originalDate);
    }

    private function driver(
        CashGapForecastItem $item,
        float $amount,
        float $balanceImpact,
        string $date,
        float $effectiveProbability,
        ?string $originalDate,
    ): array
    {
        return [
            'type' => $item->bucket,
            'direction' => $item->direction,
            'date' => $date,
            'original_date' => $originalDate,
            'amount' => $this->money($amount),
            'balance_impact' => $this->money($balanceImpact),
            'probability' => $effectiveProbability,
            'original_probability' => $this->probability($item->probability),
            'cash_flow_key' => $item->normalizedCashFlowKey(),
            'source' => [
                'type' => $item->sourceType,
                'id' => $item->sourceId,
            ],
            'description' => $item->description,
            'drill_down' => $this->driverDrillDown($item),
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

        $maxGapAmount = $gapDays === []
            ? 0.0
            : max(array_map(static fn (CashGapForecastDay $day): float => $day->cashGap, $gapDays));

        return [
            'has_gap' => $gapDays !== [],
            'first_gap_date' => $gapDays[0]->date ?? null,
            'negative_days' => count($gapDays),
            'max_gap_amount' => $this->money($maxGapAmount),
            'deficit_amount' => $this->money($maxGapAmount),
            'min_closing_balance' => $this->money($minClosingBalance),
        ];
    }

    private function topDrivers(array $days): array
    {
        $drivers = [];

        foreach ($days as $day) {
            foreach ($day->drivers as $driver) {
                if (
                    (float) $driver['balance_impact'] < 0.0
                    || $driver['type'] === CashGapForecastItem::BUCKET_OVERDUE_INFLOW
                ) {
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
            'scenario_adjustments_count' => count($context->scenarioAdjustments),
        ];
    }

    private function signals(CashGapForecastContext $context, array $days, array $cashGap, array $drivers): array
    {
        return [
            'currency' => $context->resolvedFilters()->currency,
            'first_gap' => [
                'detected' => (bool) $cashGap['has_gap'],
                'date' => $cashGap['first_gap_date'],
                'amount' => $cashGap['deficit_amount'],
                'severity' => $cashGap['has_gap'] === true ? self::RISK_CRITICAL : self::RISK_LOW,
            ],
            'minimum_balance' => [
                'amount' => $cashGap['min_closing_balance'],
                'severity' => $cashGap['min_closing_balance'] < 0.0 ? self::RISK_CRITICAL : self::RISK_LOW,
            ],
            'deficit' => [
                'amount' => $cashGap['deficit_amount'],
                'negative_days' => $cashGap['negative_days'],
            ],
            'payment_drivers' => $drivers,
            'overdue_inflows' => $this->overdueInflows($days),
        ];
    }

    private function overdueInflows(array $days): array
    {
        $overdueInflows = [];

        foreach ($days as $day) {
            foreach ($day->drivers as $driver) {
                if (($driver['type'] ?? null) === CashGapForecastItem::BUCKET_OVERDUE_INFLOW) {
                    $overdueInflows[] = $driver;
                }
            }
        }

        return $overdueInflows;
    }

    private function isPessimisticScenario(CashGapForecastContext $context): bool
    {
        return in_array($context->scenario, [
            CashGapForecastContext::SCENARIO_PESSIMISTIC,
            CashGapForecastContext::SCENARIO_STRESS,
        ], true);
    }

    private function copyItem(CashGapForecastItem $item, array $overrides): CashGapForecastItem
    {
        return new CashGapForecastItem(
            date: $overrides['date'] ?? $item->date,
            direction: $overrides['direction'] ?? $item->direction,
            bucket: $overrides['bucket'] ?? $item->bucket,
            amount: $overrides['amount'] ?? $item->amount,
            probability: $overrides['probability'] ?? $item->probability,
            organizationId: $overrides['organizationId'] ?? $item->organizationId,
            projectId: $overrides['projectId'] ?? $item->projectId,
            counterpartyId: $overrides['counterpartyId'] ?? $item->counterpartyId,
            budgetArticleId: $overrides['budgetArticleId'] ?? $item->budgetArticleId,
            responsibilityCenterId: $overrides['responsibilityCenterId'] ?? $item->responsibilityCenterId,
            currency: $overrides['currency'] ?? $item->currency,
            sourceType: $overrides['sourceType'] ?? $item->sourceType,
            sourceId: $overrides['sourceId'] ?? $item->sourceId,
            description: $overrides['description'] ?? $item->description,
            originalDate: $overrides['originalDate'] ?? $item->originalDate,
            cashFlowKey: $overrides['cashFlowKey'] ?? $item->cashFlowKey,
            drillDown: $overrides['drillDown'] ?? $item->drillDown,
        );
    }

    private function scenarioDrillDown(
        CashGapForecastItem $item,
        CashGapScenarioAdjustment $adjustment,
    ): array {
        return array_merge($item->drillDown, [
            'scenario_action' => $adjustment->action,
            'scenario_reason' => $adjustment->reason,
        ]);
    }

    private function driverDrillDown(CashGapForecastItem $item): array
    {
        $drillDown = [];

        foreach ($item->drillDown as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $drillDown[$key] = $value;
            }
        }

        if ($drillDown === [] && $item->sourceType !== null) {
            $drillDown['type'] = $item->sourceType;
            $drillDown['id'] = $item->sourceId;
        }

        return $drillDown;
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

    private function originalDate(CashGapForecastItem $item, string $scheduledDate): ?string
    {
        if ($item->originalDate !== null) {
            return $item->originalDate;
        }

        $inputDate = $this->date($item->date)->format('Y-m-d');

        return $inputDate === $scheduledDate ? null : $inputDate;
    }

    private function date(string $date): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($date);
        } catch (Throwable) {
            throw new InvalidArgumentException(trans_message('budgeting.cash_gap.errors.item_invalid'));
        }
    }
}
