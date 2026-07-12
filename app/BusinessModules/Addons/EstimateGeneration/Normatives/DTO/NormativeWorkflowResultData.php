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
        return $this->rerankResult?->selectedCandidateId ?? $this->candidateSet->candidates[0]->id ?? null;
    }
}
