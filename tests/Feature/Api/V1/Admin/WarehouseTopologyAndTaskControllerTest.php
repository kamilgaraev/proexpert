<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\BasicWarehouse\Models\InventoryAct;
use App\BusinessModules\Features\BasicWarehouse\Models\InventoryActItem;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseIdentifier;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseLogisticUnit;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseStorageCell;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseTask;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseZone;
use App\BusinessModules\Features\BasicWarehouse\Services\WarehouseService;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Material;
use App\Models\MeasurementUnit;
use App\Models\Module;
use App\Models\OrganizationModuleActivation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class WarehouseTopologyAndTaskControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_cell_and_zone_load_do_not_sum_incompatible_measurement_units(): void
    {
        $context = AdminApiTestContext::create();
        $warehouse = $this->createWarehouse($context->organization->id, 'Main warehouse', 'MAIN-LOAD');
        $zone = $this->createZone($warehouse->id, 'Storage zone', 'LOAD-ZONE');
        $singleUnitCell = $this->createCell(
            $context->organization->id,
            $warehouse->id,
            $zone->id,
            'Single unit cell',
            'CELL-SINGLE'
        );
        $mixedUnitCell = $this->createCell(
            $context->organization->id,
            $warehouse->id,
            $zone->id,
            'Mixed unit cell',
            'CELL-MIXED'
        );
        $unknownUnitCell = $this->createCell(
            $context->organization->id,
            $warehouse->id,
            $zone->id,
            'Unknown unit cell',
            'CELL-UNKNOWN'
        );
        foreach ([$singleUnitCell, $mixedUnitCell, $unknownUnitCell] as $cell) {
            $cell->forceFill(['capacity' => 100])->save();
        }

        $kilogram = MeasurementUnit::query()
            ->where('organization_id', $context->organization->id)
            ->where('short_name', 'кг')
            ->firstOrFail();
        $piece = MeasurementUnit::query()
            ->where('organization_id', $context->organization->id)
            ->where('short_name', 'шт')
            ->firstOrFail();
        $kgMaterial = Material::query()->create([
            'organization_id' => $context->organization->id,
            'measurement_unit_id' => $kilogram->id,
            'name' => 'Steel',
            'code' => 'STEEL-KG',
            'is_active' => true,
        ]);
        $pieceMaterial = Material::query()->create([
            'organization_id' => $context->organization->id,
            'measurement_unit_id' => $piece->id,
            'name' => 'Fastener',
            'code' => 'FASTENER-PCS',
            'is_active' => true,
        ]);
        $unknownMaterial = Material::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Unknown unit material',
            'code' => 'UNKNOWN-UNIT',
            'is_active' => true,
        ]);

        foreach ([
            [$singleUnitCell->id, null, $kgMaterial->id, 10],
            [null, $singleUnitCell->code, $kgMaterial->id, 5],
            [$mixedUnitCell->id, null, $kgMaterial->id, 2],
            [$mixedUnitCell->id, null, $pieceMaterial->id, 3],
            [$unknownUnitCell->id, null, $unknownMaterial->id, 4],
        ] as [$cellId, $locationCode, $materialId, $quantity]) {
            WarehouseBalance::query()->create([
                'organization_id' => $context->organization->id,
                'warehouse_id' => $warehouse->id,
                'cell_id' => $cellId,
                'location_code' => $locationCode,
                'material_id' => $materialId,
                'available_quantity' => $quantity,
                'reserved_quantity' => 0,
                'unit_price' => 1,
            ]);
        }
        $this->allowAdminAccess();

        $cellsResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/warehouses/{$warehouse->id}/cells")
            ->assertOk();
        $cells = collect($cellsResponse->json('data'))->keyBy('code');

        $this->assertSame(15, $cells['CELL-SINGLE']['stored_quantity']);
        $this->assertSame('кг', $cells['CELL-SINGLE']['measurement_unit']);
        $this->assertSame(15, $cells['CELL-SINGLE']['current_utilization']);
        $this->assertFalse($cells['CELL-SINGLE']['has_mixed_measurement_units']);
        $this->assertNull($cells['CELL-MIXED']['stored_quantity']);
        $this->assertNull($cells['CELL-MIXED']['current_utilization']);
        $this->assertTrue($cells['CELL-MIXED']['has_mixed_measurement_units']);
        $this->assertCount(2, $cells['CELL-MIXED']['quantity_breakdown']);
        $this->assertNull($cells['CELL-UNKNOWN']['stored_quantity']);
        $this->assertTrue($cells['CELL-UNKNOWN']['has_incomplete_measurement_units']);

        $zoneResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/warehouses/{$warehouse->id}/zones")
            ->assertOk();
        $zoneData = collect($zoneResponse->json('data'))->firstWhere('id', $zone->id);
        $this->assertNull($zoneData['stored_quantity']);
        $this->assertNull($zoneData['summary']['capacity']);
        $this->assertNull($zoneData['current_utilization']);
        $this->assertTrue($zoneData['has_mixed_measurement_units']);
        $this->assertTrue($zoneData['has_incomplete_measurement_units']);
    }

    public function test_warehouse_list_replaces_technical_custody_logins_with_person_names(): void
    {
        $context = AdminApiTestContext::create();
        $context->user->forceFill(['name' => 'technical_owner_login'])->save();
        $responsibleWithoutProfile = User::factory()->create([
            'name' => 'technical_storekeeper_login',
            'current_organization_id' => $context->organization->id,
        ]);
        $project = Project::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Жилой комплекс Северный',
            'status' => 'active',
        ]);
        $regularWarehouse = $this->createWarehouse(
            $context->organization->id,
            'Основной склад',
            'MAIN'
        );
        $custodyWithProfile = OrganizationWarehouse::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'responsible_user_id' => $context->user->id,
            'name' => 'Ответственное хранение: Жилой комплекс Северный, technical_owner_login',
            'code' => 'CUSTODY-WITH-PROFILE',
            'warehouse_type' => OrganizationWarehouse::TYPE_CUSTODY,
            'is_active' => true,
        ]);
        $custodyWithoutProfile = OrganizationWarehouse::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'responsible_user_id' => $responsibleWithoutProfile->id,
            'name' => 'Ответственное хранение: Жилой комплекс Северный, technical_storekeeper_login',
            'code' => 'CUSTODY-WITHOUT-PROFILE',
            'warehouse_type' => OrganizationWarehouse::TYPE_CUSTODY,
            'is_active' => true,
        ]);
        WorkforceEmployee::query()->create([
            'organization_id' => $context->organization->id,
            'user_id' => $context->user->id,
            'personnel_number' => 'QA-CUSTODY-NAME-001',
            'last_name' => 'Гараев',
            'first_name' => 'Камиль',
            'middle_name' => 'Тестович',
            'employment_status' => 'active',
            'hire_date' => '2026-01-01',
        ]);
        $this->allowAdminAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/warehouses')
            ->assertOk();

        $warehouses = collect($response->json('data'))->keyBy('id');

        $this->assertSame('Основной склад', $warehouses->get($regularWarehouse->id)['name']);
        $this->assertSame(
            'Ответственное хранение: Жилой комплекс Северный, Гараев Камиль Тестович',
            $warehouses->get($custodyWithProfile->id)['name']
        );
        $this->assertSame(
            'Ответственное хранение: Жилой комплекс Северный, ФИО не указано',
            $warehouses->get($custodyWithoutProfile->id)['name']
        );
        $this->assertStringNotContainsString('technical_', $response->getContent());
    }

    public function test_owner_can_manage_warehouse_topology_and_tasks_without_foreign_leaks(): void
    {
        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $warehouse = $this->createWarehouse($context->organization->id, 'Main warehouse', 'MAIN');
        $foreignWarehouse = $this->createWarehouse($foreignContext->organization->id, 'Foreign warehouse', 'FOREIGN');
        $foreignZone = $this->createZone($foreignWarehouse->id, 'Foreign zone', 'F-ZONE');
        $foreignCell = $this->createCell($foreignContext->organization->id, $foreignWarehouse->id, $foreignZone->id, 'Foreign cell', 'F-CELL');
        $foreignUnit = $this->createLogisticUnit(
            $foreignContext->organization->id,
            $foreignWarehouse->id,
            $foreignZone->id,
            $foreignCell->id,
            'Foreign pallet',
            'F-PALLET'
        );
        $this->allowAdminAccess();

        $zoneResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/warehouses/{$warehouse->id}/zones", [
                'name' => 'Receiving zone',
                'code' => 'Z-REC',
                'zone_type' => WarehouseZone::TYPE_RECEIVING,
                'rack_number' => 'A',
                'shelf_number' => '01',
                'cell_number' => '001',
                'capacity' => 100,
                'is_active' => true,
            ]);

        $zoneResponse->assertCreated();
        $zoneResponse->assertJsonPath('data.code', 'Z-REC');
        $zoneResponse->assertJsonPath('data.current_utilization', 0);
        $zone = WarehouseZone::query()->where('warehouse_id', $warehouse->id)->where('code', 'Z-REC')->firstOrFail();

        $cellResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/warehouses/{$warehouse->id}/cells", [
                'zone_id' => $zone->id,
                'name' => 'Receiving cell A1',
                'code' => 'CELL-A1',
                'cell_type' => WarehouseStorageCell::TYPE_RECEIVING,
                'status' => WarehouseStorageCell::STATUS_AVAILABLE,
                'rack_number' => 'A',
                'shelf_number' => '01',
                'bin_number' => '001',
                'capacity' => 50,
                'storage_conditions' => [
                    'temperature' => 'room',
                    'humidity' => 'normal',
                ],
                'is_active' => true,
            ]);

        $cellResponse->assertCreated();
        $cellResponse->assertJsonPath('data.zone.id', $zone->id);
        $cellResponse->assertJsonPath('data.storage_conditions.temperature', 'room');
        $cell = WarehouseStorageCell::query()->where('warehouse_id', $warehouse->id)->where('code', 'CELL-A1')->firstOrFail();

        $unitResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/warehouses/{$warehouse->id}/logistic-units", [
                'zone_id' => $zone->id,
                'cell_id' => $cell->id,
                'name' => 'Pallet A1',
                'code' => 'PALLET-A1',
                'unit_type' => WarehouseLogisticUnit::TYPE_PALLET,
                'status' => WarehouseLogisticUnit::STATUS_AVAILABLE,
                'capacity' => 20,
                'current_load' => 5,
            ]);

        $unitResponse->assertCreated();
        $unitResponse->assertJsonPath('data.storage_address', 'Зона Z-REC, Стеллаж A, Полка 01, Ячейка 001');
        $unitResponse->assertJsonPath('data.current_utilization', 25);
        $unit = WarehouseLogisticUnit::query()->where('warehouse_id', $warehouse->id)->where('code', 'PALLET-A1')->firstOrFail();

        $taskResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/warehouses/{$warehouse->id}/tasks", [
                'zone_id' => $zone->id,
                'cell_id' => $cell->id,
                'logistic_unit_id' => $unit->id,
                'assigned_to_id' => $context->user->id,
                'title' => 'Place pallet A1',
                'task_type' => WarehouseTask::TYPE_PLACEMENT,
                'status' => WarehouseTask::STATUS_QUEUED,
                'priority' => WarehouseTask::PRIORITY_HIGH,
                'planned_quantity' => 10,
            ]);

        $taskResponse->assertCreated();
        $taskResponse->assertJsonPath('data.status', WarehouseTask::STATUS_QUEUED);
        $taskResponse->assertJsonPath('data.zone.id', $zone->id);
        $taskResponse->assertJsonPath('data.cell.id', $cell->id);
        $taskResponse->assertJsonPath('data.logistic_unit.id', $unit->id);
        $task = WarehouseTask::query()->where('warehouse_id', $warehouse->id)->where('title', 'Place pallet A1')->firstOrFail();

        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/warehouses/{$warehouse->id}/zones")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $zone->id);

        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/warehouses/{$warehouse->id}/tasks?status=queued")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $task->id);

        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/warehouses/{$foreignWarehouse->id}/zones")
            ->assertNotFound();

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/warehouses/{$warehouse->id}/cells", [
                'zone_id' => $foreignZone->id,
                'name' => 'Bad cell',
                'code' => 'BAD-CELL',
                'cell_type' => WarehouseStorageCell::TYPE_STORAGE,
                'status' => WarehouseStorageCell::STATUS_AVAILABLE,
            ])
            ->assertStatus(422);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/warehouses/{$warehouse->id}/logistic-units", [
                'zone_id' => $zone->id,
                'cell_id' => $foreignCell->id,
                'name' => 'Bad unit',
                'code' => 'BAD-UNIT',
                'unit_type' => WarehouseLogisticUnit::TYPE_PALLET,
                'status' => WarehouseLogisticUnit::STATUS_AVAILABLE,
            ])
            ->assertStatus(422);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/warehouses/{$warehouse->id}/tasks", [
                'zone_id' => $zone->id,
                'cell_id' => $cell->id,
                'logistic_unit_id' => $foreignUnit->id,
                'title' => 'Bad task',
                'task_type' => WarehouseTask::TYPE_PLACEMENT,
            ])
            ->assertStatus(422);

        $this->assertDatabaseMissing('warehouse_storage_cells', [
            'organization_id' => $context->organization->id,
            'code' => 'BAD-CELL',
        ]);
        $this->assertDatabaseMissing('warehouse_logistic_units', [
            'organization_id' => $context->organization->id,
            'code' => 'BAD-UNIT',
        ]);
        $this->assertDatabaseMissing('warehouse_tasks', [
            'organization_id' => $context->organization->id,
            'title' => 'Bad task',
        ]);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/warehouses/{$warehouse->id}/tasks/{$task->id}/status", [
                'status' => WarehouseTask::STATUS_IN_PROGRESS,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', WarehouseTask::STATUS_IN_PROGRESS);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/warehouses/{$warehouse->id}/tasks/{$task->id}/status", [
                'status' => WarehouseTask::STATUS_COMPLETED,
                'completed_quantity' => 8,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', WarehouseTask::STATUS_COMPLETED)
            ->assertJsonPath('data.completed_quantity', 8)
            ->assertJsonPath('data.progress_percent', 80)
            ->assertJsonPath('data.completed_by.id', $context->user->id);

        $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/warehouses/{$warehouse->id}/tasks/{$task->id}")
            ->assertStatus(422);
    }

    public function test_custom_admin_without_advanced_zone_permission_cannot_manage_warehouse_topology(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'custom_warehouse_viewer');
        $warehouse = $this->createWarehouse($context->organization->id, 'Main warehouse', 'MAIN');
        $this->denyAdvancedZonePermission();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/warehouses/{$warehouse->id}/zones", [
                'name' => 'Restricted zone',
                'code' => 'REST',
                'zone_type' => WarehouseZone::TYPE_STORAGE,
            ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('warehouse_zones', [
            'warehouse_id' => $warehouse->id,
            'code' => 'REST',
        ]);
    }

    public function test_task_workflow_assigns_executor_resumes_work_and_locks_completed_task(): void
    {
        $context = AdminApiTestContext::create();
        $warehouse = $this->createWarehouse($context->organization->id, 'Main warehouse', 'MAIN');
        $task = $this->createTask(
            $context->organization->id,
            $warehouse->id,
            null,
            null,
            'Inspect pallet',
            'TASK-WORKFLOW'
        );
        $this->allowAdminAccess();

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/warehouses/{$warehouse->id}/tasks/{$task->id}/status", [
                'status' => WarehouseTask::STATUS_IN_PROGRESS,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', WarehouseTask::STATUS_IN_PROGRESS)
            ->assertJsonPath('data.assigned_to_id', $context->user->id);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/warehouses/{$warehouse->id}/tasks/{$task->id}/status", [
                'status' => WarehouseTask::STATUS_BLOCKED,
            ])
            ->assertOk()
            ->assertJsonPath('data.blocked_from_status', WarehouseTask::STATUS_IN_PROGRESS)
            ->assertJsonPath('data.resume_status', WarehouseTask::STATUS_IN_PROGRESS);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/warehouses/{$warehouse->id}/tasks/{$task->id}/status", [
                'status' => WarehouseTask::STATUS_IN_PROGRESS,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', WarehouseTask::STATUS_IN_PROGRESS)
            ->assertJsonPath('data.blocked_from_status', null);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/warehouses/{$warehouse->id}/tasks/{$task->id}/status", [
                'status' => WarehouseTask::STATUS_COMPLETED,
            ])
            ->assertOk()
            ->assertJsonPath('data.can_edit', false)
            ->assertJsonPath('data.available_transitions', []);

        $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/warehouses/{$warehouse->id}/tasks/{$task->id}", [
                'title' => 'Changed after completion',
                'status' => WarehouseTask::STATUS_QUEUED,
            ])
            ->assertUnprocessable();

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/warehouses/{$warehouse->id}/tasks/{$task->id}/status", [
                'status' => WarehouseTask::STATUS_COMPLETED,
                'notes' => 'Changed after completion',
            ])
            ->assertUnprocessable();

        $this->assertDatabaseHas('warehouse_tasks', [
            'id' => $task->id,
            'status' => WarehouseTask::STATUS_COMPLETED,
            'title' => 'Inspect pallet',
        ]);
    }

    public function test_task_relations_must_belong_to_current_organization(): void
    {
        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $warehouse = $this->createWarehouse($context->organization->id, 'Main warehouse', 'MAIN');
        $foreignMaterial = Material::query()->create([
            'organization_id' => $foreignContext->organization->id,
            'name' => 'Foreign material',
            'code' => 'FOREIGN-MAT',
            'is_active' => true,
        ]);
        $foreignProject = Project::factory()->create([
            'organization_id' => $foreignContext->organization->id,
        ]);
        $foreignAssignee = User::factory()->create([
            'current_organization_id' => $foreignContext->organization->id,
        ]);
        $this->allowAdminAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/warehouses/{$warehouse->id}/tasks", [
                'title' => 'Foreign relation task',
                'task_type' => WarehouseTask::TYPE_PICKING,
                'material_id' => $foreignMaterial->id,
                'project_id' => $foreignProject->id,
                'assigned_to_id' => $foreignAssignee->id,
            ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['material_id', 'project_id', 'assigned_to_id']);
        $this->assertDatabaseMissing('warehouse_tasks', [
            'organization_id' => $context->organization->id,
            'title' => 'Foreign relation task',
        ]);
    }

    public function test_web_admin_system_role_has_warehouse_topology_permissions_from_role_definition(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $warehouse = $this->createWarehouse($context->organization->id, 'Main warehouse', 'MAIN');
        $this->activateBasicWarehouseModule($context->organization->id);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/warehouses/{$warehouse->id}/zones", [
                'name' => 'System role zone',
                'code' => 'SYS-ZONE',
                'zone_type' => WarehouseZone::TYPE_STORAGE,
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('warehouse_zones', [
            'warehouse_id' => $warehouse->id,
            'code' => 'SYS-ZONE',
        ]);
    }

    public function test_zone_and_cell_updates_reject_empty_names_with_business_field_labels(): void
    {
        $context = AdminApiTestContext::create();
        $warehouse = $this->createWarehouse($context->organization->id, 'Main warehouse', 'MAIN');
        $zone = $this->createZone($warehouse->id, 'Storage zone', 'STORAGE');
        $cell = $this->createCell(
            $context->organization->id,
            $warehouse->id,
            $zone->id,
            'Storage cell',
            'CELL-1'
        );
        $this->allowAdminAccess();

        $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/warehouses/{$warehouse->id}/zones/{$zone->id}", ['name' => ''])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Заполните поле «название зоны».')
            ->assertJsonPath('errors.name.0', 'Заполните поле «название зоны».');

        $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/warehouses/{$warehouse->id}/cells/{$cell->id}", ['name' => ''])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Заполните поле «название ячейки».')
            ->assertJsonPath('errors.name.0', 'Заполните поле «название ячейки».');

        $this->assertDatabaseHas('warehouse_zones', [
            'id' => $zone->id,
            'name' => 'Storage zone',
        ]);
        $this->assertDatabaseHas('warehouse_storage_cells', [
            'id' => $cell->id,
            'name' => 'Storage cell',
        ]);
    }

    public function test_logistic_unit_validation_uses_business_field_labels_on_create_and_update(): void
    {
        $context = AdminApiTestContext::create();
        $warehouse = $this->createWarehouse($context->organization->id, 'Main warehouse', 'MAIN');
        $zone = $this->createZone($warehouse->id, 'Storage zone', 'STORAGE');
        $unit = $this->createLogisticUnit(
            $context->organization->id,
            $warehouse->id,
            $zone->id,
            null,
            'Pallet',
            'PALLET-1'
        );
        $this->allowAdminAccess();

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/warehouses/{$warehouse->id}/logistic-units", [
                'name' => 'Invalid pallet',
                'code' => 'INVALID-PALLET',
                'unit_type' => WarehouseLogisticUnit::TYPE_PALLET,
                'status' => WarehouseLogisticUnit::STATUS_AVAILABLE,
                'capacity' => -1,
                'current_load' => -1,
                'gross_weight' => -1,
                'volume' => -1,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.capacity.0', 'Поле «вместимость» должно быть не меньше 0.')
            ->assertJsonPath('errors.current_load.0', 'Поле «текущая загрузка» должно быть не меньше 0.')
            ->assertJsonPath('errors.gross_weight.0', 'Поле «вес брутто» должно быть не меньше 0.')
            ->assertJsonPath('errors.volume.0', 'Поле «объём» должно быть не меньше 0.');

        $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/warehouses/{$warehouse->id}/logistic-units/{$unit->id}", ['name' => ''])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Заполните поле «название логистической единицы».')
            ->assertJsonPath('errors.name.0', 'Заполните поле «название логистической единицы».');

        $this->assertDatabaseHas('warehouse_logistic_units', [
            'id' => $unit->id,
            'name' => 'Pallet',
        ]);
        $this->assertDatabaseMissing('warehouse_logistic_units', [
            'warehouse_id' => $warehouse->id,
            'code' => 'INVALID-PALLET',
        ]);
    }

    public function test_logistic_unit_cannot_be_nested_in_its_descendant(): void
    {
        $context = AdminApiTestContext::create();
        $warehouse = $this->createWarehouse($context->organization->id, 'Main warehouse', 'MAIN');
        $zone = $this->createZone($warehouse->id, 'Storage zone', 'STORAGE');
        $parent = $this->createLogisticUnit(
            $context->organization->id,
            $warehouse->id,
            $zone->id,
            null,
            'Parent pallet',
            'PARENT-PALLET'
        );
        $child = $this->createLogisticUnit(
            $context->organization->id,
            $warehouse->id,
            $zone->id,
            null,
            'Child box',
            'CHILD-BOX'
        );
        $child->update(['parent_unit_id' => $parent->id]);
        $this->allowAdminAccess();

        $this->withHeaders($context->authHeaders())
            ->putJson(
                "/api/v1/admin/warehouses/{$warehouse->id}/logistic-units/{$parent->id}",
                ['parent_unit_id' => $child->id]
            )
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'Нельзя вложить логистическую единицу в одну из её дочерних единиц.'
            );

        $this->assertDatabaseHas('warehouse_logistic_units', [
            'id' => $parent->id,
            'parent_unit_id' => null,
        ]);
        $this->assertDatabaseHas('warehouse_logistic_units', [
            'id' => $child->id,
            'parent_unit_id' => $parent->id,
        ]);
    }

    public function test_zone_cannot_be_deleted_while_it_has_linked_warehouse_entities(): void
    {
        $context = AdminApiTestContext::create();
        $warehouse = $this->createWarehouse($context->organization->id, 'Main warehouse', 'MAIN');
        $this->allowAdminAccess();

        $zoneWithCell = $this->createZone($warehouse->id, 'Cell zone', 'ZONE-CELL');
        $this->createCell($context->organization->id, $warehouse->id, $zoneWithCell->id, 'Cell', 'CELL-1');

        $zoneWithUnit = $this->createZone($warehouse->id, 'Unit zone', 'ZONE-UNIT');
        $this->createLogisticUnit(
            $context->organization->id,
            $warehouse->id,
            $zoneWithUnit->id,
            null,
            'Pallet',
            'PALLET-1'
        );

        $zoneWithTask = $this->createZone($warehouse->id, 'Task zone', 'ZONE-TASK');
        $this->createTask($context->organization->id, $warehouse->id, $zoneWithTask->id, null, 'Zone task', 'TASK-ZONE');

        $zoneWithIdentifier = $this->createZone($warehouse->id, 'Identifier zone', 'ZONE-ID');
        WarehouseIdentifier::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $warehouse->id,
            'identifier_type' => WarehouseIdentifier::TYPE_QR,
            'code' => 'ZONE-ID-QR',
            'entity_type' => 'zone',
            'entity_id' => $zoneWithIdentifier->id,
            'status' => WarehouseIdentifier::STATUS_ACTIVE,
        ]);

        foreach ([
            [$zoneWithCell, 'basic_warehouse.zone.delete_has_cells'],
            [$zoneWithUnit, 'basic_warehouse.zone.delete_has_logistic_units'],
            [$zoneWithTask, 'basic_warehouse.zone.delete_has_tasks'],
            [$zoneWithIdentifier, 'basic_warehouse.zone.delete_has_identifiers'],
        ] as [$zone, $messageKey]) {
            $this->withHeaders($context->authHeaders())
                ->deleteJson("/api/v1/admin/warehouses/{$warehouse->id}/zones/{$zone->id}")
                ->assertUnprocessable()
                ->assertJsonPath('message', trans_message($messageKey));

            $this->assertDatabaseHas('warehouse_zones', ['id' => $zone->id]);
        }
    }

    public function test_cell_cannot_be_deleted_while_it_has_logistic_units_or_unfinished_tasks(): void
    {
        $context = AdminApiTestContext::create();
        $warehouse = $this->createWarehouse($context->organization->id, 'Main warehouse', 'MAIN');
        $zone = $this->createZone($warehouse->id, 'Storage zone', 'STORAGE');
        $this->allowAdminAccess();

        $cellWithUnit = $this->createCell($context->organization->id, $warehouse->id, $zone->id, 'Unit cell', 'CELL-UNIT');
        $this->createLogisticUnit(
            $context->organization->id,
            $warehouse->id,
            $zone->id,
            $cellWithUnit->id,
            'Pallet in cell',
            'PALLET-CELL'
        );

        $cellWithTask = $this->createCell($context->organization->id, $warehouse->id, $zone->id, 'Task cell', 'CELL-TASK');
        $this->createTask(
            $context->organization->id,
            $warehouse->id,
            $zone->id,
            $cellWithTask->id,
            'Cell task',
            'TASK-CELL'
        );

        foreach ([
            [$cellWithUnit, 'basic_warehouse.cell.delete_has_logistic_units'],
            [$cellWithTask, 'basic_warehouse.cell.delete_has_unfinished_tasks'],
        ] as [$cell, $messageKey]) {
            $this->withHeaders($context->authHeaders())
                ->deleteJson("/api/v1/admin/warehouses/{$warehouse->id}/cells/{$cell->id}")
                ->assertUnprocessable()
                ->assertJsonPath('message', trans_message($messageKey));

            $this->assertDatabaseHas('warehouse_storage_cells', ['id' => $cell->id]);
        }
    }

    public function test_cell_cannot_be_deleted_while_it_has_balance_or_operation_history(): void
    {
        $context = AdminApiTestContext::create();
        $warehouse = $this->createWarehouse($context->organization->id, 'Main warehouse', 'MAIN');
        $zone = $this->createZone($warehouse->id, 'Storage zone', 'STORAGE');
        $this->allowAdminAccess();

        $cellWithBalance = $this->createCell(
            $context->organization->id,
            $warehouse->id,
            $zone->id,
            'Balance cell',
            'CELL-BALANCE'
        );
        $cellWithMovement = $this->createCell(
            $context->organization->id,
            $warehouse->id,
            $zone->id,
            'Movement cell',
            'CELL-MOVEMENT'
        );
        $cellWithInventoryItem = $this->createCell(
            $context->organization->id,
            $warehouse->id,
            $zone->id,
            'Inventory cell',
            'CELL-INVENTORY'
        );

        $balanceMaterial = Material::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Balance material',
            'code' => 'BALANCE-MATERIAL',
            'is_active' => true,
        ]);
        $movementMaterial = Material::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Movement material',
            'code' => 'MOVEMENT-MATERIAL',
            'is_active' => true,
        ]);
        $inventoryMaterial = Material::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Inventory material',
            'code' => 'INVENTORY-MATERIAL',
            'is_active' => true,
        ]);

        WarehouseBalance::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $warehouse->id,
            'cell_id' => $cellWithBalance->id,
            'material_id' => $balanceMaterial->id,
            'available_quantity' => 0,
            'reserved_quantity' => 0,
            'unit_price' => 0,
        ]);
        WarehouseMovement::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $warehouse->id,
            'cell_id' => $cellWithMovement->id,
            'material_id' => $movementMaterial->id,
            'movement_type' => WarehouseMovement::TYPE_RECEIPT,
            'quantity' => 1,
            'price' => 10,
            'movement_date' => now(),
        ]);
        $inventoryAct = InventoryAct::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $warehouse->id,
            'act_number' => 'INV-CELL-HISTORY',
            'status' => InventoryAct::STATUS_DRAFT,
            'inventory_date' => now()->toDateString(),
            'created_by' => $context->user->id,
            'commission_members' => [],
        ]);
        InventoryActItem::query()->create([
            'inventory_act_id' => $inventoryAct->id,
            'cell_id' => $cellWithInventoryItem->id,
            'material_id' => $inventoryMaterial->id,
            'expected_quantity' => 0,
            'actual_quantity' => 0,
            'difference' => 0,
            'unit_price' => 10,
            'total_value' => 0,
            'location_code' => $cellWithInventoryItem->code,
        ]);

        foreach ([
            [$cellWithBalance, 'basic_warehouse.cell.delete_has_balances'],
            [$cellWithMovement, 'basic_warehouse.cell.delete_has_movements'],
            [$cellWithInventoryItem, 'basic_warehouse.cell.delete_has_inventory_items'],
        ] as [$cell, $messageKey]) {
            $this->withHeaders($context->authHeaders())
                ->deleteJson("/api/v1/admin/warehouses/{$warehouse->id}/cells/{$cell->id}")
                ->assertUnprocessable()
                ->assertJsonPath('message', trans_message($messageKey));

            $this->assertDatabaseHas('warehouse_storage_cells', ['id' => $cell->id]);
        }
    }

    public function test_warehouse_operations_and_inventory_use_cell_id_with_legacy_location_code(): void
    {
        $context = AdminApiTestContext::create();
        $warehouse = $this->createWarehouse($context->organization->id, 'Main warehouse', 'MAIN');
        $targetWarehouse = $this->createWarehouse($context->organization->id, 'Target warehouse', 'TARGET');
        $zone = $this->createZone($warehouse->id, 'Main zone', 'MAIN-ZONE');
        $targetZone = $this->createZone($targetWarehouse->id, 'Target zone', 'TARGET-ZONE');
        $cell = $this->createCell($context->organization->id, $warehouse->id, $zone->id, 'Main cell', 'CELL-MAIN');
        $targetCell = $this->createCell($context->organization->id, $targetWarehouse->id, $targetZone->id, 'Target cell', 'CELL-TARGET');
        $material = Material::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Cement',
            'code' => 'CEMENT-CELL',
            'is_active' => true,
        ]);
        $this->allowAdminAccess();

        $receipt = $this->withHeaders($context->authHeaders())
            ->postJson(
                '/api/v1/admin/warehouses/operations/receipt',
                [
                    'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
                    'warehouse_id' => $warehouse->id,
                    'cell_id' => $cell->id,
                    'material_id' => $material->id,
                    'quantity' => 10,
                    'price' => 100,
                ]);

        $receipt->assertCreated()
            ->assertJsonPath('data.cell_id', $cell->id)
            ->assertJsonPath('data.cell.code', $cell->code);
        $this->assertDatabaseHas('warehouse_balances', [
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'cell_id' => $cell->id,
            'location_code' => $cell->code,
        ]);

        $this->withHeaders($context->authHeaders())
            ->postJson(
                '/api/v1/admin/warehouses/operations/write-off',
                [
                    'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
                    'warehouse_id' => $warehouse->id,
                    'cell_id' => $cell->id,
                    'material_id' => $material->id,
                    'quantity' => 2,
                    'reason' => 'Damage',
                    'operation_category' => 'damage',
                ])
            ->assertOk()
            ->assertJsonPath('data.movement.cell_id', $cell->id);

        $this->withHeaders($context->authHeaders())
            ->postJson(
                '/api/v1/admin/warehouses/operations/transfer',
                [
                    'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
                    'from_warehouse_id' => $warehouse->id,
                    'from_cell_id' => $cell->id,
                    'to_warehouse_id' => $targetWarehouse->id,
                    'to_cell_id' => $targetCell->id,
                    'material_id' => $material->id,
                    'quantity' => 3,
                ])
            ->assertOk()
            ->assertJsonPath('data.movement_out.cell_id', $cell->id)
            ->assertJsonPath('data.movement_in.cell_id', $targetCell->id);

        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/warehouses/{$warehouse->id}/balances?cell_id={$cell->id}")
            ->assertOk()
            ->assertJsonPath('data.0.cell_id', $cell->id)
            ->assertJsonPath('data.0.cell.code', $cell->code)
            ->assertJsonPath('data.0.storage_address', $cell->full_address);

        $inventory = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouses/inventory', [
                'warehouse_id' => $warehouse->id,
                'inventory_date' => '2026-07-10',
            ]);

        $inventory->assertCreated()
            ->assertJsonPath('data.items.0.cell_id', $cell->id)
            ->assertJsonPath('data.items.0.location_code', $cell->code);
        $this->assertDatabaseHas('inventory_act_items', [
            'cell_id' => $cell->id,
            'location_code' => $cell->code,
        ]);

        $foreignContext = AdminApiTestContext::create();
        $foreignWarehouse = $this->createWarehouse($foreignContext->organization->id, 'Foreign warehouse', 'FOREIGN');
        $foreignZone = $this->createZone($foreignWarehouse->id, 'Foreign zone', 'FOREIGN-ZONE');
        $foreignCell = $this->createCell($foreignContext->organization->id, $foreignWarehouse->id, $foreignZone->id, 'Foreign cell', 'FOREIGN-CELL');

        $this->withHeaders($context->authHeaders())
            ->postJson(
                '/api/v1/admin/warehouses/operations/receipt',
                [
                    'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
                    'warehouse_id' => $warehouse->id,
                    'cell_id' => $foreignCell->id,
                    'material_id' => $material->id,
                    'quantity' => 1,
                    'price' => 100,
                ])
            ->assertUnprocessable();

        $this->assertSame(5.0, (float) WarehouseBalance::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('material_id', $material->id)
            ->where('cell_id', $cell->id)
            ->sum('available_quantity'));
    }

    public function test_aggregated_stock_keeps_unlocated_quantity_separate_from_cell_address(): void
    {
        $context = AdminApiTestContext::create();
        $warehouse = $this->createWarehouse($context->organization->id, 'Main warehouse', 'MAIN');
        $zone = $this->createZone($warehouse->id, 'Storage zone', 'STORAGE');
        $cell = $this->createCell(
            $context->organization->id,
            $warehouse->id,
            $zone->id,
            'Storage cell',
            'CELL-A1'
        );
        $material = Material::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Cement',
            'code' => 'CEMENT-MIXED-ADDRESS',
            'is_active' => true,
        ]);

        WarehouseBalance::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'available_quantity' => 100,
            'reserved_quantity' => 2,
            'unit_price' => 18,
        ]);
        WarehouseBalance::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $warehouse->id,
            'cell_id' => $cell->id,
            'material_id' => $material->id,
            'available_quantity' => 1,
            'reserved_quantity' => 1,
            'unit_price' => 18,
            'batch_number' => 'QA-BATCH-001',
        ]);
        $this->allowAdminAccess();

        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/warehouses/{$warehouse->id}/balances")
            ->assertOk()
            ->assertJsonPath('data.0.available_quantity', 101)
            ->assertJsonPath('data.0.reserved_quantity', 3)
            ->assertJsonPath('data.0.total_quantity', 104)
            ->assertJsonPath('data.0.addressed_quantity', 2)
            ->assertJsonPath('data.0.unlocated_quantity', 102)
            ->assertJsonPath('data.0.has_unlocated_quantity', true)
            ->assertJsonPath('data.0.cell_id', null)
            ->assertJsonPath('data.0.cell', null)
            ->assertJsonPath('data.0.cell_ids.0', $cell->id)
            ->assertJsonPath('data.0.cells.0.id', $cell->id)
            ->assertJsonPath('data.0.cells.0.available_quantity', 1)
            ->assertJsonPath('data.0.cells.0.reserved_quantity', 1);
    }

    public function test_stock_zone_filter_matches_current_and_legacy_cell_locations(): void
    {
        $context = AdminApiTestContext::create();
        $warehouse = $this->createWarehouse($context->organization->id, 'Main warehouse', 'MAIN');
        $storageZone = $this->createZone($warehouse->id, 'Storage zone', 'STORAGE');
        $otherZone = $this->createZone($warehouse->id, 'Other zone', 'OTHER');
        $storageCell = $this->createCell(
            $context->organization->id,
            $warehouse->id,
            $storageZone->id,
            'Storage cell',
            'STORAGE-A1'
        );
        $otherCell = $this->createCell(
            $context->organization->id,
            $warehouse->id,
            $otherZone->id,
            'Other cell',
            'OTHER-A1'
        );
        $otherWarehouse = $this->createWarehouse($context->organization->id, 'Other warehouse', 'OTHER-WAREHOUSE');
        $foreignZone = $this->createZone($otherWarehouse->id, 'Foreign storage zone', 'FOREIGN-STORAGE');
        $this->createCell(
            $context->organization->id,
            $otherWarehouse->id,
            $foreignZone->id,
            'Foreign cell with duplicate code',
            $storageCell->code
        );
        $currentMaterial = Material::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Current cell stock',
            'code' => 'CURRENT-CELL-STOCK',
            'is_active' => true,
        ]);
        $legacyMaterial = Material::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Legacy cell stock',
            'code' => 'LEGACY-CELL-STOCK',
            'is_active' => true,
        ]);
        $otherMaterial = Material::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Other zone stock',
            'code' => 'OTHER-ZONE-STOCK',
            'is_active' => true,
        ]);

        WarehouseBalance::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $warehouse->id,
            'cell_id' => $storageCell->id,
            'location_code' => $storageCell->code,
            'material_id' => $currentMaterial->id,
            'available_quantity' => 2,
            'reserved_quantity' => 0,
            'unit_price' => 10,
        ]);
        WarehouseBalance::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $warehouse->id,
            'cell_id' => null,
            'location_code' => $storageCell->code,
            'material_id' => $legacyMaterial->id,
            'available_quantity' => 3,
            'reserved_quantity' => 0,
            'unit_price' => 20,
        ]);
        WarehouseBalance::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $warehouse->id,
            'cell_id' => $otherCell->id,
            'location_code' => $otherCell->code,
            'material_id' => $otherMaterial->id,
            'available_quantity' => 4,
            'reserved_quantity' => 0,
            'unit_price' => 30,
        ]);
        $this->allowAdminAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/warehouses/{$warehouse->id}/balances?zone_id={$storageZone->id}")
            ->assertOk();

        $balancesByMaterial = collect($response->json('data'))->keyBy('material_code');

        $this->assertCount(2, $balancesByMaterial);
        $this->assertSame(2, $balancesByMaterial['CURRENT-CELL-STOCK']['available_quantity']);
        $this->assertSame(3, $balancesByMaterial['LEGACY-CELL-STOCK']['available_quantity']);
        $this->assertArrayNotHasKey('OTHER-ZONE-STOCK', $balancesByMaterial);

        $this->withHeaders($context->authHeaders())
            ->getJson(
                "/api/v1/admin/warehouses/{$warehouse->id}/balances?zone_id={$storageZone->id}&page=1&per_page=1"
            )
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.pagination.total', 2)
            ->assertJsonPath('data.pagination.per_page', 1)
            ->assertJsonPath('data.summary.total_items', 2)
            ->assertJsonPath('data.summary.total_value', 80);

        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/warehouses/{$warehouse->id}/balances?zone_id={$foreignZone->id}")
            ->assertUnprocessable();
        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/warehouses/{$warehouse->id}/balances?zone_id=invalid")
            ->assertUnprocessable();

        $foreignContext = AdminApiTestContext::create();
        $foreignWarehouse = $this->createWarehouse($foreignContext->organization->id, 'Foreign warehouse', 'FOREIGN');
        $foreignOrganizationZone = $this->createZone($foreignWarehouse->id, 'Foreign zone', 'FOREIGN-ZONE');
        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/warehouses/{$foreignWarehouse->id}/balances?zone_id={$foreignOrganizationZone->id}")
            ->assertUnprocessable();

        $foreignZoneBalances = app(WarehouseService::class)->getStockData($context->organization->id, [
            'warehouse_id' => $warehouse->id,
            'zone_id' => $foreignZone->id,
        ]);
        $this->assertSame([], $foreignZoneBalances);
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

    private function createZone(int $warehouseId, string $name, string $code): WarehouseZone
    {
        return WarehouseZone::query()->create([
            'warehouse_id' => $warehouseId,
            'name' => $name,
            'code' => $code,
            'zone_type' => WarehouseZone::TYPE_STORAGE,
            'is_active' => true,
        ]);
    }

    private function createCell(
        int $organizationId,
        int $warehouseId,
        int $zoneId,
        string $name,
        string $code
    ): WarehouseStorageCell {
        return WarehouseStorageCell::query()->create([
            'organization_id' => $organizationId,
            'warehouse_id' => $warehouseId,
            'zone_id' => $zoneId,
            'name' => $name,
            'code' => $code,
            'cell_type' => WarehouseStorageCell::TYPE_STORAGE,
            'status' => WarehouseStorageCell::STATUS_AVAILABLE,
            'is_active' => true,
        ]);
    }

    private function createLogisticUnit(
        int $organizationId,
        int $warehouseId,
        int $zoneId,
        ?int $cellId,
        string $name,
        string $code
    ): WarehouseLogisticUnit {
        return WarehouseLogisticUnit::query()->create([
            'organization_id' => $organizationId,
            'warehouse_id' => $warehouseId,
            'zone_id' => $zoneId,
            'cell_id' => $cellId,
            'name' => $name,
            'code' => $code,
            'unit_type' => WarehouseLogisticUnit::TYPE_PALLET,
            'status' => WarehouseLogisticUnit::STATUS_AVAILABLE,
            'is_active' => true,
        ]);
    }

    private function createTask(
        int $organizationId,
        int $warehouseId,
        ?int $zoneId,
        ?int $cellId,
        string $title,
        string $taskNumber
    ): WarehouseTask {
        return WarehouseTask::query()->create([
            'organization_id' => $organizationId,
            'warehouse_id' => $warehouseId,
            'zone_id' => $zoneId,
            'cell_id' => $cellId,
            'task_number' => $taskNumber,
            'title' => $title,
            'task_type' => WarehouseTask::TYPE_PLACEMENT,
            'status' => WarehouseTask::STATUS_QUEUED,
            'priority' => WarehouseTask::PRIORITY_NORMAL,
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

    private function denyAdvancedZonePermission(): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')
                ->andReturnUsing(
                    static fn (User $user, string $permission): bool => $permission !== 'warehouse.advanced.zones'
                );
            $mock->shouldReceive('hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['custom_warehouse_viewer']);
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

    private function activateBasicWarehouseModule(int $organizationId): void
    {
        $module = Module::query()->create([
            'name' => 'Basic warehouse',
            'slug' => 'basic-warehouse',
            'version' => '1.0.0',
            'type' => 'feature',
            'billing_model' => 'free',
            'category' => 'operations',
            'is_active' => true,
            'is_system_module' => false,
            'can_deactivate' => true,
        ]);

        OrganizationModuleActivation::query()->create([
            'organization_id' => $organizationId,
            'module_id' => $module->id,
            'status' => 'active',
            'activated_at' => now(),
            'is_bundled_with_plan' => false,
            'is_auto_renew_enabled' => false,
        ]);
    }
}
