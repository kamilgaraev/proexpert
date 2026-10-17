<?php

declare(strict_types=1);

namespace Tests\Feature\BasicWarehouse;

use App\BusinessModules\Features\BasicWarehouse\Enums\ProjectMaterialDeliveryStatusEnum;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\ProjectMaterialDelivery;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseProjectAllocation;
use App\BusinessModules\Features\BasicWarehouse\Services\ProjectMaterialDeliveryService;
use App\BusinessModules\Features\BasicWarehouse\Services\WarehouseService;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestStatusEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestTypeEnum;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Material;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class ProjectMaterialDeliveryMovementTest extends TestCase
{
    public function test_project_delivery_can_store_project_warehouse_and_movement_links(): void
    {
        $this->assertTrue(Schema::hasColumn('organization_warehouses', 'project_id'));
        $this->assertTrue(Schema::hasColumn('organization_warehouses', 'responsible_user_id'));
        $this->assertTrue(Schema::hasColumn('project_material_deliveries', 'project_warehouse_id'));
        $this->assertTrue(Schema::hasColumn('warehouse_movements', 'related_user_id'));
        $this->assertTrue(Schema::hasColumn('warehouse_movements', 'operation_category'));
        $this->assertTrue(Schema::hasColumn('warehouse_movements', 'project_material_delivery_id'));
        $this->assertTrue(Schema::hasColumn('journal_materials', 'warehouse_movement_id'));
        $this->assertTrue(Schema::hasColumn('journal_materials', 'custody_warehouse_id'));
    }

    public function test_custody_warehouse_can_be_linked_to_project_and_responsible_user(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create([
            'organization_id' => $organization->id,
        ]);
        $responsibleUser = User::factory()->create([
            'current_organization_id' => $organization->id,
        ]);

        $warehouse = OrganizationWarehouse::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'responsible_user_id' => $responsibleUser->id,
            'name' => 'Ответственный: '.$responsibleUser->name,
            'code' => 'CUST-'.$responsibleUser->id,
            'warehouse_type' => OrganizationWarehouse::TYPE_CUSTODY,
            'is_active' => true,
            'is_main' => false,
        ]);

        $this->assertSame(OrganizationWarehouse::TYPE_CUSTODY, $warehouse->warehouse_type);
        $this->assertSame($project->id, $warehouse->project?->id);
        $this->assertSame($responsibleUser->id, $warehouse->responsibleUser?->id);
    }

    public function test_shipping_delivery_creates_outbound_movement_and_decreases_source_stock(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAdminAccess();
        $setup = $this->createDeliveryContext($context);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/project-material-deliveries/{$setup['delivery']->id}/ship", [
                'quantity' => 30,
                'responsible_user_id' => $context->user->id,
                'notes' => 'Отправка на объект',
            ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath(
            'data.project_warehouse.name',
            'Объектовый склад: '.$setup['project']->name,
        );

        $this->assertDatabaseHas('warehouse_balances', [
            'warehouse_id' => $setup['sourceWarehouse']->id,
            'material_id' => $setup['material']->id,
            'available_quantity' => 70,
        ]);

        $delivery = $setup['delivery']->fresh();

        $this->assertNotNull($delivery->outbound_movement_id);
        $this->assertNotNull($delivery->project_warehouse_id);
        $this->assertSame('in_transit', $delivery->status->value);
        $transitWarehouse = OrganizationWarehouse::query()
            ->where('organization_id', $context->organization->id)
            ->where('project_id', $setup['project']->id)
            ->where('warehouse_type', OrganizationWarehouse::TYPE_EXTERNAL)
            ->where('settings->purpose', 'project_delivery_transit')
            ->firstOrFail();
        $this->assertSame(
            'Доставка на объект: '.$setup['project']->name,
            $transitWarehouse->name,
        );

        $this->assertDatabaseHas('warehouse_movements', [
            'id' => $delivery->outbound_movement_id,
            'warehouse_id' => $setup['sourceWarehouse']->id,
            'to_warehouse_id' => $transitWarehouse->id,
            'material_id' => $setup['material']->id,
            'movement_type' => WarehouseMovement::TYPE_TRANSFER_OUT,
            'quantity' => 30,
            'operation_category' => WarehouseMovement::CATEGORY_PROJECT_DELIVERY,
            'project_material_delivery_id' => $delivery->id,
            'related_user_id' => $context->user->id,
        ]);

        $this->assertDatabaseHas('warehouse_balances', [
            'warehouse_id' => $transitWarehouse->id,
            'material_id' => $setup['material']->id,
            'available_quantity' => 30,
        ]);

        $balance = app(WarehouseService::class)->getAssetBalance(
            $context->organization->id,
            $setup['sourceWarehouse']->id,
            $setup['material']->id,
        );

        $this->assertNotNull($balance);
        $this->assertSame(0.0, $balance->getAllocatedQuantity());
        $this->assertSame(70.0, $balance->getAvailableForAllocation());

        $stock = app(WarehouseService::class)->getStockData(
            $context->organization->id,
            ['warehouse_id' => $setup['sourceWarehouse']->id],
        );

        $this->assertSame(0.0, (float) $stock[0]['allocated_total']);
        $this->assertSame(70.0, (float) $stock[0]['unallocated_quantity']);
        $this->assertSame([], $stock[0]['project_allocations']);
    }

    public function test_purchase_delivery_binds_the_selected_warehouse_on_first_shipment(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAdminAccess();
        $setup = $this->createPurchaseDeliveryContext($context);
        $endpoint = "/api/v1/admin/project-material-deliveries/{$setup['delivery']->id}/ship";

        $this->withHeaders($context->authHeaders())->postJson($endpoint, [
            'quantity' => 10,
            'warehouse_id' => $setup['sourceWarehouse']->id,
        ])->assertOk();

        $delivery = $setup['delivery']->fresh();
        $this->assertSame($setup['sourceWarehouse']->id, $delivery->warehouse_id);
        $this->assertSame('purchase', $delivery->source_type);
        $this->assertSame($setup['siteRequest']->id, $delivery->site_request_id);
        $this->assertNull($delivery->warehouse_project_allocation_id);
        $this->assertSame(10.0, (float) $delivery->shipped_quantity);
        $this->assertDatabaseHas('warehouse_movements', [
            'id' => $delivery->outbound_movement_id,
            'warehouse_id' => $setup['sourceWarehouse']->id,
            'material_id' => $setup['material']->id,
            'quantity' => 10,
            'project_material_delivery_id' => $delivery->id,
        ]);

        $this->withHeaders($context->authHeaders())->postJson($endpoint, [
            'quantity' => 5,
            'warehouse_id' => $setup['sourceWarehouse']->id,
        ])->assertOk();
        $this->assertSame(15.0, (float) $delivery->fresh()->shipped_quantity);
        $this->assertDatabaseHas('warehouse_balances', [
            'warehouse_id' => $setup['sourceWarehouse']->id,
            'material_id' => $setup['material']->id,
            'available_quantity' => 85,
        ]);
    }

    public function test_purchase_delivery_can_ship_from_its_project_warehouse(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAdminAccess();
        $setup = $this->createPurchaseDeliveryContext($context);
        $setup['sourceWarehouse']->update(['project_id' => $setup['project']->id, 'warehouse_type' => OrganizationWarehouse::TYPE_PROJECT]);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/project-material-deliveries/{$setup['delivery']->id}/ship", [
                'quantity' => 10, 'warehouse_id' => $setup['sourceWarehouse']->id,
            ])->assertOk();
        $this->assertSame($setup['sourceWarehouse']->id, $setup['delivery']->fresh()->warehouse_id);
    }

    public function test_purchase_delivery_rejects_invalid_warehouse_context_and_rolls_back_binding(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAdminAccess();
        foreach (['foreign_organization', 'other_project', 'inactive', 'deleted', 'physical_shortage', 'transit', 'allocated_stock'] as $case) {
            $setup = $this->createPurchaseDeliveryContext($context);
            if ($case === 'foreign_organization') {
                $setup['sourceWarehouse']->update(['organization_id' => Organization::factory()->create()->id]);
            } elseif ($case === 'other_project') {
                $otherProject = Project::factory()->create(['organization_id' => $context->organization->id]);
                $setup['sourceWarehouse']->update(['project_id' => $otherProject->id, 'warehouse_type' => OrganizationWarehouse::TYPE_PROJECT]);
            } elseif ($case === 'inactive') {
                $setup['sourceWarehouse']->update(['is_active' => false]);
            } elseif ($case === 'deleted') {
                $setup['sourceWarehouse']->delete();
            } elseif ($case === 'physical_shortage') {
                WarehouseBalance::query()->where('warehouse_id', $setup['sourceWarehouse']->id)->update(['available_quantity' => 5]);
            } elseif ($case === 'transit') {
                $setup['sourceWarehouse']->update([
                    'project_id' => $setup['project']->id,
                    'warehouse_type' => OrganizationWarehouse::TYPE_EXTERNAL,
                    'settings' => ['purpose' => 'project_delivery_transit'],
                ]);
            } else {
                $setup['allocation']->update(['allocated_quantity' => 95]);
            }
            $movementCount = WarehouseMovement::query()->count();
            $warehouseCount = OrganizationWarehouse::withTrashed()->count();

            $this->withHeaders($context->authHeaders())
                ->postJson("/api/v1/admin/project-material-deliveries/{$setup['delivery']->id}/ship", [
                    'quantity' => 10, 'warehouse_id' => $setup['sourceWarehouse']->id,
                ])->assertUnprocessable();

            $delivery = $setup['delivery']->fresh();
            $this->assertNull($delivery->warehouse_id, $case);
            $this->assertNull($delivery->outbound_movement_id, $case);
            $this->assertNull($delivery->project_warehouse_id, $case);
            $this->assertSame(0.0, (float) $delivery->shipped_quantity, $case);
            $this->assertSame($movementCount, WarehouseMovement::query()->count(), $case);
            $this->assertSame($warehouseCount, OrganizationWarehouse::withTrashed()->count(), $case);
        }
    }

    public function test_purchase_delivery_uses_outstanding_allocations_and_does_not_subtract_physical_reserves_twice(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAdminAccess();
        $setup = $this->createPurchaseDeliveryContext($context);
        $setup['allocation']->update(['allocated_quantity' => 95]);
        ProjectMaterialDelivery::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $setup['project']->id,
            'material_id' => $setup['material']->id, 'warehouse_id' => $setup['sourceWarehouse']->id,
            'warehouse_project_allocation_id' => $setup['allocation']->id, 'source_type' => 'warehouse',
            'status' => ProjectMaterialDeliveryStatusEnum::IN_TRANSIT,
            'requested_quantity' => 95, 'reserved_quantity' => 95, 'shipped_quantity' => 90,
        ]);
        WarehouseBalance::query()->where('warehouse_id', $setup['sourceWarehouse']->id)
            ->update(['available_quantity' => 40, 'reserved_quantity' => 60]);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/project-material-deliveries/{$setup['delivery']->id}/ship", [
                'quantity' => 30, 'warehouse_id' => $setup['sourceWarehouse']->id,
            ])->assertOk();
        $this->assertDatabaseHas('warehouse_balances', [
            'warehouse_id' => $setup['sourceWarehouse']->id, 'material_id' => $setup['material']->id,
            'available_quantity' => 10, 'reserved_quantity' => 60,
        ]);
    }

    public function test_shipping_cannot_switch_an_existing_warehouse_or_rebind_a_legacy_shipment(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAdminAccess();
        foreach (['bound', 'already_shipped', 'legacy_missing_source'] as $case) {
            $setup = $this->createDeliveryContext($context);
            $otherWarehouse = $setup['sourceWarehouse']->replicate()->fill(['code' => 'OTHER-'.$setup['project']->id]);
            $otherWarehouse->save();
            if ($case !== 'bound') {
                $setup['delivery']->update(['shipped_quantity' => 10, 'status' => ProjectMaterialDeliveryStatusEnum::IN_TRANSIT]);
            }
            if ($case === 'legacy_missing_source') {
                $setup['delivery']->update(['warehouse_id' => null]);
            }
            $originalWarehouseId = $setup['delivery']->warehouse_id;
            $shipped = (float) $setup['delivery']->shipped_quantity;

            $this->withHeaders($context->authHeaders())
                ->postJson("/api/v1/admin/project-material-deliveries/{$setup['delivery']->id}/ship", [
                    'quantity' => 5, 'warehouse_id' => $otherWarehouse->id,
                ])->assertUnprocessable();
            $this->assertSame($originalWarehouseId, $setup['delivery']->fresh()->warehouse_id, $case);
            $this->assertSame($shipped, (float) $setup['delivery']->fresh()->shipped_quantity, $case);
            $this->assertSame(0, WarehouseMovement::query()->where('project_material_delivery_id', $setup['delivery']->id)->count(), $case);
        }
    }

    public function test_shipping_service_rejects_foreign_actor_and_responsible_user(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAdminAccess();
        $foreignContext = AdminApiTestContext::create();
        foreach (['actor', 'responsible'] as $case) {
            $setup = $this->createPurchaseDeliveryContext($context);
            try {
                app(ProjectMaterialDeliveryService::class)->ship(
                    $setup['delivery'], $case === 'actor' ? $foreignContext->user : $context->user,
                    ['quantity' => 10, 'warehouse_id' => $setup['sourceWarehouse']->id,
                        'responsible_user_id' => $case === 'responsible' ? $foreignContext->user->id : $context->user->id],
                );
                $this->fail('Foreign '.$case.' was accepted');
            } catch (\DomainException) {
                $this->assertNull($setup['delivery']->fresh()->warehouse_id, $case);
                $this->assertSame(0.0, (float) $setup['delivery']->fresh()->shipped_quantity, $case);
            }
        }
    }

    public function test_shipping_accepts_an_active_responsible_member_with_a_different_current_organization(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAdminAccess();
        $setup = $this->createPurchaseDeliveryContext($context);
        $responsibleContext = AdminApiTestContext::create();
        $responsibleContext->user->organizations()->attach($context->organization->id, ['is_active' => true]);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/project-material-deliveries/{$setup['delivery']->id}/ship", [
                'quantity' => 10, 'warehouse_id' => $setup['sourceWarehouse']->id,
                'responsible_user_id' => $responsibleContext->user->id,
            ])->assertOk();
        $this->assertSame($responsibleContext->user->id, $setup['delivery']->fresh()->responsible_user_id);
    }

    public function test_shipping_rejects_final_delivery_statuses_without_stock_movements(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAdminAccess();
        foreach ([ProjectMaterialDeliveryStatusEnum::CANCELLED, ProjectMaterialDeliveryStatusEnum::ACCEPTED] as $status) {
            $setup = $this->createPurchaseDeliveryContext($context);
            $setup['delivery']->update(['status' => $status]);
            $this->withHeaders($context->authHeaders())
                ->postJson("/api/v1/admin/project-material-deliveries/{$setup['delivery']->id}/ship", [
                    'quantity' => 5, 'warehouse_id' => $setup['sourceWarehouse']->id,
                ])->assertUnprocessable();
            $this->assertSame($status, $setup['delivery']->fresh()->status);
            $this->assertNull($setup['delivery']->fresh()->warehouse_id);
            $this->assertSame(0, WarehouseMovement::query()->where('project_material_delivery_id', $setup['delivery']->id)->count());
        }
    }

    public function test_additional_allocation_preserves_active_delivery_progress(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAdminAccess();
        $setup = $this->createDeliveryContext($context);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/project-material-deliveries/{$setup['delivery']->id}/ship", [
                'quantity' => 20,
            ])
            ->assertOk();

        $stock = app(WarehouseService::class)->getStockData(
            $context->organization->id,
            ['warehouse_id' => $setup['sourceWarehouse']->id],
        );

        $this->assertSame(10.0, (float) $stock[0]['allocated_total']);
        $this->assertSame(70.0, (float) $stock[0]['unallocated_quantity']);
        $this->assertSame(10.0, (float) $stock[0]['project_allocations'][0]['allocated_quantity']);
        $this->assertSame(30.0, (float) $stock[0]['project_allocations'][0]['planned_quantity']);

        $setup['allocation']->forceFill(['allocated_quantity' => 40])->save();

        $delivery = app(ProjectMaterialDeliveryService::class)->createFromAllocation(
            $setup['allocation']->fresh(),
            $context->user,
            [
                'quantity' => 10,
                'total_quantity' => 40,
            ],
        );

        $this->assertSame(ProjectMaterialDeliveryStatusEnum::IN_TRANSIT, $delivery->status);
        $this->assertSame(40.0, (float) $delivery->requested_quantity);
        $this->assertSame(40.0, (float) $delivery->reserved_quantity);
        $this->assertSame(20.0, (float) $delivery->shipped_quantity);
        $this->assertSame(20.0, $delivery->remainingQuantityToShip());
    }

    public function test_additional_allocation_reopens_cancelled_delivery_without_counting_returned_stock_as_shipped(): void
    {
        $context = AdminApiTestContext::create();
        $setup = $this->createDeliveryContext($context);
        $setup['delivery']->forceFill([
            'status' => ProjectMaterialDeliveryStatusEnum::CANCELLED,
            'shipped_quantity' => 10,
            'accepted_quantity' => 0,
        ])->save();
        $setup['allocation']->forceFill(['allocated_quantity' => 40])->save();

        $delivery = app(ProjectMaterialDeliveryService::class)->createFromAllocation(
            $setup['allocation']->fresh(),
            $context->user,
            [
                'quantity' => 10,
                'total_quantity' => 40,
            ],
        );

        $this->assertSame(ProjectMaterialDeliveryStatusEnum::RESERVED, $delivery->status);
        $this->assertSame(0.0, (float) $delivery->shipped_quantity);
        $this->assertSame(40.0, $delivery->remainingQuantityToShip());
    }

    public function test_admin_receiving_delivery_creates_inbound_movement_and_increases_project_stock(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAdminAccess();
        $setup = $this->createDeliveryContext($context);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/project-material-deliveries/{$setup['delivery']->id}/ship", [
                'quantity' => 30,
                'responsible_user_id' => $context->user->id,
            ])
            ->assertOk();

        $delivery = $setup['delivery']->fresh();

        $receivePayload = [
            'idempotency_key' => '66666666-6666-4666-8666-666666666666',
            'quantity' => 25,
            'notes' => 'Принято в админке',
        ];
        $response = $this->withHeaders($context->authHeaders())
            ->postJson(
                "/api/v1/admin/project-material-deliveries/{$delivery->id}/receive",
                $receivePayload,
            );

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->withHeaders($context->authHeaders())
            ->postJson(
                "/api/v1/admin/project-material-deliveries/{$delivery->id}/receive",
                $receivePayload,
            )
            ->assertOk();

        $delivery = $delivery->fresh();
        $transitWarehouse = OrganizationWarehouse::query()
            ->where('organization_id', $context->organization->id)
            ->where('project_id', $setup['project']->id)
            ->where('warehouse_type', OrganizationWarehouse::TYPE_EXTERNAL)
            ->where('settings->purpose', 'project_delivery_transit')
            ->firstOrFail();

        $this->assertSame('partially_delivered', $delivery->status->value);
        $this->assertSame(25.0, (float) $delivery->accepted_quantity);
        $this->assertNotNull($delivery->inbound_movement_id);

        $this->assertDatabaseHas('warehouse_balances', [
            'warehouse_id' => $delivery->project_warehouse_id,
            'material_id' => $setup['material']->id,
            'available_quantity' => 25,
        ]);

        $this->assertDatabaseHas('warehouse_movements', [
            'id' => $delivery->inbound_movement_id,
            'warehouse_id' => $delivery->project_warehouse_id,
            'from_warehouse_id' => $transitWarehouse->id,
            'material_id' => $setup['material']->id,
            'movement_type' => WarehouseMovement::TYPE_TRANSFER_IN,
            'quantity' => 25,
            'operation_category' => WarehouseMovement::CATEGORY_PROJECT_DELIVERY,
            'project_material_delivery_id' => $delivery->id,
        ]);
        $this->assertSame(1, WarehouseMovement::query()
            ->where('project_material_delivery_id', $delivery->id)
            ->where('movement_type', WarehouseMovement::TYPE_TRANSFER_IN)
            ->where('warehouse_id', $delivery->project_warehouse_id)
            ->count());

        $receivePayload['quantity'] = 24;
        $this->withHeaders($context->authHeaders())
            ->postJson(
                "/api/v1/admin/project-material-deliveries/{$delivery->id}/receive",
                $receivePayload,
            )
            ->assertStatus(409);
    }

    public function test_admin_delivery_cannot_receive_more_than_shipped(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAdminAccess();
        $setup = $this->createDeliveryContext($context);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/project-material-deliveries/{$setup['delivery']->id}/ship", [
                'quantity' => 10,
            ])
            ->assertOk();

        $delivery = $setup['delivery']->fresh();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson(
                "/api/v1/admin/project-material-deliveries/{$delivery->id}/receive",
                [
                    'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
                    'quantity' => 11,
                ]);

        $response->assertStatus(422);

        $this->assertSame(0.0, (float) $delivery->fresh()->accepted_quantity);
        $this->assertDatabaseMissing('warehouse_balances', [
            'warehouse_id' => $delivery->project_warehouse_id,
            'material_id' => $setup['material']->id,
            'available_quantity' => 11,
        ]);
    }

    public function test_accepting_current_shipment_keeps_delivery_active_when_more_is_reserved(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAdminAccess();
        $setup = $this->createDeliveryContext($context);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/project-material-deliveries/{$setup['delivery']->id}/ship", [
                'quantity' => 10,
            ])
            ->assertOk();

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/project-material-deliveries/{$setup['delivery']->id}/receive", [
                'idempotency_key' => '55555555-5555-4555-8555-555555555555',
                'quantity' => 10,
            ])
            ->assertOk();

        $delivery = $setup['delivery']->fresh();

        $this->assertSame(ProjectMaterialDeliveryStatusEnum::PARTIALLY_DELIVERED, $delivery->status);
        $this->assertNull($delivery->accepted_at);
        $this->assertSame(20.0, $delivery->remainingQuantityToShip());
        $this->assertSame(0.0, $delivery->remainingQuantityToAccept());
    }

    public function test_cancelling_partially_received_delivery_returns_only_unaccepted_transit_stock(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAdminAccess();
        $setup = $this->createDeliveryContext($context);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/project-material-deliveries/{$setup['delivery']->id}/ship", [
                'quantity' => 10,
            ])
            ->assertOk();

        $materialsResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/projects/{$setup['project']->id}/materials");
        $materialsResponse->assertOk();
        $materialsResponse->assertJsonPath('data.data.0.allocated_quantity', 30);
        $materialsResponse->assertJsonPath('data.data.0.awaiting_shipment_quantity', 20);
        $materialsResponse->assertJsonPath('data.data.0.departed_quantity', 10);
        $materialsResponse->assertJsonPath('data.data.0.removable_quantity', 20);

        $delivery = $setup['delivery']->fresh();
        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/project-material-deliveries/{$delivery->id}/receive", [
                'idempotency_key' => '88888888-8888-4888-8888-888888888888',
                'quantity' => 4,
            ])
            ->assertOk();

        $cancelEndpoint = "/api/v1/admin/project-material-deliveries/{$delivery->id}/cancel";
        $this->withHeaders($context->authHeaders())
            ->postJson($cancelEndpoint, ['notes' => 'Отмена остатка поставки'])
            ->assertOk();
        $this->withHeaders($context->authHeaders())
            ->postJson($cancelEndpoint, ['notes' => 'Повтор после таймаута'])
            ->assertOk();

        $delivery = $delivery->fresh();
        $this->assertSame(ProjectMaterialDeliveryStatusEnum::CANCELLED, $delivery->status);
        $this->assertSame(96.0, (float) WarehouseBalance::query()
            ->where('warehouse_id', $setup['sourceWarehouse']->id)
            ->where('material_id', $setup['material']->id)
            ->sum('available_quantity'));
        $this->assertSame(4.0, (float) WarehouseBalance::query()
            ->where('warehouse_id', $delivery->project_warehouse_id)
            ->where('material_id', $setup['material']->id)
            ->sum('available_quantity'));

        $reversalMovements = WarehouseMovement::query()
            ->where('project_material_delivery_id', $delivery->id)
            ->where('metadata->delivery_reversal', true)
            ->get();
        $this->assertCount(2, $reversalMovements);
        $this->assertSame(6.0, (float) $reversalMovements
            ->firstWhere('movement_type', WarehouseMovement::TYPE_TRANSFER_OUT)
            ->quantity);

        $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/project-allocations/{$setup['allocation']->id}", [
                'idempotency_key' => '99999999-9999-4999-8999-999999999999',
                'quantity' => 26,
            ])
            ->assertOk();

        $this->assertSame(4.0, (float) $setup['allocation']->fresh()->allocated_quantity);
        $this->assertSame(4.0, (float) $delivery->fresh()->requested_quantity);
        $this->assertSame(4.0, (float) $delivery->fresh()->reserved_quantity);
    }

    private function createPurchaseDeliveryContext(AdminApiTestContext $context): array
    {
        $setup = $this->createDeliveryContext($context);
        $siteRequest = SiteRequest::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $setup['project']->id,
            'user_id' => $context->user->id,
            'title' => 'Материалы из закупки',
            'status' => SiteRequestStatusEnum::APPROVED,
            'priority' => 'medium',
            'request_type' => SiteRequestTypeEnum::MATERIAL_REQUEST,
            'material_id' => $setup['material']->id,
            'material_name' => $setup['material']->name,
            'material_quantity' => 30,
            'material_unit' => 'шт',
        ]);
        $setup['delivery']->update([
            'warehouse_id' => null,
            'warehouse_project_allocation_id' => null,
            'site_request_id' => $siteRequest->id,
            'source_type' => 'purchase',
            'status' => ProjectMaterialDeliveryStatusEnum::PROCESSING,
            'reserved_quantity' => 0,
        ]);

        return [...$setup, 'siteRequest' => $siteRequest];
    }

    private function createDeliveryContext(AdminApiTestContext $context): array
    {
        $project = Project::factory()->create([
            'organization_id' => $context->organization->id,
        ]);
        $sourceWarehouse = OrganizationWarehouse::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Центральный склад',
            'code' => 'MAIN-'.$project->id,
            'warehouse_type' => OrganizationWarehouse::TYPE_CENTRAL,
            'is_main' => true,
            'is_active' => true,
        ]);
        $material = Material::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Гвозди строительные',
            'code' => 'NAILS-'.$project->id,
            'default_price' => 12.5,
            'is_active' => true,
        ]);

        WarehouseBalance::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $sourceWarehouse->id,
            'material_id' => $material->id,
            'available_quantity' => 100,
            'reserved_quantity' => 0,
            'unit_price' => 12.5,
        ]);

        $allocation = WarehouseProjectAllocation::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $sourceWarehouse->id,
            'material_id' => $material->id,
            'project_id' => $project->id,
            'allocated_quantity' => 30,
            'allocated_by_user_id' => $context->user->id,
            'allocated_at' => now(),
        ]);

        $delivery = ProjectMaterialDelivery::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'material_id' => $material->id,
            'warehouse_id' => $sourceWarehouse->id,
            'warehouse_project_allocation_id' => $allocation->id,
            'source_type' => 'warehouse',
            'status' => 'reserved',
            'requested_quantity' => 30,
            'reserved_quantity' => 30,
        ]);

        return [
            'project' => $project,
            'sourceWarehouse' => $sourceWarehouse,
            'material' => $material,
            'allocation' => $allocation,
            'delivery' => $delivery,
        ];
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
