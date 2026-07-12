<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkDatasetType;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPredictionCaseData;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\RecordedPort;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\EstimateGeneration\RecordedFixtureCaptureBuilder;

final class RecordedFixtureCaptureBuilderTest extends TestCase
{
    #[Test]
    public function capture_is_deterministic_and_source_bound(): void
    {
        $source = 'contentful-source';
        $case = new BenchmarkPredictionCaseData('case-001', BenchmarkDatasetType::Regression,
            BenchmarkSourceType::Dxf, 'case/input.dxf', hash('sha256', $source), [], ['geometry'], [], []);
        $builder = new RecordedFixtureCaptureBuilder;

        $first = $builder->geometryDependency($case, RecordedPort::CadExtraction, $source);
        self::assertSame($first, $builder->geometryDependency($case, RecordedPort::CadExtraction, $source));

        $this->expectException(InvalidArgumentException::class);
        $builder->geometryDependency($case, RecordedPort::CadExtraction, $source.'changed');
    }

    #[Test]
    public function capture_rejects_expected_or_prediction_oracles(): void
    {
        $builder = new RecordedFixtureCaptureBuilder;
        $this->expectException(InvalidArgumentException::class);
        $builder->envelope([
            'source_sha256' => str_repeat('a', 64), 'privacy_result' => 'passed',
            'approval_kind' => 'maintainer_code_review',
        ], ['expected_total' => '1'], str_repeat('b', 64), str_repeat('a', 64));
    }

    #[Test]
    public function inventory_is_stable_and_reviewable(): void
    {
        $inventory = (new RecordedFixtureCaptureBuilder)->inventory(['case-b', 'case-a'], [str_repeat('b', 64), str_repeat('a', 64)]);
        self::assertSame(['case-a', 'case-b'], array_column($inventory['cases'], 'case_id'));
    }
}
