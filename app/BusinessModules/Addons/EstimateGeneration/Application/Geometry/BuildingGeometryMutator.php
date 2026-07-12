<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Geometry;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\NormalizedBuildingModelData;
use InvalidArgumentException;

final class BuildingGeometryMutator
{
    public function mutate(array $model, GeometryConfirmationCommand $command, ?int $evidenceId = null): NormalizedBuildingModelData
    {
        foreach ($command->operations as $operation) {
            $floorIndex = $this->find($model['floors'], $operation['floor_key']);
            if ($operation['collection'] === 'floors') {
                $model['floors'][$floorIndex]['height_m'] = $operation['value'];
                $model['floors'][$floorIndex]['geometry_certainty'] = 'confirmed';
                $this->addEvidence($model['floors'][$floorIndex], $evidenceId);

                continue;
            }
            $elementIndex = $this->find($model['floors'][$floorIndex][$operation['collection']], $operation['element_key']);
            $element = &$model['floors'][$floorIndex][$operation['collection']][$elementIndex];
            $element[$operation['field']] = $operation['value'];
            $element['geometry_certainty'] = 'confirmed';
            $this->addEvidence($element, $evidenceId);
            unset($element);
        }
        if ($command->scale !== null) {
            [$x1, $y1] = $command->scale['pixel_start'];
            [$x2, $y2] = $command->scale['pixel_end'];
            $model['scale_status'] = 'confirmed';
            $model['scale_meters_per_unit'] = (float) $command->scale['meters'] / hypot((float) $x2 - (float) $x1, (float) $y2 - (float) $y1);
            $model['assumptions'] = array_values(array_filter($model['assumptions'], static fn (array $assumption): bool => ! in_array($assumption['code'], ['scale_estimated', 'scale_missing', 'scale_conflict'], true)));
            foreach ($model['floors'] as &$floor) {
                $this->addEvidence($floor, $evidenceId);
            }
            unset($floor);
        }
        if ($evidenceId !== null) {
            $model['evidence_ids'] = array_values(array_unique([...$model['evidence_ids'], $evidenceId]));
            sort($model['evidence_ids'], SORT_NUMERIC);
            $model['metrics']['evidence_count'] = count($model['evidence_ids']);
        }
        $model['metrics'] = NormalizedBuildingModelData::fromArray($model)->metrics;

        return NormalizedBuildingModelData::fromArray($model);
    }

    private function addEvidence(array &$item, ?int $evidenceId): void
    {
        if ($evidenceId !== null) {
            $item['evidence_ids'] = array_values(array_unique([...$item['evidence_ids'], $evidenceId]));
        }
    }

    private function find(array $items, string $key): int
    {
        foreach ($items as $index => $item) {
            if (($item['key'] ?? null) === $key) {
                return $index;
            }
        }
        throw new InvalidArgumentException('Geometry element does not belong to the locked model.');
    }
}
