<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Factory;

use App\Models\User;

final readonly class InspectFactoryInputs
{
    public function __construct(private FactoryInspectionStore $store, private FactoryInputInspector $inspector) {}

    public function handle(User $actor, int $projectId, int $sessionId, int $stateVersion, string $requestId): array
    {
        $claim = $this->store->claim($actor, $projectId, $sessionId, $stateVersion, $requestId);
        if (is_array($claim)) {
            return $claim;
        }
        $result = $this->inspector->inspect($claim->session, $claim->snapshot, $claim->modelToken);
        $this->store->complete($claim, $result);

        return $this->store->current($actor, $projectId, $sessionId, $requestId);
    }
}
