<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\BasicWarehouse\Models\Asset;
use App\BusinessModules\Features\BasicWarehouse\Models\AssetReservation;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\ContractorType;
use App\Models\Contractor;
use App\Models\Material;
use App\Models\MeasurementUnit;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;
use function trans_message;

class WarehouseOperationsControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_write_off_projects_returns_only_active_non_archived_projects_of_current_organization(): void
    {
        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $activeProject = Project::factory()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Активный объект',
            'status' => 'active',
            'is_archived' => false,
        ]);
        $participantProject = Project::factory()->create([
            'organization_id' => $foreignContext->organization->id,
            'name' => 'Партнёрский объект',
            'status' => 'active',
            'is_archived' => false,
        ]);
        $participantProject->organizations()->attach($context->organization->id, [
            'role' => 'contractor',
            'role_new' => 'contractor',
            'is_active' => true,
        ]);
        Project::factory()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Завершённый объект',
            'status' => 'completed',
            'is_archived' => false,
        ]);
        Project::factory()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Архивный объект',
            'status' => 'active',
            'is_archived' => true,
        ]);
        Project::factory()->create([
            'organization_id' => $foreignContext->organization->id,
            'name' => 'Чужой объект',
            'status' => 'active',
            'is_archived' => false,
        ]);
        $this->allowAdminAccess();

        $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/warehouses/operations/write-off/projects')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.projects', [
                [
                    'id' => $activeProject->id,
                    'name' => 'Активный объект',
                ],
                [
                    'id' => $participantProject->id,
                    'name' => 'Партнёрский объект',
                ],
            ]);
    }

    public function test_owner_can_receive_write_off_transfer_reserve_and_partially_release_stock(): void
    {
        $context = AdminApiTestContext::create();
        $unit = $this->createUnit($context->organization->id);
        $material = $this->createMaterial($context->organization->id, $unit->id, 'Cement', 'CEM-OPS');
        $sourceWarehouse = $this->createWarehouse($context->organization->id, 'Source warehouse', 'SRC');
        $targetWarehouse = $this->createWarehouse($context->organization->id, 'Target warehouse', 'DST');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $anotherProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouses/operations/receipt', [
                'warehouse_id' => $sourceWarehouse->id,
                'material_id' => $material->id,
                'quantity' => 10,
                'price' => 100,
                'reason' => 'First batch',
            ])
            ->assertCreated();

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouses/operations/receipt', [
                'warehouse_id' => $sourceWarehouse->id,
                'material_id' => $material->id,
                'quantity' => 5,
                'price' => 150,
                'reason' => 'Second batch',
            ])
            ->assertCreated();

        $writeOffResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouses/operations/write-off', [
                'warehouse_id' => $sourceWarehouse->id,
                'material_id' => $material->id,
                'quantity' => 12,
                'reason' => 'Site issue',
                'operation_category' => WarehouseMovement::CATEGORY_LOSS,
            ])
            ->assertOk();
        $writeOffResponse->assertJsonPath('data.movement.operation_category', WarehouseMovement::CATEGORY_LOSS);

        $this->assertSame(0.0, $this->balanceQuantity($context->organization->id, $sourceWarehouse->id, $material->id, 100));
        $this->assertSame(3.0, $this->balanceQuantity($context->organization->id, $sourceWarehouse->id, $material->id, 150));
        $writeOffMovement = WarehouseMovement::query()
            ->where('organization_id', $context->organization->id)
            ->where('movement_type', WarehouseMovement::TYPE_WRITE_OFF)
            ->firstOrFail();
        $this->assertSame(12.0, (float) $writeOffMovement->quantity);
        $this->assertSame(108.33, round((float) $writeOffMovement->price, 2));

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouses/operations/transfer', [
                'from_warehouse_id' => $sourceWarehouse->id,
                'to_warehouse_id' => $targetWarehouse->id,
                'material_id' => $material->id,
                'quantity' => 2,
                'reason' => 'Move to target',
            ])
            ->assertOk();

        $this->assertSame(1.0, $this->balanceQuantity($context->organization->id, $sourceWarehouse->id, $material->id, 150));
        $this->assertSame(2.0, $this->balanceQuantity($context->organization->id, $targetWarehouse->id, $material->id, 150));
        $this->assertDatabaseHas('warehouse_movements', [
            'organization_id' => $context->organization->id,
            'warehouse_id' => $sourceWarehouse->id,
            'to_warehouse_id' => $targetWarehouse->id,
            'movement_type' => WarehouseMovement::TYPE_TRANSFER_OUT,
        ]);
        $this->assertDatabaseHas('warehouse_movements', [
            'organization_id' => $context->organization->id,
            'warehouse_id' => $targetWarehouse->id,
            'from_warehouse_id' => $sourceWarehouse->id,
            'movement_type' => WarehouseMovement::TYPE_TRANSFER_IN,
        ]);

        $anotherReserveResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouses/operations/reserve', [
                'warehouse_id' => $targetWarehouse->id,
                'material_id' => $material->id,
                'quantity' => 0.75,
                'project_id' => $anotherProject->id,
                'reason' => 'Hold for another project',
            ]);

        $anotherReserveResponse->assertOk();
        $reserveResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouses/operations/reserve', [
                'warehouse_id' => $targetWarehouse->id,
                'material_id' => $material->id,
                'quantity' => 1,
                'project_id' => $project->id,
                'reason' => 'Hold for project',
            ]);

        $reserveResponse->assertOk();
        $reservation = AssetReservation::query()
            ->where('organization_id', $context->organization->id)
            ->where('warehouse_id', $targetWarehouse->id)
            ->where('material_id', $material->id)
            ->where('project_id', $project->id)
            ->firstOrFail();
        $anotherReservation = AssetReservation::query()
            ->where('organization_id', $context->organization->id)
            ->where('warehouse_id', $targetWarehouse->id)
            ->where('material_id', $material->id)
            ->where('project_id', $anotherProject->id)
            ->firstOrFail();
        $this->assertSame($context->organization->id, $reservation->organization_id);
        $this->assertSame($project->id, $reservation->project_id);
        $this->assertSame(1.0, (float) $reservation->quantity);
        $this->assertSame($anotherProject->id, $anotherReservation->project_id);
        $this->assertSame(0.75, (float) $anotherReservation->quantity);
        $this->assertSame(0.25, $this->availableQuantity($context->organization->id, $targetWarehouse->id, $material->id));
        $this->assertSame(1.75, $this->reservedQuantity($context->organization->id, $targetWarehouse->id, $material->id));

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouses/operations/unreserve', [
                'warehouse_id' => $targetWarehouse->id,
                'material_id' => $material->id,
                'quantity' => 0.5,
                'project_id' => $project->id,
                'reason' => 'Partially release',
            ])
            ->assertOk();

        $this->assertSame(0.75, $this->availableQuantity($context->organization->id, $targetWarehouse->id, $material->id));
        $this->assertSame(1.25, $this->reservedQuantity($context->organization->id, $targetWarehouse->id, $material->id));
        $this->assertSame(0.5, (float) $reservation->fresh()->quantity);
        $this->assertSame(AssetReservation::STATUS_ACTIVE, $reservation->fresh()->status);
        $this->assertSame($project->id, $reservation->fresh()->project_id);
        $this->assertSame(0.75, (float) $anotherReservation->fresh()->quantity);
        $this->assertSame(AssetReservation::STATUS_ACTIVE, $anotherReservation->fresh()->status);
    }

    public function test_operations_reject_foreign_warehouse_and_material_ids_before_mutation(): void
    {
        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $unit = $this->createUnit($context->organization->id);
        $foreignUnit = $this->createUnit($foreignContext->organization->id);
        $warehouse = $this->createWarehouse($context->organization->id, 'Main warehouse', 'MAIN');
        $foreignWarehouse = $this->createWarehouse($foreignContext->organization->id, 'Foreign warehouse', 'FOR');
        $material = $this->createMaterial($context->organization->id, $unit->id, 'Cement', 'CEM-OWN');
        $foreignMaterial = $this->createMaterial($foreignContext->organization->id, $foreignUnit->id, 'Foreign cement', 'CEM-FOR');
        $this->allowAdminAccess();

        $foreignWarehouseWriteOff = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouses/operations/write-off', [
                'warehouse_id' => $foreignWarehouse->id,
                'material_id' => $material->id,
                'quantity' => 1,
                'reason' => 'Bad warehouse',
                'operation_category' => WarehouseMovement::CATEGORY_LOSS,
            ]);

        $foreignWarehouseWriteOff->assertStatus(422);

        $foreignMaterialWriteOff = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouses/operations/write-off', [
                'warehouse_id' => $warehouse->id,
                'material_id' => $foreignMaterial->id,
                'quantity' => 1,
                'reason' => 'Bad material',
                'operation_category' => WarehouseMovement::CATEGORY_LOSS,
            ]);

        $foreignMaterialWriteOff->assertStatus(422);

        $foreignTransferTarget = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouses/operations/transfer', [
                'from_warehouse_id' => $warehouse->id,
                'to_warehouse_id' => $foreignWarehouse->id,
                'material_id' => $material->id,
                'quantity' => 1,
                'reason' => 'Bad target',
            ]);

        $foreignTransferTarget->assertStatus(422);

        $foreignMaterialReserve = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouses/operations/reserve', [
                'warehouse_id' => $warehouse->id,
                'material_id' => $foreignMaterial->id,
                'quantity' => 1,
                'reason' => 'Bad reserve',
            ]);

        $foreignMaterialReserve->assertStatus(422);

        $foreignProject = Project::factory()->create(['organization_id' => $foreignContext->organization->id]);
        $foreignProjectReceipt = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouses/operations/receipt', [
                'warehouse_id' => $warehouse->id,
                'material_id' => $material->id,
                'quantity' => 1,
                'price' => 100,
                'project_id' => $foreignProject->id,
                'reason' => 'Bad project',
            ]);

        $foreignProjectReceipt->assertStatus(422);

        $foreignWarehouseUnreserve = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouses/operations/unreserve', [
                'warehouse_id' => $foreignWarehouse->id,
                'material_id' => $material->id,
                'quantity' => 1,
                'reason' => 'Bad release',
            ]);

        $foreignWarehouseUnreserve->assertStatus(422);

        $this->assertDatabaseMissing('warehouse_movements', [
            'organization_id' => $context->organization->id,
        ]);
        $this->assertDatabaseMissing('asset_reservations', [
            'organization_id' => $context->organization->id,
        ]);
    }

    public function test_receipt_accepts_form_numeric_fields_as_strings(): void
    {
        $context = AdminApiTestContext::create();
        $unit = $this->createUnit($context->organization->id);
        $material = $this->createMaterial($context->organization->id, $unit->id, 'Cement', 'CEM-FORM');
        $warehouse = $this->createWarehouse($context->organization->id, 'Main warehouse', 'MAIN');
        $this->allowAdminAccess();

        $this->withHeaders($context->authHeaders())
            ->post('/api/v1/admin/warehouses/operations/receipt', [
                'warehouse_id' => (string) $warehouse->id,
                'material_id' => (string) $material->id,
                'quantity' => '2',
                'price' => '15000',
                'project_id' => 'null',
                'document_number' => '1',
                'reason' => 'Покупка',
                'metadata' => '[]',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('warehouse_movements', [
            'organization_id' => $context->organization->id,
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'movement_type' => WarehouseMovement::TYPE_RECEIPT,
            'quantity' => 2,
            'price' => 15000,
            'document_number' => '1',
        ]);
    }

    public function test_transfer_to_contractor_uses_scoped_ids_and_preserves_project_on_movements(): void
    {
        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $unit = $this->createUnit($context->organization->id);
        $foreignUnit = $this->createUnit($foreignContext->organization->id);
        $material = $this->createMaterial($context->organization->id, $unit->id, 'Cement', 'CEM-CON');
        $foreignMaterial = $this->createMaterial($foreignContext->organization->id, $foreignUnit->id, 'Foreign cement', 'CEM-FCON');
        $warehouse = $this->createWarehouse($context->organization->id, 'Main warehouse', 'MAIN');
        $foreignWarehouse = $this->createWarehouse($foreignContext->organization->id, 'Foreign warehouse', 'FOR');
        $contractor = $this->createContractor($context->organization->id, 'Site Contractor');
        $foreignContractor = $this->createContractor($foreignContext->organization->id, 'Foreign Contractor');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $foreignProject = Project::factory()->create(['organization_id' => $foreignContext->organization->id]);
        $this->allowAdminAccess();

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouses/operations/receipt', [
                'warehouse_id' => $warehouse->id,
                'material_id' => $material->id,
                'quantity' => 5,
                'price' => 100,
                'reason' => 'Initial contractor stock',
            ])
            ->assertCreated();

        $foreignPayload = [
            'from_warehouse_id' => $foreignWarehouse->id,
            'contractor_id' => $foreignContractor->id,
            'material_id' => $foreignMaterial->id,
            'quantity' => 1,
            'project_id' => $foreignProject->id,
            'document_number' => 'M-15-F',
            'reason' => 'Foreign transfer attempt',
        ];

        $foreignResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouses/operations/transfer-to-contractor', $foreignPayload);

        $foreignResponse->assertStatus(422);
        $this->assertSame(5.0, $this->availableQuantity($context->organization->id, $warehouse->id, $material->id));
        $this->assertDatabaseMissing('warehouse_movements', [
            'organization_id' => $context->organization->id,
            'movement_type' => WarehouseMovement::TYPE_TRANSFER_OUT,
        ]);

        $transferResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouses/operations/transfer-to-contractor', [
                'from_warehouse_id' => $warehouse->id,
                'contractor_id' => $contractor->id,
                'material_id' => $material->id,
                'quantity' => 2,
                'project_id' => $project->id,
                'document_number' => 'M-15-001',
                'reason' => 'Issue to contractor',
            ]);

        $transferResponse->assertOk();
        $transferResponse->assertJsonPath('data.transfer_type', 'internal_external_warehouse');
        $this->assertSame(3.0, $this->availableQuantity($context->organization->id, $warehouse->id, $material->id));
        $contractorWarehouseId = (int) $transferResponse->json('data.contractor_warehouse_id');
        $this->assertSame(2.0, $this->availableQuantity($context->organization->id, $contractorWarehouseId, $material->id));

        $this->assertDatabaseHas('warehouse_movements', [
            'organization_id' => $context->organization->id,
            'warehouse_id' => $warehouse->id,
            'to_warehouse_id' => $contractorWarehouseId,
            'material_id' => $material->id,
            'movement_type' => WarehouseMovement::TYPE_TRANSFER_OUT,
            'project_id' => $project->id,
            'document_number' => 'M-15-001',
        ]);
        $this->assertDatabaseHas('warehouse_movements', [
            'organization_id' => $context->organization->id,
            'warehouse_id' => $contractorWarehouseId,
            'from_warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'movement_type' => WarehouseMovement::TYPE_TRANSFER_IN,
            'project_id' => $project->id,
            'document_number' => 'M-15-001',
        ]);
    }

    public function test_cross_organization_transfer_preserves_project_context_for_recipient(): void
    {
        $context = AdminApiTestContext::create();
        $recipientContext = AdminApiTestContext::create();
        $unit = $this->createUnit($context->organization->id);
        $material = $this->createMaterial($context->organization->id, $unit->id, 'Cement', 'CEM-CROSS');
        $warehouse = $this->createWarehouse($context->organization->id, 'Main warehouse', 'MAIN-CROSS');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $contractor = Contractor::query()->create([
            'organization_id' => $context->organization->id,
            'source_organization_id' => $recipientContext->organization->id,
            'name' => 'Recipient organization',
            'contractor_type' => ContractorType::INVITED_ORGANIZATION->value,
        ]);
        $this->allowAdminAccess();

        DB::table('project_organization')->insert([
            'project_id' => $project->id,
            'organization_id' => $recipientContext->organization->id,
            'role' => 'contractor',
            'role_new' => 'contractor',
            'is_active' => true,
            'invited_at' => now(),
            'accepted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouses/operations/receipt', [
                'warehouse_id' => $warehouse->id,
                'material_id' => $material->id,
                'quantity' => 5,
                'price' => 100,
                'reason' => 'Initial cross-organization stock',
            ])
            ->assertCreated();

        $transferResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouses/operations/transfer-to-contractor', [
                'from_warehouse_id' => $warehouse->id,
                'contractor_id' => $contractor->id,
                'material_id' => $material->id,
                'quantity' => 2,
                'project_id' => $project->id,
                'document_number' => 'M-15-CROSS',
                'reason' => 'Issue to invited organization',
            ]);

        $transferResponse->assertOk();
        $transferResponse->assertJsonPath('data.transfer_type', 'cross_organization');

        $targetMaterial = Material::query()
            ->where('organization_id', $recipientContext->organization->id)
            ->where('name', $material->name)
            ->firstOrFail();
        $targetWarehouseId = (int) $transferResponse->json('data.receipt_warehouse_id');

        $receiptMovement = WarehouseMovement::query()
            ->where('organization_id', $recipientContext->organization->id)
            ->where('warehouse_id', $targetWarehouseId)
            ->where('material_id', $targetMaterial->id)
            ->where('movement_type', WarehouseMovement::TYPE_RECEIPT)
            ->firstOrFail();

        $this->assertSame($project->id, $receiptMovement->project_id);
        $this->assertSame($project->id, $receiptMovement->metadata['source_project_id']);
        $this->assertSame(2.0, $this->availableQuantity(
            $recipientContext->organization->id,
            $targetWarehouseId,
            $targetMaterial->id
        ));
    }

    public function test_operations_reject_insufficient_available_stock_without_mutating_balances(): void
    {
        $context = AdminApiTestContext::create();
        $unit = $this->createUnit($context->organization->id);
        $material = $this->createMaterial($context->organization->id, $unit->id, 'Cement', 'CEM-LIMIT');
        $sourceWarehouse = $this->createWarehouse($context->organization->id, 'Source warehouse', 'SRC');
        $targetWarehouse = $this->createWarehouse($context->organization->id, 'Target warehouse', 'DST');
        $this->allowAdminAccess();

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouses/operations/receipt', [
                'warehouse_id' => $sourceWarehouse->id,
                'material_id' => $material->id,
                'quantity' => 3,
                'price' => 100,
                'reason' => 'Initial stock',
            ])
            ->assertCreated();

        $writeOffResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouses/operations/write-off', [
                'warehouse_id' => $sourceWarehouse->id,
                'material_id' => $material->id,
                'quantity' => 4,
                'reason' => 'Too much write off',
                'operation_category' => WarehouseMovement::CATEGORY_LOSS,
            ]);

        $writeOffResponse
            ->assertStatus(422)
            ->assertJsonPath('message', trans_message('warehouse_basic.validation.insufficient_stock', [
                'available' => 3,
                'requested' => 4,
            ]));
        $this->assertSame(3.0, $this->availableQuantity($context->organization->id, $sourceWarehouse->id, $material->id));
        $this->assertDatabaseMissing('warehouse_movements', [
            'organization_id' => $context->organization->id,
            'warehouse_id' => $sourceWarehouse->id,
            'movement_type' => WarehouseMovement::TYPE_WRITE_OFF,
        ]);

        $transferResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouses/operations/transfer', [
                'from_warehouse_id' => $sourceWarehouse->id,
                'to_warehouse_id' => $targetWarehouse->id,
                'material_id' => $material->id,
                'quantity' => 4,
                'reason' => 'Too much transfer',
            ]);

        $transferResponse
            ->assertStatus(422)
            ->assertJsonPath('message', trans_message('warehouse_basic.validation.insufficient_transfer_stock', [
                'available' => 3,
                'requested' => 4,
            ]));
        $this->assertSame(3.0, $this->availableQuantity($context->organization->id, $sourceWarehouse->id, $material->id));
        $this->assertSame(0.0, $this->availableQuantity($context->organization->id, $targetWarehouse->id, $material->id));
        $this->assertDatabaseMissing('warehouse_movements', [
            'organization_id' => $context->organization->id,
            'movement_type' => WarehouseMovement::TYPE_TRANSFER_OUT,
        ]);

        $reserveResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouses/operations/reserve', [
                'warehouse_id' => $sourceWarehouse->id,
                'material_id' => $material->id,
                'quantity' => 4,
                'reason' => 'Too much reserve',
            ]);

        $reserveResponse
            ->assertStatus(422)
            ->assertJsonPath('message', trans_message('warehouse_basic.validation.insufficient_reserve_stock', [
                'available' => 3,
                'requested' => 4,
            ]));
        $this->assertSame(3.0, $this->availableQuantity($context->organization->id, $sourceWarehouse->id, $material->id));
        $this->assertSame(0.0, $this->reservedQuantity($context->organization->id, $sourceWarehouse->id, $material->id));
        $this->assertDatabaseMissing('asset_reservations', [
            'organization_id' => $context->organization->id,
            'warehouse_id' => $sourceWarehouse->id,
            'material_id' => $material->id,
        ]);

        $unreserveResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouses/operations/unreserve', [
                'warehouse_id' => $sourceWarehouse->id,
                'material_id' => $material->id,
                'quantity' => 1,
                'reason' => 'Nothing to release',
            ]);

        $unreserveResponse
            ->assertStatus(422)
            ->assertJsonPath('message', trans_message('warehouse_basic.validation.insufficient_reserved_stock', [
                'reserved' => 0,
                'requested' => 1,
            ]));
        $this->assertSame(3.0, $this->availableQuantity($context->organization->id, $sourceWarehouse->id, $material->id));
        $this->assertSame(0.0, $this->reservedQuantity($context->organization->id, $sourceWarehouse->id, $material->id));
    }

    public function test_transfer_rejects_same_source_and_target_warehouse_with_business_message(): void
    {
        $context = AdminApiTestContext::create();
        $unit = $this->createUnit($context->organization->id);
        $material = $this->createMaterial($context->organization->id, $unit->id, 'Cement', 'CEM-SAME');
        $warehouse = $this->createWarehouse($context->organization->id, 'Main warehouse', 'MAIN');
        $this->allowAdminAccess();

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouses/operations/receipt', [
                'warehouse_id' => $warehouse->id,
                'material_id' => $material->id,
                'quantity' => 3,
                'price' => 100,
                'reason' => 'Initial stock',
            ])
            ->assertCreated();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouses/operations/transfer', [
                'from_warehouse_id' => $warehouse->id,
                'to_warehouse_id' => $warehouse->id,
                'material_id' => $material->id,
                'quantity' => 1,
                'reason' => 'Same warehouse transfer',
            ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('message', trans_message('warehouse_basic.validation.transfer_same_warehouse'));

        $this->assertSame(3.0, $this->availableQuantity($context->organization->id, $warehouse->id, $material->id));
        $this->assertDatabaseMissing('warehouse_movements', [
            'organization_id' => $context->organization->id,
            'movement_type' => WarehouseMovement::TYPE_TRANSFER_OUT,
        ]);
    }

    private function createUnit(int $organizationId): MeasurementUnit
    {
        return MeasurementUnit::query()->create([
            'organization_id' => $organizationId,
            'name' => 'Piece',
            'short_name' => 'pcs',
            'type' => 'material',
            'is_default' => false,
            'is_system' => false,
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

    private function createMaterial(int $organizationId, int $measurementUnitId, string $name, string $code): Material
    {
        return Material::query()->create([
            'organization_id' => $organizationId,
            'name' => $name,
            'code' => $code,
            'measurement_unit_id' => $measurementUnitId,
            'additional_properties' => ['asset_type' => Asset::TYPE_MATERIAL],
            'is_active' => true,
        ]);
    }

    private function createContractor(int $organizationId, string $name): Contractor
    {
        return Contractor::query()->create([
            'organization_id' => $organizationId,
            'name' => $name,
            'contractor_type' => ContractorType::MANUAL->value,
        ]);
    }

    private function balanceQuantity(int $organizationId, int $warehouseId, int $materialId, int $price): float
    {
        return (float) WarehouseBalance::query()
            ->where('organization_id', $organizationId)
            ->where('warehouse_id', $warehouseId)
            ->where('material_id', $materialId)
            ->where('unit_price', $price)
            ->value('available_quantity');
    }

    private function availableQuantity(int $organizationId, int $warehouseId, int $materialId): float
    {
        return (float) WarehouseBalance::query()
            ->where('organization_id', $organizationId)
            ->where('warehouse_id', $warehouseId)
            ->where('material_id', $materialId)
            ->sum('available_quantity');
    }

    private function reservedQuantity(int $organizationId, int $warehouseId, int $materialId): float
    {
        return (float) WarehouseBalance::query()
            ->where('organization_id', $organizationId)
            ->where('warehouse_id', $warehouseId)
            ->where('material_id', $materialId)
            ->sum('reserved_quantity');
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
