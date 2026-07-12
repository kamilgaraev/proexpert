<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO;

final readonly class NormativeCandidateDecisionContextData
{
    /** @param list<string> $sourceEvidence */
    public function __construct(
        public int $organizationId,
        public int $projectId,
        public int $sessionId,
        public string $workItemId,
        public string $checkpointClaimToken,
        public string $inputVersion,
        public int $logicalAttempt,
        public string $promptVersion,
        public string $schemaVersion,
        public string $modelVersion,
        public array $sourceEvidence,
    ) {
        EvidenceBounds::assert($sourceEvidence);
    }
}
