<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\RecordedPortRequestHasher;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\RecordedPortEnvelopeException;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeCandidateData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeCandidateDecisionContextData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeCandidateSetData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\WorkIntentData;
use DateTimeImmutable;
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

    #[Test]
    public function every_typed_reranker_request_field_is_hash_bound(): void
    {
        [$intent, $context, $set] = $this->request();
        $baseline = RecordedPortRequestHasher::reranker($intent, $context, $set);
        foreach ([
            $this->request(semanticScore: 0.71),
            $this->request(material: 'brick'),
            $this->request(candidateEvidence: ['catalog:changed']),
            $this->request(region: '78'),
            $this->request(applicability: '2026-07-13'),
            $this->request(contextSchema: 'normative-rerank-v2'),
            $this->request(setStatus: 'review_required'),
            $this->request(blockingIssues: ['manual_review']),
            $this->request(reverse: true),
        ] as [$changedIntent, $changedContext, $changedSet]) {
            self::assertNotSame($baseline, RecordedPortRequestHasher::reranker($changedIntent, $changedContext, $changedSet));
        }
    }

    private function request(
        float $semanticScore = 0.7,
        string $material = 'concrete',
        array $candidateEvidence = ['catalog:one'],
        string $region = '77',
        string $applicability = '2026-07-12',
        string $contextSchema = 'normative-rerank-v1',
        string $setStatus = 'retrieval_only',
        array $blockingIssues = [],
        bool $reverse = false,
    ): array {
        $intent = new WorkIntentData(1, 2, 3, 'work-1', 'floor work', 'm2', 'area', 'concrete',
            'floor_covering', 'finishing', '11', 'floor_covering', 'dataset:v1', 'parsed', $region,
            new DateTimeImmutable($applicability), ['quantity:1']);
        $context = new NormativeCandidateDecisionContextData(1, 2, 3, 'work-1', 'claim-token', 'sha256:input',
            1, 'prompt:v1', $contextSchema, 'model:v1', ['quantity:1']);
        $candidate = static fn (string $id, float $score): NormativeCandidateData => new NormativeCandidateData(
            $id, $id === 'candidate-a' ? 10 : 11, 5, 'dataset:v1', 'parsed', '11-01', 'Floor work',
            'm2', 'area', $material, 'floor_covering', 'finishing', '11', 'floor_covering', '77',
            new DateTimeImmutable('2026-01-01'), null, 0.8, $score, 'lexical:v1', 'semantic:v1', $candidateEvidence,
        );
        $candidates = [$candidate('candidate-a', $semanticScore), $candidate('candidate-b', 0.6)];
        if ($reverse) {
            $candidates = array_reverse($candidates);
        }

        return [$intent, $context, new NormativeCandidateSetData(1, 2, 3, 'work-1', 'dataset:v1', 'lexical:v1',
            'semantic:v1', $candidates, [], $setStatus, $blockingIssues, 'scoring:v1')];
    }
}
