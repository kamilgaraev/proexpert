<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use InvalidArgumentException;

final readonly class FinalizationDeliveryReceipt
{
    public function __construct(
        public int $organizationId,
        public int $projectId,
        public int $sessionId,
        public string $generationAttemptId,
        public string $eventType,
        public int $recipientId,
        public string $businessKey,
    ) {
        if ($organizationId < 1 || $projectId < 1 || $sessionId < 1 || $recipientId < 1
            || preg_match('/\A[0-9a-f-]{36}\z/', $generationAttemptId) !== 1
            || preg_match('/\A[a-z][a-z0-9_]{0,79}\z/', $eventType) !== 1
            || preg_match('/\A[0-9a-f]{64}\z/', $businessKey) !== 1) {
            throw new InvalidArgumentException('Finalization delivery receipt identity is invalid.');
        }
    }
}
