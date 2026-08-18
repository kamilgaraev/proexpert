<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ObservationClaim;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\CanonicalSourceDecimal;

final class AcceptedDocumentFactProjector
{
    public function project(ObservationClaim $claim): ?array
    {
        $value = $claim->value['data'];
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        $numeric = ($claim->value['type'] ?? null) === 'number';
        if ($numeric && ! is_int($value) && ! CanonicalSourceDecimal::isValid($value)) {
            return null;
        }
        if ($numeric && ! in_array($claim->factType, ['elevation', 'level'], true)
            && ! (is_int($value) ? $value >= 0 : CanonicalSourceDecimal::isNonNegative($value))) {
            return null;
        }

        $type = match ($claim->factType) {
            'area' => $numeric
                ? ($this->isBuildingTotalArea($claim->entityKey)
                    ? 'total_area'
                    : ($this->hasEntityPrefix($claim->entityKey, 'room') ? 'room_area' : 'zone_area'))
                : 'dimension',
            'dimension_chain' => $numeric ? 'dimension' : null,
            'elevation', 'level' => 'elevation',
            'floor_count' => $this->validFloorCount($claim) ? 'floor_count' : null,
            'material', 'finish_zone' => 'material',
            'technology_candidate', 'roof_geometry' => 'work_scope',
            'table' => 'table_row',
            'note' => 'note',
            default => null,
        };
        if ($type === null) {
            return null;
        }

        return [
            'fact_type' => $type,
            'label_key' => match ($type) {
                'room_area', 'zone_area' => 'area',
                default => $type,
            },
            'value_text' => $numeric ? null : (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value),
            'value_number' => $numeric ? $value : null,
            'unit' => $type === 'elevation' ? ($this->canonicalUnit($claim->unit) ?? 'm') : $this->canonicalUnit($claim->unit),
        ];
    }

    private function validFloorCount(ObservationClaim $claim): bool
    {
        $value = $claim->value['data'];

        return ($claim->value['type'] ?? null) === 'number'
            && $claim->unit === null
            && (is_int($value) || is_string($value))
            && preg_match('/^[1-9][0-9]{0,2}$/D', (string) $value) === 1
            && (int) $value <= 200;
    }

    private function isBuildingTotalArea(string $entityKey): bool
    {
        return preg_match('/^building[.:_-](?:area[.:_-]total|total[.:_-]area)$/D', mb_strtolower($entityKey)) === 1;
    }

    private function hasEntityPrefix(string $entityKey, string $prefix): bool
    {
        return preg_match('/^'.preg_quote($prefix, '/').'[.:_-]/D', mb_strtolower($entityKey)) === 1;
    }

    private function canonicalUnit(?string $unit): ?string
    {
        if ($unit === null) {
            return null;
        }

        return match (mb_strtolower(trim($unit))) {
            'м²', 'м2', 'm²', 'm2' => 'm2',
            'м³', 'м3', 'm³', 'm3' => 'm3',
            'мм', 'mm' => 'mm',
            'см', 'cm' => 'cm',
            'м', 'm' => 'm',
            default => trim($unit),
        };
    }
}
