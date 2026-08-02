<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\DTO;

final readonly class WipForecastReportResult
{
    public function __construct(
        public array $filters,
        public array $period,
        public array $summary,
        public array $totalsByCurrency,
        public array $rows,
        public array $formulas,
        public array $assumptions,
        public array $sourceCoverage,
        public array $freshness,
        public array $problemFlags,
        public array $riskFlags,
        public array $drillDown,
        public array $actions,
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
            formulas: $result['formulas'],
            assumptions: $result['assumptions'],
            sourceCoverage: $result['source_coverage'],
            freshness: $result['freshness'],
            problemFlags: $result['problem_flags'],
            riskFlags: $result['risk_flags'],
            drillDown: $result['drill_down'],
            actions: $result['actions'],
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
            'formulas' => $this->formulas,
            'assumptions' => $this->assumptions,
            'source_coverage' => $this->sourceCoverage,
            'freshness' => $this->freshness,
            'problem_flags' => $this->problemFlags,
            'risk_flags' => $this->riskFlags,
            'drill_down' => $this->drillDown,
            'actions' => $this->actions,
            'meta' => $this->meta,
        ];
    }
}
