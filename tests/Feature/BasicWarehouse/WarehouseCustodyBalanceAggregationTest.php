<?php

declare(strict_types=1);

namespace Tests\Feature\BasicWarehouse;

use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Material;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class WarehouseCustodyBalanceAggregationTest extends TestCase
{
    // Regression: ISSUE-WH-CUSTODY-002 — партии одного материала отображались как одинаковые строки
    // Found by /qa on 2026-08-30
    // Report: .gstack/qa-reports/qa-report-admin-most-rf-2026-08-30.md
    public function test_balances_endpoint_aggregates_accounting_batches_for_one_responsible_material(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAdminAccess();
        $setup = $this->createProjectWarehouseContext($context);

        foreach ([5.0, 7.5] as $quantity) {
            $this->withHeaders($context->authHeaders())
                ->postJson('/api/v1/admin/warehouses/custody/issue', [
                    'idempotency_key' => (string) Str::uuid(),
                    'project_id' => $setup['project']->id,
                    'project_warehouse_id' => $setup['projectWarehouse']->id,
                    'material_id' => $setup['material']->id,
                    'responsible_user_id' => $setup['responsibleUser']->id,
                    'quantity' => $quantity,
                ])
                ->assertOk();
        }

        $custodyWarehouse = OrganizationWarehouse::query()
            ->where('warehouse_type', OrganizationWarehouse::TYPE_CUSTODY)
            ->where('project_id', $setup['project']->id)
            ->where('responsible_user_id', $setup['responsibleUser']->id)
            ->firstOrFail();

        self::assertSame(2, WarehouseBalance::query()
            ->where('warehouse_id', $custodyWarehouse->id)
            ->where('material_id', $setup['material']->id)
            ->count());

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/warehouses/custody/balances?project_id='.$setup['project']->id);

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.available_quantity', 12.5);
        $response->assertJsonPath('data.0.unit_price', 16);
        $response->assertJsonPath('data.0.project_id', $setup['project']->id);
        $response->assertJsonPath('data.0.material_id', $setup['material']->id);
        $response->assertJsonPath('data.0.responsible_user_id', $setup['responsibleUser']->id);
    }

    /**
     * @return array{project: Project, projectWarehouse: OrganizationWarehouse, responsibleUser: User, material: Material}
     */
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
            'available_quantity' => 5,
            'reserved_quantity' => 0,
            'unit_price' => 10,
        ]);
        WarehouseBalance::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $projectWarehouse->id,
            'material_id' => $material->id,
            'available_quantity' => 45,
            'reserved_quantity' => 0,
            'unit_price' => 20,
            'batch_number' => 'SECOND-BATCH',
        ]);

        return compact('project', 'projectWarehouse', 'responsibleUser', 'material');
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
