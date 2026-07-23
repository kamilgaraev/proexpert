<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Quality\Arbiter;

use InvalidArgumentException;

final readonly class ArbiterOperationContext
{
    public function __construct(
        public int $organizationId,
        public int $projectId,
        public int $sessionId,
        public string $checkpointClaimToken,
        public string $inputVersion,
        public int $attemptOrdinal,
    ) {
        if (min($organizationId, $projectId, $sessionId, $attemptOrdinal) < 1
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $checkpointClaimToken) !== 1
            || preg_match('/^[A-Za-z0-9:._-]{1,80}$/', $inputVersion) !== 1) {
            throw new InvalidArgumentException('Invalid completeness arbiter operation context.');
        }
    }
}
