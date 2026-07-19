<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Quality;

final readonly class DraftReadinessProjector
{
    public function __construct(private DraftReadinessInspector $inspector = new DraftReadinessInspector) {}

    public function project(array $draft): array
    {
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
        ];
        $draft['problem_flags'] = array_values(array_unique([
            ...($draft['problem_flags'] ?? []),
            ...$blockingCodes,
            ...$warningCodes,
        ]));

        return $draft;
    }
}
