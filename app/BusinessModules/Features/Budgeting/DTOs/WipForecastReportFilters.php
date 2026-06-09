<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\DTOs;

use Carbon\CarbonImmutable;

final readonly class WipForecastReportFilters
{
    public const GROUP_PROJECT = 'project';
    public const GROUP_STAGE = 'stage';
    public const GROUP_CONTRACT = 'contract';
    public const GROUP_ESTIMATE_ITEM = 'estimate_item';
    public const GROUP_PERIOD = 'period';
    public const GROUP_CURRENCY = 'currency';

    public const DEFAULT_GROUP_BY = [
        self::GROUP_PROJECT,
        self::GROUP_STAGE,
        self::GROUP_CONTRACT,
        self::GROUP_PERIOD,
        self::GROUP_CURRENCY,
    ];

    public const ALLOWED_GROUP_BY = [
        self::GROUP_PROJECT,
        self::GROUP_STAGE,
        self::GROUP_CONTRACT,
        self::GROUP_ESTIMATE_ITEM,
        self::GROUP_PERIOD,
        self::GROUP_CURRENCY,
    ];

    /**
     * @param list<string> $groupBy
     */
    public function __construct(
        public int $organizationId,
        public string $periodStart,
        public string $periodEnd,
        public string $asOfDate,
        public ?int $forecastVersionId,
        public ?string $forecastVersionUuid,
        public ?int $budgetVersionId,
        public ?string $budgetVersionUuid,
        public ?int $scenarioId,
        public ?string $scenarioUuid,
        public ?int $projectId,
        public ?int $stageId,
        public ?int $contractId,
        public ?int $estimateItemId,
        public ?string $currency,
        public array $groupBy,
    ) {
    }

    public function period(): array
    {
        return [
            'from' => $this->periodStart,
            'to' => $this->periodEnd,
            'as_of_date' => $this->asOfDate,
            'start_month' => $this->periodStartMonth(),
            'end_month' => $this->periodEndMonth(),
            'as_of_month' => CarbonImmutable::parse($this->asOfDate)->startOfMonth()->toDateString(),
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

    public function toArray(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'as_of_date' => $this->asOfDate,
            'forecast_version_uuid' => $this->forecastVersionUuid,
            'budget_version_uuid' => $this->budgetVersionUuid,
            'scenario_uuid' => $this->scenarioUuid,
            'project_id' => $this->projectId,
            'stage_id' => $this->stageId,
            'contract_id' => $this->contractId,
            'estimate_item_id' => $this->estimateItemId,
            'currency' => $this->currency,
            'group_by' => $this->groupBy,
        ];
    }
}
