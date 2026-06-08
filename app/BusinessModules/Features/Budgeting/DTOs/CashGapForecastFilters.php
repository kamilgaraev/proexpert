<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\DTOs;

final readonly class CashGapForecastFilters
{
    public function __construct(
        public ?int $organizationId = null,
        public ?int $projectId = null,
        public ?int $counterpartyId = null,
        public ?string $budgetArticleId = null,
        public ?string $responsibilityCenterId = null,
        public ?string $currency = 'RUB',
    ) {
    }

    public function matches(CashGapForecastItem $item): bool
    {
        return $this->matchesRequiredOrganization($item)
            && $this->matchesNullableInt($this->projectId, $item->projectId)
            && $this->matchesNullableInt($this->counterpartyId, $item->counterpartyId)
            && $this->matchesNullableString($this->budgetArticleId, $item->budgetArticleId)
            && $this->matchesNullableString($this->responsibilityCenterId, $item->responsibilityCenterId)
            && $this->matchesNullableString($this->currency, $item->currency);
    }

    public function toArray(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'project_id' => $this->projectId,
            'counterparty_id' => $this->counterpartyId,
            'budget_article_id' => $this->budgetArticleId,
            'responsibility_center_id' => $this->responsibilityCenterId,
            'currency' => $this->currency,
        ];
    }

    private function matchesRequiredOrganization(CashGapForecastItem $item): bool
    {
        if ($this->organizationId === null) {
            return true;
        }

        return $item->organizationId === $this->organizationId;
    }

    private function matchesNullableInt(?int $filter, ?int $value): bool
    {
        return $filter === null || $value === $filter;
    }

    private function matchesNullableString(?string $filter, ?string $value): bool
    {
        return $filter === null || $value === $filter;
    }
}
