<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Operations;

final readonly class AdminFailureResolutionSnapshot
{
    public function __construct(
        public string $failureId,
        public int $organizationId,
        public int $projectId,
        public int $sessionId,
        public int $latestOccurrenceSequence,
        public bool $hasActiveOccurrence,
    ) {}
}
