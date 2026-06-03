<?php

declare(strict_types=1);

namespace Tests\Feature\BasicWarehouse;

use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Material;
use App\Models\Project;
use App\Models\User;
use App\Services\Storage\FileService;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class WarehouseCustodyFlowTest extends TestCase
{
    public function test_admin_can_issue_material_to_responsible_user(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAdminAccess();
        $setup = $this->createProjectWarehouseContext($context);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouses/custody/issue', [
                'project_id' => $setup['project']->id,
                'project_warehouse_id' => $setup['projectWarehouse']->id,
                'material_id' => $setup['material']->id,
                'responsible_user_id' => $setup['responsibleUser']->id,
                'quantity' => 20,
                'document_number' => 'REQ-1',
                'reason' => 'Issue for work',
            ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.movement_out.operation_category', WarehouseMovement::CATEGORY_RESPONSIBLE_ISSUE);
        $response->assertJsonPath('data.movement_out.operation_category_label', trans_message('basic_warehouse.operation_categories.responsible_issue'));
        $response->assertJsonPath('data.movement_out.related_user.id', $setup['responsibleUser']->id);
        $response->assertJsonPath('data.movement_in.operation_category', WarehouseMovement::CATEGORY_RESPONSIBLE_ISSUE);
        $response->assertJsonPath('data.movement_in.related_user.id', $setup['responsibleUser']->id);

        $custodyWarehouse = OrganizationWarehouse::query()
            ->where('warehouse_type', OrganizationWarehouse::TYPE_CUSTODY)
            ->where('project_id', $setup['project']->id)
            ->where('responsible_user_id', $setup['responsibleUser']->id)
            ->firstOrFail();

        $this->assertDatabaseHas('warehouse_balances', [
            'warehouse_id' => $setup['projectWarehouse']->id,
            'material_id' => $setup['material']->id,
            'available_quantity' => 30,
        ]);
        $this->assertDatabaseHas('warehouse_balances', [
            'warehouse_id' => $custodyWarehouse->id,
            'material_id' => $setup['material']->id,
            'available_quantity' => 20,
        ]);

        $this->assertDatabaseHas('warehouse_movements', [
            'warehouse_id' => $setup['projectWarehouse']->id,
            'to_warehouse_id' => $custodyWarehouse->id,
            'material_id' => $setup['material']->id,
            'movement_type' => WarehouseMovement::TYPE_TRANSFER_OUT,
            'operation_category' => WarehouseMovement::CATEGORY_RESPONSIBLE_ISSUE,
            'related_user_id' => $setup['responsibleUser']->id,
            'quantity' => 20,
        ]);
        $this->assertDatabaseHas('warehouse_movements', [
            'warehouse_id' => $custodyWarehouse->id,
            'from_warehouse_id' => $setup['projectWarehouse']->id,
            'material_id' => $setup['material']->id,
            'movement_type' => WarehouseMovement::TYPE_TRANSFER_IN,
            'operation_category' => WarehouseMovement::CATEGORY_RESPONSIBLE_ISSUE,
            'related_user_id' => $setup['responsibleUser']->id,
            'quantity' => 20,
        ]);
    }

    public function test_admin_can_return_material_from_responsible_user_to_project(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAdminAccess();
        $setup = $this->createProjectWarehouseContext($context);

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouses/custody/issue', [
                'project_id' => $setup['project']->id,
                'project_warehouse_id' => $setup['projectWarehouse']->id,
                'material_id' => $setup['material']->id,
                'responsible_user_id' => $setup['responsibleUser']->id,
                'quantity' => 20,
            ])
            ->assertOk();

        $custodyWarehouse = OrganizationWarehouse::query()
            ->where('warehouse_type', OrganizationWarehouse::TYPE_CUSTODY)
            ->where('project_id', $setup['project']->id)
            ->where('responsible_user_id', $setup['responsibleUser']->id)
            ->firstOrFail();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouses/custody/return', [
                'custody_warehouse_id' => $custodyWarehouse->id,
                'material_id' => $setup['material']->id,
                'quantity' => 7,
                'document_number' => 'RET-1',
                'reason' => 'Return unused',
            ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $this->assertDatabaseHas('warehouse_balances', [
            'warehouse_id' => $setup['projectWarehouse']->id,
            'material_id' => $setup['material']->id,
            'available_quantity' => 37,
        ]);
        $this->assertDatabaseHas('warehouse_balances', [
            'warehouse_id' => $custodyWarehouse->id,
            'material_id' => $setup['material']->id,
            'available_quantity' => 13,
        ]);
        $this->assertDatabaseHas('warehouse_movements', [
            'warehouse_id' => $custodyWarehouse->id,
            'to_warehouse_id' => $setup['projectWarehouse']->id,
            'material_id' => $setup['material']->id,
            'movement_type' => WarehouseMovement::TYPE_TRANSFER_OUT,
            'operation_category' => WarehouseMovement::CATEGORY_RESPONSIBLE_RETURN,
            'related_user_id' => $setup['responsibleUser']->id,
            'quantity' => 7,
        ]);
    }

    public function test_admin_can_list_responsible_custody_balances(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAdminAccess();
        $setup = $this->createProjectWarehouseContext($context);

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouses/custody/issue', [
                'project_id' => $setup['project']->id,
                'project_warehouse_id' => $setup['projectWarehouse']->id,
                'material_id' => $setup['material']->id,
                'responsible_user_id' => $setup['responsibleUser']->id,
                'quantity' => 12.5,
            ])
            ->assertOk();

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/warehouses/custody/balances?project_id='.$setup['project']->id);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.0.project_id', $setup['project']->id);
        $response->assertJsonPath('data.0.material_id', $setup['material']->id);
        $response->assertJsonPath('data.0.responsible_user_id', $setup['responsibleUser']->id);
        $response->assertJsonPath('data.0.available_quantity', 12.5);
    }

    public function test_admin_can_get_responsible_custody_summary(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAdminAccess();
        $setup = $this->createProjectWarehouseContext($context);
        $secondResponsible = $this->createResponsibleUser($context, $setup['project']);

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouses/custody/issue', [
                'project_id' => $setup['project']->id,
                'project_warehouse_id' => $setup['projectWarehouse']->id,
                'material_id' => $setup['material']->id,
                'responsible_user_id' => $setup['responsibleUser']->id,
                'quantity' => 12.5,
            ])
            ->assertOk();

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouses/custody/issue', [
                'project_id' => $setup['project']->id,
                'project_warehouse_id' => $setup['projectWarehouse']->id,
                'material_id' => $setup['material']->id,
                'responsible_user_id' => $secondResponsible->id,
                'quantity' => 7.5,
            ])
            ->assertOk();

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/warehouses/custody/summary?project_id='.$setup['project']->id);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.summary.responsible_users_count', 2);
        $response->assertJsonPath('data.summary.positions_count', 2);
        $response->assertJsonPath('data.summary.materials_count', 1);
        $response->assertJsonPath('data.summary.projects_count', 1);
        $this->assertEquals(20.0, $response->json('data.summary.total_quantity'));

        $rows = collect($response->json('data.rows'))->keyBy('responsible_user_id');

        $this->assertEquals(12.5, $rows->get($setup['responsibleUser']->id)['total_quantity']);
        $this->assertEquals(1, $rows->get($setup['responsibleUser']->id)['positions_count']);
        $this->assertEquals(7.5, $rows->get($secondResponsible->id)['total_quantity']);
        $this->assertEquals(1, $rows->get($secondResponsible->id)['positions_count']);
    }

    public function test_admin_can_export_responsible_custody_detail_and_summary(): void
    {
        Storage::fake('s3');

        $this->mock(FileService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('disk')->andReturn(Storage::disk('s3'));
            $mock->shouldReceive('temporaryUrl')->andReturnUsing(
                static fn (?string $path): string => 'https://files.local/'.basename((string) $path)
            );
        });

        $context = AdminApiTestContext::create();
        $this->allowAdminAccess();
        $setup = $this->createProjectWarehouseContext($context);

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouses/custody/issue', [
                'project_id' => $setup['project']->id,
                'project_warehouse_id' => $setup['projectWarehouse']->id,
                'material_id' => $setup['material']->id,
                'responsible_user_id' => $setup['responsibleUser']->id,
                'quantity' => 12.5,
            ])
            ->assertOk();

        $detailResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/warehouses/custody/export?mode=detail&responsible_user_id='.$setup['responsibleUser']->id);

        $detailResponse->assertOk();
        $detailResponse->assertJsonPath('success', true);
        $detailResponse->assertJsonPath('data.mode', 'detail');
        $this->assertStringContainsString('custody_detail_', $detailResponse->json('data.path'));
        $this->assertStringEndsWith('.xlsx', $detailResponse->json('data.path'));

        $summaryResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/warehouses/custody/export?mode=summary');

        $summaryResponse->assertOk();
        $summaryResponse->assertJsonPath('success', true);
        $summaryResponse->assertJsonPath('data.mode', 'summary');
        $this->assertStringContainsString('custody_summary_', $summaryResponse->json('data.path'));
        $this->assertStringEndsWith('.xlsx', $summaryResponse->json('data.path'));

        $this->assertCount(2, Storage::disk('s3')->allFiles());
    }

    public function test_admin_cannot_issue_more_than_project_stock(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAdminAccess();
        $setup = $this->createProjectWarehouseContext($context);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouses/custody/issue', [
                'project_id' => $setup['project']->id,
                'project_warehouse_id' => $setup['projectWarehouse']->id,
                'material_id' => $setup['material']->id,
                'responsible_user_id' => $setup['responsibleUser']->id,
                'quantity' => 51,
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('organization_warehouses', [
            'warehouse_type' => OrganizationWarehouse::TYPE_CUSTODY,
            'project_id' => $setup['project']->id,
            'responsible_user_id' => $setup['responsibleUser']->id,
        ]);
    }

    public function test_mobile_issue_requires_warehouse_stock_permission(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $this->denyWarehouseStockAccess();
        $setup = $this->createProjectWarehouseContext($context);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/mobile/warehouse/custody/issue', [
                'project_id' => $setup['project']->id,
                'project_warehouse_id' => $setup['projectWarehouse']->id,
                'material_id' => $setup['material']->id,
                'responsible_user_id' => $setup['responsibleUser']->id,
                'quantity' => 5,
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('organization_warehouses', [
            'warehouse_type' => OrganizationWarehouse::TYPE_CUSTODY,
            'project_id' => $setup['project']->id,
            'responsible_user_id' => $setup['responsibleUser']->id,
        ]);
    }

    private function createProjectWarehouseContext(AdminApiTestContext $context): array
    {
        $project = Project::factory()->create([
            'organization_id' => $context->organization->id,
        ]);
        $responsibleUser = User::factory()->create([
            'current_organization_id' => $context->organization->id,
        ]);
        $context->organization->users()->attach($responsibleUser->id, [
            'is_owner' => false,
            'is_active' => true,
            'settings' => null,
        ]);
        $project->users()->attach($responsibleUser->id, [
            'role' => 'foreman',
            'assigned_by_user_id' => $context->user->id,
            'is_active' => true,
            'assigned_at' => now(),
        ]);

        $projectWarehouse = OrganizationWarehouse::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'name' => 'Project warehouse',
            'code' => 'PRJ-'.$project->id,
            'warehouse_type' => OrganizationWarehouse::TYPE_PROJECT,
            'is_main' => false,
            'is_active' => true,
        ]);
        $material = Material::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Nails',
            'code' => 'NAILS-'.$project->id,
            'default_price' => 10,
            'is_active' => true,
        ]);

        WarehouseBalance::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $projectWarehouse->id,
            'material_id' => $material->id,
            'available_quantity' => 50,
            'reserved_quantity' => 0,
            'unit_price' => 10,
        ]);

        return [
            'project' => $project,
            'projectWarehouse' => $projectWarehouse,
            'responsibleUser' => $responsibleUser,
            'material' => $material,
        ];
    }

    private function createResponsibleUser(AdminApiTestContext $context, Project $project): User
    {
        $responsibleUser = User::factory()->create([
            'current_organization_id' => $context->organization->id,
        ]);

        $context->organization->users()->attach($responsibleUser->id, [
            'is_owner' => false,
            'is_active' => true,
            'settings' => null,
        ]);

        $project->users()->attach($responsibleUser->id, [
            'role' => 'foreman',
            'assigned_by_user_id' => $context->user->id,
            'is_active' => true,
            'assigned_at' => now(),
        ]);

        return $responsibleUser;
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

    private function denyWarehouseStockAccess(): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturnUsing(
                static fn (User $user, string $permission, ?array $context = null): bool => $permission !== 'warehouse.manage_stock'
            );
            $mock->shouldReceive('hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['foreman']);
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
