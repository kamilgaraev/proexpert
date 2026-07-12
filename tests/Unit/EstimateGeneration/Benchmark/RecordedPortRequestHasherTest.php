<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\RecordedPortRequestHasher;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\RecordedPortEnvelopeException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RecordedPortRequestHasherTest extends TestCase
{
    #[Test]
    public function planner_quantity_and_candidate_order_are_hash_dependencies(): void
    {
        $planner = RecordedPortRequestHasher::planner(
            ['model_version' => 'v1'],
            ['quantities' => [['key' => 'floor_area', 'amount' => '12']]],
            ['vector:R1' => 2],
        );
        self::assertNotSame($planner, RecordedPortRequestHasher::planner(
            ['model_version' => 'v1'],
            ['quantities' => [['key' => 'floor_area', 'amount' => '13']]],
            ['vector:R1' => 2],
        ));

        self::assertNotSame(
            RecordedPortRequestHasher::rerankerFixture(['candidate-a', 'candidate-b'], ['work_item_id' => 'work-1']),
            RecordedPortRequestHasher::rerankerFixture(['candidate-b', 'candidate-a'], ['work_item_id' => 'work-1']),
        );

        $this->expectException(RecordedPortEnvelopeException::class);
        RecordedPortRequestHasher::verify($planner, RecordedPortRequestHasher::planner(
            ['model_version' => 'v1'],
            ['quantities' => [['key' => 'floor_area', 'amount' => '13']]],
            ['vector:R1' => 2],
        ), 'recorded_planner_request_dependency_invalid');
    }

    #[Test]
    public function changed_candidate_order_is_rejected(): void
    {
        $recorded = RecordedPortRequestHasher::rerankerFixture(['candidate-a', 'candidate-b'], ['work_item_id' => 'work-1']);

        $this->expectException(RecordedPortEnvelopeException::class);
        RecordedPortRequestHasher::verify($recorded,
            RecordedPortRequestHasher::rerankerFixture(['candidate-b', 'candidate-a'], ['work_item_id' => 'work-1']),
            'recorded_normative_reranker_dependency_invalid');
    }
}
