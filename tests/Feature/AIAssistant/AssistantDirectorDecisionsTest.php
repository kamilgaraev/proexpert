<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantProjectFinanceOverview;
use App\BusinessModules\Features\AIAssistant\Services\AssistantProjectScheduleImpactReader;
use App\BusinessModules\Features\AIAssistant\Services\AssistantSourceReferenceGuard;
use App\BusinessModules\Features\AIAssistant\Services\AssistantProjectAttentionReader;
use App\BusinessModules\Features\AIAssistant\Services\AssistantProjectWorkforceReader;
use App\BusinessModules\Features\AIAssistant\Services\AssistantProjectPaymentReader;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Features\BasicWarehouse\Models\ProjectMaterialDelivery;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\Project;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use App\Models\TaskDependency;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantDirectorDecisionsTest extends TestCase
{
    private AssistantRealAuthorizationFixture $fixture;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-13 10:00:00'); Queue::fake(); config(['cache.default' => 'array']);
        $this->fixture = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $this->project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->organization->id,
            'name' => 'Текущий объект', 'budget_amount' => '50000000.00', 'status' => 'active', 'is_archived' => false]));
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    public function test_schedule_impact_uses_full_active_graph_without_writes_and_rechecks_changes(): void
    {
        $schedule = $this->schedule($this->project);
        $first = $this->task($schedule, 'Предшественник', '2026-10-01', '2026-10-11', 10);
        $second = $this->task($schedule, 'Следующая работа', '2026-10-11', '2026-10-31', 20);
        $edge = TaskDependency::withoutEvents(fn () => TaskDependency::query()->create(['organization_id' => $this->fixture->organization->id,
            'schedule_id' => $schedule->id, 'predecessor_task_id' => $first->id, 'successor_task_id' => $second->id,
            'created_by_user_id' => $this->fixture->owner->id, 'dependency_type' => 'FS', 'lag_days' => 0, 'lag_hours' => 0, 'lag_type' => 'days', 'is_active' => true, 'validation_status' => 'valid']));
        $other = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->organization->id]));
        $this->task($this->schedule($other), 'Другой проект', '2026-10-01', '2026-10-02', 1);
        $draft = $this->schedule($this->project); $draft->updateQuietly(['status' => 'draft']);
        $this->task($draft, 'Черновая работа', '2026-10-01', '2026-10-02', 1);
        $before = [$first->fresh()->getAttributes(), $schedule->fresh()->getAttributes(), $edge->fresh()->getAttributes()];
        $read = $this->impact();
        self::assertCount(1, $read['data']['schedules']);
        self::assertSame(2, $read['data']['schedules'][0]['task_count']);
        self::assertSame(2.0, $read['data']['schedules'][0]['candidates'][0]['finish_shift_days']);
        self::assertStringNotContainsString('Другой проект', json_encode($read));
        self::assertStringNotContainsString('Черновая работа', json_encode($read));
        self::assertSame($before, [$first->fresh()->getAttributes(), $schedule->fresh()->getAttributes(), $edge->fresh()->getAttributes()]);
        self::assertNotNull(app(AssistantProjectScheduleImpactReader::class)->verifiedAnswer($read, $this->fixture->owner, $this->fixture->organization->id));
        $reader = app(AssistantProjectScheduleImpactReader::class);
        $critical = $reader->read($this->fixture->owner, $this->fixture->organization->id, ['project_id' => $this->project->id, 'focus' => 'critical_path']);
        self::assertStringContainsString('Задач с нулевым резервом: 2', $critical['server_formatted_answer']);
        self::assertStringContainsString('Следующая работа', $critical['server_formatted_answer']);
        $first->updateQuietly(['constraint_type' => 'must_start_on']);
        $dependencies = $reader->read($this->fixture->owner, $this->fixture->organization->id, ['project_id' => $this->project->id, 'focus' => 'dependencies']);
        self::assertStringContainsString('Предшественник #'.$first->id, $dependencies['server_formatted_answer']);
        self::assertStringContainsString('Показано 1 из 1', $dependencies['server_formatted_answer']);
        self::assertStringContainsString('Оценка недоступна', $dependencies['server_formatted_answer']);
        $first->updateQuietly(['constraint_type' => 'none']);
        $edge->updateQuietly(['lag_days' => 2]);
        self::assertFalse(app(AssistantSourceReferenceGuard::class)->fresh($this->fixture->owner, $this->fixture->organization->id, $read['source_refs']));
        $read = $this->impact();
        UserRoleAssignment::query()->where('user_id', $this->fixture->owner->id)->update(['is_active' => false]);
        self::assertFalse(app(AssistantSourceReferenceGuard::class)->fresh($this->fixture->owner, $this->fixture->organization->id, $read['source_refs']));
    }

    public function test_no_dependencies_does_not_invent_critical_path(): void
    {
        $this->task($this->schedule($this->project), 'Без связей', '2026-10-01', '2026-10-02', 1);
        $read = $this->impact();
        self::assertStringContainsString('Зависимости не заданы', $read['server_formatted_answer']);
        self::assertStringContainsString('это не критический путь', $read['server_formatted_answer']);
        self::assertStringContainsString('проверить связи', mb_strtolower($read['server_formatted_answer']));
    }

    public function test_finance_overview_preserves_master_budget_period_actuals_and_comparison_limit(): void
    {
        Carbon::setTestNow();
        $this->project->forceFill(['created_at' => now()->subDay(), 'updated_at' => now()->subSecond()])->saveQuietly();
        Model::withoutEvents(function (): void {
            $asset = \App\BusinessModules\Features\MachineryOperations\Models\MachineryAsset::query()->create(['organization_id' => $this->fixture->organization->id,
                'current_project_id' => $this->project->id, 'asset_code' => 'OVERVIEW-SHIFT', 'name' => 'Кран', 'status' => 'in_operation', 'ownership_type' => 'owned']);
            \App\BusinessModules\Features\MachineryOperations\Models\MachineryShiftReport::query()->create(['organization_id' => $this->fixture->organization->id,
                'asset_id' => $asset->id, 'project_id' => $this->project->id, 'report_date' => '2026-09-10', 'status' => 'approved',
                'actual_hours' => '1.25', 'hourly_rate_snapshot' => '10.01', 'approved_at' => '2026-09-10 12:00:00']);
        });
        $read = $this->finance();
        self::assertTrue($read['source_covered'], json_encode($read['data']));
        self::assertSame('50000000.00', $read['data']['project_budget']);
        self::assertStringContainsString('50000000.00', $read['server_formatted_answer']);
        self::assertStringContainsString('2026-09-01', $read['server_formatted_answer']);
        self::assertStringContainsString('2026-09-30', $read['server_formatted_answer']);
        self::assertStringContainsString('12.51 RUB', $read['server_formatted_answer']);
        self::assertStringContainsString('Подтвердить перерасход нельзя', $read['server_formatted_answer']);
        self::assertContains('project', array_column($read['source_refs'], 'entity_type'));
        self::assertContains('live_project_financial_projection', array_column($read['source_refs'], 'entity_type'));
        foreach ($read['source_refs'] as $ref) {
            self::assertTrue(app(AssistantSourceReferenceGuard::class)->fresh($this->fixture->owner, $this->fixture->organization->id, [$ref]), json_encode($ref));
        }
        self::assertNotNull(app(AssistantProjectFinanceOverview::class)->verifiedAnswer($read, $this->fixture->owner, $this->fixture->organization->id));
        $this->project->updateQuietly(['budget_amount' => '60000000.00']);
        self::assertNull(app(AssistantProjectFinanceOverview::class)->verifiedAnswer($read, $this->fixture->owner, $this->fixture->organization->id));
    }

    public function test_supply_attention_counts_deliveries_and_site_requests_before_limit_and_rechecks_material(): void
    {
        $unit = \App\Models\MeasurementUnit::withoutEvents(fn () => \App\Models\MeasurementUnit::query()->firstOrCreate(['organization_id' => $this->fixture->organization->id,
            'short_name' => 'кг'], ['name' => 'Килограмм']));
        $material = \App\Models\Material::withoutEvents(fn () => \App\Models\Material::query()->create(['organization_id' => $this->fixture->organization->id,
            'name' => 'Цемент М500', 'measurement_unit_id' => $unit->id]));
        $base = ['organization_id' => $this->fixture->organization->id, 'project_id' => $this->project->id, 'material_id' => $material->id, 'requested_quantity' => '100.000'];
        Model::withoutEvents(function () use ($base): void {
            for ($i = 0; $i < 21; $i++) { ProjectMaterialDelivery::query()->create($base + ['source_type' => 'purchase', 'status' => 'processing']); }
            ProjectMaterialDelivery::query()->create($base + ['source_type' => 'purchase', 'status' => 'accepted']);
            ProjectMaterialDelivery::query()->create($base + ['source_type' => 'warehouse', 'status' => 'processing']);
            foreach (['pending', 'in_review', 'approved', 'cancelled'] as $status) {
                SiteRequest::query()->create(['organization_id' => $this->fixture->organization->id, 'project_id' => $this->project->id,
                    'user_id' => $this->fixture->owner->id, 'title' => 'Заявка '.$status, 'status' => $status, 'request_type' => 'material_request']);
            }
        });
        $reader = app(AssistantProjectAttentionReader::class);
        $read = $reader->read($this->fixture->owner, $this->fixture->organization->id, ['project_id' => $this->project->id, 'focus' => 'procurement', 'limit' => 3]);
        $groups = array_column($read['data']['groups'], null, 'kind');
        self::assertSame(21, $groups['open_deliveries']['total']);
        self::assertCount(3, $groups['open_deliveries']['items']);
        self::assertSame('Цемент М500', $groups['open_deliveries']['items'][0]['title']);
        self::assertSame(2, $groups['pending_site_requests']['total']);
        self::assertStringContainsString('Влияние снабжения', $read['server_formatted_answer']);
        self::assertTrue($reader->matchesReference($this->fixture->owner, $this->fixture->organization->id, $read['source_refs'][0]));
        $material->updateQuietly(['name' => 'Другое название']);
        self::assertFalse($reader->matchesReference($this->fixture->owner, $this->fixture->organization->id, $read['source_refs'][0]));
    }

    private function finance(): array
    {
        return app(AssistantProjectFinanceOverview::class)->read($this->fixture->owner, $this->fixture->organization,
            ['project_id' => $this->project->id, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'currency' => null]);
    }

    public function test_payment_readiness_preserves_exact_balances_full_count_and_unknown_balances(): void
    {
        $base = ['organization_id' => $this->fixture->organization->id, 'project_id' => $this->project->id,
            'document_type' => 'invoice', 'document_date' => '2026-09-01', 'direction' => 'outgoing', 'invoice_type' => 'other',
            'amount' => '100.10', 'paid_amount' => '0.00', 'remaining_amount' => '100.10', 'currency' => 'RUB', 'status' => 'approved'];
        Model::withoutEvents(function () use ($base): void {
            for ($i = 0; $i < 21; $i++) { PaymentDocument::query()->create(['document_number' => 'Счёт '.$i] + $base); }
            PaymentDocument::query()->create(['document_number' => 'Неизвестный остаток', 'remaining_amount' => null] + $base);
            PaymentDocument::query()->create(['document_number' => 'Несогласованный остаток', 'remaining_amount' => '99.00'] + $base);
            PaymentDocument::query()->create(['document_number' => 'Оплаченный', 'status' => 'paid', 'paid_amount' => '100.10', 'remaining_amount' => '0.00'] + $base);
            PaymentDocument::query()->create(['document_number' => 'Отменённый', 'status' => 'cancelled'] + $base);
            $other = Project::factory()->create(['organization_id' => $this->fixture->organization->id]);
            PaymentDocument::query()->create(['document_number' => 'Другой проект', 'project_id' => $other->id] + $base);
        });
        $reader = app(AssistantProjectPaymentReader::class);
        $read = $reader->read($this->fixture->owner, $this->fixture->organization->id, ['project_id' => $this->project->id]);
        self::assertSame(21, $read['data']['total']);
        self::assertCount(20, $read['data']['items']);
        self::assertSame(2, $read['data']['unknown_balance_count']);
        self::assertSame('100.10', $read['data']['items'][0]['remaining_amount']);
        self::assertStringContainsString('не подтверждены', $read['server_formatted_answer']);
        foreach (['Другой проект', 'Оплаченный', 'Отменённый', 'Несогласованный остаток'] as $excluded) { self::assertStringNotContainsString($excluded, json_encode($read, JSON_UNESCAPED_UNICODE)); }
        self::assertNotNull($reader->verifiedAnswer($read, $this->fixture->owner, $this->fixture->organization->id));
        PaymentDocument::query()->whereKey($read['data']['items'][0]['id'])->update(['paid_amount' => '100.10', 'remaining_amount' => '0.00', 'status' => 'paid']);
        self::assertFalse($reader->matchesReference($this->fixture->owner, $this->fixture->organization->id, $read['source_refs'][0]));
        $actor = $this->fixture->addMember(['ai-assistant' => ['ai_assistant.chat'], 'project-management' => ['projects.view']], ['payments.invoice.view_all']);
        $actor->assignedProjects()->attach($this->project->id, ['role' => 'member', 'is_active' => true]);
        self::assertTrue(app(\App\Domain\Authorization\Services\AuthorizationService::class)->canCurrent($actor, 'payments.invoice.view_all', ['organization_id' => $this->fixture->organization->id]));
        self::assertFalse(app(\App\Domain\Authorization\Services\AuthorizationService::class)->canCurrent($actor, 'payments.invoice.view', ['organization_id' => $this->fixture->organization->id]));
        self::assertTrue(app(\App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker::class)->canExposeTool($actor, 'get_project_payment_readiness'));
        self::assertSame(20, $reader->read($actor, $this->fixture->organization->id, ['project_id' => $this->project->id])['data']['total']);
    }

    public function test_workforce_reads_complete_period_hours_separately_and_rechecks_new_entries(): void
    {
        Model::withoutEvents(function (): void {
            for ($i = 0; $i < 25; $i++) {
                \App\Models\TimeEntry::query()->create(['organization_id' => $this->fixture->organization->id, 'project_id' => $this->project->id,
                    'worker_type' => 'external', 'worker_name' => 'Работник '.$i, 'work_date' => '2026-09-10', 'hours_worked' => '10.25',
                    'title' => 'Работа', 'status' => 'submitted', 'hourly_rate' => '9999.00', 'notes' => 'Секретная заметка']);
            }
            \App\Models\TimeEntry::query()->create(['organization_id' => $this->fixture->organization->id, 'project_id' => $this->project->id,
                'worker_type' => 'external', 'worker_name' => 'Другой месяц', 'work_date' => '2026-10-10', 'hours_worked' => '99.00', 'title' => 'Работа', 'status' => 'submitted']);
            $order = \App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborWorkOrder::query()->create(['organization_id' => $this->fixture->organization->id,
                'project_id' => $this->project->id, 'created_by_user_id' => $this->fixture->owner->id, 'order_number' => 'WORKFORCE-1', 'title' => 'Наряд', 'status' => 'submitted']);
            $sheet = \App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborTimesheet::query()->create(['organization_id' => $this->fixture->organization->id,
                'project_id' => $this->project->id, 'work_order_id' => $order->id, 'created_by_user_id' => $this->fixture->owner->id, 'shift_date' => '2026-09-10', 'status' => 'submitted']);
            $line = \App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborWorkOrderLine::query()->create(['organization_id' => $this->fixture->organization->id,
                'work_order_id' => $order->id, 'name' => 'Работа', 'unit' => 'шт', 'planned_quantity' => '10.00']);
            $employee = \App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee::query()->create(['organization_id' => $this->fixture->organization->id,
                'personnel_number' => 'DIRECTOR-1', 'last_name' => 'Волков', 'first_name' => 'Алексей', 'middle_name' => 'Сергеевич',
                'employment_status' => 'active', 'hire_date' => '2026-05-01', 'phone' => 'SECRET-PHONE']);
            \App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborTimesheetEntry::query()->create(['organization_id' => $this->fixture->organization->id,
                'timesheet_id' => $sheet->id, 'work_order_line_id' => $line->id, 'employee_id' => $employee->id, 'worker_name' => null, 'hours' => '3.50', 'safety_permit_reference' => 'SECRET-PERMIT']);
        });
        $reader = app(AssistantProjectWorkforceReader::class);
        $arguments = ['project_id' => $this->project->id, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30'];
        $read = $reader->read($this->fixture->owner, $this->fixture->organization->id, $arguments);
        self::assertSame('256.25', $read['data']['sections']['time_entry']['hours_total']);
        self::assertSame(25, $read['data']['sections']['time_entry']['by_status']['submitted']);
        self::assertSame(25, $read['data']['sections']['time_entry']['worker_names_count']);
        self::assertCount(20, $read['data']['sections']['time_entry']['worker_names']);
        self::assertSame('3.50', $read['data']['sections']['production_labor_timesheet']['hours_total']);
        self::assertSame(['Волков Алексей Сергеевич'], $read['data']['sections']['production_labor_timesheet']['worker_names']);
        foreach (['hourly_rate', '9999.00', 'Секретная', 'SECRET-PERMIT', 'SECRET-PHONE', 'Другой месяц'] as $private) { self::assertStringNotContainsString($private, json_encode($read, JSON_UNESCAPED_UNICODE)); }
        self::assertStringContainsString('Отдельного согласования табелей', $read['server_formatted_answer']);
        self::assertNotNull($reader->verifiedAnswer($read, $this->fixture->owner, $this->fixture->organization->id));
        \App\Models\TimeEntry::withoutEvents(fn () => \App\Models\TimeEntry::query()->create(['organization_id' => $this->fixture->organization->id, 'project_id' => $this->project->id,
            'worker_type' => 'external', 'worker_name' => 'Новая запись', 'work_date' => '2026-09-11', 'hours_worked' => '1.00', 'title' => 'Работа', 'status' => 'submitted']));
        self::assertFalse($reader->matchesReference($this->fixture->owner, $this->fixture->organization->id, $read['source_refs'][0]));
        $actor = $this->fixture->addMember(['ai-assistant' => ['ai_assistant.chat'], 'project-management' => ['projects.view']]);
        $actor->assignedProjects()->attach($this->project->id, ['role' => 'member', 'is_active' => true]);
        $limited = $reader->read($actor, $this->fixture->organization->id, $arguments);
        self::assertFalse($limited['data']['sections']['time_entry']['available']);
        self::assertFalse($limited['data']['sections']['production_labor_timesheet']['available']);
        self::assertStringNotContainsString('256.25', $limited['server_formatted_answer']);
    }

    private function impact(): array
    {
        return app(AssistantProjectScheduleImpactReader::class)->read($this->fixture->owner, $this->fixture->organization->id, ['project_id' => $this->project->id]);
    }

    private function schedule(Project $project): ProjectSchedule
    {
        return ProjectSchedule::withoutEvents(fn () => ProjectSchedule::query()->create(['organization_id' => $project->organization_id,
            'project_id' => $project->id, 'created_by_user_id' => $this->fixture->owner->id, 'name' => 'График',
            'planned_start_date' => '2026-10-01', 'planned_end_date' => '2026-11-01', 'status' => 'active']));
    }

    private function task(ProjectSchedule $schedule, string $name, string $start, string $end, int $duration): ScheduleTask
    {
        return ScheduleTask::withoutEvents(fn () => ScheduleTask::query()->create(['organization_id' => $schedule->organization_id,
            'schedule_id' => $schedule->id, 'created_by_user_id' => $this->fixture->owner->id, 'name' => $name,
            'status' => 'in_progress', 'task_type' => 'task', 'progress_percent' => 10, 'constraint_type' => 'none',
            'planned_start_date' => $start, 'planned_end_date' => $end, 'planned_duration_days' => $duration]));
    }
}
