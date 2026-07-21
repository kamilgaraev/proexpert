<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Quality;

final readonly class DraftReadinessProjector
{
    public function __construct(
        private DraftReadinessInspector $inspector = new DraftReadinessInspector,
        private EstimateCompletenessProfile $completeness = new EstimateCompletenessProfile,
        private EstimateBudgetScope $budgetScope = new EstimateBudgetScope,
    ) {}

    public function project(array $draft): array
    {
        $draft['completeness'] = $this->completeness->project($draft);
        $draft['budget_scope'] = $this->budgetScope->project($draft, $this->directCosts($draft));
        $inspection = $this->inspector->inspect($draft);
        $blockingCodes = array_column($inspection->blockingIssues, 'code');
        $warningCodes = array_column($inspection->warnings, 'code');
        $draft['readiness_summary'] = $inspection->toArray();
        $draft['quality_summary'] = [
            ...($draft['quality_summary'] ?? []),
            'status' => $blockingCodes === [] ? 'passed' : 'review_required',
            'level' => $blockingCodes === [] ? 'passed' : 'critical',
            'critical_flags' => $blockingCodes,
            'warning_flags' => $warningCodes,
            'completeness_status' => $draft['completeness']['status'],
        ];
        $draft['problem_flags'] = array_values(array_unique([
            ...($draft['problem_flags'] ?? []),
            ...$blockingCodes,
            ...$warningCodes,
        ]));

        return $draft;
    }

    private function directCosts(array $draft): float
    {
        $total = 0.0;

        foreach ((array) ($draft['local_estimates'] ?? []) as $estimate) {
            foreach ((array) ($estimate['sections'] ?? []) as $section) {
                foreach ((array) ($section['work_items'] ?? []) as $workItem) {
                    if (! is_array($workItem) || ($workItem['item_type'] ?? 'priced_work') !== 'priced_work') {
                        continue;
                    }

                    if (is_numeric($workItem['total_cost'] ?? null)) {
                        $total += (float) $workItem['total_cost'];
                    }
                }
            }
        }

        return round($total, 2);
    }
}
