<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkManifest;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPredictionCaseData;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class BenchmarkPredictionCaseDataTest extends TestCase
{
    #[Test]
    public function prediction_projection_exposes_no_expected_or_fixture_root_state(): void
    {
        $root = dirname(__DIR__, 3).'/Fixtures/EstimateGeneration/benchmarks';
        $case = BenchmarkManifest::fromFile($root.'/manifest.json', $root)->case('reg-dxf-001');

        $projection = BenchmarkPredictionCaseData::fromCase($case);
        $serialized = serialize($projection);
        $properties = array_map(
            static fn (\ReflectionProperty $property): string => $property->getName(),
            (new ReflectionClass($projection))->getProperties(),
        );

        self::assertSame([
            'id', 'dataset', 'sourceType', 'inputLocator', 'inputSha256', 'tags',
            'allowedCapabilities', 'recordedEnvelopeReferences', 'recordedEnvelopeSha256',
        ], $properties);
        self::assertStringNotContainsString('expected', strtolower($serialized));
        self::assertStringNotContainsString(strtolower($root), strtolower($serialized));
        self::assertStringNotContainsString($case->expectedSha256, $serialized);
        self::assertStringNotContainsString($case->expectedLocator, $serialized);
    }

    #[Test]
    public function projection_rejects_traversal_in_input_and_recorded_envelope_locators(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new BenchmarkPredictionCaseData(
            'case-001',
            \App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkDatasetType::Regression,
            \App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkSourceType::Dxf,
            '../expected.json',
            str_repeat('a', 64),
            ['geometry'],
            ['cad_geometry'],
            ['vision_extraction' => '../expected.json'],
            ['vision_extraction' => str_repeat('b', 64)],
        );
    }
}
