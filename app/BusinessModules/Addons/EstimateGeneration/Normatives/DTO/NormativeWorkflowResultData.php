<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO;

final readonly class NormativeWorkflowResultData
{
    public function __construct(
        public string $status,
        public NormativeCandidateSetData $candidateSet,
        public ?NormativeRerankResultData $rerankResult,
        public array $blockingIssues,
    ) {}

    public function selectedCandidateId(): ?string
    {
        if ($this->status === 'reranked') {
            return $this->rerankResult?->selectedCandidateId;
        }

        return $this->status === 'retrieval_only' ? ($this->candidateSet->candidates[0]->id ?? null) : null;
    }
}
