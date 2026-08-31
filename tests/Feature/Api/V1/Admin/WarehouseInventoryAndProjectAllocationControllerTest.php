<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\BasicWarehouse\Enums\ProjectMaterialDeliveryStatusEnum;
use App\BusinessModules\Features\BasicWarehouse\Models\Asset;
use App\BusinessModules\Features\BasicWarehouse\Models\InventoryAct;
use App\BusinessModules\Features\BasicWarehouse\Models\InventoryActItem;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\ProjectMaterialDelivery;
use App\BusinessModules\Features\BasicWarehouse\Models\ProjectMaterialDeliveryEvent;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseProjectAllocation;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Material;
use App\Models\MeasurementUnit;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class WarehouseInventoryAndProjectAllocationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_search_finds_an_act_beyond_the_first_page_and_stays_organization_scoped(): void
    {
        $context = AdminApiTestContext::create();
        $warehouse = $this->createWarehouse($context->organization->id, 'Основной склад', 'INV-SEARCH');
        $this->allowAdminAccess();

        $target = InventoryAct::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $warehouse->id,
            'act_number' => 'INV-20260831-SEARCH',
            'status' => InventoryAct::STATUS_DRAFT,
            'inventory_date' => now()->subDays(30)->toDateString(),
            'created_by' => $context->user->id,
            'commission_members' => [],
            'notes' => 'Закрывающая проверка северного склада',
        ]);

        foreach (range(1, 20) as $offset) {
            InventoryAct::query()->create([
                'organization_id' => $context->organization->id,
                'warehouse_id' => $warehouse->id,
                'act_number' => "INV-20260831-DISTRACTOR-{$offset}",
                'status' => InventoryAct::STATUS_DRAFT,
                'inventory_date' => now()->subDays($offset - 1)->toDateString(),
                'created_by' => $context->user->id,
                'commission_members' => [],
            ]);
        }

        $foreignContext = AdminApiTestContext::create();
        $foreignWarehouse = $this->createWarehouse($foreignContext->organization->id, 'Чужой склад', 'INV-SEARCH');
        InventoryAct::query()->create([
            'organization_id' => $foreignContext->organization->id,
            'warehouse_id' => $foreignWarehouse->id,
            'act_number' => 'INV-20260831-SEARCH',
            'status' => InventoryAct::STATUS_DRAFT,
            'inventory_date' => now()->toDateString(),
            'created_by' => $foreignContext->user->id,
            'commission_members' => [],
        ]);

        $firstPage = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/warehouses/inventory?warehouse_id={$warehouse->id}")
            ->assertOk();
        self::assertNotContains(
            $target->id,
            collect($firstPage->json('data.data'))->pluck('id')->all(),
        );

        foreach (['inv-20260831-search', 'закрывающая проверка'] as $search) {
            $this->withHeaders($context->authHeaders())
                ->getJson("/api/v1/admin/warehouses/inventory?warehouse_id={$warehouse->id}&search=".urlencode($search))
                ->assertOk()
                ->assertJsonPath('data.meta.total', 1)
                ->assertJsonPath('data.data.0.id', $target->id);
        }
    }

    public function test_inventory_registry_metrics_cover_the_filtered_registry_instead_of_the_current_page(): void
    {
        $context = AdminApiTestContext::create();
        $warehouse = $this->createWarehouse($context->organization->id, 'Основной склад', 'INV-METRICS');
        $unit = $this->createUnit($context->organization->id);
        $material = $this->createMaterial($context->organization->id, $unit->id, 'Материал сверки', 'INV-METRICS');
        $this->allowAdminAccess();

        $acts = [];
        foreach ([
            InventoryAct::STATUS_DRAFT,
            InventoryAct::STATUS_IN_PROGRESS,
            InventoryAct::STATUS_APPROVED,
        ] as $offset => $status) {
            $acts[] = InventoryAct::query()->create([
                'organization_id' => $context->organization->id,
                'warehouse_id' => $warehouse->id,
                'act_number' => "INV-20260831-METRICS-{$offset}",
                'status' => $status,
                'inventory_date' => now()->subDays($offset)->toDateString(),
                'created_by' => $context->user->id,
                'commission_members' => [],
                'notes' => $offset === 1 ? 'Контрольная выборка' : null,
            ]);
        }

        foreach ([1 => 2, 2 => 5] as $actOffset => $discrepancyCount) {
            foreach (range(1, $discrepancyCount) as $itemOffset) {
                InventoryActItem::query()->create([
                    'inventory_act_id' => $acts[$actOffset]->id,
                    'material_id' => $material->id,
                    'expected_quantity' => 1,
                    'actual_quantity' => 2,
                    'difference' => 1,
                    'unit_price' => 100,
                    'total_value' => 100,
                    'batch_number' => "METRICS-{$actOffset}-{$itemOffset}",
                ]);
            }
        }

        $foreignContext = AdminApiTestContext::create();
        $foreignWarehouse = $this->createWarehouse($foreignContext->organization->id, 'Чужой склад', 'INV-METRICS');
        $foreignUnit = $this->createUnit($foreignContext->organization->id);
        $foreignMaterial = $this->createMaterial(
            $foreignContext->organization->id,
            $foreignUnit->id,
            'Чужой материал',
            'INV-METRICS-FOREIGN',
        );
        $foreignAct = InventoryAct::query()->create([
            'organization_id' => $foreignContext->organization->id,
            'warehouse_id' => $foreignWarehouse->id,
            'act_number' => 'INV-20260831-METRICS-FOREIGN',
            'status' => InventoryAct::STATUS_IN_PROGRESS,
            'inventory_date' => now()->toDateString(),
            'created_by' => $foreignContext->user->id,
            'commission_members' => [],
            'summary' => ['items_with_discrepancy' => 100],
        ]);
        InventoryActItem::query()->create([
            'inventory_act_id' => $foreignAct->id,
            'material_id' => $foreignMaterial->id,
            'expected_quantity' => 1,
            'actual_quantity' => 100,
            'difference' => 99,
            'unit_price' => 100,
            'total_value' => 9900,
            'batch_number' => 'METRICS-FOREIGN',
        ]);

        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/warehouses/inventory?warehouse_id={$warehouse->id}&per_page=1")
            ->assertOk()
            ->assertJsonPath('data.meta.total', 3)
            ->assertJsonPath('data.meta.metrics.acts_total', 3)
            ->assertJsonPath('data.meta.metrics.draft_acts', 1)
            ->assertJsonPath('data.meta.metrics.in_progress_acts', 1)
            ->assertJsonPath('data.meta.metrics.discrepancy_items', 7);

        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/warehouses/inventory?warehouse_id={$warehouse->id}&search=".urlencode('контрольная выборка'))
            ->assertOk()
            ->assertJsonPath('data.meta.metrics.acts_total', 1)
            ->assertJsonPath('data.meta.metrics.draft_acts', 0)
            ->assertJsonPath('data.meta.metrics.in_progress_acts', 1)
            ->assertJsonPath('data.meta.metrics.discrepancy_items', 2);
    }

    public function test_inventory_registry_returns_live_item_summary_before_the_act_is_completed(): void
    {
        $context = AdminApiTestContext::create();
        $warehouse = $this->createWarehouse($context->organization->id, 'Основной склад', 'INV-LIVE-SUMMARY');
        $unit = $this->createUnit($context->organization->id);
        $material = $this->createMaterial($context->organization->id, $unit->id, 'Материал сверки', 'INV-LIVE-SUMMARY');
        $this->allowAdminAccess();

        $act = InventoryAct::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $warehouse->id,
            'act_number' => 'INV-20260831-LIVE-SUMMARY',
            'status' => InventoryAct::STATUS_IN_PROGRESS,
            'inventory_date' => now()->toDateString(),
            'created_by' => $context->user->id,
            'commission_members' => [],
            'summary' => null,
        ]);

        foreach ([
            ['batch' => 'LIVE-SUMMARY-1', 'difference' => 1, 'total_value' => 100],
            ['batch' => 'LIVE-SUMMARY-2', 'difference' => 0, 'total_value' => 0],
        ] as $item) {
            InventoryActItem::query()->create([
                'inventory_act_id' => $act->id,
                'material_id' => $material->id,
                'expected_quantity' => 1,
                'actual_quantity' => 1 + $item['difference'],
                'difference' => $item['difference'],
                'unit_price' => 100,
                'total_value' => $item['total_value'],
                'batch_number' => $item['batch'],
            ]);
        }

        $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/warehouses/inventory?search=INV-20260831-LIVE-SUMMARY')
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $act->id)
            ->assertJsonPath('data.data.0.summary.total_items', 2)
            ->assertJsonPath('data.data.0.summary.items_with_discrepancy', 1)
            ->assertJsonPath('data.data.0.summary.total_difference_value', 100);
    }

    public function test_project_allocation_respects_stock_availability_and_can_be_partially_deallocated(): void
    {
        $context = AdminApiTestContext::create();
        $unit = $this->createUnit($context->organization->id);
        $warehouse = $this->createWarehouse($context->organization->id, 'Main warehouse', 'MAIN-ALLOC');
        $material = $this->createMaterial($context->organization->id, $unit->id, 'Cement', 'CEM-ALLOC');
        $project = Project::factory()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Pilot allocation project',
        ]);
        $this->createBalance($context->organization->id, $warehouse->id, $material->id, 10, 250);
        $this->allowAdminAccess();

        $firstAllocationResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/project-allocations', [
                'idempotency_key' => '11111111-1111-4111-8111-111111111111',
                'warehouse_id' => $warehouse->id,
                'material_id' => $material->id,
                'project_id' => $project->id,
                'quantity' => 6,
                'notes' => 'Hold for first stage',
            ]);

        $firstAllocationResponse->assertCreated();
        $firstAllocationResponse->assertJsonPath('success', true);

        $allocation = WarehouseProjectAllocation::query()
            ->where('organization_id', $context->organization->id)
            ->where('warehouse_id', $warehouse->id)
            ->where('material_id', $material->id)
            ->where('project_id', $project->id)
            ->firstOrFail();

        $this->assertSame(6.0, (float) $allocation->allocated_quantity);
        $delivery = ProjectMaterialDelivery::query()
            ->where('warehouse_project_allocation_id', $allocation->id)
            ->firstOrFail();

        $tooMuchResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/project-allocations', [
                'idempotency_key' => '22222222-2222-4222-8222-222222222222',
                'warehouse_id' => $warehouse->id,
                'material_id' => $material->id,
                'project_id' => $project->id,
                'quantity' => 5,
            ]);

        $tooMuchResponse->assertStatus(422);
        $this->assertSame(6.0, (float) $allocation->fresh()->allocated_quantity);

        $listResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/project-allocations/project/{$project->id}");

        $listResponse->assertOk();
        $listResponse->assertJsonPath('success', true);
        $this->assertSame([$allocation->id], collect($listResponse->json('data'))->pluck('id')->all());

        $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/project-allocations/{$allocation->id}", ['quantity' => 2])
            ->assertStatus(422)
            ->assertJsonValidationErrors('idempotency_key');

        $delivery->forceFill([
            'status' => ProjectMaterialDeliveryStatusEnum::IN_TRANSIT,
            'shipped_quantity' => 3,
        ])->save();
        $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/project-allocations/{$allocation->id}", [
                'idempotency_key' => '77777777-7777-4777-8777-777777777777',
                'quantity' => 4,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', trans_message(
                'basic_warehouse.project_allocations.quantity_below_shipped',
                ['quantity' => 3],
            ));
        $this->assertSame(6.0, (float) $allocation->fresh()->allocated_quantity);
        $delivery->forceFill([
            'status' => ProjectMaterialDeliveryStatusEnum::RESERVED,
            'shipped_quantity' => 0,
        ])->save();

        $partialDeallocateResponse = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/project-allocations/{$allocation->id}", [
                'idempotency_key' => '88888888-8888-4888-8888-888888888888',
                'quantity' => 2,
            ]);

        $partialDeallocateResponse->assertOk();
        $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/project-allocations/{$allocation->id}", [
                'idempotency_key' => '88888888-8888-4888-8888-888888888888',
                'quantity' => 2,
            ])
            ->assertOk();
        $this->assertSame(4.0, (float) $allocation->fresh()->allocated_quantity);
        $this->assertSame(4.0, (float) $delivery->fresh()->requested_quantity);
        $this->assertSame(4.0, (float) $delivery->fresh()->reserved_quantity);

        $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/project-allocations/{$allocation->id}", [
                'idempotency_key' => '88888888-8888-4888-8888-888888888888',
                'quantity' => 1,
            ])
            ->assertStatus(409);

        $fullDeallocateResponse = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/project-allocations/{$allocation->id}", [
                'idempotency_key' => '99999999-9999-4999-8999-999999999999',
            ]);

        $fullDeallocateResponse->assertOk();
        $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/project-allocations/{$allocation->id}", [
                'idempotency_key' => '99999999-9999-4999-8999-999999999999',
            ])
            ->assertOk();
        $this->assertDatabaseMissing('warehouse_project_allocations', [
            'id' => $allocation->id,
        ]);
        $this->assertSame(ProjectMaterialDeliveryStatusEnum::CANCELLED, $delivery->fresh()->status);
        $this->assertSame(0.0, (float) $delivery->fresh()->requested_quantity);
        $this->assertSame(0.0, (float) $delivery->fresh()->reserved_quantity);
        $this->assertNull($delivery->fresh()->warehouse_project_allocation_id);
        $this->assertDatabaseCount('project_material_delivery_events', 3);
    }

    public function test_project_allocation_rejects_foreign_project_and_foreign_material_before_mutation(): void
    {
        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $unit = $this->createUnit($context->organization->id);
        $foreignUnit = $this->createUnit($foreignContext->organization->id);
        $warehouse = $this->createWarehouse($context->organization->id, 'Main warehouse', 'MAIN-SCOPE');
        $material = $this->createMaterial($context->organization->id, $unit->id, 'Cement', 'CEM-SCOPE');
        $foreignMaterial = $this->createMaterial($foreignContext->organization->id, $foreignUnit->id, 'Foreign cement', 'CEM-F-SCOPE');
        $foreignProject = Project::factory()->create([
            'organization_id' => $foreignContext->organization->id,
            'name' => 'Foreign allocation project',
        ]);
        $this->createBalance($context->organization->id, $warehouse->id, $material->id, 10, 250);
        $this->allowAdminAccess();

        $foreignProjectResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/project-allocations', [
                'idempotency_key' => '33333333-3333-4333-8333-333333333333',
                'warehouse_id' => $warehouse->id,
                'material_id' => $material->id,
                'project_id' => $foreignProject->id,
                'quantity' => 1,
            ]);

        $foreignProjectResponse->assertStatus(422);

        $foreignMaterialResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/project-allocations', [
                'idempotency_key' => '44444444-4444-4444-8444-444444444444',
                'warehouse_id' => $warehouse->id,
                'material_id' => $foreignMaterial->id,
                'project_id' => $foreignProject->id,
                'quantity' => 1,
            ]);

        $foreignMaterialResponse->assertStatus(422);

        $this->assertDatabaseMissing('warehouse_project_allocations', [
            'organization_id' => $context->organization->id,
        ]);
    }

    public function test_project_allocation_requires_idempotency_key(): void
    {
        $context = AdminApiTestContext::create();
        $unit = $this->createUnit($context->organization->id);
        $warehouse = $this->createWarehouse($context->organization->id, 'Main warehouse', 'MAIN-IDEMP-REQ');
        $material = $this->createMaterial($context->organization->id, $unit->id, 'Cement', 'CEM-IDEMP-REQ');
        $project = Project::factory()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Idempotency project',
        ]);
        $this->createBalance($context->organization->id, $warehouse->id, $material->id, 10, 250);
        $this->allowAdminAccess();

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/project-allocations', [
                'warehouse_id' => $warehouse->id,
                'material_id' => $material->id,
                'project_id' => $project->id,
                'quantity' => 2,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('idempotency_key');

        $this->assertDatabaseCount('warehouse_project_allocations', 0);
    }

    public function test_project_allocation_is_idempotent_and_keeps_delivery_quantity_in_sync(): void
    {
        $context = AdminApiTestContext::create();
        $unit = $this->createUnit($context->organization->id);
        $warehouse = $this->createWarehouse($context->organization->id, 'Main warehouse', 'MAIN-IDEMP');
        $material = $this->createMaterial($context->organization->id, $unit->id, 'Cement', 'CEM-IDEMP');
        $project = Project::factory()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Idempotent allocation project',
        ]);
        $this->createBalance($context->organization->id, $warehouse->id, $material->id, 10, 250);
        $this->allowAdminAccess();

        $firstPayload = [
            'idempotency_key' => '55555555-5555-4555-8555-555555555555',
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'project_id' => $project->id,
            'quantity' => 2,
            'notes' => 'First allocation',
        ];

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/project-allocations', $firstPayload)
            ->assertCreated();
        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/project-allocations', $firstPayload)
            ->assertCreated();

        $allocation = WarehouseProjectAllocation::query()->firstOrFail();
        $delivery = ProjectMaterialDelivery::query()->firstOrFail();
        $this->assertSame(2.0, (float) $allocation->allocated_quantity);
        $this->assertSame(2.0, (float) $delivery->requested_quantity);
        $this->assertSame(2.0, (float) $delivery->reserved_quantity);
        $this->assertDatabaseCount('project_material_delivery_events', 1);

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/project-allocations', [
                ...$firstPayload,
                'quantity' => 3,
            ])
            ->assertStatus(409);

        $secondPayload = [
            ...$firstPayload,
            'idempotency_key' => '66666666-6666-4666-8666-666666666666',
            'quantity' => 3,
            'notes' => 'Second allocation',
        ];
        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/project-allocations', $secondPayload)
            ->assertCreated();

        $this->assertSame(5.0, (float) $allocation->fresh()->allocated_quantity);
        $this->assertSame(5.0, (float) $delivery->fresh()->requested_quantity);
        $this->assertSame(5.0, (float) $delivery->fresh()->reserved_quantity);
        $this->assertSame(
            [2.0, 3.0],
            ProjectMaterialDeliveryEvent::query()->orderBy('id')->pluck('quantity')->map(fn ($value) => (float) $value)->all()
        );

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/project-allocations', $firstPayload)
            ->assertCreated();
        $this->assertSame(5.0, (float) $allocation->fresh()->allocated_quantity);
        $this->assertDatabaseCount('project_material_delivery_events', 2);
    }

    public function test_inventory_lifecycle_builds_items_from_current_stock_and_approval_updates_balances(): void
    {
        $context = AdminApiTestContext::create();
        $unit = $this->createUnit($context->organization->id);
        $warehouse = $this->createWarehouse($context->organization->id, 'Inventory warehouse', 'INV-WH');
        $material = $this->createMaterial($context->organization->id, $unit->id, 'Paint', 'PNT-INV');
        $this->createBalance($context->organization->id, $warehouse->id, $material->id, 4, 100, 'A1', 'B-1');
        $this->createBalance($context->organization->id, $warehouse->id, $material->id, 6, 100, 'A2', 'B-1');
        $this->allowAdminAccess();

        $createResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouses/inventory', [
                'warehouse_id' => $warehouse->id,
                'inventory_date' => '2026-05-12',
                'commission_members' => [$context->user->id],
                'notes' => 'Monthly control',
            ]);

        $createResponse->assertCreated();
        $createResponse->assertJsonPath('success', true);
        $createResponse->assertJsonPath('data.status', InventoryAct::STATUS_DRAFT);
        $createResponse->assertJsonCount(2, 'data.items');

        $actId = (int) $createResponse->json('data.id');
        $items = collect($createResponse->json('data.items'))->keyBy('location_code');
        $this->assertSame(4, $items->get('A1')['expected_quantity']);
        $this->assertSame(6, $items->get('A2')['expected_quantity']);
        $this->assertSame('B-1', $items->get('A1')['batch_number']);
        $this->assertSame('B-1', $items->get('A2')['batch_number']);

        $completeBeforeCountingResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/warehouses/inventory/{$actId}/complete");

        $completeBeforeCountingResponse->assertStatus(400);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/warehouses/inventory/{$actId}/start")
            ->assertOk()
            ->assertJsonPath('data.status', InventoryAct::STATUS_IN_PROGRESS);

        $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/warehouses/inventory/{$actId}/items/{$items->get('A1')['id']}", [
                'actual_quantity' => 3,
                'notes' => 'Shortage found in A1',
            ])
            ->assertOk()
            ->assertJsonPath('data.difference_quantity', -1)
            ->assertJsonPath('data.difference_value', -100);

        $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/warehouses/inventory/{$actId}/items/{$items->get('A2')['id']}", [
                'actual_quantity' => 5,
                'notes' => 'Shortage found in A2',
            ])
            ->assertOk()
            ->assertJsonPath('data.difference_quantity', -1)
            ->assertJsonPath('data.difference_value', -100);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/warehouses/inventory/{$actId}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', InventoryAct::STATUS_COMPLETED)
            ->assertJsonPath('data.summary.total_items', 2)
            ->assertJsonPath('data.summary.items_with_discrepancy', 2);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/warehouses/inventory/{$actId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', InventoryAct::STATUS_APPROVED);

        $act = InventoryAct::query()->findOrFail($actId);
        $this->assertSame($context->user->id, $act->approved_by);
        $this->assertSame(8.0, $this->availableQuantity($context->organization->id, $warehouse->id, $material->id));
    }

    public function test_inventory_rejects_foreign_warehouse_before_creating_act(): void
    {
        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $foreignWarehouse = $this->createWarehouse($foreignContext->organization->id, 'Foreign warehouse', 'INV-FOR');
        $this->allowAdminAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouses/inventory', [
                'warehouse_id' => $foreignWarehouse->id,
                'inventory_date' => '2026-05-12',
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('inventory_acts', [
            'organization_id' => $context->organization->id,
        ]);
    }

    public function test_inventory_counts_reserved_stock_as_physical_and_preserves_reservations_on_approval(): void
    {
        $context = AdminApiTestContext::create();
        $unit = $this->createUnit($context->organization->id);
        $warehouse = $this->createWarehouse($context->organization->id, 'Reserved inventory warehouse', 'INV-RES');
        $material = $this->createMaterial($context->organization->id, $unit->id, 'Reserved paint', 'PNT-RES');
        $balance = $this->createBalance($context->organization->id, $warehouse->id, $material->id, 95, 18);
        $balance->update(['reserved_quantity' => 6]);
        $this->allowAdminAccess();

        $createResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouses/inventory', [
                'warehouse_id' => $warehouse->id,
                'inventory_date' => '2026-08-29',
            ])
            ->assertCreated()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.act_number', 'INV-20260829-0001')
            ->assertJsonPath('data.items.0.expected_quantity', 101);

        $actId = (int) $createResponse->json('data.id');
        $itemId = (int) $createResponse->json('data.items.0.id');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/warehouses/inventory/{$actId}/start")
            ->assertOk();

        $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/warehouses/inventory/{$actId}/items/{$itemId}", [
                'actual_quantity' => 100,
            ])
            ->assertOk()
            ->assertJsonPath('data.difference_quantity', -1);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/warehouses/inventory/{$actId}/complete")
            ->assertOk();

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/warehouses/inventory/{$actId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', InventoryAct::STATUS_APPROVED);

        $balance->refresh();
        $this->assertSame(94.0, (float) $balance->available_quantity);
        $this->assertSame(6.0, (float) $balance->reserved_quantity);
        $this->assertSame(100.0, $balance->total_quantity);
    }

    public function test_inventory_rejects_approval_when_physical_count_is_below_active_reservations(): void
    {
        $context = AdminApiTestContext::create();
        $unit = $this->createUnit($context->organization->id);
        $warehouse = $this->createWarehouse($context->organization->id, 'Fully reserved warehouse', 'INV-FULL-RES');
        $material = $this->createMaterial($context->organization->id, $unit->id, 'Reserved cement', 'CEM-FULL-RES');
        $balance = $this->createBalance($context->organization->id, $warehouse->id, $material->id, 0, 20);
        $balance->update(['reserved_quantity' => 6]);
        $this->allowAdminAccess();

        $createResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouses/inventory', [
                'warehouse_id' => $warehouse->id,
                'inventory_date' => '2026-08-29',
            ])
            ->assertCreated()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.expected_quantity', 6);

        $actId = (int) $createResponse->json('data.id');
        $itemId = (int) $createResponse->json('data.items.0.id');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/warehouses/inventory/{$actId}/start")
            ->assertOk();

        $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/warehouses/inventory/{$actId}/items/{$itemId}", [
                'actual_quantity' => 5.999,
            ])
            ->assertOk();

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/warehouses/inventory/{$actId}/complete")
            ->assertOk();

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/warehouses/inventory/{$actId}/approve")
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath(
                'message',
                'Фактический остаток меньше уже зарезервированного количества. Сначала скорректируйте активные резервы.'
            );

        $this->assertSame(InventoryAct::STATUS_COMPLETED, InventoryAct::query()->findOrFail($actId)->status);
        $balance->refresh();
        $this->assertSame(0.0, (float) $balance->available_quantity);
        $this->assertSame(6.0, (float) $balance->reserved_quantity);
    }

    public function test_inventory_numbers_are_scoped_to_organization(): void
    {
        $firstContext = AdminApiTestContext::create();
        $secondContext = AdminApiTestContext::create();
        $firstWarehouse = $this->createWarehouse($firstContext->organization->id, 'First inventory warehouse', 'INV-ORG-1');
        $secondWarehouse = $this->createWarehouse($secondContext->organization->id, 'Second inventory warehouse', 'INV-ORG-2');
        $this->allowAdminAccess();

        $firstResponse = $this->withHeaders($firstContext->authHeaders())
            ->postJson('/api/v1/admin/warehouses/inventory', [
                'warehouse_id' => $firstWarehouse->id,
                'inventory_date' => '2026-08-29',
            ])
            ->assertCreated();

        $secondResponse = $this->withHeaders($secondContext->authHeaders())
            ->postJson('/api/v1/admin/warehouses/inventory', [
                'warehouse_id' => $secondWarehouse->id,
                'inventory_date' => '2026-08-29',
            ])
            ->assertCreated();

        $this->assertSame('INV-20260829-0001', $firstResponse->json('data.act_number'));
        $this->assertSame('INV-20260829-0001', $secondResponse->json('data.act_number'));
    }

    public function test_inventory_number_does_not_collide_after_an_earlier_act_is_deleted(): void
    {
        $context = AdminApiTestContext::create();
        $warehouse = $this->createWarehouse($context->organization->id, 'Sequence inventory warehouse', 'INV-SEQ');
        $this->allowAdminAccess();

        $actIds = [];
        foreach (['2026-08-27', '2026-08-28', '2026-08-29'] as $inventoryDate) {
            $response = $this->withHeaders($context->authHeaders())
                ->postJson('/api/v1/admin/warehouses/inventory', [
                    'warehouse_id' => $warehouse->id,
                    'inventory_date' => $inventoryDate,
                ])
                ->assertCreated();

            $actIds[] = (int) $response->json('data.id');
        }

        InventoryAct::query()->findOrFail($actIds[0])->delete();

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouses/inventory', [
                'warehouse_id' => $warehouse->id,
                'inventory_date' => '2026-08-30',
            ])
            ->assertCreated()
            ->assertJsonPath('data.act_number', 'INV-20260830-0004');
    }

    public function test_inventory_reconciles_located_and_unlocated_balances_independently(): void
    {
        $context = AdminApiTestContext::create();
        $unit = $this->createUnit($context->organization->id);
        $warehouse = $this->createWarehouse($context->organization->id, 'Address inventory warehouse', 'INV-ADDR');
        $material = $this->createMaterial($context->organization->id, $unit->id, 'Addressed paint', 'PNT-ADDR');
        $locatedBalance = $this->createBalance(
            $context->organization->id,
            $warehouse->id,
            $material->id,
            5,
            100,
            'A1',
            'B-ADDR'
        );
        $unlocatedBalance = $this->createBalance(
            $context->organization->id,
            $warehouse->id,
            $material->id,
            5,
            100,
            null,
            'B-ADDR'
        );
        $this->allowAdminAccess();

        $createResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouses/inventory', [
                'warehouse_id' => $warehouse->id,
                'inventory_date' => '2026-08-29',
            ])
            ->assertCreated()
            ->assertJsonCount(2, 'data.items');

        $items = collect($createResponse->json('data.items'))->keyBy('location_code');
        $locatedItemId = (int) $items->get('A1')['id'];
        $unlocatedItemId = (int) $items->get(null)['id'];
        $actId = (int) $createResponse->json('data.id');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/warehouses/inventory/{$actId}/start")
            ->assertOk();

        $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/warehouses/inventory/{$actId}/items/{$locatedItemId}", [
                'actual_quantity' => 4,
            ])
            ->assertOk();

        $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/warehouses/inventory/{$actId}/items/{$unlocatedItemId}", [
                'actual_quantity' => 5,
            ])
            ->assertOk();

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/warehouses/inventory/{$actId}/complete")
            ->assertOk();

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/warehouses/inventory/{$actId}/approve")
            ->assertOk();

        $this->assertSame(4.0, (float) $locatedBalance->fresh()->available_quantity);
        $this->assertSame(5.0, (float) $unlocatedBalance->fresh()->available_quantity);
    }

    public function test_inventory_supports_same_batch_at_multiple_prices(): void
    {
        $context = AdminApiTestContext::create();
        $unit = $this->createUnit($context->organization->id);
        $warehouse = $this->createWarehouse($context->organization->id, 'Price inventory warehouse', 'INV-PRICE');
        $material = $this->createMaterial($context->organization->id, $unit->id, 'Priced paint', 'PNT-PRICE');
        $this->createBalance($context->organization->id, $warehouse->id, $material->id, 3, 100, null, 'B-PRICE');
        $this->createBalance($context->organization->id, $warehouse->id, $material->id, 4, 200, null, 'B-PRICE');
        $this->allowAdminAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouses/inventory', [
                'warehouse_id' => $warehouse->id,
                'inventory_date' => '2026-08-29',
            ])
            ->assertCreated()
            ->assertJsonCount(2, 'data.items');

        $this->assertSame([100, 200], collect($response->json('data.items'))->pluck('unit_price')->sort()->values()->all());
    }

    public function test_inventory_grouping_distinguishes_literal_marker_values(): void
    {
        $context = AdminApiTestContext::create();
        $unit = $this->createUnit($context->organization->id);
        $warehouse = $this->createWarehouse($context->organization->id, 'Marker inventory warehouse', 'INV-MARKER');
        $material = $this->createMaterial($context->organization->id, $unit->id, 'Marker paint', 'PNT-MARKER');
        $this->createBalance($context->organization->id, $warehouse->id, $material->id, 3, 100);
        $this->createBalance(
            $context->organization->id,
            $warehouse->id,
            $material->id,
            4,
            100,
            'no-location',
            'no-batch'
        );
        $this->allowAdminAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouses/inventory', [
                'warehouse_id' => $warehouse->id,
                'inventory_date' => '2026-08-29',
            ])
            ->assertCreated()
            ->assertJsonCount(2, 'data.items');

        $items = collect($response->json('data.items'))->keyBy('expected_quantity');
        $this->assertNull($items->get(3)['location_code']);
        $this->assertNull($items->get(3)['batch_number']);
        $this->assertSame('no-location', $items->get(4)['location_code']);
        $this->assertSame('no-batch', $items->get(4)['batch_number']);
    }

    public function test_inventory_grouping_distinguishes_delimiters_inside_position_values(): void
    {
        $context = AdminApiTestContext::create();
        $unit = $this->createUnit($context->organization->id);
        $warehouse = $this->createWarehouse($context->organization->id, 'Delimiter inventory warehouse', 'INV-DELIMITER');
        $material = $this->createMaterial($context->organization->id, $unit->id, 'Delimiter paint', 'PNT-DELIMITER');
        $this->createBalance($context->organization->id, $warehouse->id, $material->id, 3, 100, 'A:B', 'C');
        $this->createBalance($context->organization->id, $warehouse->id, $material->id, 4, 100, 'A', 'B:C');
        $this->allowAdminAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouses/inventory', [
                'warehouse_id' => $warehouse->id,
                'inventory_date' => '2026-08-29',
            ])
            ->assertCreated()
            ->assertJsonCount(2, 'data.items');

        $this->assertSame(
            [['A:B', 'C'], ['A', 'B:C']],
            collect($response->json('data.items'))
                ->map(static fn (array $item): array => [$item['location_code'], $item['batch_number']])
                ->sortBy(static fn (array $position): string => implode('|', $position))
                ->values()
                ->all()
        );
    }

    public function test_inventory_constraint_migration_refuses_destructive_rollback_after_new_identity_is_used(): void
    {
        $context = AdminApiTestContext::create();
        $unit = $this->createUnit($context->organization->id);
        $warehouse = $this->createWarehouse($context->organization->id, 'Rollback inventory warehouse', 'INV-ROLLBACK');
        $material = $this->createMaterial($context->organization->id, $unit->id, 'Rollback paint', 'PNT-ROLLBACK');
        $this->createBalance($context->organization->id, $warehouse->id, $material->id, 3, 100, 'A1', 'B-ROLLBACK');
        $this->createBalance($context->organization->id, $warehouse->id, $material->id, 4, 100, 'A2', 'B-ROLLBACK');
        $this->allowAdminAccess();

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouses/inventory', [
                'warehouse_id' => $warehouse->id,
                'inventory_date' => '2026-08-29',
            ])
            ->assertCreated()
            ->assertJsonCount(2, 'data.items');

        $migration = require base_path(
            'app/BusinessModules/Features/BasicWarehouse/migrations/2026_08_29_000001_harden_inventory_identity_constraints.php'
        );

        try {
            $migration->down();
            self::fail('Inventory identity migration rollback must reject incompatible data.');
        } catch (\LogicException) {
        }

        self::assertTrue(Schema::hasIndex('inventory_act_items', 'inventory_act_items_position_unique'));
    }

    public function test_inventory_matches_zero_like_batch_and_location_codes_exactly(): void
    {
        $context = AdminApiTestContext::create();
        $unit = $this->createUnit($context->organization->id);
        $warehouse = $this->createWarehouse($context->organization->id, 'Zero code warehouse', 'INV-ZERO');
        $material = $this->createMaterial($context->organization->id, $unit->id, 'Zero code paint', 'PNT-ZERO');
        $balance = $this->createBalance($context->organization->id, $warehouse->id, $material->id, 5, 100, '0', '0');
        $this->allowAdminAccess();

        $createResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouses/inventory', [
                'warehouse_id' => $warehouse->id,
                'inventory_date' => '2026-08-29',
            ])
            ->assertCreated()
            ->assertJsonPath('data.items.0.location_code', '0')
            ->assertJsonPath('data.items.0.batch_number', '0');

        $actId = (int) $createResponse->json('data.id');
        $itemId = (int) $createResponse->json('data.items.0.id');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/warehouses/inventory/{$actId}/start")
            ->assertOk();

        $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/warehouses/inventory/{$actId}/items/{$itemId}", [
                'actual_quantity' => 4,
            ])
            ->assertOk();

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/warehouses/inventory/{$actId}/complete")
            ->assertOk();

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/warehouses/inventory/{$actId}/approve")
            ->assertOk();

        $this->assertSame(4.0, (float) $balance->fresh()->available_quantity);
        $this->assertSame(1, WarehouseBalance::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('material_id', $material->id)
            ->count());
    }

    public function test_advanced_reservation_rejects_foreign_warehouse_material_and_project_before_mutation(): void
    {
        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $foreignUnit = $this->createUnit($foreignContext->organization->id);
        $foreignWarehouse = $this->createWarehouse($foreignContext->organization->id, 'Foreign reservation warehouse', 'RES-FOR');
        $foreignMaterial = $this->createMaterial($foreignContext->organization->id, $foreignUnit->id, 'Foreign paint', 'PNT-FOR');
        $foreignProject = Project::factory()->create([
            'organization_id' => $foreignContext->organization->id,
        ]);
        $this->allowAdminAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/advanced-warehouse/reservations', [
                'warehouse_id' => $foreignWarehouse->id,
                'material_id' => $foreignMaterial->id,
                'project_id' => $foreignProject->id,
                'quantity' => 1,
                'reason' => 'Foreign reservation attempt',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['warehouse_id', 'material_id', 'project_id']);
        $this->assertDatabaseMissing('asset_reservations', [
            'organization_id' => $context->organization->id,
        ]);
    }

    public function test_auto_reorder_rule_rejects_foreign_warehouse_material_and_supplier_before_mutation(): void
    {
        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $foreignUnit = $this->createUnit($foreignContext->organization->id);
        $foreignWarehouse = $this->createWarehouse($foreignContext->organization->id, 'Foreign reorder warehouse', 'REORDER-FOR');
        $foreignMaterial = $this->createMaterial($foreignContext->organization->id, $foreignUnit->id, 'Foreign adhesive', 'ADH-FOR');
        $foreignSupplier = Supplier::query()->create([
            'organization_id' => $foreignContext->organization->id,
            'name' => 'Foreign supplier',
            'is_active' => true,
        ]);
        $this->allowAdminAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/advanced-warehouse/auto-reorder/rules', [
                'warehouse_id' => $foreignWarehouse->id,
                'material_id' => $foreignMaterial->id,
                'min_stock_level' => 1,
                'reorder_point' => 2,
                'reorder_quantity' => 3,
                'supplier_id' => $foreignSupplier->id,
                'is_active' => true,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['warehouse_id', 'material_id', 'default_supplier_id']);
        $this->assertDatabaseMissing('auto_reorder_rules', [
            'organization_id' => $context->organization->id,
        ]);
    }

    public function test_advanced_analytics_rejects_foreign_warehouse_and_asset_filters(): void
    {
        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $foreignUnit = $this->createUnit($foreignContext->organization->id);
        $foreignWarehouse = $this->createWarehouse($foreignContext->organization->id, 'Foreign analytics warehouse', 'AN-FOR');
        $foreignMaterial = $this->createMaterial($foreignContext->organization->id, $foreignUnit->id, 'Foreign analytics material', 'AN-MAT-FOR');
        $this->allowAdminAccess();

        $turnoverResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/advanced-warehouse/analytics/turnover?'.http_build_query([
                'warehouse_id' => $foreignWarehouse->id,
            ]));

        $turnoverResponse->assertStatus(422);
        $turnoverResponse->assertJsonValidationErrors('warehouse_id');

        $forecastResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/advanced-warehouse/analytics/forecast?'.http_build_query([
                'asset_ids' => [$foreignMaterial->id],
            ]));

        $forecastResponse->assertStatus(422);
        $forecastResponse->assertJsonValidationErrors('asset_ids.0');

        $forecastWarehouseResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/advanced-warehouse/analytics/forecast?'.http_build_query([
                'warehouse_id' => $foreignWarehouse->id,
            ]));

        $forecastWarehouseResponse->assertStatus(422);
        $forecastWarehouseResponse->assertJsonValidationErrors('warehouse_id');

        $abcXyzResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/advanced-warehouse/analytics/abc-xyz?'.http_build_query([
                'warehouse_id' => $foreignWarehouse->id,
            ]));

        $abcXyzResponse->assertStatus(422);
        $abcXyzResponse->assertJsonValidationErrors('warehouse_id');
    }

    public function test_advanced_analytics_are_scoped_to_selected_warehouse(): void
    {
        $context = AdminApiTestContext::create();
        $unit = $this->createUnit($context->organization->id);
        $mainWarehouse = $this->createWarehouse($context->organization->id, 'Main warehouse', 'MAIN-ANL');
        $reserveWarehouse = $this->createWarehouse($context->organization->id, 'Reserve warehouse', 'RES-ANL');
        $mainMaterial = $this->createMaterial($context->organization->id, $unit->id, 'Main cement', 'MAIN-CEM-ANL');
        $reserveMaterial = $this->createMaterial($context->organization->id, $unit->id, 'Reserve cement', 'RES-CEM-ANL');
        $this->createBalance($context->organization->id, $mainWarehouse->id, $mainMaterial->id, 40, 100);
        $this->createBalance($context->organization->id, $reserveWarehouse->id, $reserveMaterial->id, 1000, 200);
        $this->createMovement($context->organization->id, $mainWarehouse->id, $mainMaterial->id, 9, 100);
        $this->createMovement($context->organization->id, $reserveWarehouse->id, $reserveMaterial->id, 90, 200);
        $this->allowAdminAccess();

        $forecastResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/advanced-warehouse/analytics/forecast?'.http_build_query([
                'warehouse_id' => $mainWarehouse->id,
                'horizon_days' => 30,
            ]));

        $forecastResponse->assertOk();
        $forecastIds = collect($forecastResponse->json('data.forecasts'))->pluck('asset_id')->all();
        $this->assertSame([$mainMaterial->id], $forecastIds);
        $this->assertSame(40.0, (float) $forecastResponse->json('data.forecasts.0.current_stock'));

        $abcXyzResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/advanced-warehouse/analytics/abc-xyz?'.http_build_query([
                'warehouse_id' => $mainWarehouse->id,
            ]));

        $abcXyzResponse->assertOk();
        $analysisIds = collect($abcXyzResponse->json('data.assets'))->pluck('asset_id')->all();
        $this->assertSame([$mainMaterial->id], $analysisIds);
    }

    public function test_admin_viewer_cannot_manage_inventory_or_project_allocations_without_warehouse_permissions(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'admin_viewer');
        $unit = $this->createUnit($context->organization->id);
        $warehouse = $this->createWarehouse($context->organization->id, 'Viewer warehouse', 'VIEW-WH');
        $material = $this->createMaterial($context->organization->id, $unit->id, 'Sand', 'SND-VIEW');
        $project = Project::factory()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Viewer project',
        ]);
        $this->createBalance($context->organization->id, $warehouse->id, $material->id, 10, 50);

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouses/inventory', [
                'warehouse_id' => $warehouse->id,
                'inventory_date' => '2026-05-12',
            ])
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/project-allocations', [
                'warehouse_id' => $warehouse->id,
                'material_id' => $material->id,
                'project_id' => $project->id,
                'quantity' => 1,
            ])
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->assertDatabaseMissing('inventory_acts', [
            'organization_id' => $context->organization->id,
        ]);
        $this->assertDatabaseMissing('warehouse_project_allocations', [
            'organization_id' => $context->organization->id,
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

    private function createBalance(
        int $organizationId,
        int $warehouseId,
        int $materialId,
        float $quantity,
        float $price,
        ?string $locationCode = null,
        ?string $batchNumber = null
    ): WarehouseBalance {
        return WarehouseBalance::query()->create([
            'organization_id' => $organizationId,
            'warehouse_id' => $warehouseId,
            'material_id' => $materialId,
            'available_quantity' => $quantity,
            'reserved_quantity' => 0,
            'unit_price' => $price,
            'min_stock_level' => 0,
            'max_stock_level' => 0,
            'location_code' => $locationCode,
            'batch_number' => $batchNumber,
            'last_movement_at' => now(),
            'created_at' => now(),
        ]);
    }

    private function createMovement(
        int $organizationId,
        int $warehouseId,
        int $materialId,
        float $quantity,
        float $price
    ): WarehouseMovement {
        return WarehouseMovement::query()->create([
            'organization_id' => $organizationId,
            'warehouse_id' => $warehouseId,
            'material_id' => $materialId,
            'movement_type' => WarehouseMovement::TYPE_WRITE_OFF,
            'quantity' => $quantity,
            'price' => $price,
            'movement_date' => now()->subDays(5),
        ]);
    }

    private function availableQuantity(int $organizationId, int $warehouseId, int $materialId): float
    {
        return (float) WarehouseBalance::query()
            ->where('organization_id', $organizationId)
            ->where('warehouse_id', $warehouseId)
            ->where('material_id', $materialId)
            ->sum('available_quantity');
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
