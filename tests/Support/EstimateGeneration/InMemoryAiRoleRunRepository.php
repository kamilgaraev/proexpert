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

    /** @var array<string, AiRoleRunResult> */
    private array $results = [];

    /** @var array<int, string> */
    private array $identities = [];

    public function claim(AiRoleRunInput $input, string $ownerUuid): AiRoleRunClaim
    {
        $this->inputs[] = $input;
        $identity = $input->identityFingerprint();
        $runId = count($this->identities) + 1;
        $this->identities[$runId] = $identity;

        return ! isset($this->results[$identity])
            ? new AiRoleRunClaim($runId, 'owned', $ownerUuid)
            : new AiRoleRunClaim($runId, 'replay', result: $this->results[$identity]);
    }

    public function startPhysicalAttempt(int $runId, string $ownerUuid, string $physicalAttemptId): void {}

    public function complete(int $runId, string $ownerUuid, AiRoleRunResult $result): void
    {
        $this->results[$this->identities[$runId]] = $result;
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
