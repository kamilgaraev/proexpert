<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

final class AcceptanceBenchmarkGate
{
    private const THRESHOLDS = [
        'work_recall' => 0.90,
        'normative_top3' => 0.95,
        'evidenced_applicable_items' => 1.0,
        'technical_success_rate' => 0.98,
    ];

    public function assert(BenchmarkReportData $report): void
    {
        if ($report->dataset !== BenchmarkDatasetType::Acceptance || $report->caseCount < 6) {
            throw new BenchmarkContractException('acceptance_coverage_insufficient');
        }
        $this->assertSummary($report->failedCount, $report->skippedCount, $report->metrics);
    }

    public function assertSummary(int $failedCount, int $skippedCount, array $metrics): void
    {
        if ($failedCount !== 0 || $skippedCount !== 0) {
            throw new BenchmarkContractException('acceptance_failures_not_allowed');
        }
        foreach (self::THRESHOLDS as $metric => $threshold) {
            $value = $metrics[$metric]['macro'] ?? null;
            if (! is_float($value) && ! is_int($value) || (float) $value < $threshold) {
                throw new BenchmarkContractException('acceptance_threshold_failed_'.$metric);
            }
        }
    }
}
