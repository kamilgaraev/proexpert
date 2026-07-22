<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use Closure;

interface TargetedPackageCommitStore
{
    public function withinLockedSession(int $sessionId, int $organizationId, int $projectId, Closure $callback): mixed;

    /** @return array<string, mixed>|null */
    public function operation(EstimateGenerationSession $session, string $operationId): ?array;

    /** @param array<string, mixed> $payload */
    public function recordOperation(EstimateGenerationSession $session, array $payload): void;
}
