<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\DTOs;

use App\BusinessModules\Features\Budgeting\DTOs\CashGapForecastContext;
use App\BusinessModules\Features\Budgeting\DTOs\CashGapScenarioAdjustment;

final readonly class PaymentCalendarCashGapOptions
{
    /**
     * @param list<array{cash_flow_key:string,date:string,reason?:string|null}> $reschedules
     * @param list<array{cash_flow_key:string,probability:float,reason?:string|null}> $probabilityOverrides
     * @param list<array{date:string,amount:float,currency?:string|null,description?:string|null,reason?:string|null}> $financingItems
     * @param list<string> $excludedCashFlowKeys
     */
    public function __construct(
        public ?float $openingBalance = null,
        public string $scenario = CashGapForecastContext::SCENARIO_BASE,
        public int $stressInflowDelayDays = 7,
        public float $stressInflowProbabilityFactor = 0.75,
        public float $optimisticInflowProbabilityLift = 0.1,
        public int $optimisticInflowAdvanceDays = 0,
        public array $reschedules = [],
        public array $probabilityOverrides = [],
        public array $financingItems = [],
        public array $excludedCashFlowKeys = [],
    ) {
    }

    public function hasOpeningBalance(): bool
    {
        return $this->openingBalance !== null;
    }

    public function hasScenarioAssumptions(): bool
    {
        return $this->scenario !== CashGapForecastContext::SCENARIO_BASE
            || $this->assumptionsCount() > 0;
    }

    public function assumptionsCount(): int
    {
        return count($this->reschedules)
            + count($this->probabilityOverrides)
            + count($this->financingItems)
            + count($this->excludedCashFlowKeys);
    }

    /**
     * @return list<CashGapScenarioAdjustment>
     */
    public function scenarioAdjustments(): array
    {
        return [
            ...$this->rescheduleAdjustments(),
            ...$this->probabilityAdjustments(),
            ...$this->financingAdjustments(),
            ...$this->exclusionAdjustments(),
        ];
    }

    /**
     * @return list<CashGapScenarioAdjustment>
     */
    private function rescheduleAdjustments(): array
    {
        $adjustments = [];

        foreach ($this->reschedules as $reschedule) {
            $adjustments[] = new CashGapScenarioAdjustment(
                action: CashGapScenarioAdjustment::ACTION_RESCHEDULE_PAYMENT,
                cashFlowKey: $this->nullableString($reschedule['cash_flow_key'] ?? null),
                date: $this->nullableString($reschedule['date'] ?? null),
                reason: $this->nullableString($reschedule['reason'] ?? null),
            );
        }

        return $adjustments;
    }

    /**
     * @return list<CashGapScenarioAdjustment>
     */
    private function probabilityAdjustments(): array
    {
        $adjustments = [];

        foreach ($this->probabilityOverrides as $override) {
            $adjustments[] = new CashGapScenarioAdjustment(
                action: CashGapScenarioAdjustment::ACTION_CHANGE_INFLOW_PROBABILITY,
                cashFlowKey: $this->nullableString($override['cash_flow_key'] ?? null),
                probability: array_key_exists('probability', $override) ? (float) $override['probability'] : null,
                reason: $this->nullableString($override['reason'] ?? null),
            );
        }

        return $adjustments;
    }

    /**
     * @return list<CashGapScenarioAdjustment>
     */
    private function financingAdjustments(): array
    {
        $adjustments = [];

        foreach ($this->financingItems as $item) {
            $adjustments[] = new CashGapScenarioAdjustment(
                action: CashGapScenarioAdjustment::ACTION_ADD_TEMPORARY_FINANCING,
                date: $this->nullableString($item['date'] ?? null),
                amount: array_key_exists('amount', $item) ? (float) $item['amount'] : null,
                currency: $this->nullableString($item['currency'] ?? null),
                description: $this->nullableString($item['description'] ?? null),
                reason: $this->nullableString($item['reason'] ?? null),
            );
        }

        return $adjustments;
    }

    /**
     * @return list<CashGapScenarioAdjustment>
     */
    private function exclusionAdjustments(): array
    {
        $adjustments = [];

        foreach ($this->excludedCashFlowKeys as $cashFlowKey) {
            $adjustments[] = new CashGapScenarioAdjustment(
                action: CashGapScenarioAdjustment::ACTION_EXCLUDE_PAYMENT,
                cashFlowKey: $this->nullableString($cashFlowKey),
            );
        }

        return $adjustments;
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
