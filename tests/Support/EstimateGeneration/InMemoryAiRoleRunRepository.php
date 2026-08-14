<?php

declare(strict_types=1);

namespace Tests\Support\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\AiRoleRunRepository;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunClaim;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunFailure;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunResult;

final class InMemoryAiRoleRunRepository implements AiRoleRunRepository
{
    /** @var list<AiRoleRunInput> */
    public array $inputs = [];

    private ?AiRoleRunResult $result = null;

    public function claim(AiRoleRunInput $input, string $ownerUuid): AiRoleRunClaim
    {
        $this->inputs[] = $input;

        return $this->result === null
            ? new AiRoleRunClaim(1, 'owned', $ownerUuid)
            : new AiRoleRunClaim(1, 'replay', result: $this->result);
    }

    public function startPhysicalAttempt(int $runId, string $ownerUuid, string $physicalAttemptId): void {}

    public function complete(int $runId, string $ownerUuid, AiRoleRunResult $result): void
    {
        $this->result = $result;
    }

    public function fail(int $runId, string $ownerUuid, AiRoleRunFailure $failure): void {}

    public function loadCurrent(AiRoleRunInput $input): ?AiRoleRunClaim
    {
        return null;
    }

    public function completedFingerprints(int $organizationId, int $projectId, int $sessionId, array $roles, array $sourceVersions): array
    {
        return [];
    }
}
