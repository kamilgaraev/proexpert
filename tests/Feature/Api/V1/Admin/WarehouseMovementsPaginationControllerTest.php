<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Material;
use App\Models\MeasurementUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class WarehouseMovementsPaginationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_movements_are_paginated_filtered_and_isolated_by_organization(): void
    {
        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        [$warehouse, $material] = $this->createWarehouseContext($context->organization->id, 'MAIN');
        [$foreignWarehouse, $foreignMaterial] = $this->createWarehouseContext(
            $foreignContext->organization->id,
            'FOREIGN'
        );

        foreach (['WO-001', 'WO-002', 'WO-003'] as $index => $documentNumber) {
            $this->createMovement(
                $context->organization->id,
                $warehouse->id,
                $material->id,
                WarehouseMovement::TYPE_WRITE_OFF,
                $documentNumber,
                sprintf('Повреждение партии %d', $index + 1),
                sprintf('2026-08-%02d 10:00:00', $index + 1),
            );
        }
        $this->createMovement(
            $context->organization->id,
            $warehouse->id,
            $material->id,
            WarehouseMovement::TYPE_RECEIPT,
            'RCPT-001',
            'Поступление',
            '2026-08-04 10:00:00',
        );
        $this->createMovement(
            $foreignContext->organization->id,
            $foreignWarehouse->id,
            $foreignMaterial->id,
            WarehouseMovement::TYPE_WRITE_OFF,
            'FOREIGN-WO',
            'Чужое списание',
            '2026-08-05 10:00:00',
        );
        $this->allowAdminAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->getJson(sprintf(
                '/api/v1/admin/warehouses/%d/movements?movement_type=write_off&per_page=2&page=2',
                $warehouse->id,
            ));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.document_number', 'WO-001');
        $this->assertStringContainsString(
            'movement_type=write_off',
            (string) $response->json('links.prev'),
        );
        $this->assertStringContainsString('per_page=2', (string) $response->json('links.prev'));
        $this->assertStringNotContainsString('FOREIGN-WO', $response->getContent());
    }

    public function test_search_uses_the_full_filtered_history_and_request_is_validated(): void
    {
        $context = AdminApiTestContext::create();
        [$warehouse, $material] = $this->createWarehouseContext($context->organization->id, 'SEARCH');
        $this->createMovement(
            $context->organization->id,
            $warehouse->id,
            $material->id,
            WarehouseMovement::TYPE_WRITE_OFF,
            'WO-SEARCH',
            'Корректировка после инвентаризации',
            '2026-08-10 10:00:00',
        );
        $this->createMovement(
            $context->organization->id,
            $warehouse->id,
            $material->id,
            WarehouseMovement::TYPE_WRITE_OFF,
            'WO-OTHER',
            'Повреждение упаковки',
            '2026-08-11 10:00:00',
        );
        $this->allowAdminAccess();

        $this->withHeaders($context->authHeaders())
            ->getJson(sprintf(
                '/api/v1/admin/warehouses/%d/movements?movement_type=write_off&search=%s',
                $warehouse->id,
                urlencode('инвентаризации'),
            ))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.document_number', 'WO-SEARCH');

        $this->withHeaders($context->authHeaders())
            ->getJson(sprintf(
                '/api/v1/admin/warehouses/%d/movements?movement_type=unknown&per_page=101&page=0',
                $warehouse->id,
            ))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['movement_type', 'per_page', 'page']);
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
            'name' => 'Шуруповёрт '.$suffix,
            'code' => 'DRILL-'.$suffix,
            'measurement_unit_id' => $unit->id,
            'additional_properties' => ['asset_type' => 'equipment'],
            'is_active' => true,
        ]);

        return [$warehouse, $material];
    }

    private function createMovement(
        int $organizationId,
        int $warehouseId,
        int $materialId,
        string $movementType,
        string $documentNumber,
        string $reason,
        string $movementDate,
    ): void {
        WarehouseMovement::query()->create([
            'organization_id' => $organizationId,
            'warehouse_id' => $warehouseId,
            'material_id' => $materialId,
            'movement_type' => $movementType,
            'quantity' => 1,
            'price' => 100,
            'document_number' => $documentNumber,
            'reason' => $reason,
            'movement_date' => $movementDate,
        ]);
    }

    private function allowAdminAccess(): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturn(true);
            $mock->shouldReceive('hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['web_admin']);
            $mock->shouldReceive('getUserRoles')->andReturnUsing(
                static function (User $user, ?AuthorizationContext $context = null) {
                    return $user->roleAssignments()
                        ->where('is_active', true)
                        ->when($context !== null, static fn ($query) => $query->where('context_id', $context->id))
                        ->get();
                }
            );
        });
    }
}
