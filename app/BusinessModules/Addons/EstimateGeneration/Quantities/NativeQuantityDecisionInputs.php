<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Quantities;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;

final class NativeQuantityDecisionInputs
{
    public static function factIds(ProjectModelSnapshot $snapshot): array
    {
        $unrelated = self::unrelatedFacts($snapshot);

        return array_values(array_filter(array_column($snapshot->facts, 'id'), static fn (string $id): bool => ! isset($unrelated[$id])));
    }

    public static function decisions(ProjectModelSnapshot $snapshot, array $decisions): array
    {
        $unrelated = self::unrelatedFacts($snapshot);

        return array_values(array_filter($decisions, static function ($decision) use ($unrelated): bool {
            if (! $decision instanceof Decision || ! isset($unrelated[$decision->selectedFactId ?? ''])) {
                return true;
            }
            $fact = $unrelated[$decision->selectedFactId];

            return [$decision->organizationId, $decision->projectId, $decision->sessionId, $decision->sourceVersion, $decision->version]
                !== [$fact->organizationId, $fact->projectId, $fact->sessionId, $fact->sourceVersion, $fact->version];
        }));
    }

    private static function unrelatedFacts(ProjectModelSnapshot $snapshot): array
    {
        $unrelated = [];
        $relevant = [];
        foreach ($snapshot->facts as $fact) {
            if (in_array($fact->type, ['entity_identity', 'evaluation_price'], true)) {
                $unrelated[$fact->id] = $fact;
            } else {
                $relevant[$fact->id] = true;
            }
        }

        return array_diff_key($unrelated, $relevant);
    }
}
