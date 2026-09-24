<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\DTOs;

use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\Support\PortfolioDecimal;

final readonly class UndatedPaymentCalendarItem
{
    public string $amount;
    public string $remainingAmount;
    public string $bucket;
    public string $sourceType;

    public function __construct(
        public int $organizationId,
        public string $direction,
        string $amount,
        string $remainingAmount,
        public string $currency,
        public int|string|null $sourceId,
        public string $cashFlowKey,
        public ?int $projectId,
        public ?int $counterpartyId,
        public int|string|null $budgetArticleId,
        public int|string|null $responsibilityCenterId,
        public array $drillDown,
    ) {
        $this->amount = PortfolioDecimal::money($amount);
        $this->remainingAmount = PortfolioDecimal::money($remainingAmount);
        $this->bucket = 'undated';
        $this->sourceType = 'payment_schedule';
    }

    public function toArray(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'date' => null,
            'direction' => $this->direction,
            'bucket' => $this->bucket,
            'amount' => $this->amount,
            'remaining_amount' => $this->remainingAmount,
            'currency' => $this->currency,
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
            'cash_flow_key' => $this->cashFlowKey,
            'project_id' => $this->projectId,
            'counterparty_id' => $this->counterpartyId,
            'budget_article_id' => $this->budgetArticleId,
            'responsibility_center_id' => $this->responsibilityCenterId,
            'editable' => false,
            'drill_down' => $this->drillDown,
        ];
    }
}
