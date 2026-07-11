<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

final readonly class FinalizationEvent
{
    public function __construct(
        public int $organizationId,
        public int $projectId,
        public int $sessionId,
        public string $generationAttemptId,
        public string $type,
        public string $idempotencyKey,
    ) {}

    public static function completed(int $organizationId, int $projectId, int $sessionId, string $generationAttemptId): self
    {
        $type = 'estimate_generation_completed';

        return new self(
            $organizationId,
            $projectId,
            $sessionId,
            $generationAttemptId,
            $type,
            hash('sha256', implode('|', [$organizationId, $projectId, $sessionId, $generationAttemptId, $type])),
        );
    }
}
