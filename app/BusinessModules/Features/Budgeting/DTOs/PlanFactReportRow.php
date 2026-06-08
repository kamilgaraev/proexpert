<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\DTOs;

final readonly class PlanFactReportRow
{
    public function __construct(
        public array $group,
        public ?array $budgetArticle,
        public ?array $responsibilityCenter,
        public ?array $project,
        public ?array $counterparty,
        public array $scenario,
        public string $currency,
        public float $planAmount,
        public float $forecastAmount,
        public float $actualAmount,
        public float $committedAmount,
        public float $varianceAmount,
        public ?float $variancePercent,
        public string $riskLevel,
        public string $drillDownKey,
    ) {
    }

    public function toArray(): array
    {
        return [
            'group' => $this->group,
            'budget_article' => $this->budgetArticle,
            'responsibility_center' => $this->responsibilityCenter,
            'project' => $this->project,
            'counterparty' => $this->counterparty,
            'scenario' => $this->scenario,
            'currency' => $this->currency,
            'plan_amount' => $this->planAmount,
            'forecast_amount' => $this->forecastAmount,
            'actual_amount' => $this->actualAmount,
            'committed_amount' => $this->committedAmount,
            'variance_amount' => $this->varianceAmount,
            'variance_percent' => $this->variancePercent,
            'risk_level' => $this->riskLevel,
            'drill_down_key' => $this->drillDownKey,
        ];
    }
}
