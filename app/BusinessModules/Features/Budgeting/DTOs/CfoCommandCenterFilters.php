<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\DTOs;

use App\BusinessModules\Core\Payments\DTOs\PaymentCalendarSourceFilters;
use Carbon\CarbonImmutable;

final readonly class CfoCommandCenterFilters
{
    public function __construct(
        public int $organizationId,
        public string $periodStart,
        public string $periodEnd,
        public ?int $projectId = null,
        public ?int $responsibilityCenterId = null,
        public ?string $responsibilityCenterUuid = null,
        public ?int $budgetArticleId = null,
        public ?string $budgetArticleUuid = null,
        public ?int $counterpartyId = null,
        public ?string $currency = null,
        public ?string $budgetVersionUuid = null,
        public ?string $scenarioUuid = null,
        public int $itemLimit = 10,
    ) {
    }

    public function period(): array
    {
        return [
            'from' => $this->periodStart,
            'to' => $this->periodEnd,
            'start_month' => $this->periodStartMonth(),
            'end_month' => $this->periodEndMonth(),
        ];
    }

    public function periodStartMonth(): string
    {
        return CarbonImmutable::parse($this->periodStart)->startOfMonth()->toDateString();
    }

    public function periodEndMonth(): string
    {
        return CarbonImmutable::parse($this->periodEnd)->startOfMonth()->toDateString();
    }

    public function calendarFilters(): PaymentCalendarSourceFilters
    {
        return new PaymentCalendarSourceFilters(
            organizationId: $this->organizationId,
            periodStart: $this->periodStart,
            periodEnd: $this->periodEnd,
            projectId: $this->projectId,
            counterpartyId: $this->counterpartyId,
            budgetArticleId: $this->budgetArticleId,
            responsibilityCenterId: $this->responsibilityCenterId,
            currency: $this->currency,
        );
    }

    public function cashGapFilters(string $currency): CashGapForecastFilters
    {
        return new CashGapForecastFilters(
            organizationId: $this->organizationId,
            projectId: $this->projectId,
            counterpartyId: $this->counterpartyId,
            budgetArticleId: $this->budgetArticleId !== null ? (string) $this->budgetArticleId : null,
            responsibilityCenterId: $this->responsibilityCenterId !== null ? (string) $this->responsibilityCenterId : null,
            currency: $currency,
        );
    }

    public function planFactInput(): array
    {
        $input = [
            'organization_id' => $this->organizationId,
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'project_id' => $this->projectId,
            'responsibility_center_id' => $this->responsibilityCenterUuid ?? $this->responsibilityCenterId,
            'budget_article_id' => $this->budgetArticleUuid ?? $this->budgetArticleId,
            'counterparty_id' => $this->counterpartyId,
            'currency' => $this->currency,
            'group_by' => PlanFactReportFilters::DEFAULT_GROUP_BY,
        ];

        if ($this->budgetVersionUuid !== null) {
            $input['budget_version_uuid'] = $this->budgetVersionUuid;
        }

        if ($this->scenarioUuid !== null) {
            $input['scenario_uuid'] = $this->scenarioUuid;
        }

        return $input;
    }

    public function toArray(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'project_id' => $this->projectId,
            'responsibility_center_id' => $this->responsibilityCenterUuid ?? $this->responsibilityCenterId,
            'budget_article_id' => $this->budgetArticleUuid ?? $this->budgetArticleId,
            'counterparty_id' => $this->counterpartyId,
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'currency' => $this->currency,
            'budget_version_uuid' => $this->budgetVersionUuid,
            'scenario_uuid' => $this->scenarioUuid,
            'item_limit' => $this->itemLimit,
        ];
    }
}
