<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\NormalizedBuildingModelData;

final class BuildingModelMaterialEvidenceExtractor
{
    /**
     * @param  array<string, mixed>  $analysis
     * @return list<array<string, mixed>>
     */
    public function extract(array $analysis, string $workItemKey): array
    {
        if (! in_array($workItemKey, ['walls.external_volume', 'walls.internal'], true)) {
            return [];
        }

        $model = $analysis['normalized_building_model'] ?? null;
        if ($model instanceof NormalizedBuildingModelData) {
            $model = $model->toArray();
        }
        if (! is_array($model) || ! is_array($model['floors'] ?? null)) {
            return [];
        }

        $result = [];
        foreach ($model['floors'] as $floor) {
            if (! is_array($floor) || ! is_array($floor['walls'] ?? null)) {
                continue;
            }
            foreach ($floor['walls'] as $wall) {
                if (! is_array($wall) || ! $this->matchesWorkItem($wall, $workItemKey)) {
                    continue;
                }
                $material = trim((string) ($wall['material'] ?? ''));
                $evidenceRefs = is_array($wall['evidence_ids'] ?? null)
                    ? array_values(array_unique(array_filter(
                        array_map(static fn (mixed $id): string => trim((string) $id), $wall['evidence_ids']),
                        static fn (string $id): bool => $id !== '',
                    )))
                    : [];
                if ($material === '' || $evidenceRefs === []) {
                    continue;
                }

                $external = $workItemKey === 'walls.external_volume';
                $result[] = [
                    'text' => ($external ? 'Материал наружной стены: ' : 'Материал внутренней стены или перегородки: ').$material,
                    'source' => 'building_model',
                    'evidence_refs' => $evidenceRefs,
                    'normative_search_text' => ($external ? 'кладка наружных стен из ' : 'кладка внутренних стен и перегородок из ').$material,
                ];
            }
        }

        return array_slice($result, 0, 32);
    }

    /** @param array<string, mixed> $wall */
    private function matchesWorkItem(array $wall, string $workItemKey): bool
    {
        $type = mb_strtolower(trim((string) ($wall['type'] ?? $wall['kind'] ?? '')));
        $external = $wall['external'] ?? null;

        return $workItemKey === 'walls.external_volume'
            ? $external === true || in_array($type, ['external', 'exterior', 'наружная', 'наружный'], true)
            : $external === false || in_array($type, ['internal', 'interior', 'partition', 'внутренняя', 'внутренний', 'перегородка'], true);
    }
}
