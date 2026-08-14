<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunClaim;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunFailure;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunResult;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Role\AiAnalysisRole;

interface AiRoleRunRepository
{
    public function claim(AiRoleRunInput $input, string $ownerUuid): AiRoleRunClaim;

    public function startPhysicalAttempt(int $runId, string $ownerUuid, string $physicalAttemptId): void;

    public function complete(int $runId, string $ownerUuid, AiRoleRunResult $result): void;

    public function fail(int $runId, string $ownerUuid, AiRoleRunFailure $failure): void;

    public function loadCurrent(AiRoleRunInput $input): ?AiRoleRunClaim;

    /** @param list<AiAnalysisRole> $roles @param list<string> $sourceVersions @return array<string, list<string>> */
    public function completedFingerprints(
        int $organizationId,
        int $projectId,
        int $sessionId,
        array $roles,
        array $sourceVersions,
    ): array;
}
