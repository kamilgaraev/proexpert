<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\DTOs;

final readonly class BudgetLimitCheckContext
{
    public const ENFORCEMENT_INFORM = 'inform';
    public const ENFORCEMENT_SOFT_BLOCK = 'soft_block';
    public const ENFORCEMENT_HARD_BLOCK = 'hard_block';

    public function __construct(
        public string $operationType,
        public int|string|null $operationId,
        public int $organizationId,
        public string $budgetPeriodId,
        public string $budgetArticleId,
        public string $responsibilityCenterId,
        public string $period,
        public string $currency,
        public ?int $projectId = null,
        public ?int $contractId = null,
        public ?int $counterpartyId = null,
        public ?string $limitId = null,
        public string $enforcementMode = self::ENFORCEMENT_SOFT_BLOCK,
        public float $warningThresholdRatio = 0.9,
        public bool $hasApprovedBudget = true,
    ) {
    }

    public function dimensions(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'budget_period_id' => $this->budgetPeriodId,
            'budget_article_id' => $this->budgetArticleId,
            'responsibility_center_id' => $this->responsibilityCenterId,
            'period' => $this->period,
            'project_id' => $this->projectId,
            'contract_id' => $this->contractId,
            'counterparty_id' => $this->counterpartyId,
        ];
    }

    public function operation(): array
    {
        return [
            'operation_type' => $this->operationType,
            'operation_id' => $this->operationId,
        ];
    }
}
