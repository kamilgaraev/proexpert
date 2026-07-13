<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Operations;

final readonly class AdminFailureResolutionCommand
{
    public const OPERATION = 'resolve_failure';

    public function __construct(
        public int $actorId,
        public string $failureId,
        public int $organizationId,
        public int $projectId,
        public int $sessionId,
        public int $expectedOccurrenceSequence,
        public string $idempotencyKey,
    ) {}

    public function fingerprint(): string
    {
        $canonical = implode('|', [
            self::OPERATION,
            'organization_id='.$this->organizationId,
            'project_id='.$this->projectId,
            'actor_id='.$this->actorId,
            'failure_id='.strtolower($this->failureId),
            'session_id='.$this->sessionId,
            'expected_occurrence_sequence='.$this->expectedOccurrenceSequence,
        ]);

        return 'sha256:'.hash('sha256', $canonical);
    }
}
