<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\AcceptanceBenchmarkCorpusLoader;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\AcceptanceBenchmarkGate;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkContractException;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkDatasetType;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPipelineAdapter;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPipelineResultData;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPredictionCaseData;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPrivateObjectStore;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkRunner;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkRunOptions;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\InProcessBenchmarkCaseExecutor;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\Metrics\MetricRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IndependentAcceptanceExecutionGateTest extends TestCase
{
    #[Test]
    public function six_source_private_corpus_passes_master_gate_without_prediction_oracle(): void
    {
        $organizationId = 731;
        $prefix = "org-{$organizationId}/estimate-generation/benchmarks/acceptance/independent-v1";
        $specs = $this->sourceSpecs();
        $objects = [];
        $cases = [];
        $predictions = [];
        foreach ($specs as $index => $spec) {
            $ordinal = $index + 1;
            $id = 'acceptance-independent-'.$spec['slug'].'-20260713';
            $prediction = $this->prediction($ordinal);
            $expected = json_encode([
                'schema_version' => 1,
                'expected_model_schema_version' => 'benchmark-expected:v1',
                'expected' => $prediction,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $inputPath = "{$prefix}/{$spec['slug']}/input.{$spec['extension']}";
            $expectedPath = "{$prefix}/{$spec['slug']}/expected.json";
            $cases[] = [
                'id' => $id, 'dataset' => 'acceptance', 'source_type' => $spec['source_type'],
                'input_locator' => 's3://org-{organization_id}/estimate-generation/benchmarks/acceptance/independent-v1/'.$spec['slug'].'/input.'.$spec['extension'],
                'expected_locator' => 's3://org-{organization_id}/estimate-generation/benchmarks/acceptance/independent-v1/'.$spec['slug'].'/expected.json',
                'input_sha256' => hash('sha256', $spec['bytes']), 'expected_sha256' => hash('sha256', $expected),
                'license' => 'most-private-contract-fixture',
                'provenance' => 'independent-capture:most-qa-20260713:'.$spec['slug'],
                'tags' => ['independent', 'private', 'reviewed'], 'schema_version' => 1,
                'expected_model_schema_version' => 'benchmark-expected:v1',
                'allowed_capabilities' => $spec['capabilities'],
            ];
            $objects[$inputPath] = $spec['bytes'];
            $objects[$expectedPath] = $expected;
            $predictions[$id] = $prediction;
        }
        $manifest = json_encode([
            'schema_version' => 1,
            'manifest_version' => 'acceptance-independent-private:v2',
            'cases' => $cases,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $manifestHash = hash('sha256', $manifest);
        $objects["{$prefix}/manifest.json"] = $manifest;
        foreach ($cases as $case) {
            $id = $case['id'];
            $objects["{$prefix}/envelopes/{$id}.json"] = json_encode([
                'schema_version' => 1, 'capture_kind' => 'contract_fixture',
                'provider' => 'most-independent-recorded-provider', 'model_version' => 'independent-contract-v2',
                'payload_schema_version' => 'benchmark-prediction:v1',
                'source_fixture_sha256' => $case['input_sha256'],
                'privacy_scanner' => 'most-fixture-privacy', 'privacy_scanner_version' => '1.0.0',
                'privacy_result' => 'passed', 'approval_kind' => 'maintainer_code_review',
                'approval_ref' => 'plan3-task11-recorded-fixtures-v1', 'approved_at' => '2026-07-12T00:00:00Z',
                'manifest_sha256' => $manifestHash,
                'prediction' => [...$predictions[$id], 'model_schema_version' => 'acceptance-prediction:v2'],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }
        $store = new InMemoryAcceptanceStore($objects);
        $corpus = (new AcceptanceBenchmarkCorpusLoader($store))->load(
            $organizationId,
            "s3://{$prefix}/manifest.json",
        );
        $adapter = new IndependentRecordedAcceptanceAdapter($store, $prefix, $manifestHash);
        $report = (new BenchmarkRunner(MetricRegistry::standard(), new InProcessBenchmarkCaseExecutor))->run(
            $corpus->manifest,
            BenchmarkDatasetType::Acceptance,
            $adapter,
            new BenchmarkRunOptions('acceptance-gate:v2', 'recorded-contract:v2', 5_000, 0.0, 'strict-zero:v1', false),
            $corpus->objects,
            $corpus->executionReference,
        );
        (new AcceptanceBenchmarkGate)->assert($report);

        self::assertSame(6, $report->attemptedCount);
        self::assertSame(6, $report->succeededCount);
        self::assertSame(0, $report->failedCount);
        self::assertSame(0, $report->skippedCount);
        foreach (['work_recall', 'normative_top3', 'evidenced_applicable_items', 'technical_success_rate'] as $metric) {
            self::assertSame(1.0, $report->metrics[$metric]['macro']);
        }
    }

    #[Test]
    #[DataProvider('rejectedGateSummaries')]
    public function master_gate_rejects_failures_skips_and_metric_regressions(int $failed, int $skipped, array $metrics, string $code): void
    {
        $this->expectExceptionMessage($code);
        (new AcceptanceBenchmarkGate)->assertSummary($failed, $skipped, $metrics);
    }

    public static function rejectedGateSummaries(): iterable
    {
        $passing = self::passingMetrics();
        yield 'failure' => [1, 0, $passing, 'acceptance_failures_not_allowed'];
        yield 'skip' => [0, 1, $passing, 'acceptance_failures_not_allowed'];
        foreach (['work_recall' => 0.899, 'normative_top3' => 0.949, 'evidenced_applicable_items' => 0.999, 'technical_success_rate' => 0.979] as $metric => $value) {
            yield $metric => [0, 0, [...$passing, $metric => ['macro' => $value]], 'acceptance_threshold_failed_'.$metric];
        }
    }

    private static function passingMetrics(): array
    {
        return [
            'work_recall' => ['macro' => 0.90], 'normative_top3' => ['macro' => 0.95],
            'evidenced_applicable_items' => ['macro' => 1.0], 'technical_success_rate' => ['macro' => 0.98],
        ];
    }

    private function sourceSpecs(): array
    {
        $root = dirname(__DIR__, 3).'/Fixtures/EstimateGeneration/benchmarks/regression';
        $read = static fn (string $path): string => (string) file_get_contents($root.'/'.$path);

        return [
            ['slug' => 'freehand-sketch', 'source_type' => 'undimensioned_sketch', 'extension' => 'svg',
                'bytes' => str_replace('</svg>', '<desc>acceptance freehand 731</desc></svg>', $read('replay-freehand-review-001/input.svg')), 'capabilities' => ['document_understanding']],
            ['slug' => 'scanned-pdf', 'source_type' => 'scanned_pdf', 'extension' => 'pdf',
                'bytes' => $read('replay-scanned-pdf-001/input.pdf')."\n% acceptance-private-731-scanned\n", 'capabilities' => ['document_understanding']],
            ['slug' => 'vector-pdf', 'source_type' => 'vector_pdf', 'extension' => 'pdf',
                'bytes' => $read('replay-vector-pdf-001/input.pdf')."\n% acceptance-private-731-vector\n", 'capabilities' => ['document_understanding']],
            ['slug' => 'dxf-plan', 'source_type' => 'dxf', 'extension' => 'dxf',
                'bytes' => "\n".$read('replay-vector-wall-opening-001/input.dxf'), 'capabilities' => ['geometry']],
            ['slug' => 'real-dwg', 'source_type' => 'dwg', 'extension' => 'dwg',
                'bytes' => $read('replay-dwg-layout-001/input.dwg').'ACCEPTANCE731', 'capabilities' => ['geometry']],
            ['slug' => 'engineering-scale-ambiguity', 'source_type' => 'dimensioned_sketch', 'extension' => 'svg',
                'bytes' => str_replace('</svg>', '<desc>acceptance scale ambiguity 1:50 1:100</desc></svg>', $read('replay-engineering-layout-001/input.svg')), 'capabilities' => ['document_understanding']],
        ];
    }

    private function prediction(int $ordinal): array
    {
        $suffix = (string) (730 + $ordinal);

        return [
            'sheet_type' => 'floor_plan', 'room_cells' => ['acceptance-room-'.$suffix],
            'wall_cells' => ['acceptance-wall-'.$suffix], 'opening_ids' => ['acceptance-opening-'.$suffix],
            'areas' => ['acceptance-room-'.$suffix => '17.25'], 'quantities' => ['finish.floor' => '17.25'],
            'work_ids' => ['acceptance-work-'.$suffix], 'review_codes' => [],
            'normative_rankings' => ['acceptance-work-'.$suffix => ['acceptance-norm-'.$suffix]],
            'costs' => ['acceptance-work-'.$suffix => '731.00'],
            'applicable_item_ids' => ['acceptance-work-'.$suffix],
            'evidence_ids_by_item' => ['acceptance-work-'.$suffix => ['acceptance-evidence-'.$suffix]],
        ];
    }
}

final class InMemoryAcceptanceStore implements BenchmarkPrivateObjectStore
{
    public function __construct(public array $objects) {}

    public function read(string $path, int $maxBytes): string
    {
        $value = $this->objects[$path] ?? throw new BenchmarkContractException('private_object_unavailable');

        return strlen($value) <= $maxBytes ? $value : throw new BenchmarkContractException('private_object_too_large');
    }
}

final readonly class IndependentRecordedAcceptanceAdapter implements BenchmarkPipelineAdapter
{
    public function __construct(
        private BenchmarkPrivateObjectStore $store,
        private string $prefix,
        private string $manifestHash,
    ) {}

    public function id(): string
    {
        return 'independent-recorded-contract';
    }

    public function run(BenchmarkPredictionCaseData $case, int $timeoutMs): BenchmarkPipelineResultData
    {
        $payload = json_decode($this->store->read("{$this->prefix}/envelopes/{$case->id}.json", 1_000_000), true, 64, JSON_THROW_ON_ERROR);
        $required = [
            'capture_kind' => 'contract_fixture', 'provider' => 'most-independent-recorded-provider',
            'model_version' => 'independent-contract-v2', 'payload_schema_version' => 'benchmark-prediction:v1',
            'source_fixture_sha256' => $case->inputSha256, 'privacy_result' => 'passed',
            'approval_kind' => 'maintainer_code_review', 'approval_ref' => 'plan3-task11-recorded-fixtures-v1',
            'approved_at' => '2026-07-12T00:00:00Z', 'manifest_sha256' => $this->manifestHash,
        ];
        foreach ($required as $key => $value) {
            if (($payload[$key] ?? null) !== $value) {
                return BenchmarkPipelineResultData::technicalFailure('recorded_contract_metadata_invalid');
            }
        }
        if (array_key_exists('expected', $payload)) {
            return BenchmarkPipelineResultData::technicalFailure('prediction_oracle_detected');
        }

        return BenchmarkPipelineResultData::success(
            $payload['prediction'],
            ['recorded_provider' => $payload['model_version']],
            '0',
            'RUB',
        );
    }
}
