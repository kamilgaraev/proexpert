<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\DTOs;

final readonly class CashGapForecastResult
{
    public function __construct(
        public CashGapForecastContext $context,
        public array $days,
        public array $totals,
        public array $cashGap,
        public string $riskLevel,
        public array $explanation,
        public array $drivers,
        public array $signals,
        public array $meta,
    ) {
    }

    public function toArray(): array
    {
        return [
            'period' => $this->context->period(),
            'scenario' => $this->context->scenario,
            'opening_balance' => $this->totals['opening_balance'],
            'inflows' => $this->totals['inflows'],
            'outflows' => $this->totals['outflows'],
            'reserved_outflows' => $this->totals['reserved_outflows'],
            'overdue_inflows' => $this->totals['overdue_inflows'],
            'overdue_outflows' => $this->totals['overdue_outflows'],
            'closing_balance' => $this->totals['closing_balance'],
            'cash_gap' => $this->cashGap,
            'risk_level' => $this->riskLevel,
            'explanation' => $this->explanation,
            'drivers' => $this->drivers,
            'signals' => $this->signals,
            'filters' => $this->context->resolvedFilters()->toArray(),
            'daily' => array_map(
                static fn (CashGapForecastDay $day): array => $day->toArray(),
                $this->days
            ),
            'meta' => $this->meta,
        ];
    }
}
