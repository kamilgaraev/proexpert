<?php

declare(strict_types=1);

namespace Tests\Feature\BasicWarehouse;

use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\BasicWarehouse\Services\Export\WarehouseMovementDocumentResolver;
use App\Models\Material;
use App\Models\MeasurementUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class WarehouseMovementDocumentResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_groups_only_lines_from_the_same_business_document(): void
    {
        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        [$warehouse, $material] = $this->createWarehouseContext($context->organization->id, 'MAIN');
        [$otherWarehouse] = $this->createWarehouseContext($context->organization->id, 'OTHER');
        [$foreignWarehouse, $foreignMaterial] = $this->createWarehouseContext(
            $foreignContext->organization->id,
            'FOREIGN',
        );

        $selected = $this->createMovement($context->organization->id, $warehouse->id, $material->id);
        $sameDocumentLine = $this->createMovement(
            $context->organization->id,
            $warehouse->id,
            $material->id,
        );
        $this->createMovement($context->organization->id, $otherWarehouse->id, $material->id);
        $this->createMovement(
            $context->organization->id,
            $warehouse->id,
            $material->id,
            '2026-08-31 10:00:00',
        );
        $this->createMovement(
            $foreignContext->organization->id,
            $foreignWarehouse->id,
            $foreignMaterial->id,
        );

        $resolved = app(WarehouseMovementDocumentResolver::class)->resolve($selected);

        $this->assertSame(
            [$selected->id, $sameDocumentLine->id],
            $resolved->pluck('id')->all(),
        );
    }

    public function test_it_keeps_an_unnumbered_operation_as_a_single_line(): void
    {
        $context = AdminApiTestContext::create();
        [$warehouse, $material] = $this->createWarehouseContext($context->organization->id, 'SINGLE');
        $selected = $this->createMovement(
            $context->organization->id,
            $warehouse->id,
            $material->id,
            documentNumber: null,
        );
        $this->createMovement(
            $context->organization->id,
            $warehouse->id,
            $material->id,
            documentNumber: null,
        );

        $resolved = app(WarehouseMovementDocumentResolver::class)->resolve($selected);

        $this->assertSame([$selected->id], $resolved->pluck('id')->all());
    }

    /** @return array{OrganizationWarehouse, Material} */
    private function createWarehouseContext(int $organizationId, string $suffix): array
    {
        $unit = MeasurementUnit::query()->create([
            'organization_id' => $organizationId,
            'name' => 'Штука '.$suffix,
            'short_name' => strtolower($suffix),
            'type' => 'material',
            'is_default' => false,
            'is_system' => false,
        ]);
        $warehouse = OrganizationWarehouse::query()->create([
            'organization_id' => $organizationId,
            'name' => 'Склад '.$suffix,
            'code' => 'WH-'.$suffix,
            'warehouse_type' => OrganizationWarehouse::TYPE_CENTRAL,
            'is_main' => false,
            'is_active' => true,
        ]);
        $material = Material::query()->create([
            'organization_id' => $organizationId,
            'name' => 'Материал '.$suffix,
            'code' => 'MAT-'.$suffix,
            'measurement_unit_id' => $unit->id,
            'additional_properties' => ['asset_type' => 'material'],
            'is_active' => true,
        ]);

        return [$warehouse, $material];
    }

    private function createMovement(
        int $organizationId,
        int $warehouseId,
        int $materialId,
        string $movementDate = '2026-08-30 10:00:00',
        ?string $documentNumber = 'DOC-001',
    ): WarehouseMovement {
        return WarehouseMovement::query()->create([
            'organization_id' => $organizationId,
            'warehouse_id' => $warehouseId,
            'material_id' => $materialId,
            'movement_type' => WarehouseMovement::TYPE_WRITE_OFF,
            'operation_category' => WarehouseMovement::CATEGORY_DAMAGE,
            'quantity' => 1,
            'price' => 100,
            'document_number' => $documentNumber,
            'reason' => 'Повреждение',
            'movement_date' => $movementDate,
        ]);
    }
}
