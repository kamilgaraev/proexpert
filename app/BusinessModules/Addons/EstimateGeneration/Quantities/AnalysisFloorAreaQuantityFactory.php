<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Quantities;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;

final readonly class AnalysisFloorAreaQuantityFactory
{
    /** @param array<string, mixed> $analysis */
    public function make(array $analysis): ?QuantityData
    {
        [$value, $sourcePath] = $this->area($analysis);
        if ($value === null) {
            return null;
        }

        try {
            $amount = BigDecimal::of((string) $value);
        } catch (MathException) {
            return null;
        }
        if ($amount->isLessThanOrEqualTo(BigDecimal::zero())) {
            return null;
        }

        $model = is_array($analysis['normalized_building_model'] ?? null)
            ? $analysis['normalized_building_model']
            : [];
        $evidenceIds = array_values(array_unique(array_map(
            'strval',
            array_filter(
                is_array($model['evidence_ids'] ?? null) ? $model['evidence_ids'] : [],
                static fn (mixed $id): bool => (is_int($id) || is_string($id)) && (int) $id > 0
            )
        )));
        sort($evidenceIds, SORT_NATURAL);
        $confirmed = ($model['scale_status'] ?? null) === 'confirmed' && $evidenceIds !== [];

        return new QuantityData(
            key: 'floor_area',
            unit: 'm2',
            amount: (string) $amount->toScale(6, RoundingMode::HalfUp),
            formulaKey: 'document.facts.total_floor_area',
            formulaVersion: '1.0.0',
            formulaInputs: [
                'source_path' => $sourcePath,
                'source_value' => (string) $value,
            ],
            source: $confirmed ? QuantitySource::Evidenced : QuantitySource::Estimated,
            evidenceIds: $evidenceIds,
            modelVersion: is_string($model['model_version'] ?? null)
                ? $model['model_version']
                : 'building-model:v1',
            assumptions: ['document_area_preliminary_takeoff'],
            reviewBlockers: $confirmed ? [] : ['estimated_quantity_requires_review'],
        );
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @return array{0: int|float|string|null, 1: string}
     */
    private function area(array $analysis): array
    {
        $facts = $analysis['document_context']['facts_summary'] ?? null;
        if (is_array($facts) && $this->isNumeric($facts['total_area_m2'] ?? null)) {
            return [$facts['total_area_m2'], 'document_context.facts_summary.total_area_m2'];
        }
        $object = $analysis['object'] ?? null;
        if (is_array($object) && $this->isNumeric($object['area'] ?? null)) {
            return [$object['area'], 'object.area'];
        }

        return [null, ''];
    }

    private function isNumeric(mixed $value): bool
    {
        return (is_int($value) || is_float($value) || is_string($value)) && is_numeric($value);
    }
}
