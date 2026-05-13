<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseIdentifier;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseLogisticUnit;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseScanEvent;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class WarehouseIdentifierAndScanEventControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_identifiers_are_scoped_unique_and_keep_single_primary_per_entity(): void
    {
        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $warehouse = $this->createWarehouse($context->organization->id, 'Main warehouse', 'WH-ID');
        $foreignWarehouse = $this->createWarehouse($foreignContext->organization->id, 'Foreign warehouse', 'WH-FOR');
        $this->createIdentifier($foreignContext->organization->id, $foreignWarehouse->id, 'FOREIGN-CODE');
        $this->allowAdminAccess();

        $firstResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouse-identifiers', [
                'warehouse_id' => $warehouse->id,
                'identifier_type' => WarehouseIdentifier::TYPE_QR,
                'code' => 'QR-WH-001',
                'entity_type' => 'warehouse',
                'entity_id' => $warehouse->id,
                'label' => 'Main QR',
                'status' => WarehouseIdentifier::STATUS_ACTIVE,
                'is_primary' => true,
            ]);

        $firstResponse->assertCreated();
        $firstIdentifierId = (int) $firstResponse->json('data.id');

        $secondResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouse-identifiers', [
                'warehouse_id' => $warehouse->id,
                'identifier_type' => WarehouseIdentifier::TYPE_BARCODE,
                'code' => 'BAR-WH-001',
                'entity_type' => 'warehouse',
                'entity_id' => $warehouse->id,
                'label' => 'Main barcode',
                'status' => WarehouseIdentifier::STATUS_ACTIVE,
                'is_primary' => true,
            ]);

        $secondResponse->assertCreated();
        $secondIdentifierId = (int) $secondResponse->json('data.id');

        $this->assertFalse((bool) WarehouseIdentifier::query()->findOrFail($firstIdentifierId)->is_primary);
        $this->assertTrue((bool) WarehouseIdentifier::query()->findOrFail($secondIdentifierId)->is_primary);

        $duplicateResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouse-identifiers', [
                'warehouse_id' => $warehouse->id,
                'identifier_type' => WarehouseIdentifier::TYPE_QR,
                'code' => 'BAR-WH-001',
                'entity_type' => 'warehouse',
                'entity_id' => $warehouse->id,
                'status' => WarehouseIdentifier::STATUS_ACTIVE,
            ]);

        $duplicateResponse->assertStatus(422);

        $foreignWarehouseResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouse-identifiers', [
                'warehouse_id' => $foreignWarehouse->id,
                'identifier_type' => WarehouseIdentifier::TYPE_QR,
                'code' => 'QR-BAD-WH',
                'entity_type' => 'warehouse',
                'entity_id' => $warehouse->id,
                'status' => WarehouseIdentifier::STATUS_ACTIVE,
            ]);

        $foreignWarehouseResponse->assertStatus(422);
        $foreignWarehouseResponse->assertJsonValidationErrors('warehouse_id');

        $indexResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/warehouse-identifiers');

        $indexResponse->assertOk();
        $codes = collect($indexResponse->json('data'))->pluck('code')->all();
        $this->assertContains('QR-WH-001', $codes);
        $this->assertContains('BAR-WH-001', $codes);
        $this->assertNotContains('FOREIGN-CODE', $codes);
    }

    public function test_scan_events_resolve_only_active_identifiers_and_update_logistic_unit_scan_time(): void
    {
        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $warehouse = $this->createWarehouse($context->organization->id, 'Scan warehouse', 'SCAN-WH');
        $foreignWarehouse = $this->createWarehouse($foreignContext->organization->id, 'Foreign scan warehouse', 'SCAN-FOR');
        $logisticUnit = $this->createLogisticUnit($context->organization->id, $warehouse->id, 'Pallet 1', 'PAL-1');
        $activeIdentifier = $this->createIdentifier(
            $context->organization->id,
            $warehouse->id,
            'PALLET-ACTIVE',
            entityType: 'logistic_unit',
            entityId: $logisticUnit->id,
        );
        $archivedIdentifier = $this->createIdentifier(
            $context->organization->id,
            $warehouse->id,
            'PALLET-ARCHIVED',
            status: WarehouseIdentifier::STATUS_ARCHIVED,
            entityType: 'logistic_unit',
            entityId: $logisticUnit->id,
        );
        $this->allowAdminAccess();

        $resolvedResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouse-scan-events', [
                'warehouse_id' => $warehouse->id,
                'code' => 'PALLET-ACTIVE',
                'source' => WarehouseScanEvent::SOURCE_ADMIN,
                'scan_context' => 'acceptance',
            ]);

        $resolvedResponse->assertCreated();
        $resolvedResponse->assertJsonPath('data.result', WarehouseScanEvent::RESULT_RESOLVED);
        $resolvedResponse->assertJsonPath('data.identifier_id', $activeIdentifier->id);
        $resolvedResponse->assertJsonPath('data.logistic_unit_id', $logisticUnit->id);
        $this->assertNotNull($activeIdentifier->fresh()->last_scanned_at);
        $this->assertNotNull($logisticUnit->fresh()->last_scanned_at);

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouse-identifiers/resolve', [
                'warehouse_id' => $warehouse->id,
                'code' => 'PALLET-ACTIVE',
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $activeIdentifier->id);

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouse-identifiers/resolve', [
                'warehouse_id' => $foreignWarehouse->id,
                'code' => 'PALLET-ACTIVE',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('warehouse_id');

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouse-scan-events', [
                'warehouse_id' => $foreignWarehouse->id,
                'code' => 'PALLET-ACTIVE',
                'source' => WarehouseScanEvent::SOURCE_ADMIN,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('warehouse_id');

        $archivedResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouse-scan-events', [
                'warehouse_id' => $warehouse->id,
                'code' => 'PALLET-ARCHIVED',
                'source' => WarehouseScanEvent::SOURCE_ADMIN,
            ]);

        $archivedResponse->assertCreated();
        $archivedResponse->assertJsonPath('data.result', WarehouseScanEvent::RESULT_NOT_FOUND);
        $archivedResponse->assertJsonPath('data.identifier_id', null);
        $this->assertNull($archivedIdentifier->fresh()->last_scanned_at);

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouse-identifiers/resolve', [
                'warehouse_id' => $warehouse->id,
                'code' => 'PALLET-ARCHIVED',
            ])
            ->assertNotFound();

        $unknownResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouse-scan-events', [
                'warehouse_id' => $warehouse->id,
                'code' => 'UNKNOWN-CODE',
                'source' => WarehouseScanEvent::SOURCE_ADMIN,
            ]);

        $unknownResponse->assertCreated();
        $unknownResponse->assertJsonPath('data.result', WarehouseScanEvent::RESULT_NOT_FOUND);

        $indexResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/warehouse-scan-events?result=not_found');

        $indexResponse->assertOk();
        $this->assertEqualsCanonicalizing(
            ['PALLET-ARCHIVED', 'UNKNOWN-CODE'],
            collect($indexResponse->json('data'))->pluck('code')->all()
        );
    }

    public function test_admin_viewer_cannot_manage_identifiers_or_scan_events_without_warehouse_permission(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'admin_viewer');
        $warehouse = $this->createWarehouse($context->organization->id, 'Viewer warehouse', 'VIEW-ID');

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouse-identifiers', [
                'warehouse_id' => $warehouse->id,
                'identifier_type' => WarehouseIdentifier::TYPE_QR,
                'code' => 'VIEWER-CODE',
                'entity_type' => 'warehouse',
                'entity_id' => $warehouse->id,
                'status' => WarehouseIdentifier::STATUS_ACTIVE,
            ])
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouse-scan-events', [
                'warehouse_id' => $warehouse->id,
                'code' => 'VIEWER-CODE',
                'source' => WarehouseScanEvent::SOURCE_ADMIN,
            ])
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->assertDatabaseMissing('warehouse_identifiers', [
            'organization_id' => $context->organization->id,
            'code' => 'VIEWER-CODE',
        ]);
        $this->assertDatabaseMissing('warehouse_scan_events', [
            'organization_id' => $context->organization->id,
            'code' => 'VIEWER-CODE',
        ]);
    }

    private function createWarehouse(int $organizationId, string $name, string $code): OrganizationWarehouse
    {
        return OrganizationWarehouse::query()->create([
            'organization_id' => $organizationId,
            'name' => $name,
            'code' => $code,
            'warehouse_type' => OrganizationWarehouse::TYPE_CENTRAL,
            'is_main' => false,
            'is_active' => true,
        ]);
    }

    private function createLogisticUnit(
        int $organizationId,
        int $warehouseId,
        string $name,
        string $code
    ): WarehouseLogisticUnit {
        return WarehouseLogisticUnit::query()->create([
            'organization_id' => $organizationId,
            'warehouse_id' => $warehouseId,
            'name' => $name,
            'code' => $code,
            'unit_type' => WarehouseLogisticUnit::TYPE_PALLET,
            'status' => WarehouseLogisticUnit::STATUS_AVAILABLE,
            'is_active' => true,
        ]);
    }

    private function createIdentifier(
        int $organizationId,
        int $warehouseId,
        string $code,
        string $status = WarehouseIdentifier::STATUS_ACTIVE,
        string $entityType = 'warehouse',
        ?int $entityId = null
    ): WarehouseIdentifier {
        return WarehouseIdentifier::query()->create([
            'organization_id' => $organizationId,
            'warehouse_id' => $warehouseId,
            'identifier_type' => WarehouseIdentifier::TYPE_QR,
            'code' => $code,
            'entity_type' => $entityType,
            'entity_id' => $entityId ?? $warehouseId,
            'label' => $code,
            'status' => $status,
            'is_primary' => false,
            'assigned_at' => now(),
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
