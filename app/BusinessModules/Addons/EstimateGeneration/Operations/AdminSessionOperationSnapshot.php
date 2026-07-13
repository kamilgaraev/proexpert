<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Operations;

final readonly class AdminSessionOperationSnapshot
{
    public function __construct(
        public int $sessionId,
        public int $organizationId,
        public int $projectId,
        public int $stateVersion,
        public string $status,
        public ?string $resumeStatus,
        public bool $hasRecoverableFailure,
    ) {}
}
