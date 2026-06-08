<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\DTOs;

use Carbon\CarbonImmutable;

final readonly class PlanFactReportFilters
{
    public const GROUP_MONTH = 'month';
    public const GROUP_BUDGET_ARTICLE = 'budget_article';
    public const GROUP_RESPONSIBILITY_CENTER = 'responsibility_center';
    public const GROUP_PROJECT = 'project';
    public const GROUP_CURRENCY = 'currency';

    public const DEFAULT_GROUP_BY = [
        self::GROUP_MONTH,
        self::GROUP_BUDGET_ARTICLE,
        self::GROUP_RESPONSIBILITY_CENTER,
        self::GROUP_PROJECT,
        self::GROUP_CURRENCY,
    ];

    public const ALLOWED_GROUP_BY = [
        self::GROUP_MONTH,
        self::GROUP_BUDGET_ARTICLE,
        self::GROUP_RESPONSIBILITY_CENTER,
        self::GROUP_PROJECT,
        self::GROUP_CURRENCY,
    ];

    /**
     * @param list<string> $groupBy
     */
    public function __construct(
        public int $organizationId,
        public string $periodStart,
        public string $periodEnd,
        public int $budgetVersionId,
        public string $budgetVersionUuid,
        public int $scenarioId,
        public string $scenarioUuid,
        public ?int $projectId,
        public ?int $responsibilityCenterId,
        public ?string $responsibilityCenterUuid,
        public ?int $budgetArticleId,
        public ?string $budgetArticleUuid,
        public ?int $counterpartyId,
        public ?string $currency,
        public array $groupBy,
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

    public function toArray(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'budget_version_uuid' => $this->budgetVersionUuid,
            'scenario_uuid' => $this->scenarioUuid,
            'project_id' => $this->projectId,
            'responsibility_center_id' => $this->responsibilityCenterUuid,
            'budget_article_id' => $this->budgetArticleUuid,
            'counterparty_id' => $this->counterpartyId,
            'currency' => $this->currency,
            'group_by' => $this->groupBy,
        ];
    }
}
