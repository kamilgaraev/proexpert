<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunClaim;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunFailure;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunResult;

interface AiRoleRunRepository
{
    public function claim(AiRoleRunInput $input, string $ownerUuid): AiRoleRunClaim;

    public function startPhysicalAttempt(int $runId, string $ownerUuid, string $physicalAttemptId): void;

    public function complete(int $runId, string $ownerUuid, AiRoleRunResult $result): void;

    public function fail(int $runId, string $ownerUuid, AiRoleRunFailure $failure): void;

    public function loadCurrent(AiRoleRunInput $input): ?AiRoleRunClaim;
}
