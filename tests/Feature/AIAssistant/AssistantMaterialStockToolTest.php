<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Actions\Domains\GetMaterialStockTool;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantMaterialStockReader;
use App\BusinessModules\Features\AIAssistant\Services\AssistantSourceReferenceGuard;
use App\BusinessModules\Features\AIAssistant\Services\AssistantSourceReferenceIdentity;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseProjectAllocation;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Material;
use App\Models\MeasurementUnit;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TestCase;

final class AssistantMaterialStockToolTest extends TestCase
{
    use RefreshDatabase;

    private AssistantMaterialStockReader $reader;
    private AssistantDataAccessPolicy $policy;
    private Organization $organization;
    private User $actor;
    private Project $project;
    private bool $permissions = true;

    protected function setUp(): void
    {
        parent::setUp();
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('forCurrentChecks')->andReturnSelf();
        $authorization->shouldReceive('canCurrent')->andReturnUsing(fn (): bool => $this->permissions);
        $modules = Mockery::mock(OrganizationEntitlementService::class);
        $modules->shouldReceive('getEffectiveModules')->andReturn(collect(['ai-assistant', 'basic-warehouse', 'catalog-management', 'project-management'])
            ->map(static fn (string $slug): object => (object) ['slug' => $slug]));
        $this->policy = new AssistantDataAccessPolicy($authorization, new UserProjectAccessService, $modules);
        $this->app->instance(AssistantDataAccessPolicy::class, $this->policy);
        $this->reader = new AssistantMaterialStockReader($this->policy);
        $this->organization = Organization::withoutEvents(fn () => Organization::factory()->create());
        $this->actor = User::withoutEvents(fn () => User::factory()->create(['current_organization_id' => $this->organization->id, 'is_active' => true]));
        $this->actor->organizations()->attach($this->organization->id, ['is_active' => true, 'project_access_mode' => 'assigned_projects']);
        $this->project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->organization->id, 'is_archived' => false]));
        $this->actor->assignedProjects()->attach($this->project->id, ['is_active' => true, 'role' => 'member']);
    }

    public function test_live_sum_separates_reservations_and_units_and_excludes_private_contributions(): void
    {
        $warehouse = $this->warehouse($this->project);
        $material = $this->material('Бетон М300', 'т');
        $first = $this->balance($warehouse, $material, '4.000', '1.111');
        $second = $this->balance($warehouse, $material, '6.000', '2.222');
        $private = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->organization->id, 'is_archived' => false]));
        $hidden = $this->balance($this->warehouse($private), $material, '9876543210.111', '0.000');
        $cubic = $this->material('Бетон М400', 'м³');
        $this->balance($warehouse, $cubic, '0.125', '0.000');
        $unknown = $this->material('Бетон без единицы', null);
        $this->balance($warehouse, $unknown, '2.001', '0.000');
        $this->balance($warehouse, $this->material('Краска', 'л'), '99.000', '0.000');
        $permission = Mockery::mock(AIPermissionChecker::class);
        $permission->shouldReceive('canExecuteTool')->with($this->actor, 'get_material_stock', ['query' => 'Бетон'])->andReturn(true);
        $result = (new GetMaterialStockTool($this->reader, $permission))->execute(['query' => 'Бетон'], $this->actor, $this->organization);
        $this->assertSame('success', $result['status']);
        $this->assertCount(3, $result['stock']);
        $byId = array_column($result['stock'], null, 'material_id');
        $this->assertSame('10.000', $byId[$material->id]['available_quantity']);
        $this->assertSame('3.333', $byId[$material->id]['reserved_quantity']);
        $this->assertSame('0.125', $byId[$cubic->id]['available_quantity']);
        $this->assertNull($byId[$unknown->id]['measurement_unit_id']);
        $balanceRefs = array_values(array_filter($result['source_refs'], static fn (array $ref): bool => $ref['entity_type'] === 'warehouse_balance'));
        $this->assertContains($first->id, array_column($balanceRefs, 'entity_id'));
        $this->assertContains($second->id, array_column($balanceRefs, 'entity_id'));
        $this->assertNotContains($hidden->id, array_column($balanceRefs, 'entity_id'));
        $this->assertSame(['warehouse_id', 'material_id', 'available_quantity', 'reserved_quantity'], $balanceRefs[0]['checked_fields']);
        $this->assertTrue((new AssistantSourceReferenceGuard($this->policy))->fresh($this->actor, $this->organization->id, $result['source_refs']));
        $this->assertNotNull($this->reader->verifiedAnswer($result, $this->actor, $this->organization->id));
        $first->update(['available_quantity' => '7.000']);
        $this->assertNull($this->reader->verifiedAnswer($result, $this->actor, $this->organization->id));
        $this->assertFalse((new AssistantSourceReferenceGuard($this->policy))->fresh($this->actor, $this->organization->id, $result['source_refs']));
    }

    public function test_receipt_proves_all_contributions_and_detects_mutated_unit_and_actor(): void
    {
        $warehouse = $this->warehouse($this->project);
        $material = $this->material('Бетон', 'т');
        for ($i = 0; $i < 61; $i++) {
            $this->balance($warehouse, $material, '0.001', '0.002');
        }
        $result = $this->reader->read($this->actor, $this->organization->id, ['material_ids' => [$material->id]]);
        $this->assertSame('0.061', $result['stock'][0]['available_quantity']);
        $this->assertSame('0.122', $result['stock'][0]['reserved_quantity']);
        $this->assertCount(61, $result['stock_evidence']['rows'][0]['contributions']);
        $this->assertCount(63, $result['source_refs']);
        $forged = $result;
        $forged['stock_evidence']['rows'][0]['available_quantity'] = '100.000';
        $this->assertNull($this->reader->verifiedAnswer($forged, $this->actor, $this->organization->id));
        $forged['stock_evidence']['version'] = AssistantSourceReferenceIdentity::key(array_intersect_key($forged['stock_evidence'],
            array_flip(['scope', 'quantity_scope', 'organization_id', 'actor_id', 'filters', 'rows'])));
        $this->assertNull($this->reader->verifiedAnswer($forged, $this->actor, $this->organization->id));
        $forged = $result;
        $forged['server_formatted_answer'] = 'Invented response';
        $forged['source_refs'] = [];
        $verified = $this->reader->verifiedAnswer($forged, $this->actor, $this->organization->id);
        $this->assertSame($result['server_formatted_answer'], $verified['text']);
        $this->assertCount(63, $verified['source_refs']);
        $forged = $result;
        $forged['stock_evidence']['actor_id'] = $this->actor->id + 1;
        $this->assertNull($this->reader->verifiedAnswer($forged, $this->actor, $this->organization->id));
        MeasurementUnit::query()->whereKey($material->measurement_unit_id)->update(['short_name' => 'кг']);
        $this->assertNull($this->reader->verifiedAnswer($result, $this->actor, $this->organization->id));
    }

    public function test_empty_is_distinct_from_revoked_access_and_project_filter_matches_canonical_allocation(): void
    {
        $this->assertSame('empty', $this->reader->read($this->actor, $this->organization->id, ['query' => 'Бетон'])['status']);
        $warehouse = $this->warehouse($this->project);
        $material = $this->material('Бетон', 'т');
        $this->balance($warehouse, $material, '4.000', '1.000');
        $this->assertSame('empty', $this->reader->read($this->actor, $this->organization->id, ['project_id' => $this->project->id])['status']);
        WarehouseProjectAllocation::withoutEvents(fn () => WarehouseProjectAllocation::query()->create(['organization_id' => $this->organization->id,
            'warehouse_id' => $warehouse->id, 'material_id' => $material->id, 'project_id' => $this->project->id, 'allocated_quantity' => '1.000']));
        $result = $this->reader->read($this->actor, $this->organization->id, ['project_id' => $this->project->id]);
        $this->assertSame('4.000', $result['stock'][0]['available_quantity']);
        $this->permissions = false;
        $this->assertSame('unavailable', $this->reader->read($this->actor, $this->organization->id, [])['status']);
        $this->assertNull($this->reader->verifiedAnswer($result, $this->actor, $this->organization->id));
    }

    public function test_foreign_or_private_explicit_project_is_denied(): void
    {
        $private = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->organization->id]));
        $foreign = Project::withoutEvents(fn () => Project::factory()->create());
        foreach ([$private, $foreign] as $project) {
            try {
                $this->reader->read($this->actor, $this->organization->id, ['project_id' => $project->id]);
                $this->fail('Private or foreign project must not be readable.');
            } catch (AccessDeniedHttpException) {
                $this->assertTrue(true);
            }
        }
        $permission = Mockery::mock(AIPermissionChecker::class);
        $this->expectException(AccessDeniedHttpException::class);
        (new GetMaterialStockTool($this->reader, $permission))->execute([], $this->actor,
            Organization::withoutEvents(fn () => Organization::factory()->create()));
    }

    public function test_project_filter_reports_associated_warehouse_totals_without_claiming_project_or_free_quantity(): void
    {
        $warehouse = $this->warehouse($this->project);
        $material = $this->material('Бетон', 'т');
        $this->balance($warehouse, $material, '100.000', '3.000');
        WarehouseProjectAllocation::withoutEvents(fn () => WarehouseProjectAllocation::query()->create([
            'organization_id' => $this->organization->id, 'warehouse_id' => $warehouse->id, 'material_id' => $material->id,
            'project_id' => $this->project->id, 'allocated_quantity' => '2.000']));
        $result = $this->reader->read($this->actor, $this->organization->id, ['project_id' => $this->project->id, 'query' => 'Бетон']);
        $scope = $result['quantity_scope'];
        $this->assertSame('100.000', $result['stock'][0]['available_quantity']);
        $this->assertSame('warehouse_positions_with_project_allocation', $scope['kind']);
        $this->assertSame($this->project->id, $scope['project_id']);
        foreach (['project_allocation_quantity_calculated', 'on_site_quantity_calculated', 'free_for_allocation_calculated'] as $field) {
            $this->assertFalse($scope[$field]);
        }
        $this->assertArrayNotHasKey('allocated_quantity', $result['stock'][0]);
        $this->assertStringContainsString('складские остатки позиций, связанных с проектом', $result['server_formatted_answer']);
        $this->assertStringContainsString('выделенное проекту', $result['server_formatted_answer']);
        $this->assertStringContainsString('не рассчитываются', $result['server_formatted_answer']);
        $this->assertStringContainsString('Свободное количество для нового распределения здесь не рассчитывается', $result['server_formatted_answer']);
        $verified = $this->reader->verifiedAnswer($result, $this->actor, $this->organization->id);
        $this->assertSame($result['server_formatted_answer'], $verified['text']);
        $this->assertSame($scope, $verified['quantity_scope']);
        $general = $this->reader->read($this->actor, $this->organization->id, ['query' => 'Бетон']);
        $this->assertSame('warehouse_balance', $general['quantity_scope']['kind']);
        $this->assertStringNotContainsString('связанных с проектом', $general['server_formatted_answer']);
        $this->assertSame('100.000', $general['stock'][0]['available_quantity']);
    }

    private function warehouse(Project $project): OrganizationWarehouse
    {
        return OrganizationWarehouse::withoutEvents(fn () => OrganizationWarehouse::query()->create(['organization_id' => $this->organization->id,
            'project_id' => $project->id, 'name' => 'Склад', 'code' => bin2hex(random_bytes(5)), 'warehouse_type' => 'project', 'is_active' => true]));
    }

    private function material(string $name, ?string $unit): Material
    {
        $measurement = $unit === null ? null : MeasurementUnit::withoutEvents(fn () => MeasurementUnit::query()->create([
            'organization_id' => $this->organization->id, 'name' => $unit, 'short_name' => $unit.'-'.bin2hex(random_bytes(3)), 'type' => 'material', 'is_system' => false]));

        return Material::withoutEvents(fn () => Material::query()->create(['organization_id' => $this->organization->id, 'name' => $name,
            'code' => bin2hex(random_bytes(5)), 'measurement_unit_id' => $measurement?->id, 'is_active' => true]));
    }

    private function balance(OrganizationWarehouse $warehouse, Material $material, string $available, string $reserved): WarehouseBalance
    {
        return WarehouseBalance::withoutEvents(fn () => WarehouseBalance::query()->create(['organization_id' => $this->organization->id,
            'warehouse_id' => $warehouse->id, 'material_id' => $material->id, 'available_quantity' => $available,
            'reserved_quantity' => $reserved, 'unit_price' => '0.00', 'batch_number' => bin2hex(random_bytes(5))]));
    }
}
