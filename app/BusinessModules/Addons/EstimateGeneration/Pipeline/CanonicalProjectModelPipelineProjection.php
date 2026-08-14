<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;

final readonly class CanonicalProjectModelPipelineProjection
{
    private const MAX_FACTS = 10_000;

    public function __construct(private ProjectModelRepository $models) {}

    /** @return array{token:string,effective_values:list<array<string, mixed>>} */
    public function forScope(int $organizationId, int $projectId, int $sessionId): array
    {
        $projection = $this->models->snapshotForUnderstanding(
            $organizationId,
            $projectId,
            $sessionId,
            self::MAX_FACTS,
        );
        $effectiveValues = [];
        foreach ($projection['snapshot']->facts as $fact) {
            if (! $fact instanceof Fact || $fact->status !== 'confirmed') {
                continue;
            }
            $effectiveValues[] = [
                'entity_stable_key' => $fact->entityId,
                'assertion_stable_key' => $fact->id,
                'assertion_type' => $fact->type,
                'value' => $fact->unit === null
                    ? ['value' => $fact->value]
                    : ['value' => $fact->value, 'unit' => $fact->unit],
                'version' => $fact->version,
                'source_version' => $fact->sourceVersion,
            ];
        }

        return [
            'token' => $projection['token'],
            'effective_values' => $effectiveValues,
        ];
    }
}
