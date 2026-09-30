<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Actions\Domains\GetMaterialStockTool;
use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantRequestCancelled;
use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantRequestDeadlineExceeded;
use App\BusinessModules\Features\AIAssistant\Models\AssistantRequest;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantMaterialStockReader;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestExecutionContext;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestLifecycle;
use App\BusinessModules\Features\AIAssistant\Services\ConversationManager;
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
use App\Services\Credits\AICreditService;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use ReflectionClass;
use ReflectionProperty;
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
    private array $deniedPermissions = [];
    private AuthorizationService $authorization;
    private ?AssistantRequestExecutionContext $execution = null;

    protected function setUp(): void
    {
        parent::setUp();
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('forCurrentChecks')->andReturnSelf();
        $authorization->shouldReceive('canCurrent')->andReturnUsing(fn (User $actor, string $permission): bool => $this->permissions
            && ! in_array($permission, $this->deniedPermissions, true))->byDefault();
        $this->authorization = $authorization;
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

    protected function tearDown(): void
    {
        $this->execution?->restoreDatabaseStatementTimeouts();
        $this->app->forgetInstance(AssistantRequestExecutionContext::class);
        parent::tearDown();
    }

    public function test_runtime_read_has_bounded_full_checkpoints_and_returns_the_same_empty_result(): void
    {
        $baseline = $this->reader->read($this->actor, $this->organization->id, ['query' => 'бетон']);
        $this->assertSame('empty', $baseline['status']);
        $this->runtime();
        $requestReads = 0;
        DB::listen(static function (QueryExecuted $query) use (&$requestReads): void {
            if (str_starts_with($query->sql, 'select') && str_contains($query->sql, 'ai_assistant_requests')) {
                $requestReads++;
            }
        });
        try {
            $result = $this->reader->read($this->actor, $this->organization->id, ['query' => 'бетон']);
        } catch (\Throwable $exception) {
            $this->fail('Runtime reader failed after '.$requestReads.' fresh request reads: '.$exception::class.': '.$exception->getMessage());
        }
        $this->assertSame($baseline['status'], $result['status']);
        $this->assertGreaterThan(0, $requestReads);
        $this->assertLessThanOrEqual(3, $requestReads);
        $this->assertNotNull($this->reader->verifiedAnswer($result, $this->actor, $this->organization->id));
    }

    public function test_runtime_read_observes_warehouse_revocation_and_fresh_quantities_on_repeated_reads(): void
    {
        $warehouse = $this->warehouse($this->project);
        $material = $this->material('Бетон', 'т');
        $balance = $this->balance($warehouse, $material, '4.000', '1.000');
        $this->runtime();
        $result = $this->reader->read($this->actor, $this->organization->id, ['query' => 'бетон']);
        $this->assertSame('success', $result['status']);
        $this->assertSame('4.000', $result['stock'][0]['available_quantity']);
        $this->assertNotNull($this->reader->verifiedAnswer($result, $this->actor, $this->organization->id));
        $balance->update(['available_quantity' => '6.000']);
        $this->assertNull($this->reader->verifiedAnswer($result, $this->actor, $this->organization->id));
        $fresh = $this->reader->read($this->actor, $this->organization->id, ['query' => 'бетон']);
        $this->assertSame('6.000', $fresh['stock'][0]['available_quantity']);
        $this->deniedPermissions = ['warehouse.view'];
        $this->assertSame('unavailable', $this->reader->read($this->actor, $this->organization->id, ['query' => 'бетон'])['status']);
        $this->assertNull($this->reader->verifiedAnswer($fresh, $this->actor, $this->organization->id));
    }

    public function test_runtime_read_rejects_cancellation_before_the_read_and_after_the_stock_query(): void
    {
        $request = $this->runtime();
        $request->update(['cancel_requested_at' => now()]);
        try {
            $this->reader->read($this->actor, $this->organization->id, ['query' => 'бетон']);
            $this->fail('A cancelled request must not return stock.');
        } catch (AssistantRequestCancelled) {
            $this->assertTrue(true);
        }
        $request->update(['cancel_requested_at' => null]);
        $cancelled = false;
        DB::listen(static function (QueryExecuted $query) use ($request, &$cancelled): void {
            if (! $cancelled && str_contains($query->sql, 'SUM(stock.available_quantity)')) {
                $cancelled = true;
                AssistantRequest::query()->whereKey($request->id)->update(['cancel_requested_at' => now()]);
            }
        });
        $this->expectException(AssistantRequestCancelled::class);
        $this->reader->read($this->actor, $this->organization->id, ['query' => 'бетон']);
    }

    public function test_runtime_policy_callback_checks_the_effective_operation_deadline(): void
    {
        $this->runtime();
        $this->authorization->shouldReceive('canCurrent')->with($this->actor, 'warehouse.view', Mockery::any())
            ->andReturnUsing(function (): bool {
                $property = new ReflectionProperty(AssistantRequestExecutionContext::class, 'operationDeadlines');
                $deadlines = $property->getValue($this->execution);
                $deadlines[array_key_last($deadlines)] = hrtime(true) - 1;
                $property->setValue($this->execution, $deadlines);

                return true;
            });
        $this->expectException(AssistantRequestDeadlineExceeded::class);
        $this->reader->read($this->actor, $this->organization->id, ['query' => 'бетон']);
    }

    private function runtime(): AssistantRequest
    {
        $request = AssistantRequest::withoutEvents(fn () => AssistantRequest::query()->create([
            'request_id' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64), 'organization_id' => $this->organization->id,
            'user_id' => $this->actor->id, 'profile' => 'normal', 'status' => 'running', 'stage' => 'reading', 'surface' => 'admin',
            'max_calls' => 3, 'approved_max_minor' => 0, 'heartbeat_at' => now(), 'lease_expires_at' => now()->addMinutes(8)]));
        $lifecycle = new AssistantRequestLifecycle((new ReflectionClass(AICreditService::class))->newInstanceWithoutConstructor(),
            new AIPermissionChecker($this->authorization), Mockery::mock(ConversationManager::class), $this->policy);
        $this->execution = new AssistantRequestExecutionContext($lifecycle, $request, $this->actor, 30_000);
        $this->app->instance(AssistantRequestExecutionContext::class, $this->execution);

        return $request;
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
