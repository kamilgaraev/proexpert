<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Presentation;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;

final readonly class ProjectModelReadProjection
{
    public function __construct(private ProjectModelRepository $models) {}

    public function forScope(int $organizationId, int $projectId, int $sessionId): array
    {
        $snapshot = $this->models->snapshot($organizationId, $projectId, $sessionId);
        $effective = [];
        foreach ($snapshot->facts as $fact) {
            if (! $fact instanceof Fact || $fact->status !== 'confirmed') {
                continue;
            }
            $effective[] = [
                'entity_stable_key' => $fact->entityId,
                'assertion_stable_key' => $fact->id,
                'assertion_type' => $fact->type,
                'value' => $fact->unit === null
                    ? ['value' => $fact->value]
                    : ['value' => $fact->value, 'unit' => $fact->unit],
                'version' => $fact->version,
            ];
        }

        return [
            'facts' => $snapshot->facts,
            'conflicts' => $snapshot->conflicts,
            'effective_values' => $effective,
        ];
    }
}
