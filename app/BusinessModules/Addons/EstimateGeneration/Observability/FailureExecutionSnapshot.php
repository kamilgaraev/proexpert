<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use Illuminate\Support\Str;

final readonly class FailureExecutionSnapshot
{
    public function __construct(
        public int $organizationId,
        public int $projectId,
        public int $sessionId,
        public int $stateVersion,
        public string $status,
        public string $attemptId,
        public string $eventId,
        public string $correlationId,
    ) {}

    public static function capture(EstimateGenerationSession $session, string $operation, ?string $attemptId = null): self
    {
        $attemptId ??= (string) Str::uuid();

        return new self(
            organizationId: (int) $session->organization_id,
            projectId: (int) $session->project_id,
            sessionId: (int) $session->getKey(),
            stateVersion: (int) $session->state_version,
            status: $session->status->value,
            attemptId: $attemptId,
            eventId: (string) Str::uuid(),
            correlationId: AiOperationContext::deterministicId($operation.'|'.$session->getKey().'|'.$attemptId),
        );
    }
}
