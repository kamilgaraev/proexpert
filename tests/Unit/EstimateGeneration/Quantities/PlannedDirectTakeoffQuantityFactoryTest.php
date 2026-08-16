<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Quantities;

use App\BusinessModules\Addons\EstimateGeneration\Quantities\PlannedDirectTakeoffQuantityFactory;
use PHPUnit\Framework\TestCase;

final class PlannedDirectTakeoffQuantityFactoryTest extends TestCase
{
    public function test_direct_takeoff_is_bound_to_current_snapshot_and_tenant_scope(): void
    {
        $sourceRef = [
            'document_id' => 17,
            'page_number' => 4,
            'source_version' => 'sha256:'.str_repeat('d', 64),
        ];
        $scope = [
            'organization_id' => 10,
            'project_id' => 20,
            'session_id' => 30,
            'source_version' => 'sha256:'.str_repeat('d', 64),
        ];

        $quantity = (new PlannedDirectTakeoffQuantityFactory)->make([
            'quantity' => '72.19',
            'unit' => 'm2',
            'quantity_formula' => 'rough.floor',
            'quantity_basis' => 'Площадь по листу общих данных',
            'source_refs' => [$sourceRef],
            'metadata' => [
                'quantity_key' => 'rough.floor',
                'quantity_source' => 'drawing_takeoff',
            ],
        ], [
            'input_snapshot_hash' => str_repeat('a', 64),
            'scope_identity' => $scope,
        ]);

        self::assertNotNull($quantity);
        self::assertSame(str_repeat('a', 64), $quantity->formulaInputs['snapshot_identity']['input_fingerprint']);
        self::assertSame([[...$sourceRef, ...$scope]], $quantity->formulaInputs['operands']);
    }

    public function test_direct_takeoff_without_current_generation_identity_is_not_promoted(): void
    {
        $quantity = (new PlannedDirectTakeoffQuantityFactory)->make([
            'quantity' => '72.19',
            'unit' => 'm2',
            'quantity_formula' => 'rough.floor',
            'source_refs' => [['document_id' => 17, 'page_number' => 4]],
            'metadata' => [
                'quantity_key' => 'rough.floor',
                'quantity_source' => 'drawing_takeoff',
            ],
        ]);

        self::assertNull($quantity);
    }
}
