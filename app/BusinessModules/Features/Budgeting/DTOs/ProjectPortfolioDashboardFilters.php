<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\DTOs;

use App\BusinessModules\Core\Payments\DTOs\PaymentCalendarSourceFilters;

final readonly class ProjectPortfolioDashboardFilters
{
    public function __construct(
        public int $organizationId,
        public string $periodStart,
        public string $periodEnd,
        public string $asOfDate,
        public ?int $projectManagerId = null,
        public ?string $projectStatus = null,
        public ?string $projectType = null,
        public ?int $responsibilityCenterId = null,
        public ?string $responsibilityCenterUuid = null,
        public ?string $currency = null,
        public int $limit = 25,
        public int $topN = 25,
    ) {
    }

    public function period(): array
    {
        return [
            'from' => $this->periodStart,
            'to' => $this->periodEnd,
            'as_of_date' => $this->asOfDate,
        ];
    }

    public function marginInput(): array
    {
        $input = [
            'organization_id' => $this->organizationId,
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'responsibility_center_id' => $this->responsibilityCenterUuid ?? $this->responsibilityCenterId,
            'currency' => $this->currency,
            'group_by' => [
                ProjectMarginReportFilters::GROUP_PROJECT,
                ProjectMarginReportFilters::GROUP_CURRENCY,
            ],
        ];

        return $this->withoutNulls($input);
    }

    public function planFactInput(): array
    {
        $input = [
            'organization_id' => $this->organizationId,
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'responsibility_center_id' => $this->responsibilityCenterUuid ?? $this->responsibilityCenterId,
            'currency' => $this->currency,
            'group_by' => [
                PlanFactReportFilters::GROUP_PROJECT,
                PlanFactReportFilters::GROUP_CURRENCY,
            ],
        ];

        return $this->withoutNulls($input);
    }

    public function wipForecastInput(): array
    {
        $input = [
            'organization_id' => $this->organizationId,
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'as_of_date' => $this->asOfDate,
            'currency' => $this->currency,
            'group_by' => [
                WipForecastReportFilters::GROUP_PROJECT,
                WipForecastReportFilters::GROUP_CURRENCY,
            ],
        ];

        return $this->withoutNulls($input);
    }

    public function calendarFilters(): PaymentCalendarSourceFilters
    {
        return new PaymentCalendarSourceFilters(
            organizationId: $this->organizationId,
            periodStart: $this->periodStart,
            periodEnd: $this->periodEnd,
            responsibilityCenterId: $this->responsibilityCenterId,
            currency: $this->currency,
        );
    }

    public function toArray(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'as_of_date' => $this->asOfDate,
            'project_manager_id' => $this->projectManagerId,
            'project_status' => $this->projectStatus,
            'project_type' => $this->projectType,
            'responsibility_center_id' => $this->responsibilityCenterUuid ?? $this->responsibilityCenterId,
            'currency' => $this->currency,
            'limit' => $this->limit,
            'top_n' => $this->topN,
        ];
    }

    private function withoutNulls(array $input): array
    {
        return array_filter($input, static fn (mixed $value): bool => $value !== null);
    }
}
