<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\DTO;

final readonly class ProjectMarginReportResult
{
    public function __construct(
        public array $filters,
        public array $period,
        public array $summary,
        public array $totalsByCurrency,
        public array $rows,
        public array $groups,
        public bool $drillDownAvailable,
        public array $sourcesCoverage,
        public array $warnings,
        public array $meta,
    ) {
    }

    public static function fromArray(array $result): self
    {
        return new self(
            filters: $result['filters'],
            period: $result['period'],
            summary: $result['summary'],
            totalsByCurrency: $result['totals_by_currency'],
            rows: $result['rows'],
            groups: $result['groups'],
            drillDownAvailable: $result['drill_down_available'],
            sourcesCoverage: $result['sources_coverage'],
            warnings: $result['warnings'],
            meta: $result['meta'],
        );
    }

    public function toArray(): array
    {
        return [
            'filters' => $this->filters,
            'period' => $this->period,
            'summary' => $this->summary,
            'totals_by_currency' => $this->totalsByCurrency,
            'rows' => $this->rows,
            'groups' => $this->groups,
            'drill_down_available' => $this->drillDownAvailable,
            'sources_coverage' => $this->sourcesCoverage,
            'warnings' => $this->warnings,
            'meta' => $this->meta,
        ];
    }
}
