<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkDatasetType;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPredictionCaseData;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\ProductionReplayBenchmarkAdapter;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\RecordedBenchmarkCatalogData;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\RecordedCatalogNormativeCandidateSource;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class ProductionReplayBenchmarkAdapterTest extends TestCase
{
    #[Test]
    public function production_replay_adapter_has_a_stable_registered_identity(): void
    {
        self::assertSame('production-replay', ProductionReplayBenchmarkAdapter::ID);
    }

    #[Test]
    public function immutable_catalog_source_builds_real_candidates_without_expected_fields(): void
    {
        $catalog = RecordedBenchmarkCatalogData::fromArray([
            'schema_version' => 'recorded-benchmark-catalog:v1', 'dataset_id' => 77,
            'dataset_version' => 'fsnb-2026.1', 'dataset_status' => 'parsed', 'region_code' => '77',
            'price_period' => 'prices-2026.07', 'currency' => 'RUB',
            'candidates' => [[
                'candidate_id' => 'candidate-101', 'normative_id' => 101, 'dataset_id' => 77,
                'dataset_version' => 'fsnb-2026.1', 'dataset_status' => 'parsed', 'code' => '10-01-001-01',
                'name' => 'Монтаж стены', 'unit' => 'm2', 'unit_dimension' => 'area', 'material' => 'brick',
                'technology' => 'masonry', 'structure' => 'wall', 'normative_section' => '10-01',
                'object_type' => 'residential', 'region_code' => '77', 'valid_from' => '2026-01-01',
                'lexical_score' => 0.91, 'semantic_score' => 0.93, 'source_evidence' => ['norm:101'],
            ]],
            'resources' => [['candidate_id' => 'candidate-101']],
            'prices' => [['id' => 9001]], 'privacy_scanner' => 'most-fixture-privacy',
            'privacy_scanner_version' => '1.0', 'approval_kind' => 'maintainer_code_review',
            'approval_ref' => 'review:task11', 'approved_at' => '2026-07-12T00:00:00Z',
        ]);

        $candidates = (new RecordedCatalogNormativeCandidateSource($catalog))->find(1, 1, 'fsnb-2026.1', 'wall', 16, null);

        self::assertCount(1, $candidates);
        self::assertSame('candidate-101', $candidates[0]->id);
        self::assertArrayNotHasKey('expected', $candidates[0]->toArray());
    }

    #[Test]
    public function adapter_source_contains_no_catalog_driven_intent_or_fabricated_readiness_and_evidence(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/Benchmark/ProductionReplayBenchmarkAdapter.php');

        self::assertStringNotContainsString('catalog->candidates[0]', $source);
        self::assertStringNotContainsString("'complete' => true", $source);
        self::assertStringNotContainsString('model->evidenceIds', $source);
        self::assertStringContainsString('NormativeWorkIntentFactory', $source);
        self::assertStringContainsString('EstimateValidationService', $source);
    }

    #[Test]
    public function expired_cli_budget_fails_before_any_fixture_or_catalog_access(): void
    {
        $adapter = (new ReflectionClass(ProductionReplayBenchmarkAdapter::class))->newInstanceWithoutConstructor();
        $case = new BenchmarkPredictionCaseData('timeout-case', BenchmarkDatasetType::Regression,
            BenchmarkSourceType::VectorPdf, 'input.pdf', str_repeat('a', 64), [], [], [], []);

        $result = $adapter->run($case, 0);

        self::assertSame('technical_failure', $result->status);
        self::assertSame('case_timeout', $result->failureCode);
    }

    #[Test]
    public function planner_evidence_must_match_exact_calculated_quantity_evidence(): void
    {
        $adapter = (new ReflectionClass(ProductionReplayBenchmarkAdapter::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(ProductionReplayBenchmarkAdapter::class, 'quantityEvidence');

        $quantities = [
            'floor_area' => (object) ['key' => 'floor_area', 'evidenceIds' => ['11']],
            'opening_count' => (object) ['key' => 'opening_count', 'evidenceIds' => ['12']],
        ];
        self::assertSame(['11'], $method->invoke($adapter,
            ['metadata' => ['quantity_key' => 'floor_area', 'quantity_source_refs' => ['11']]], $quantities));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('recorded_planner_quantity_evidence_invalid');
        $method->invoke($adapter,
            ['metadata' => ['quantity_key' => 'floor_area', 'quantity_source_refs' => ['12']]], $quantities);
    }
}
