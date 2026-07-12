<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeCandidateDecisionContextData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeCandidateSetData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeRerankResultData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\WorkIntentData;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\Reranking\NormativeCandidateRerankerInterface;

final readonly class RecordedNormativeCandidateReranker implements NormativeCandidateRerankerInterface
{
    public function __construct(private RecordedPortEnvelope $envelope) {}

    public function rerank(WorkIntentData $workItem, NormativeCandidateDecisionContextData $context, NormativeCandidateSetData $candidateSet): NormativeRerankResultData
    {
        if ($this->envelope->port !== RecordedPort::NormativeReranker) {
            throw new RecordedPortEnvelopeException('recorded_normative_reranker_port_invalid');
        }
        RecordedPortRequestHasher::verify($this->envelope->inputDependencySha256,
            RecordedPortRequestHasher::reranker($workItem, $context, $candidateSet),
            'recorded_normative_reranker_dependency_invalid');
        $ids = array_map(static fn ($candidate): string => $candidate->id, $candidateSet->candidates);
        $evidence = array_values(array_unique(array_merge($workItem->sourceEvidence, ...array_map(
            static fn ($candidate): array => $candidate->sourceEvidence,
            $candidateSet->candidates,
        ))));

        return NormativeRerankResultData::fromProviderArray($this->envelope->payload, $ids, $evidence, 'recorded-replay');
    }
}
