<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\AcceptanceBenchmarkGate;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkContractException;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkDatasetType;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkReportData;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\UniversalBenchmarkCoverage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UniversalBenchmarkCoverageTest extends TestCase
{
    public function test_complete_technical_matrix_passes_without_claiming_model_calibration(): void
    {
        (new AcceptanceBenchmarkGate)->assert($this->report(), 'universal');
        self::assertSame('universal-replay-coverage:v1', UniversalBenchmarkCoverage::VERSION);
    }

    #[DataProvider('missingScenarios')]
    public function test_each_required_scenario_is_indispensable(string $missing): void
    {
        $cases = $this->cases();
        $cases = array_values(array_filter($cases, static fn ($case): bool => ! in_array($missing, $case['tags'], true)));
        $this->expectException(BenchmarkContractException::class);
        $this->expectExceptionMessage('universal_acceptance_missing_'.$missing);
        (new AcceptanceBenchmarkGate)->assert($this->report(['caseResults' => $cases, 'caseCount' => count($cases), 'attemptedCount' => count($cases), 'succeededCount' => count($cases),
            'fixtureHashes' => $this->hashes($cases)]), 'universal');
    }

    public static function missingScenarios(): array
    {
        return array_map(static fn (string $tag): array => [$tag], UniversalBenchmarkCoverage::SCENARIOS);
    }

    public function test_a_failing_industrial_case_is_not_hidden_by_global_averages(): void
    {
        $breakdowns = $this->breakdowns();
        $breakdowns['per_tag']['industrial_equipment_commissioning']['work_recall']['macro'] = 0.5;
        $this->expectException(BenchmarkContractException::class);
        $this->expectExceptionMessage('acceptance_threshold_failed_work_recall');
        (new AcceptanceBenchmarkGate)->assert($this->report(['breakdowns' => $breakdowns]), 'universal');
    }

    #[DataProvider('invalidReports')]
    public function test_incomplete_or_inconsistent_reports_do_not_qualify(array $changes): void
    {
        $this->expectException(BenchmarkContractException::class);
        (new AcceptanceBenchmarkGate)->assert($this->report($changes), 'universal');
    }

    public static function invalidReports(): array
    {
        return [[['costStatus' => 'partial']], [['unknownCostAttempts' => 1]], [['currency' => 'USD']],
            [['costAmount' => null]], [['costAmount' => '-1']], [['attemptedCount' => 9]], [['succeededCount' => 9]],
            [['fixtureHashes' => []]], [['caseResults' => []]], [['breakdowns' => []]]];
    }

    public function test_perfect_empty_oracles_cannot_qualify(): void
    {
        $metrics = $this->metrics();
        $metrics['unknown_numeric_values_preserved']['denominator'] = 0;
        $this->expectException(BenchmarkContractException::class);
        $this->expectExceptionMessage('universal_acceptance_oracle_missing_unknown_numeric_values_preserved');
        (new AcceptanceBenchmarkGate)->assert($this->report(['metrics' => $metrics]), 'universal');
    }

    private function metrics(): array
    {
        return array_fill_keys(['work_recall', 'normative_top3', 'evidenced_applicable_items', 'technical_success_rate', 'unknown_numeric_values_preserved'], ['macro' => 1.0, 'denominator' => 1]);
    }

    private function cases(): array
    {
        return array_map(static fn (string $tag): array => ['case_id' => $tag, 'tags' => [$tag], 'status' => 'success',
            'source_type' => match ($tag) {
                'photo_no_scale', 'photo_user_dimensions' => 'site_photo',
                'cad' => 'dxf', 'xlsx' => 'xlsx', default => 'text_description',
            }], UniversalBenchmarkCoverage::SCENARIOS);
    }

    public function test_a_tag_does_not_turn_a_text_fixture_into_an_xlsx_or_photo(): void
    {
        $cases = $this->cases();
        $cases[0]['source_type'] = 'text_description';
        $this->expectException(BenchmarkContractException::class);
        $this->expectExceptionMessage('universal_acceptance_source_tag_mismatch');
        (new AcceptanceBenchmarkGate)->assert($this->report(['caseResults' => $cases]), 'universal');
    }

    private function hashes(array $cases): array
    {
        return array_map(static fn (array $case): array => ['case_id' => $case['case_id'], 'input_sha256' => hash('sha256', $case['case_id']), 'expected_sha256' => hash('sha256', $case['case_id'].'oracle')], $cases);
    }

    private function breakdowns(): array
    {
        return ['per_tag' => array_fill_keys(UniversalBenchmarkCoverage::SCENARIOS, $this->metrics())];
    }

    private function report(array $changes = []): BenchmarkReportData
    {
        $args = ['schemaVersion' => 1, 'runId' => 'test-run', 'dataset' => BenchmarkDatasetType::Acceptance,
            'manifestVersion' => 'fixture:v1', 'manifestSha256' => hash('sha256', 'fixture'), 'pipelineVersion' => 'test:v1',
            'adapterId' => 'fixture-replay', 'promptVersion' => 'test:v1', 'modelVersions' => ['vision' => 'gpt-6-luna'],
            'fixtureHashes' => $this->hashes($this->cases()), 'caseCount' => 10, 'attemptedCount' => 10, 'succeededCount' => 10,
            'failedCount' => 0, 'skippedCount' => 0, 'metrics' => $this->metrics(), 'breakdowns' => $this->breakdowns(),
            'caseResults' => $this->cases(), 'durationMs' => 0, 'costStatus' => 'known', 'costAmount' => '1.35', 'currency' => 'RUB',
            'unknownCostAttempts' => 0, 'generatedAt' => '2026-10-14T00:00:00Z', 'deterministicFingerprint' => hash('sha256', 'report'),
            'maxFailureRate' => 0.0, 'failurePolicyVersion' => 'zero:v1', 'allowUnsupported' => false];

        return new BenchmarkReportData(...array_replace($args, $changes));
    }
}
