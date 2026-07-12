<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeCandidateData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeCandidateDecisionContextData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeCandidateSetData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeRerankResultData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\WorkIntentData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeCandidateSource;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeHardGate;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeMatchingWorkflow;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeRetrievalService;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\Reranking\NormativeCandidateRerankerInterface;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class NormativePinHashAndMatchTest extends TestCase
{
    public function test_pin_changes_stage_identity_and_nonempty_real_workflow_returns_retrieval_only(): void
    {
        $base = ['local_estimates' => [['sections' => [['work_items' => [['id' => 'w']]]]]], 'normative_context_pin' => ['status' => 'pinned', 'dataset_version' => 'v1', 'applicability_date' => '2026-01-01']];
        $changed = $base;
        $changed['normative_context_pin']['dataset_version'] = 'v2';
        self::assertNotSame(hash('sha256', CanonicalPipelineJson::encode($base)), hash('sha256', CanonicalPipelineJson::encode($changed)));

        $candidate = new NormativeCandidateData('1', 1, 1, 'v1', 'parsed', '08', 'Кладка', 'м2', 'area', 'кирпич', 'кладка', 'стена', '08', 'жилой', null, new DateTimeImmutable('2025-01-01'), null, 0.8, null, 'lex-v1', null, ['norm:1']);
        $source = new class($candidate) implements NormativeCandidateSource
        {
            public function __construct(private NormativeCandidateData $candidate) {}

            public function find(int $organizationId, int $projectId, string $datasetVersion, string $query, int $limit, ?string $semanticIndexVersion): array
            {
                return [$this->candidate];
            }
        };
        $reranker = new class implements NormativeCandidateRerankerInterface
        {
            public function rerank(WorkIntentData $workItem, NormativeCandidateDecisionContextData $context, NormativeCandidateSetData $candidateSet): NormativeRerankResultData
            {
                throw new \LogicException('Retrieval-only workflow must not rerank.');
            }
        };
        $workflow = new NormativeMatchingWorkflow(new NormativeRetrievalService($source, new NormativeHardGate, 16, null), $reranker);
        $intent = new WorkIntentData(1, 2, 3, 'w', 'кладка', 'м2', 'area', 'кирпич', 'кладка', 'стена', '08', 'жилой', 'v1', 'parsed', null, new DateTimeImmutable('2026-01-01'), ['norm:1']);
        $context = new NormativeCandidateDecisionContextData(1, 2, 3, 'w', '018f47a2-4e5c-7d9a-8b1c-2d3e4f5a6b7c', 'sha256:abc', 1, 'p1', 'normative-rerank-v1', 'models:v1', ['norm:1']);

        $result = $workflow->match($intent, $context, false);
        self::assertSame('retrieval_only', $result->status);
        self::assertSame('1', $result->selectedCandidateId());
    }
}
