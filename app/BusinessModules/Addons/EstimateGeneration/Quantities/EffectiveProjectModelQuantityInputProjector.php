<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Quantities;

use InvalidArgumentException;

final class EffectiveProjectModelQuantityInputProjector
{
    /**
     * @param array<string, mixed> $input
     * @param list<array{entity_stable_key: string, assertion_stable_key: string, assertion_type: string, value: array<string, mixed>, correction_stable_key: string}> $effectiveValues
     * @return array<string, mixed>
     */
    public function project(array $input, array $effectiveValues): array
    {
        $roomAreas = [];
        foreach ($effectiveValues as $effective) {
            if (($effective['assertion_type'] ?? null) !== 'area') {
                continue;
            }
            $entityKey = $effective['entity_stable_key'] ?? null;
            $value = $effective['value'] ?? null;
            $correctionKey = $effective['correction_stable_key'] ?? null;
            if (! is_string($entityKey) || ! is_string($correctionKey) || ! is_array($value)
                || ! $this->positiveNumber($value['value'] ?? null) || ($value['unit'] ?? null) !== 'm2' || count($value) !== 2) {
                throw new InvalidArgumentException('Effective project model area correction is invalid.');
            }
            $roomAreas[$entityKey] = ['value' => $value['value'], 'correction_key' => $correctionKey];
        }
        if ($roomAreas === []) {
            return $input;
        }
        foreach ($input['rooms'] ?? [] as $index => $room) {
            if (! is_array($room) || ! isset($roomAreas[$room['id'] ?? ''])) {
                continue;
            }
            $override = $roomAreas[$room['id']];
            $input['rooms'][$index]['area'] = [
                'value' => $this->decimal($override['value']),
                'unit' => 'm2',
                'source' => 'estimated',
                'evidence_ids' => [],
                'assumptions' => ['manual_project_model_correction'],
                'metric_independent' => true,
                'context' => ['id' => $override['correction_key']],
                'provenance_version' => 'project-model-correction:v1',
            ];
        }

        return $input;
    }

    private function positiveNumber(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value > 0;
    }

    private function decimal(int|float $value): string
    {
        return rtrim(rtrim(sprintf('%.17F', $value), '0'), '.');
    }
}
