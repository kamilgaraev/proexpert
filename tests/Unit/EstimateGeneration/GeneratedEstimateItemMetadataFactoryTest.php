<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\GeneratedEstimateItemMetadataFactory;
use PHPUnit\Framework\TestCase;

final class GeneratedEstimateItemMetadataFactoryTest extends TestCase
{
    public function test_ordinary_estimate_metadata_preserves_quantity_calculation_and_applied_price(): void
    {
        $metadata = (new GeneratedEstimateItemMetadataFactory)->make([
            'quantity_basis' => 'Предварительно по площади помещений',
            'quantity_evidence' => [
                'key' => 'foundation.concrete',
                'formula_key' => 'work_item.quantity.foundation.concrete',
                'formula_version' => '1.5.0',
                'formula_inputs' => [
                    'source_quantity' => ['key' => 'first_floor_internal_area', 'unit' => 'm2', 'amount' => '113.300000'],
                    'factor' => '0.15',
                    'scenario' => ['id' => 'residential_preliminary_scenario:v1'],
                ],
                'evidence_ids' => ['evidence-room-1'],
                'assumptions' => ['preliminary_structural_from_internal_area:v1'],
            ],
            'price_source' => 'regional_catalog',
            'price_snapshot' => ['version' => 'prices-2026.06'],
            'source_refs' => [['document_id' => 142]],
            'confidence' => 0.82,
            'validation_flags' => [],
            'metadata' => [
                'material_assumption' => [
                    'code' => 'floor_laminate',
                    'message' => 'Предварительно принято покрытие пола из ламината.',
                    'severity' => 'warning',
                    'scenario_id' => 'residential_preliminary_common:v1',
                ],
            ],
        ]);

        self::assertSame('residential_preliminary_scenario:v1', $metadata['quantity_calculation']['scenario_id']);
        self::assertSame('foundation.concrete', $metadata['quantity_evidence']['key']);
        self::assertSame('0.15', $metadata['quantity_calculation']['formula_inputs']['factor']);
        self::assertSame(['evidence-room-1'], $metadata['quantity_calculation']['evidence_ids']);
        self::assertSame('regional_catalog', $metadata['applied_price']['source']);
        self::assertSame(['version' => 'prices-2026.06'], $metadata['applied_price']['snapshot']);
        self::assertSame('floor_laminate', $metadata['material_assumption']['code']);
    }

    public function test_legacy_flat_scenario_id_remains_readable(): void
    {
        $calculation = (new GeneratedEstimateItemMetadataFactory)->quantityCalculation([
            'quantity_evidence' => [
                'formula_inputs' => ['scenario_id' => 'preliminary_quantity_coefficients:v1'],
            ],
        ]);

        self::assertSame('preliminary_quantity_coefficients:v1', $calculation['scenario_id']);
    }
}
