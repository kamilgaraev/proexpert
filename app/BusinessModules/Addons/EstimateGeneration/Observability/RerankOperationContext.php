<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

use InvalidArgumentException;

final readonly class RerankOperationContext
{
    public function __construct(
        public int $organizationId,
        public int $projectId,
        public int $sessionId,
        public string $checkpointClaimToken,
        public string $inputVersion,
        public string $workItemKey,
        public int $logicalAttempt,
    ) {
        if (min($organizationId, $projectId, $sessionId, $logicalAttempt) < 1
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $checkpointClaimToken) !== 1
            || preg_match('/^[A-Za-z0-9:._-]{1,80}$/', $inputVersion) !== 1
            || preg_match('/^[A-Za-z0-9:._-]{1,120}$/', $workItemKey) !== 1) {
            throw new InvalidArgumentException('Invalid reranker operation context.');
        }
    }

    /** @param array<string, mixed> $context */
    public static function fromArray(array $context): self
    {
        return new self((int) ($context['organization_id'] ?? 0), (int) ($context['project_id'] ?? 0),
            (int) ($context['session_id'] ?? 0), (string) ($context['checkpoint_claim_token'] ?? ''),
            (string) ($context['input_version'] ?? ''), (string) ($context['work_item_key'] ?? ''),
            (int) ($context['logical_attempt'] ?? 0));
    }
}
