<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Understanding;

use App\BusinessModules\Addons\EstimateGeneration\Observability\AttemptAwareNormativeLlmClient;

final readonly class AttemptAwareCrossDocumentFactArbitratorFactory implements CrossDocumentFactArbitratorFactory
{
    public function __construct(private AttemptAwareNormativeLlmClient $client) {}

    public function create(
        int $organizationId,
        int $projectId,
        int $sessionId,
        string $checkpointClaimToken,
        int $logicalAttempt,
    ): CrossDocumentFactArbitrator {
        return new AttemptAwareCrossDocumentFactArbitrator(
            $this->client,
            $organizationId,
            $projectId,
            $sessionId,
            $checkpointClaimToken,
            $logicalAttempt,
        );
    }
}
