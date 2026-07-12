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
        $path = dirname(__DIR__, 3).'/Fixtures/EstimateGeneration/benchmarks/catalogs/vector-wall-opening-v1.json';
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $catalog = RecordedBenchmarkCatalogData::fromArray($data);

        $candidates = (new RecordedCatalogNormativeCandidateSource($catalog))->find(
            1,
            1,
            $catalog->datasetVersion,
            'floor_covering',
            16,
            null,
        );

        self::assertCount(2, $candidates);
        $ids = array_map(static fn ($candidate): string => $candidate->id, $candidates);
        sort($ids, SORT_STRING);
        self::assertSame(['vector-floor-cast-b25', 'vector-floor-cast-b30'], $ids);
        foreach ($candidates as $candidate) {
            self::assertArrayNotHasKey('expected', $candidate->toArray());
        }
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
