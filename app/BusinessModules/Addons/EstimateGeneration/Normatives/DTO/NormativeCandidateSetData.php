<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO;

final readonly class NormativeCandidateSetData
{
    /** @param list<NormativeCandidateData> $candidates @param list<RejectedNormativeCandidateData> $rejected */
    public function __construct(
        public int $organizationId,
        public int $projectId,
        public int $sessionId,
        public string $workItemId,
        public string $datasetVersion,
        public string $lexicalAlgorithmVersion,
        public ?string $semanticIndexVersion,
        public array $candidates,
        public array $rejected = [],
        public string $status = 'retrieval_only',
        public array $blockingIssues = [],
        public string $scoringVersion = \App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeScoring::VERSION,
    ) {}

    public function hash(): string
    {
        return hash('sha256', json_encode(array_map(static fn (NormativeCandidateData $candidate): array => $candidate->toArray(), $this->candidates), JSON_THROW_ON_ERROR));
    }
}
