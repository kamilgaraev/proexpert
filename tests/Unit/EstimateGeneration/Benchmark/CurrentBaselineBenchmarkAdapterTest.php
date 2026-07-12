<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkManifest;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPredictionCaseData;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\CurrentBaselineBenchmarkAdapter;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\LocalBenchmarkObjectReader;
use App\BusinessModules\Addons\EstimateGeneration\Services\Documents\DrawingGeometryAnalyzer;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\PdfParserRuntime;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\PdfTextLayerExtractor;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CurrentBaselineBenchmarkAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $container = new Container;
        $container->instance('config', new Repository([
            'estimate-generation' => ['ocr' => ['pdf_text_layer_min_chars' => 1, 'pdf_parser_memory_limit' => '128M']],
        ]));
        $container->bind('db', static fn (): never => throw new \LogicException('Database access forbidden.'));
        Container::setInstance($container);
        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        parent::tearDown();
    }

    #[Test]
    public function real_current_baseline_analyzes_vector_pdf_without_expected_data_or_database(): void
    {
        $root = dirname(__DIR__, 3).'/Fixtures/EstimateGeneration/benchmarks';
        $case = BenchmarkManifest::fromFile($root.'/manifest.json', $root)->case('dev-vector-pdf-001');
        $adapter = new CurrentBaselineBenchmarkAdapter(
            new LocalBenchmarkObjectReader($root),
            new PdfTextLayerExtractor(new PdfParserRuntime),
            new DrawingGeometryAnalyzer,
        );

        $result = $adapter->run(BenchmarkPredictionCaseData::fromCase($case), 10_000);

        self::assertSame('technical_failure', $result->status);
        self::assertSame('current-baseline', $adapter->id());
        self::assertSame('normalized_building_model_required', $result->failureCode);
    }

    #[Test]
    public function unsupported_source_returns_typed_unsupported_without_fallback(): void
    {
        $root = dirname(__DIR__, 3).'/Fixtures/EstimateGeneration/benchmarks';
        $case = BenchmarkManifest::fromFile($root.'/manifest.json', $root)->case('reg-dxf-001');
        $adapter = new CurrentBaselineBenchmarkAdapter(
            new LocalBenchmarkObjectReader($root),
            new PdfTextLayerExtractor(new PdfParserRuntime),
            new DrawingGeometryAnalyzer,
        );

        $result = $adapter->run(BenchmarkPredictionCaseData::fromCase($case), 10_000);

        self::assertSame('unsupported', $result->status);
        self::assertSame('source_type_unsupported', $result->failureCode);
    }
}
