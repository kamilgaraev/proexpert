<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Models\Contract;
use App\Models\ContractPerformanceAct;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use App\Models\User;
use App\Services\Analytics\EVMService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardEVMServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_metrics_use_only_selected_project_acts_and_payments_for_multi_project_contract(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-15 12:00:00'));

        [$organization, $user] = $this->createOrganizationAndUser();
        $project = $this->createProject($organization, [
            'budget_amount' => 1000,
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-10',
        ]);
        $otherProject = $this->createProject($organization, [
            'budget_amount' => 1000,
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-10',
        ]);

        $contractorId = $this->createContractor($organization);
        $contractId = $this->createContract($organization, $contractorId, null, [
            'number' => 'MP-1',
            'total_amount' => 1000,
            'base_amount' => 1000,
            'is_multi_project' => true,
        ]);

        DB::table('contract_project')->insert([
            [
                'contract_id' => $contractId,
                'project_id' => $project->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'contract_id' => $contractId,
                'project_id' => $otherProject->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $projectActId = $this->createAct($contractId, $project->id, 300);
        $otherProjectActId = $this->createAct($contractId, $otherProject->id, 500);

        $this->createPaymentDocument($organization, $project->id, ContractPerformanceAct::class, $projectActId, 200);
        $this->createPaymentDocument($organization, $otherProject->id, ContractPerformanceAct::class, $otherProjectActId, 400);
        $this->createPaymentDocument($organization, $project->id, Contract::class, $contractId, 75);
        $this->createPaymentDocument($organization, $otherProject->id, Contract::class, $contractId, 50);

        $metrics = app(EVMService::class)->calculateMetrics($project);

        $this->assertSame(1000.0, $metrics['bac']);
        $this->assertSame(1000.0, $metrics['pv']);
        $this->assertSame(300.0, $metrics['ev']);
        $this->assertSame(275.0, $metrics['ac']);
        $this->assertSame(-700.0, $metrics['sv']);
        $this->assertSame(25.0, $metrics['cv']);
        $this->assertSame(0.3, $metrics['spi']);
        $this->assertSame(1.09, $metrics['cpi']);
    }

    public function test_planned_value_follows_schedule_task_cost_curve_when_schedule_exists(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-10 12:00:00'));

        [$organization, $user] = $this->createOrganizationAndUser();
        $project = $this->createProject($organization, [
            'budget_amount' => 3000,
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-30',
        ]);

        $scheduleId = DB::table('project_schedules')->insertGetId([
            'project_id' => $project->id,
            'organization_id' => $organization->id,
            'created_by_user_id' => $user->id,
            'name' => 'Main schedule',
            'planned_start_date' => '2026-01-01',
            'planned_end_date' => '2026-01-30',
            'status' => 'active',
            'total_estimated_cost' => 2000,
            'overall_progress_percent' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->createScheduleTask($scheduleId, $organization, $user, [
            'name' => 'Early task',
            'planned_start_date' => '2026-01-01',
            'planned_end_date' => '2026-01-10',
            'estimated_cost' => 1000,
        ]);
        $this->createScheduleTask($scheduleId, $organization, $user, [
            'name' => 'Late task',
            'planned_start_date' => '2026-01-20',
            'planned_end_date' => '2026-01-29',
            'estimated_cost' => 1000,
        ]);
        $this->createScheduleTask($scheduleId, $organization, $user, [
            'name' => 'Summary',
            'task_type' => 'summary',
            'planned_start_date' => '2026-01-01',
            'planned_end_date' => '2026-01-29',
            'estimated_cost' => 500,
        ]);

        $metrics = app(EVMService::class)->calculateMetrics($project);

        $this->assertSame(2000.0, $metrics['bac']);
        $this->assertSame(1000.0, $metrics['pv']);
    }

    public function test_project_schedule_changes_invalidate_evm_cache_for_project(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-15 12:00:00'));

        [$organization, $user] = $this->createOrganizationAndUser();
        $project = $this->createProject($organization, [
            'budget_amount' => 1000,
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-10',
        ]);

        Cache::put($this->evmCacheKey($project), ['stale' => true], 600);

        ProjectSchedule::query()->create([
            'project_id' => $project->id,
            'organization_id' => $organization->id,
            'created_by_user_id' => $user->id,
            'name' => 'Schedule cache test',
            'planned_start_date' => '2026-01-01',
            'planned_end_date' => '2026-01-10',
            'status' => 'active',
            'total_estimated_cost' => 1000,
            'overall_progress_percent' => 0,
        ]);

        $this->assertFalse(Cache::has($this->evmCacheKey($project)));
    }

    public function test_schedule_task_plan_changes_invalidate_evm_cache_for_project(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-15 12:00:00'));

        [$organization, $user] = $this->createOrganizationAndUser();
        $project = $this->createProject($organization, [
            'budget_amount' => 1000,
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-10',
        ]);
        $scheduleId = DB::table('project_schedules')->insertGetId([
            'project_id' => $project->id,
            'organization_id' => $organization->id,
            'created_by_user_id' => $user->id,
            'name' => 'Task cache test',
            'planned_start_date' => '2026-01-01',
            'planned_end_date' => '2026-01-10',
            'status' => 'active',
            'total_estimated_cost' => 1000,
            'overall_progress_percent' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Cache::put($this->evmCacheKey($project), ['stale' => true], 600);

        ScheduleTask::query()->create([
            'schedule_id' => $scheduleId,
            'organization_id' => $organization->id,
            'created_by_user_id' => $user->id,
            'name' => 'Task cache invalidation',
            'task_type' => 'task',
            'planned_start_date' => '2026-01-01',
            'planned_end_date' => '2026-01-10',
            'planned_duration_days' => 10,
            'planned_work_hours' => 0,
            'actual_work_hours' => 0,
            'progress_percent' => 0,
            'status' => 'not_started',
            'priority' => 'normal',
            'estimated_cost' => 1000,
        ]);

        $this->assertFalse(Cache::has($this->evmCacheKey($project)));
    }

    public function test_earned_value_uses_performance_act_lines_when_they_exist(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-15 12:00:00'));

        [$organization] = $this->createOrganizationAndUser();
        $project = $this->createProject($organization, [
            'budget_amount' => 1000,
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-10',
        ]);

        $contractorId = $this->createContractor($organization);
        $contractId = $this->createContract($organization, $contractorId, $project->id, [
            'number' => 'LINES-1',
            'total_amount' => 1000,
            'base_amount' => 1000,
        ]);
        $actId = $this->createAct($contractId, $project->id, 900);

        $this->createActLine($actId, 120);
        $this->createActLine($actId, 180);

        $metrics = app(EVMService::class)->calculateMetrics($project);

        $this->assertSame(300.0, $metrics['ev']);
    }

    public function test_actual_cost_includes_source_linked_contract_payments(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-15 12:00:00'));

        [$organization] = $this->createOrganizationAndUser();
        $project = $this->createProject($organization, [
            'budget_amount' => 1000,
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-10',
        ]);

        $contractorId = $this->createContractor($organization);
        $contractId = $this->createContract($organization, $contractorId, $project->id, [
            'number' => 'SOURCE-PAY-1',
            'total_amount' => 1000,
            'base_amount' => 1000,
        ]);

        $this->createSourcePaymentDocument($organization, $project->id, Contract::class, $contractId, 180);

        $metrics = app(EVMService::class)->calculateMetrics($project);

        $this->assertSame(180.0, $metrics['ac']);
    }

    public function test_performance_act_changes_invalidate_evm_cache_for_act_project(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-15 12:00:00'));

        [$organization] = $this->createOrganizationAndUser();
        $project = $this->createProject($organization, [
            'budget_amount' => 1000,
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-10',
        ]);
        $otherProject = $this->createProject($organization, [
            'budget_amount' => 1000,
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-10',
        ]);

        $contractorId = $this->createContractor($organization);
        $contractId = $this->createContract($organization, $contractorId, null, [
            'number' => 'MP-CACHE',
            'total_amount' => 1000,
            'base_amount' => 1000,
            'is_multi_project' => true,
        ]);

        DB::table('contract_project')->insert([
            [
                'contract_id' => $contractId,
                'project_id' => $project->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'contract_id' => $contractId,
                'project_id' => $otherProject->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        Cache::put($this->evmCacheKey($project), ['stale' => true], 600);

        ContractPerformanceAct::query()->create([
            'contract_id' => $contractId,
            'project_id' => $project->id,
            'act_document_number' => 'ACT-CACHE',
            'act_date' => '2026-01-10',
            'amount' => 300,
            'status' => ContractPerformanceAct::STATUS_APPROVED,
            'is_approved' => true,
            'approval_date' => '2026-01-10',
        ]);

        $this->assertFalse(Cache::has($this->evmCacheKey($project)));
    }

    public function test_payment_document_changes_invalidate_evm_cache_for_document_project(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-15 12:00:00'));

        [$organization] = $this->createOrganizationAndUser();
        $project = $this->createProject($organization, [
            'budget_amount' => 1000,
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-10',
        ]);

        $contractorId = $this->createContractor($organization);
        $contractId = $this->createContract($organization, $contractorId, $project->id, [
            'number' => 'PAY-CACHE',
            'total_amount' => 1000,
            'base_amount' => 1000,
        ]);

        Cache::put($this->evmCacheKey($project), ['stale' => true], 600);

        $this->createPaymentDocument($organization, $project->id, Contract::class, $contractId, 250);

        $this->assertFalse(Cache::has($this->evmCacheKey($project)));
    }

    private function createOrganizationAndUser(): array
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create([
            'current_organization_id' => $organization->id,
        ]);

        return [$organization, $user];
    }

    private function evmCacheKey(Project $project): string
    {
        return 'project_metrics:v2:'.$project->id;
    }

    private function createProject(Organization $organization, array $attributes): Project
    {
        return Project::withoutEvents(fn () => Project::factory()->create(array_merge([
            'organization_id' => $organization->id,
            'address' => null,
            'status' => 'active',
        ], $attributes)));
    }

    private function createContractor(Organization $organization): int
    {
        return (int) DB::table('contractors')->insertGetId([
            'organization_id' => $organization->id,
            'name' => 'Contractor',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createContract(
        Organization $organization,
        int $contractorId,
        ?int $projectId,
        array $attributes = []
    ): int {
        return (int) DB::table('contracts')->insertGetId(array_merge([
            'organization_id' => $organization->id,
            'project_id' => $projectId,
            'contractor_id' => $contractorId,
            'number' => 'C-1',
            'date' => '2026-01-01',
            'total_amount' => 1000,
            'base_amount' => 1000,
            'gp_percentage' => 0,
            'is_fixed_amount' => true,
            'is_multi_project' => false,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    private function createAct(int $contractId, int $projectId, float $amount): int
    {
        return (int) DB::table('contract_performance_acts')->insertGetId([
            'contract_id' => $contractId,
            'project_id' => $projectId,
            'act_document_number' => 'ACT-'.$projectId.'-'.(int) $amount,
            'act_date' => '2026-01-10',
            'amount' => $amount,
            'status' => ContractPerformanceAct::STATUS_APPROVED,
            'is_approved' => true,
            'approval_date' => '2026-01-10',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createActLine(int $actId, float $amount): void
    {
        DB::table('performance_act_lines')->insert([
            'performance_act_id' => $actId,
            'line_type' => 'manual',
            'title' => 'Manual line',
            'quantity' => 1,
            'unit_price' => $amount,
            'amount' => $amount,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createPaymentDocument(
        Organization $organization,
        int $projectId,
        string $invoiceableType,
        int $invoiceableId,
        float $paidAmount
    ): void {
        PaymentDocument::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $projectId,
            'document_type' => 'invoice',
            'document_number' => 'PD-'.$projectId.'-'.$invoiceableId.'-'.(int) $paidAmount,
            'document_date' => '2026-01-10',
            'direction' => InvoiceDirection::OUTGOING,
            'invoiceable_type' => $invoiceableType,
            'invoiceable_id' => $invoiceableId,
            'amount' => $paidAmount,
            'amount_without_vat' => $paidAmount,
            'paid_amount' => $paidAmount,
            'remaining_amount' => 0,
            'status' => PaymentDocumentStatus::PAID,
            'paid_at' => '2026-01-10 12:00:00',
        ]);
    }

    private function createSourcePaymentDocument(
        Organization $organization,
        int $projectId,
        string $sourceType,
        int $sourceId,
        float $paidAmount
    ): void {
        PaymentDocument::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $projectId,
            'document_type' => 'invoice',
            'document_number' => 'SPD-'.$projectId.'-'.$sourceId.'-'.(int) $paidAmount,
            'document_date' => '2026-01-10',
            'direction' => InvoiceDirection::OUTGOING,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'amount' => $paidAmount,
            'amount_without_vat' => $paidAmount,
            'paid_amount' => $paidAmount,
            'remaining_amount' => 0,
            'status' => PaymentDocumentStatus::PAID,
            'paid_at' => '2026-01-10 12:00:00',
        ]);
    }

    private function createScheduleTask(
        int $scheduleId,
        Organization $organization,
        User $user,
        array $attributes
    ): void {
        DB::table('schedule_tasks')->insert(array_merge([
            'schedule_id' => $scheduleId,
            'organization_id' => $organization->id,
            'created_by_user_id' => $user->id,
            'name' => 'Task',
            'task_type' => 'task',
            'planned_start_date' => '2026-01-01',
            'planned_end_date' => '2026-01-10',
            'planned_duration_days' => 10,
            'planned_work_hours' => 0,
            'actual_work_hours' => 0,
            'progress_percent' => 0,
            'status' => 'not_started',
            'priority' => 'normal',
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }
}
