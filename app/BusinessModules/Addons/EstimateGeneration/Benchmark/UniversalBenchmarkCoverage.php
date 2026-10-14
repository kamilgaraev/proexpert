<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

/** Technical replay coverage; does not attest independent model calibration. */
final class UniversalBenchmarkCoverage
{
    public const VERSION = 'universal-replay-coverage:v1';

    public const SCENARIOS = [
        'photo_no_scale', 'photo_user_dimensions', 'incomplete_project', 'conflicting_project',
        'complete_project', 'cad', 'xlsx', 'current_repair', 'reconstruction',
        'industrial_equipment_commissioning',
    ];

    public function assert(BenchmarkReportData $report): void
    {
        if ($report->attemptedCount !== $report->caseCount || $report->succeededCount !== $report->caseCount
            || count($report->caseResults) !== $report->caseCount || count($report->fixtureHashes) !== $report->caseCount) {
            throw new BenchmarkContractException('universal_acceptance_case_counts_invalid');
        }
        $hashes = [];
        foreach ($report->fixtureHashes as $fixture) {
            $id = $fixture['case_id'] ?? null;
            if (! is_string($id) || isset($hashes[$id])
                || preg_match('/\A[a-f0-9]{64}\z/', (string) ($fixture['input_sha256'] ?? '')) !== 1
                || preg_match('/\A[a-f0-9]{64}\z/', (string) ($fixture['expected_sha256'] ?? '')) !== 1) {
                throw new BenchmarkContractException('universal_acceptance_fixture_identity_invalid');
            }
            $hashes[$id] = $fixture;
        }
        $cases = [];
        $covered = [];
        foreach ($report->caseResults as $case) {
            $id = $case['case_id'] ?? null;
            $tags = $case['tags'] ?? null;
            $source = is_string($case['source_type'] ?? null) ? BenchmarkSourceType::tryFrom($case['source_type']) : null;
            if (! is_string($id) || isset($cases[$id]) || ! isset($hashes[$id])
                || $source === null || ($case['status'] ?? null) !== 'success' || ! is_array($tags) || ! array_is_list($tags)) {
                throw new BenchmarkContractException('universal_acceptance_case_identity_invalid');
            }
            $cases[$id] = true;
            foreach ($tags as $tag) {
                if (is_string($tag) && in_array($tag, self::SCENARIOS, true)) {
                    $allowed = match ($tag) {
                        'photo_no_scale', 'photo_user_dimensions' => [BenchmarkSourceType::SitePhoto, BenchmarkSourceType::PhotoPlan],
                        'cad' => [BenchmarkSourceType::Dwg, BenchmarkSourceType::Dxf],
                        'xlsx' => [BenchmarkSourceType::Spreadsheet],
                        default => null,
                    };
                    if ($allowed !== null && ! in_array($source, $allowed, true)) {
                        throw new BenchmarkContractException('universal_acceptance_source_tag_mismatch');
                    }
                    $covered[$tag] = true;
                }
            }
        }
        foreach (self::SCENARIOS as $scenario) {
            if (! isset($covered[$scenario])) {
                throw new BenchmarkContractException('universal_acceptance_missing_'.$scenario);
            }
            $metrics = $report->breakdowns['per_tag'][$scenario] ?? null;
            if (! is_array($metrics)) {
                throw new BenchmarkContractException('universal_acceptance_metrics_missing');
            }
            // A good simple photo must not hide a failing industrial or conflicting-project case.
            (new AcceptanceBenchmarkGate)->assertSummary(0, 0, $metrics);
        }
        foreach (['work_recall', 'evidenced_applicable_items', 'unknown_numeric_values_preserved'] as $metric) {
            $denominator = $report->metrics[$metric]['denominator'] ?? null;
            if (! is_int($denominator) || $denominator < 1) {
                throw new BenchmarkContractException('universal_acceptance_oracle_missing_'.$metric);
            }
        }
        if ($report->costStatus !== 'known' || $report->unknownCostAttempts !== 0
            || $report->currency !== 'RUB' || ! is_string($report->costAmount)
            || preg_match('/\A(?:0|[1-9][0-9]{0,12})(?:\.[0-9]{1,9})?\z/', $report->costAmount) !== 1) {
            throw new BenchmarkContractException('universal_acceptance_supplier_cost_unknown');
        }
    }
}
