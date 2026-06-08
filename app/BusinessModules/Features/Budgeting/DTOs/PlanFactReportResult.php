<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\DTOs;

final readonly class PlanFactReportResult
{
    /**
     * @param list<PlanFactCurrencyTotal> $totalsByCurrency
     * @param list<PlanFactReportRow> $rows
     * @param list<array<string, mixed>> $groups
     * @param list<array<string, mixed>> $sourcesCoverage
     * @param list<string> $warnings
     */
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

    public function toArray(): array
    {
        return [
            'filters' => $this->filters,
            'period' => $this->period,
            'summary' => $this->summary,
            'totals_by_currency' => array_map(
                static fn (PlanFactCurrencyTotal $total): array => $total->toArray(),
                $this->totalsByCurrency,
            ),
            'rows' => array_map(
                static fn (PlanFactReportRow $row): array => $row->toArray(),
                $this->rows,
            ),
            'groups' => $this->groups,
            'drill_down_available' => $this->drillDownAvailable,
            'sources_coverage' => $this->sourcesCoverage,
            'warnings' => $this->warnings,
            'meta' => $this->meta,
        ];
    }
}
