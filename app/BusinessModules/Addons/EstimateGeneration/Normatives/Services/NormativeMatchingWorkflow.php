<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeCandidateDecisionContextData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeWorkflowResultData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\WorkIntentData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Exceptions\NormativeRerankingUnavailable;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\Reranking\NormativeCandidateRerankerInterface;

readonly class NormativeMatchingWorkflow
{
    public function __construct(private NormativeRetrievalService $retrieval, private NormativeCandidateRerankerInterface $reranker) {}

    /** @param list<\App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeCandidateData> $pinnedCandidates */
    public function match(WorkIntentData $intent, NormativeCandidateDecisionContextData $context, bool $rerankRequested, array $pinnedCandidates = []): NormativeWorkflowResultData
    {
        $set = $this->retrieval->retrieve($intent, $pinnedCandidates);
        if ($set->candidates === []) {
            return new NormativeWorkflowResultData('review_required', $set, null, ['normative_not_found']);
        }
        if (! $rerankRequested) {
            return new NormativeWorkflowResultData('retrieval_only', $set, null, []);
        }
        try {
            $reranked = $this->reranker->rerank($intent, $context, $set);
        } catch (NormativeRerankingUnavailable) {
            return new NormativeWorkflowResultData('unavailable', $set, null, ['normative_reranking_unavailable']);
        }
        if ($reranked->status === 'requires_review') {
            return new NormativeWorkflowResultData(
                'review_required',
                $set,
                $reranked,
                ['normative_match_low_confidence'],
            );
        }

        return new NormativeWorkflowResultData('reranked', $set, $reranked, []);
    }
}
