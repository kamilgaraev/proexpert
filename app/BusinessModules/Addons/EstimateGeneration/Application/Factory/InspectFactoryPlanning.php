<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Factory;

use App\Models\User;

final readonly class InspectFactoryPlanning
{
    public function __construct(private FactoryInspectionStore $store) {}

    public function handle(User $actor, int $projectId, int $sessionId, int $expectedVersion, string $requestId, string $sourceCheckpointId): array
    {
        $claim = $this->store->claim($actor, $projectId, $sessionId, $expectedVersion, $requestId, 'inspect_plan', $sourceCheckpointId);
        if (is_array($claim)) {
            return $claim;
        }
        $this->store->completePlanning($actor, $claim);

        return $this->store->current($actor, $projectId, $sessionId, $requestId);
    }
}
