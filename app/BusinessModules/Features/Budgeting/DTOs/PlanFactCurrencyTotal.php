<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\DTOs;

final readonly class PlanFactCurrencyTotal
{
    public function __construct(
        public string $currency,
        public float $planAmount,
        public float $forecastAmount,
        public float $actualAmount,
        public float $committedAmount,
        public float $varianceAmount,
        public ?float $variancePercent,
        public string $riskLevel,
        public int $rowsCount,
    ) {
    }

    public function toArray(): array
    {
        return [
            'currency' => $this->currency,
            'plan_amount' => $this->planAmount,
            'forecast_amount' => $this->forecastAmount,
            'actual_amount' => $this->actualAmount,
            'committed_amount' => $this->committedAmount,
            'variance_amount' => $this->varianceAmount,
            'variance_percent' => $this->variancePercent,
            'risk_level' => $this->riskLevel,
            'rows_count' => $this->rowsCount,
        ];
    }
}
