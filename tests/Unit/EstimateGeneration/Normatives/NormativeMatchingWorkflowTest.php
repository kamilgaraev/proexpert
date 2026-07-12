<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeCandidateData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeCandidateDecisionContextData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeCandidateSetData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeRerankResultData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\WorkIntentData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Exceptions\NormativeRerankingUnavailable;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeCandidateSource;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeHardGate;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeMatchingWorkflow;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeRetrievalService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\Reranking\NormativeCandidateRerankerInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NormativeMatchingWorkflowTest extends TestCase
{
    #[DataProvider('statuses')]
    public function test_all_workflow_statuses_without_fallback(string $mode, bool $requested, string $expected, int $expectedCalls): void
    {
        $calls = 0;
        $candidate = $this->candidate();
        $source = new class($mode === 'empty' ? [] : [$candidate]) implements NormativeCandidateSource
        {
            public function __construct(private array $candidates) {}

            public function find(int $organizationId, int $projectId, string $datasetVersion, string $query, int $limit, ?string $semanticIndexVersion): array
            {
                return $this->candidates;
            }
        };
        $reranker = new class($mode, $calls) implements NormativeCandidateRerankerInterface
        {
            public function __construct(private string $mode, private int &$calls) {}

            public function rerank(WorkIntentData $workItem, NormativeCandidateDecisionContextData $context, NormativeCandidateSetData $candidateSet): NormativeRerankResultData
            {
                $this->calls++;
                if ($this->mode === 'unavailable') {
                    throw new NormativeRerankingUnavailable;
                }

                return new NormativeRerankResultData('1', ['1'], ['unit_match'], ['norm:1'], 0.8, 'reranked', 'normative-rerank-v1', 'fake');
            }
        };
        $workflow = new NormativeMatchingWorkflow(new NormativeRetrievalService($source, new NormativeHardGate, 16, null), $reranker);

        $result = $workflow->match($this->intent(), $this->context(), $requested);

        self::assertSame($expected, $result->status);
        self::assertSame($expectedCalls, $calls);
    }

    public static function statuses(): array
    {
        return [['empty', true, 'review_required', 0], ['ok', false, 'retrieval_only', 0], ['ok', true, 'reranked', 1], ['unavailable', true, 'unavailable', 1]];
    }

    private function intent(): WorkIntentData
    {
        return new WorkIntentData(1, 2, 3, 'w', 'кладка', 'м2', 'area', 'кирпич', 'кладка', 'стена', '08', 'жилой', 'v1', 'parsed', '78', new DateTimeImmutable('2026-01-01'), ['doc:1']);
    }

    private function context(): NormativeCandidateDecisionContextData
    {
        return new NormativeCandidateDecisionContextData(1, 2, 3, 'w', '018f47a2-4e5c-7d9a-8b1c-2d3e4f5a6b7c', 'sha256:abc', 1, 'p1', 'normative-rerank-v1', 'm1', ['doc:1']);
    }

    private function candidate(): NormativeCandidateData
    {
        return new NormativeCandidateData('1', 1, 1, 'v1', 'parsed', '08', 'Кладка', 'м2', 'area', 'кирпич', 'кладка', 'стена', '08', 'жилой', '78', new DateTimeImmutable('2025-01-01'), null, 0.8, null, 'lex-v1', null, ['norm:1']);
    }
}
