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
        $model = is_array($analysis['normalized_building_model'] ?? null)
            ? $analysis['normalized_building_model']
            : [];
        $constraint = is_array($analysis['document_total_area'] ?? null)
            ? $analysis['document_total_area']
            : [];
        $hasExactEvidence = $constraint !== [];
        $metrics = is_array($model['metrics'] ?? null) ? $model['metrics'] : [];
        $floorCount = filter_var($constraint['floor_count'] ?? null, FILTER_VALIDATE_INT);
        $modelFloorCount = filter_var($metrics['floor_count'] ?? null, FILTER_VALIDATE_INT);
        $roomCount = filter_var($metrics['room_count'] ?? null, FILTER_VALIDATE_INT);
        $evidenceId = filter_var($constraint['evidence_id'] ?? null, FILTER_VALIDATE_INT);
        [$value, $sourcePath] = $hasExactEvidence
            ? [$constraint['amount'] ?? null, 'document_total_area.amount']
            : $this->preliminaryArea($analysis);
        if (! $this->isNumeric($value)) {
            return null;
        }
        if ($hasExactEvidence && ($floorCount === false || $floorCount < 1
            || $modelFloorCount === false || $modelFloorCount !== $floorCount
            || $roomCount === false || $roomCount < 1
            || $evidenceId === false || $evidenceId < 1)) {
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

        foreach (is_array($model['assumptions'] ?? null) ? $model['assumptions'] : [] as $assumption) {
            if (is_array($assumption)
                && ($assumption['severity'] ?? null) === 'blocking'
                && ($assumption['code'] ?? null) !== 'scale_missing') {
                return null;
            }
        }
        $amount = (string) $amount->toScale(6, RoundingMode::HalfUp);
        $evidenceIds = $hasExactEvidence ? [(string) $evidenceId] : [];
        $modelVersion = is_string($model['model_version'] ?? null)
            ? $model['model_version']
            : 'building-model:v1';
        $identity = hash('sha256', implode('|', [
            $modelVersion,
            'document.facts.total_floor_area',
            $amount,
            $hasExactEvidence ? (string) $evidenceId : $sourcePath,
        ]));
        $operand = [
            'role' => 'area',
            'value' => $amount,
            'unit' => 'm2',
            'source' => $hasExactEvidence ? 'evidenced' : 'estimated',
            'evidence_ids' => $evidenceIds,
            'assumptions' => [],
            'context_id' => 'model:'.$modelVersion,
            'provenance_version' => $hasExactEvidence ? 'document-total-area:v1' : 'analysis-area:v1',
        ];

        return new QuantityData(
            key: 'floor_area',
            unit: 'm2',
            amount: $amount,
            formulaKey: 'document.facts.total_floor_area',
            formulaVersion: '1.0.0',
            formulaInputs: ['items' => [[
                'identity' => $identity,
                'amount' => $amount,
                'evidence_ids' => $evidenceIds,
                'provenance_versions' => [$hasExactEvidence ? 'document-total-area:v1' : 'analysis-area:v1'],
                'named_operands' => ['area' => $operand],
            ]]],
            source: $hasExactEvidence ? QuantitySource::Evidenced : QuantitySource::Estimated,
            evidenceIds: $evidenceIds,
            modelVersion: $modelVersion,
            assumptions: $hasExactEvidence ? [] : ['document_area_preliminary_takeoff'],
            reviewBlockers: $hasExactEvidence ? [] : ['estimated_quantity_requires_review'],
        );
    }

    /** @param array<string, mixed> $analysis @return array{0: mixed, 1: string} */
    private function preliminaryArea(array $analysis): array
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
