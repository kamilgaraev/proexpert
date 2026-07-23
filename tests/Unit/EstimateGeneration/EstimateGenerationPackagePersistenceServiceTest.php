<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackage;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationPackagePersistenceService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class EstimateGenerationPackagePersistenceServiceTest extends TestCase
{
    public function test_package_item_preserves_preliminary_quantity_calculation_metadata(): void
    {
        $service = new EstimateGenerationPackagePersistenceService;
        $method = new ReflectionMethod($service, 'itemPayload');
        $method->setAccessible(true);
        $package = new EstimateGenerationPackage(['key' => 'foundation']);
        $package->id = 41;

        $payload = $method->invoke($service, $package, [
            'key' => 'foundation.concrete',
            'name' => 'Устройство фундаментов',
            'quantity' => '16.995000',
            'unit' => 'm3',
            'quantity_formula' => 'foundation.concrete',
            'quantity_evidence' => [
                'key' => 'foundation.concrete',
                'unit' => 'm3',
                'amount' => '16.995000',
                'formula_key' => 'work_item.quantity.foundation.concrete',
                'formula_version' => '1.5.0',
                'formula_inputs' => [
                    'source_quantity' => ['key' => 'first_floor_internal_area', 'unit' => 'm2', 'amount' => '113.300000'],
                    'factor' => '0.15',
                    'scenario_id' => 'preliminary_quantity_coefficients:v1',
                ],
                'source' => 'estimated',
                'evidence_ids' => ['evidence-room-1'],
                'model_version' => 'building-model:v1',
                'assumptions' => [
                    'vision_room_area_extraction',
                    'preliminary_structural_from_internal_area:v1',
                ],
                'review_blockers' => [],
            ],
            'price_source' => 'regional_catalog',
            'price_snapshot' => ['version' => 'prices-2026.06'],
        ], 0);

        self::assertSame('1.5.0', $payload['quantity_basis']['formula_version']);
        self::assertSame('113.300000', $payload['quantity_basis']['formula_inputs']['source_quantity']['amount']);
        self::assertSame('preliminary_quantity_coefficients:v1', $payload['quantity_basis']['scenario_id']);
        self::assertSame(['evidence-room-1'], $payload['quantity_basis']['evidence_ids']);
        self::assertSame(
            ['vision_room_area_extraction', 'preliminary_structural_from_internal_area:v1'],
            $payload['quantity_basis']['assumptions'],
        );
        self::assertSame($payload['quantity_basis'], $payload['metadata']['quantity_calculation']);
        self::assertSame('foundation.concrete', $payload['metadata']['quantity_evidence']['key']);
        self::assertSame([
            'source' => 'regional_catalog',
            'snapshot' => ['version' => 'prices-2026.06'],
        ], $payload['metadata']['applied_price']);
    }

    public function test_draft_package_keys_are_stable_unique_and_ignore_invalid_rows(): void
    {
        $service = new EstimateGenerationPackagePersistenceService;
        $method = new ReflectionMethod($service, 'draftPackageKeys');
        $method->setAccessible(true);

        $keys = $method->invoke($service, [
            'local_estimates' => [
                ['key' => 'foundation'],
                ['title' => 'Package without explicit key'],
                'invalid',
                ['key' => 'foundation'],
            ],
        ]);

        self::assertSame(['foundation', 'package-2'], $keys);
    }

    public function test_snapshot_work_items_drop_service_rows_from_package_positions(): void
    {
        $service = new EstimateGenerationPackagePersistenceService;
        $method = new ReflectionMethod($service, 'estimateWorkItems');
        $method->setAccessible(true);

        $items = $method->invoke($service, [[
            'key' => 'work-1',
            'item_type' => 'priced_work',
            'total_cost' => 1500,
        ], [
            'key' => 'operation-1',
            'item_type' => 'operation',
            'total_cost' => 0,
        ], [
            'key' => 'resource-note-1',
            'item_type' => 'resource_note',
            'total_cost' => 0,
        ], [
            'key' => 'review-note-1',
            'item_type' => 'review_note',
            'total_cost' => 0,
        ], [
            'key' => 'candidate-1',
            'item_type' => 'priced_work',
            'total_cost' => 0,
            'normative_match' => ['status' => 'candidate'],
        ]]);

        self::assertIsArray($items);
        self::assertSame(['work-1', 'candidate-1'], array_column($items, 'key'));
    }

    public function test_package_item_counters_are_based_on_visible_estimate_positions(): void
    {
        $service = new EstimateGenerationPackagePersistenceService;
        $method = new ReflectionMethod($service, 'itemCounters');
        $method->setAccessible(true);

        $counters = $method->invoke($service, [[
            'key' => 'work-1',
            'item_type' => 'priced_work',
        ], [
            'key' => 'candidate-1',
            'item_type' => 'priced_work',
        ]]);

        self::assertSame([
            'items_count' => 2,
            'total_items_count' => 2,
            'priced_items_count' => 2,
            'quantity_review_items_count' => 0,
            'operation_items_count' => 0,
            'review_notes_count' => 0,
        ], $counters);
    }

    public function test_package_item_counters_keep_quantity_review_and_ignore_service_rows(): void
    {
        $service = new EstimateGenerationPackagePersistenceService;
        $method = new ReflectionMethod($service, 'itemCounters');
        $method->setAccessible(true);

        $counters = $method->invoke($service, [[
            'key' => 'work-1',
            'item_type' => 'priced_work',
        ], [
            'key' => 'walls-review',
            'item_type' => 'quantity_review',
        ], [
            'key' => 'operation-1',
            'item_type' => 'operation',
        ]]);

        self::assertSame([
            'items_count' => 2,
            'total_items_count' => 2,
            'priced_items_count' => 1,
            'quantity_review_items_count' => 1,
            'operation_items_count' => 0,
            'review_notes_count' => 0,
        ], $counters);
    }
}
