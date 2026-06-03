<?php

declare(strict_types=1);

namespace Tests\Feature\BasicWarehouse;

use App\BusinessModules\Features\BasicWarehouse\Enums\ProjectMaterialDeliveryStatusEnum;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\ProjectMaterialDelivery;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\BudgetEstimates\Services\ConstructionJournalService;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\ConstructionJournal;
use App\Models\Material;
use App\Models\MeasurementUnit;
use App\Models\Project;
use App\Models\User;
use App\Services\Mobile\MobileConstructionJournalService;
use DomainException;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class WarehouseJournalConsumptionTest extends TestCase
{
    public function test_journal_material_consumption_writes_off_from_responsible_custody_warehouse(): void
    {
        $context = AdminApiTestContext::create();
        $setup = $this->createAcceptedDeliveryWithCustodyBalance($context, 10);

        $entry = app(ConstructionJournalService::class)->createEntry($setup['journal'], [
            'entry_date' => '2026-06-03',
            'work_description' => 'Монтаж крепежа',
            'materials' => [
                [
                    'material_id' => $setup['material']->id,
                    'project_material_delivery_id' => $setup['delivery']->id,
                    'material_name' => $setup['material']->name,
                    'quantity' => 4,
                    'measurement_unit' => 'шт',
                ],
            ],
        ], $context->user);

        $journalMaterial = $entry->materials()->firstOrFail();

        $this->assertDatabaseHas('warehouse_balances', [
            'warehouse_id' => $setup['custodyWarehouse']->id,
            'material_id' => $setup['material']->id,
            'available_quantity' => 6,
        ]);
        $this->assertDatabaseHas('warehouse_movements', [
            'warehouse_id' => $setup['custodyWarehouse']->id,
            'material_id' => $setup['material']->id,
            'movement_type' => WarehouseMovement::TYPE_WRITE_OFF,
            'operation_category' => WarehouseMovement::CATEGORY_PRODUCTION_USAGE,
            'project_material_delivery_id' => $setup['delivery']->id,
            'related_user_id' => $context->user->id,
            'quantity' => 4,
        ]);
        $this->assertNotNull($journalMaterial->warehouse_movement_id);
        $this->assertSame($setup['custodyWarehouse']->id, (int) $journalMaterial->custody_warehouse_id);
    }

    public function test_journal_material_consumption_cannot_exceed_responsible_balance(): void
    {
        $context = AdminApiTestContext::create();
        $setup = $this->createAcceptedDeliveryWithCustodyBalance($context, 2);

        $this->expectException(DomainException::class);

        app(ConstructionJournalService::class)->createEntry($setup['journal'], [
            'entry_date' => '2026-06-03',
            'work_description' => 'Монтаж крепежа',
            'materials' => [
                [
                    'material_id' => $setup['material']->id,
                    'project_material_delivery_id' => $setup['delivery']->id,
                    'material_name' => $setup['material']->name,
                    'quantity' => 3,
                    'measurement_unit' => 'шт',
                ],
            ],
        ], $context->user);
    }

    public function test_direct_write_off_requires_non_production_category_without_journal_context(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAdminAccess();
        $setup = $this->createAcceptedDeliveryWithCustodyBalance($context, 10);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouses/operations/write-off', [
                'warehouse_id' => $setup['custodyWarehouse']->id,
                'material_id' => $setup['material']->id,
                'quantity' => 1,
                'reason' => 'Расход на работы',
                'operation_category' => WarehouseMovement::CATEGORY_PRODUCTION_USAGE,
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('warehouse_balances', [
            'warehouse_id' => $setup['custodyWarehouse']->id,
            'material_id' => $setup['material']->id,
            'available_quantity' => 10,
        ]);
    }

    public function test_journal_consumption_materials_cannot_be_replaced_after_stock_write_off(): void
    {
        $context = AdminApiTestContext::create();
        $setup = $this->createAcceptedDeliveryWithCustodyBalance($context, 10);
        $service = app(ConstructionJournalService::class);

        $entry = $service->createEntry($setup['journal'], [
            'entry_date' => '2026-06-03',
            'work_description' => 'Монтаж крепежа',
            'materials' => [
                [
                    'material_id' => $setup['material']->id,
                    'project_material_delivery_id' => $setup['delivery']->id,
                    'material_name' => $setup['material']->name,
                    'quantity' => 4,
                    'measurement_unit' => 'шт',
                ],
            ],
        ], $context->user);

        $this->expectException(DomainException::class);

        try {
            $service->updateEntry($entry, [
                'materials' => [
                    [
                        'material_id' => $setup['material']->id,
                        'project_material_delivery_id' => $setup['delivery']->id,
                        'material_name' => $setup['material']->name,
                        'quantity' => 3,
                        'measurement_unit' => 'шт',
                    ],
                ],
            ]);
        } finally {
            $this->assertDatabaseHas('warehouse_balances', [
                'warehouse_id' => $setup['custodyWarehouse']->id,
                'material_id' => $setup['material']->id,
                'available_quantity' => 6,
            ]);
            $this->assertSame(1, WarehouseMovement::query()
                ->where('warehouse_id', $setup['custodyWarehouse']->id)
                ->where('material_id', $setup['material']->id)
                ->where('movement_type', WarehouseMovement::TYPE_WRITE_OFF)
                ->where('operation_category', WarehouseMovement::CATEGORY_PRODUCTION_USAGE)
                ->count());
        }
    }

    public function test_mobile_project_material_options_do_not_treat_project_stock_as_consumable_balance(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAdminAccess();
        $setup = $this->createAcceptedDeliveryWithCustodyBalance($context, 0);

        WarehouseBalance::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $setup['projectWarehouse']->id,
            'material_id' => $setup['material']->id,
            'available_quantity' => 7,
            'reserved_quantity' => 0,
            'unit_price' => 12,
        ]);

        $options = app(MobileConstructionJournalService::class)
            ->buildEntryFormOptions($context->user, $setup['journal']);

        $projectMaterial = collect($options['project_materials'])
            ->firstWhere('project_material_delivery_id', $setup['delivery']->id);

        $this->assertNotNull($projectMaterial);
        $this->assertSame(0.0, $projectMaterial['available_quantity']);
        $this->assertSame(0.0, $projectMaterial['custody_available_quantity']);
        $this->assertSame(7.0, $projectMaterial['project_warehouse_available_quantity']);
        $this->assertFalse($projectMaterial['can_consume_from_custody']);
        $this->assertTrue($projectMaterial['can_issue_from_project']);
        $this->assertTrue($projectMaterial['requires_issue_from_project']);
    }

    private function createAcceptedDeliveryWithCustodyBalance(
        AdminApiTestContext $context,
        float $custodyQuantity
    ): array {
        $project = Project::factory()->create([
            'organization_id' => $context->organization->id,
        ]);
        $measurementUnit = MeasurementUnit::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Штука',
            'short_name' => 'шт-' . $project->id,
            'type' => 'material',
            'is_default' => false,
            'is_system' => false,
        ]);
        $material = Material::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Гвозди',
            'code' => 'NAILS-' . $project->id,
            'measurement_unit_id' => $measurementUnit->id,
            'default_price' => 12,
            'is_active' => true,
        ]);
        $journal = ConstructionJournal::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'name' => 'Журнал работ',
            'journal_number' => 'J-' . $project->id,
            'start_date' => '2026-06-01',
            'status' => 'active',
            'created_by_user_id' => $context->user->id,
        ]);
        $projectWarehouse = OrganizationWarehouse::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'name' => 'Склад объекта',
            'code' => 'PRJ-' . $project->id,
            'warehouse_type' => OrganizationWarehouse::TYPE_PROJECT,
            'is_main' => false,
            'is_active' => true,
        ]);
        $custodyWarehouse = OrganizationWarehouse::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'responsible_user_id' => $context->user->id,
            'name' => 'Ответственное хранение',
            'code' => 'CUST-' . $project->id . '-' . $context->user->id,
            'warehouse_type' => OrganizationWarehouse::TYPE_CUSTODY,
            'is_main' => false,
            'is_active' => true,
        ]);
        $delivery = ProjectMaterialDelivery::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'material_id' => $material->id,
            'warehouse_id' => $projectWarehouse->id,
            'project_warehouse_id' => $projectWarehouse->id,
            'status' => ProjectMaterialDeliveryStatusEnum::ACCEPTED,
            'requested_quantity' => 10,
            'reserved_quantity' => 10,
            'shipped_quantity' => 10,
            'accepted_quantity' => 10,
            'accepted_at' => now(),
            'receiver_user_id' => $context->user->id,
        ]);

        WarehouseBalance::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $custodyWarehouse->id,
            'material_id' => $material->id,
            'available_quantity' => $custodyQuantity,
            'reserved_quantity' => 0,
            'unit_price' => 12,
        ]);

        return [
            'project' => $project,
            'material' => $material,
            'journal' => $journal,
            'projectWarehouse' => $projectWarehouse,
            'custodyWarehouse' => $custodyWarehouse,
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
