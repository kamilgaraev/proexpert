<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Quantities;

use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeUnitNormalizer;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class PlannedDirectTakeoffQuantityFactory
{
    public const FORMULA_VERSION = '1.0.0';

    private const DIRECT_SOURCES = [
        'document_quantity',
        'drawing_takeoff',
        'specification',
        'specification_takeoff',
        'work_volume_statement',
        'work_volume_takeoff',
    ];

    public function make(array $workItem): ?QuantityData
    {
        $sourceRefs = is_array($workItem['source_refs'] ?? null)
            ? array_values(array_filter($workItem['source_refs'], 'is_array'))
            : [];
        $flags = is_array($workItem['validation_flags'] ?? null) ? $workItem['validation_flags'] : [];
        $quantityKey = (string) ($workItem['metadata']['quantity_key'] ?? $workItem['quantity_formula'] ?? '');
        $quantitySource = (string) ($workItem['metadata']['quantity_source'] ?? '');
        $amount = $workItem['quantity'] ?? null;
        $unit = NormativeUnitNormalizer::parseDetailed((string) ($workItem['unit'] ?? ''));

        if ($quantityKey === '' || ! in_array($quantitySource, self::DIRECT_SOURCES, true)
            || $sourceRefs === [] || ! is_numeric($amount)
            || BigDecimal::of((string) $amount)->isLessThanOrEqualTo(BigDecimal::zero())
            || array_intersect($flags, ['document_takeoff_required', 'quantity_review_required', 'requires_quantity_review']) !== []
        ) {
            return null;
        }

        $canonicalUnit = match ($unit->dimension) {
            'length' => 'm',
            'area' => 'm2',
            'volume' => 'm3',
            'piece' => 'pcs',
            'mass' => 'kg',
            default => null,
        };
        if ($canonicalUnit === null) {
            return null;
        }

        $canonicalRefs = array_map(
            static fn (array $ref): string => json_encode($ref, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
            $sourceRefs,
        );
        sort($canonicalRefs, SORT_STRING);

        return new QuantityData(
            key: $quantityKey,
            unit: $canonicalUnit,
            amount: (string) BigDecimal::of((string) $amount)
                ->multipliedBy((string) $unit->multiplier)
                ->toScale(6, RoundingMode::HalfUp),
            formulaKey: (string) ($workItem['quantity_formula'] ?? $quantityKey),
            formulaVersion: self::FORMULA_VERSION,
            formulaInputs: [
                'basis' => (string) ($workItem['quantity_basis'] ?? ''),
                'source_refs' => $canonicalRefs,
            ],
            source: QuantitySource::Evidenced,
            evidenceIds: array_map(static fn (string $ref): string => 'source-ref:'.hash('sha256', $ref), $canonicalRefs),
            modelVersion: 'document-takeoff:v1',
        );
    }
}
