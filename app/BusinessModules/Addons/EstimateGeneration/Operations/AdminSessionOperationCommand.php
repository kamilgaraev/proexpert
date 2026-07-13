<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Operations;

final readonly class AdminSessionOperationCommand
{
    public function __construct(
        public int $actorId,
        public int $sessionId,
        public int $organizationId,
        public int $projectId,
        public int $expectedStateVersion,
        public AdminSessionOperation $operation,
        public string $idempotencyKey,
    ) {}
}
