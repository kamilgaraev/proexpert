<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\ReadOnly\GetProcurementSnapshotTool;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainCatalog;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainReadService;
use App\BusinessModules\Features\AIAssistant\Services\AssistantLegacyLiveEvidenceAdapter;
use App\BusinessModules\Features\AIAssistant\Services\AssistantStructuredFactVerifier;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Project\UserProjectAccessService;
use Mockery;
use Tests\TestCase;

final class AssistantLegacyLiveEvidenceTest extends TestCase
{
    private AssistantLegacyLiveEvidenceAdapter $adapter;
    private bool $permissions = true;
    private bool $financialPermissions = true;
    private Organization $organization;
    private User $actor;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('canCurrent')->andReturnUsing(fn (User $actor, string $permission): bool => $this->permissions
            && ($this->financialPermissions || ! in_array($permission, ['finance.view', 'finance.view_project_budget'], true)));
        $authorization->shouldReceive('forCurrentChecks')->andReturnSelf();
        $modules = Mockery::mock(OrganizationEntitlementService::class);
        $modules->shouldReceive('getEffectiveModules')->andReturn(collect(['project-management', 'contract-management', 'schedule-management', 'procurement', 'site-requests', 'users'])
            ->map(static fn (string $slug): object => (object) ['slug' => $slug]));
        $policy = new AssistantDataAccessPolicy($authorization, new UserProjectAccessService, $modules);
        $this->app->instance(AssistantDataAccessPolicy::class, $policy);
        $catalog = new AssistantDomainCatalog(AssistantDomainCatalog::defaults());
        $this->adapter = new AssistantLegacyLiveEvidenceAdapter(new AssistantDomainReadService($catalog, $policy, $authorization), $catalog);
        $this->organization = Organization::withoutEvents(fn () => Organization::factory()->create());
        $this->actor = User::withoutEvents(fn () => User::factory()->create(['current_organization_id' => $this->organization->id, 'is_active' => true]));
        $this->actor->organizations()->attach($this->organization->id, ['is_active' => true, 'project_access_mode' => 'assigned_projects']);
        $this->project = $this->project('Текущий проект');
    }

    public function test_project_snapshot_and_search_use_current_rows_instead_of_legacy_values(): void
    {
        $this->project->updateQuietly(['status' => 'active', 'budget_amount' => '98765432109.17']);
        $record = ['id' => $this->project->id, 'name' => 'Старое название', 'status' => 'completed', 'budget_amount' => 1.0];
        foreach (['get_project_snapshot' => [['project' => $record]], 'search_projects' => [$record]] as $tool => $results) {
            $reads = $this->adapter->read($tool, ['status' => 'success', 'results' => $results,
                'summary' => ['total_amount' => 999999.0]], $this->actor, $this->organization->id);
            $fields = $reads[0]['structured_fact_evidence']['rows'][0]['fields'];
            $this->assertSame('active', $fields['status']);
            $this->assertSame('Текущий проект', $fields['name']);
            $this->assertSame('98765432109.17', $fields['budget_amount']);
            $guard = (new AssistantStructuredFactVerifier)->guard('Какой статус и бюджет проекта?', 'Завершён, бюджет 1 рубль.', $reads);
            $this->assertFalse($guard['needs_clarification']);
            $this->assertStringContainsString('98765432109.17', $guard['text']);
            $this->assertStringNotContainsString('999999', $guard['text']);
        }
    }

    public function test_contract_snapshot_rereads_exact_money_and_dates(): void
    {
        $contract = $this->contract($this->project, '1');
        $reads = $this->adapter->read('get_contract_snapshot', ['status' => 'success', 'contracts' => [['contract' => [
            'id' => $contract->id, 'status' => 'completed', 'total_amount' => 1.0, 'planned_advance_amount' => 2.0,
            'actual_advance_amount' => 3.0, 'start_date' => '2000-01-01', 'end_date' => '2000-01-02']]]], $this->actor, $this->organization->id);
        $fields = $reads[0]['structured_fact_evidence']['rows'][0]['fields'];
        $this->assertSame('1234567890123.17', $fields['total_amount']);
        $this->assertSame('1000000000000.09', $fields['planned_advance_amount']);
        $this->assertSame('0.00', $fields['actual_advance_amount']);
        $this->assertStringStartsWith('2026-12-01', $fields['end_date']);
        $this->assertFalse((new AssistantStructuredFactVerifier)->guard('Статус, срок и сумма договора?', 'Старые значения', $reads)['needs_clarification']);
    }

    public function test_schedule_snapshot_rereads_quantities_and_status(): void
    {
        $schedule = ProjectSchedule::withoutEvents(fn () => ProjectSchedule::query()->create(['organization_id' => $this->organization->id,
            'project_id' => $this->project->id, 'created_by_user_id' => $this->actor->id, 'name' => 'График',
            'planned_start_date' => '2026-09-01', 'planned_end_date' => '2026-12-01', 'status' => 'active']));
        $task = ScheduleTask::withoutEvents(fn () => ScheduleTask::query()->create(['organization_id' => $this->organization->id,
            'schedule_id' => $schedule->id, 'created_by_user_id' => $this->actor->id, 'name' => 'Работа', 'status' => 'in_progress',
            'planned_start_date' => '2026-09-01', 'planned_end_date' => '2026-12-01', 'planned_duration_days' => 90,
            'quantity' => '1234.5678', 'completed_quantity' => '0.0000']));
        $reads = $this->adapter->read('get_schedule_snapshot', ['status' => 'success', 'tasks' => [['id' => $task->id,
            'status' => 'completed', 'quantity' => 1.0, 'completed_quantity' => 1.0]]], $this->actor, $this->organization->id);
        $fields = $reads[0]['structured_fact_evidence']['rows'][0]['fields'];
        $this->assertSame('in_progress', $fields['status']);
        $this->assertSame('1234.5678', $fields['quantity']);
        $this->assertSame('0.0000', $fields['completed_quantity']);
        $this->assertFalse((new AssistantStructuredFactVerifier)->guard('Статус и количество задачи?', 'Завершена', $reads)['needs_clarification']);
    }

    public function test_procurement_snapshot_filters_orders_by_project_and_proves_individual_rows(): void
    {
        [$request, $order] = $this->procurement($this->project, '1');
        $other = $this->project('Другой проект');
        [, $otherOrder] = $this->procurement($other, '2');
        $permission = Mockery::mock(AIPermissionChecker::class);
        $permission->shouldReceive('canExecuteTool')->andReturn(true);
        $this->app->instance(AIPermissionChecker::class, $permission);
        $result = (new GetProcurementSnapshotTool)->execute(['project_id' => $this->project->id], $this->actor, $this->organization);
        $this->assertSame([$order->id], array_column($result['purchase_orders'], 'id'));
        $this->assertNotContains($otherOrder->id, array_column($result['purchase_orders'], 'id'));
        $reads = $this->adapter->read('get_procurement_snapshot', $result, $this->actor, $this->organization->id);
        $this->assertCount(2, $reads);
        $this->assertSame($request->id, $reads[0]['structured_fact_evidence']['rows'][0]['entity_id']);
        $this->assertSame('1234567890123.17', $reads[1]['structured_fact_evidence']['rows'][0]['fields']['total_amount']);
        $this->assertFalse((new AssistantStructuredFactVerifier)->guard('Какой статус, срок и сумма заказа?', 'Старые данные', $reads)['needs_clarification']);
    }

    public function test_revocation_foreign_private_missing_rows_and_unrelated_search_tools_cannot_produce_proof(): void
    {
        $private = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->organization->id, 'is_archived' => false]));
        $foreign = Project::withoutEvents(fn () => Project::factory()->create());
        $result = ['status' => 'success', 'results' => array_map(static fn (int $id): array => ['id' => $id, 'status' => 'active'], [$private->id, $foreign->id, 999999])];
        $this->assertSame([], $this->adapter->read('search_projects', $result, $this->actor, $this->organization->id));
        $result['results'] = [['id' => $this->project->id, 'status' => 'active']];
        $this->permissions = false;
        $this->assertSame([], $this->adapter->read('search_projects', $result, $this->actor, $this->organization->id));
        $this->permissions = true;
        foreach (['search_materials', 'search_contractors', 'search_warehouse'] as $tool) {
            $this->assertSame([], $this->adapter->read($tool, $result, $this->actor, $this->organization->id));
        }
    }

    public function test_project_filter_includes_contract_linked_orders_and_excludes_other_or_unknown_projects(): void
    {
        $contract = $this->contract($this->project, '1');
        $otherContract = $this->contract($this->project('Другой проект'), '2');
        $supplier = Supplier::withoutEvents(fn () => Supplier::query()->create(['organization_id' => $this->organization->id, 'name' => 'Поставщик', 'code' => 'S1']));
        $orders = [];
        foreach ([$contract->id, $otherContract->id, null] as $index => $contractId) {
            $orders[] = PurchaseOrder::withoutEvents(fn () => PurchaseOrder::query()->create(['organization_id' => $this->organization->id,
                'supplier_id' => $supplier->id, 'contract_id' => $contractId, 'order_number' => 'ДП-'.$index,
                'order_date' => '2026-09-01', 'status' => 'confirmed', 'total_amount' => '12.17']));
        }
        $permission = Mockery::mock(AIPermissionChecker::class);
        $permission->shouldReceive('canExecuteTool')->andReturn(true);
        $this->app->instance(AIPermissionChecker::class, $permission);
        $result = (new GetProcurementSnapshotTool)->execute(['project_id' => $this->project->id], $this->actor, $this->organization);
        $this->assertSame([$orders[0]->id], array_column($result['purchase_orders'], 'id'));
        $reads = $this->adapter->read('get_procurement_snapshot', $result, $this->actor, $this->organization->id);
        $this->assertCount(1, $reads);
        $this->assertSame($orders[0]->id, $reads[0]['structured_fact_evidence']['rows'][0]['entity_id']);
    }

    public function test_financial_permission_removes_values_and_checked_fields_instead_of_substituting_zero(): void
    {
        $this->project->updateQuietly(['budget_amount' => '42.17']);
        $this->financialPermissions = false;
        $reads = $this->adapter->read('get_project_snapshot', ['status' => 'success', 'results' => [['project' => [
            'id' => $this->project->id, 'status' => 'active', 'budget_amount' => 42.17]]]], $this->actor, $this->organization->id);
        $this->assertArrayNotHasKey('budget_amount', $reads[0]['structured_fact_evidence']['rows'][0]['fields']);
        $this->assertNotContains('budget_amount', $reads[0]['source_refs'][0]['checked_fields']);
        $this->assertStringNotContainsString('42.17', $reads[0]['server_formatted_facts']);
        $this->assertTrue((new AssistantStructuredFactVerifier)->guard('Какой бюджет проекта?', 'Бюджет 0', $reads)['needs_clarification']);
    }

    public function test_user_search_uses_live_identity_and_limits_unique_entity_reads_to_25(): void
    {
        $userReads = $this->adapter->read('search_users', ['status' => 'success', 'results' => [['id' => $this->actor->id,
            'name' => 'Подмена', 'email' => 'wrong@example.test']]], $this->actor, $this->organization->id);
        $this->assertSame($this->actor->email, $userReads[0]['structured_fact_evidence']['rows'][0]['fields']['email']);
        $results = [['id' => $this->project->id, 'status' => 'active'], ['id' => $this->project->id, 'status' => 'active']];
        for ($index = 0; $index < 29; $index++) {
            $project = $this->project('Проект '.$index);
            $results[] = ['id' => $project->id, 'status' => 'active'];
        }
        $reads = $this->adapter->read('search_projects', ['status' => 'success', 'results' => $results], $this->actor, $this->organization->id);
        $this->assertCount(25, $reads);
        $ids = array_map(static fn (array $read): int => $read['structured_fact_evidence']['rows'][0]['entity_id'], $reads);
        $this->assertCount(25, array_unique($ids));
    }

    private function project(string $name): Project
    {
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->organization->id, 'name' => $name, 'is_archived' => false]));
        $this->actor->assignedProjects()->attach($project->id, ['is_active' => true, 'role' => 'member']);

        return $project;
    }

    private function procurement(Project $project, string $suffix): array
    {
        $site = SiteRequest::withoutEvents(fn () => SiteRequest::query()->create(['organization_id' => $this->organization->id,
            'project_id' => $project->id, 'user_id' => $this->actor->id, 'title' => 'Материалы', 'status' => 'approved', 'request_type' => 'material_request']));
        $request = PurchaseRequest::withoutEvents(fn () => PurchaseRequest::query()->create(['organization_id' => $this->organization->id,
            'site_request_id' => $site->id, 'request_number' => 'З-'.$suffix, 'status' => 'approved', 'needed_by' => '2026-12-01', 'budget_amount' => '1234567890123.17']));
        $supplier = Supplier::withoutEvents(fn () => Supplier::query()->create(['organization_id' => $this->organization->id, 'name' => 'Поставщик '.$suffix, 'code' => 'S'.$suffix]));
        $order = PurchaseOrder::withoutEvents(fn () => PurchaseOrder::query()->create(['organization_id' => $this->organization->id,
            'purchase_request_id' => $request->id, 'supplier_id' => $supplier->id, 'order_number' => 'П-'.$suffix,
            'order_date' => '2026-09-01', 'delivery_date' => '2026-12-01', 'status' => 'confirmed', 'total_amount' => '1234567890123.17']));

        return [$request, $order];
    }

    private function contract(Project $project, string $suffix): Contract
    {
        $contractor = Contractor::withoutEvents(fn () => Contractor::query()->create(['organization_id' => $this->organization->id, 'name' => 'Подрядчик '.$suffix]));

        return Contract::withoutEvents(fn () => Contract::query()->create(['organization_id' => $this->organization->id,
            'project_id' => $project->id, 'contractor_id' => $contractor->id, 'number' => 'Д-'.$suffix, 'date' => '2026-09-01', 'status' => 'active',
            'total_amount' => '1234567890123.17', 'planned_advance_amount' => '1000000000000.09',
            'actual_advance_amount' => '0.00', 'start_date' => '2026-09-01', 'end_date' => '2026-12-01']));
    }
}
