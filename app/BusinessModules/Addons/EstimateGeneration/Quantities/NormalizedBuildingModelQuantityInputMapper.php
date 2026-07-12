<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Quantities;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\NormalizedBuildingModelData;
use Brick\Math\BigDecimal;

final class NormalizedBuildingModelQuantityInputMapper implements BuildingModelQuantityInputMapper
{
    /** @return array<string, mixed> */
    public function map(NormalizedBuildingModelData $model): array
    {
        $rooms = [];
        $walls = [];
        $openings = [];
        $engineering = [];
        foreach ($model->floors as $floor) {
            foreach ($floor->rooms as $room) {
                $rooms[] = [
                    'id' => $room->key,
                    'polygon' => $room->polygon === null ? null : array_map(fn (array $point): array => $this->point($point), $room->polygon),
                    'height' => $floor->heightM === null ? null : $this->decimal($floor->heightM),
                    ...$this->provenance($room->evidenceIds, $room->confidence, $room->geometryCertainty),
                ];
            }
            foreach ($floor->walls as $wall) {
                $openingIds = array_values(array_map(static fn ($opening): string => $opening->key, array_filter($floor->openings, static fn ($opening): bool => $opening->wallKey === $wall->key)));
                sort($openingIds, SORT_STRING);
                $walls[] = [
                    'id' => $wall->key,
                    'length' => $wall->length() === null ? null : $this->decimal($wall->length()),
                    'height' => $wall->heightM === null ? ($floor->heightM === null ? null : $this->decimal($floor->heightM)) : $this->decimal($wall->heightM),
                    'opening_ids' => $openingIds,
                    ...$this->provenance($wall->evidenceIds, $wall->confidence, $wall->geometryCertainty),
                ];
            }
            foreach ($floor->openings as $opening) {
                $openings[] = [
                    'id' => $opening->key, 'wall_id' => $opening->wallKey,
                    'width' => $opening->widthM === null ? null : $this->decimal($opening->widthM),
                    'height' => $opening->heightM === null ? null : $this->decimal($opening->heightM),
                    ...$this->provenance($opening->evidenceIds, $opening->confidence, $opening->geometryCertainty),
                ];
            }
            foreach ($floor->engineeringElements as $element) {
                $engineering[] = [
                    'id' => $element->key, 'system' => $this->engineeringSystem($element->type),
                    'measurement' => $element->lengthM === null ? 'count' : 'length',
                    'amount' => $element->lengthM === null ? '1' : $this->decimal($element->lengthM),
                    'unit' => $element->lengthM === null ? 'pcs' : 'm',
                    ...$this->provenance($element->evidenceIds, $element->confidence, $element->geometryCertainty),
                ];
            }
        }

        return [
            'model_version' => 'building-model.v1',
            'scale' => ['status' => $model->scaleStatus === 'confirmed' ? 'confirmed' : 'unconfirmed', 'unit' => 'm'],
            'rooms' => $rooms, 'walls' => $walls, 'openings' => $openings,
            'foundations' => [], 'roofs' => [], 'engineering' => $engineering,
        ];
    }

    private function point(array $point): array
    {
        return [$this->decimal($point[0]), $this->decimal($point[1])];
    }

    private function decimal(float|int $value): string
    {
        return (string) BigDecimal::of(json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function provenance(array $evidenceIds, float $confidence, string $certainty): array
    {
        return [
            'evidence_ids' => array_map(static fn (int $id): string => (string) $id, $evidenceIds),
            'confidence' => $this->decimal($confidence),
            'source' => $certainty === 'confirmed' && $evidenceIds !== [] ? 'evidenced' : 'estimated',
            'assumptions' => $certainty === 'confirmed' ? [] : ['unconfirmed_geometry'],
        ];
    }

    private function engineeringSystem(string $type): string
    {
        return match ($type) {
            'water_point' => 'water', 'sewer_point', 'sewer_route' => 'sewer', 'heating_point' => 'heating',
            'ventilation_point' => 'ventilation', default => 'electrical',
        };
    }
}
