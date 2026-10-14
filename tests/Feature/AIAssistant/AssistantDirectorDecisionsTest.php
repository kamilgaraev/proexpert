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
            'amount' => '100.10', 'paid_amount' => '0.00', 'remaining_amount' => '100.10', 'currency' => 'RUB', 'status' => 'approved', 'due_date' => '2026-09-01'];
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
        $approvalDocument = PaymentDocument::query()->where('document_number', 'Счёт 0')->firstOrFail();
        $this->fixture->foreignOwner->updateQuietly(['name' => 'FOREIGN_PRIVATE_APPROVER']);
        Model::withoutEvents(function () use ($approvalDocument): void {
            foreach ([$this->fixture->owner->id, null, $this->fixture->foreignOwner->id] as $index => $approver) {
                \App\BusinessModules\Core\Payments\Models\PaymentApproval::query()->create(['organization_id' => $this->fixture->organization->id,
                    'payment_document_id' => $approvalDocument->id, 'approver_user_id' => $approver, 'approval_role' => 'finance_approver', 'approval_order' => $index + 1, 'status' => 'pending']);
            }
            \App\BusinessModules\Core\Payments\Models\PaymentApproval::query()->create(['organization_id' => $this->fixture->foreignOrganization->id,
                'payment_document_id' => $approvalDocument->id, 'approver_user_id' => $this->fixture->foreignOwner->id, 'approval_role' => 'finance_approver', 'approval_order' => 4, 'status' => 'pending']);
        });
        $read = $reader->read($this->fixture->owner, $this->fixture->organization->id, ['project_id' => $this->project->id]);
        self::assertSame(21, $read['data']['total']);
        self::assertCount(20, $read['data']['items']);
        self::assertSame(2, $read['data']['unknown_balance_count']);
        self::assertSame(21, $read['data']['overdue_outgoing']); self::assertSame(0, $read['data']['overdue_incoming']);
        self::assertTrue($read['data']['approval_read_available']);
        self::assertSame(3, $read['data']['items'][0]['approvals']['total']); self::assertSame(2, $read['data']['items'][0]['approvals']['unnamed']);
        self::assertSame($this->fixture->owner->name, $read['data']['items'][0]['approvals']['examples'][0]['name']);
        self::assertStringNotContainsString('FOREIGN_PRIVATE_APPROVER', json_encode($read));
        self::assertSame('100.10', $read['data']['items'][0]['remaining_amount']);
        self::assertStringContainsString('не подтверждены', $read['server_formatted_answer']);
        foreach (['Другой проект', 'Оплаченный', 'Отменённый', 'Несогласованный остаток'] as $excluded) { self::assertStringNotContainsString($excluded, json_encode($read, JSON_UNESCAPED_UNICODE)); }
        self::assertNotNull($reader->verifiedAnswer($read, $this->fixture->owner, $this->fixture->organization->id));
        self::assertSame(4, \App\BusinessModules\Core\Payments\Models\PaymentApproval::query()->count());
        $approverName = $this->fixture->owner->name;
        $this->fixture->owner->updateQuietly(['name' => 'Новое имя согласующего']);
        self::assertFalse($reader->matchesReference($this->fixture->owner, $this->fixture->organization->id, $read['source_refs'][0]));
        $this->fixture->owner->updateQuietly(['name' => $approverName]);
        PaymentDocument::query()->whereKey($read['data']['items'][0]['id'])->update(['paid_amount' => '100.10', 'remaining_amount' => '0.00', 'status' => 'paid']);
        self::assertFalse($reader->matchesReference($this->fixture->owner, $this->fixture->organization->id, $read['source_refs'][0]));
        $actor = $this->fixture->addMember(['ai-assistant' => ['ai_assistant.chat'], 'project-management' => ['projects.view']], ['payments.invoice.view_all']);
        $actor->assignedProjects()->attach($this->project->id, ['role' => 'member', 'is_active' => true]);
        self::assertTrue(app(\App\Domain\Authorization\Services\AuthorizationService::class)->canCurrent($actor, 'payments.invoice.view_all', ['organization_id' => $this->fixture->organization->id]));
        self::assertFalse(app(\App\Domain\Authorization\Services\AuthorizationService::class)->canCurrent($actor, 'payments.invoice.view', ['organization_id' => $this->fixture->organization->id]));
        self::assertTrue(app(\App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker::class)->canExposeTool($actor, 'get_project_payment_readiness'));
        $withoutApprovalAccess = $reader->read($actor, $this->fixture->organization->id, ['project_id' => $this->project->id]);
        self::assertSame(20, $withoutApprovalAccess['data']['total']);
        self::assertFalse($withoutApprovalAccess['data']['approval_read_available']);
        self::assertNull($withoutApprovalAccess['data']['items'][0]['approvals']['total']);
    }

    public function test_payment_budget_readiness_uses_current_native_checks_without_writes_and_revalidates(): void
    {
        $organizationId = $this->fixture->organization->id;
        $document = Model::withoutEvents(fn () => PaymentDocument::query()->create([
            'organization_id' => $organizationId, 'project_id' => $this->project->id, 'document_type' => 'invoice',
            'document_number' => 'CURRENT-LIMIT', 'document_date' => today(), 'direction' => 'outgoing', 'invoice_type' => 'other',
            'amount' => '1000.00', 'paid_amount' => '0.00', 'remaining_amount' => '1000.00', 'currency' => 'RUB', 'status' => 'approved',
            'due_date' => today(), 'metadata' => ['private' => str_repeat('S', 1000000)],
        ]));
        $budget = Model::withoutEvents(function () use ($organizationId, $document) {
            $period = \App\BusinessModules\Features\Budgeting\Models\BudgetPeriod::query()->create([
                'organization_id' => $organizationId, 'code' => 'READINESS', 'name' => 'Текущий месяц', 'period_type' => 'month',
                'starts_at' => now()->startOfMonth()->toDateString(), 'ends_at' => now()->endOfMonth()->toDateString(), 'status' => 'open']);
            $scenario = \App\BusinessModules\Features\Budgeting\Models\BudgetScenario::query()->create([
                'organization_id' => $organizationId, 'code' => 'READINESS', 'name' => 'Базовый', 'scenario_type' => 'base', 'is_default' => true, 'is_active' => true]);
            $article = \App\BusinessModules\Features\Budgeting\Models\BudgetArticle::query()->create([
                'organization_id' => $organizationId, 'code' => 'READINESS', 'name' => 'Платежи', 'budget_kind' => 'bdds',
                'flow_direction' => 'outflow', 'is_leaf' => true, 'is_active' => true]);
            $center = \App\BusinessModules\Features\Budgeting\Models\ResponsibilityCenter::query()->create([
                'organization_id' => $organizationId, 'center_type' => 'project', 'code' => 'READINESS', 'name' => 'ЦФО проекта', 'is_active' => true]);
            $version = \App\BusinessModules\Features\Budgeting\Models\BudgetVersion::query()->create([
                'organization_id' => $organizationId, 'budget_period_id' => $period->id, 'scenario_id' => $scenario->id,
                'budget_kind' => 'bdds', 'version_number' => 1, 'name' => 'Активный бюджет', 'status' => 'active', 'approved_at' => now(), 'activated_at' => now()]);
            $line = \App\BusinessModules\Features\Budgeting\Models\BudgetLine::query()->create([
                'budget_version_id' => $version->id, 'budget_article_id' => $article->id, 'responsibility_center_id' => $center->id, 'currency' => 'RUB']);
            $document->updateQuietly(['budget_article_id' => $article->id, 'responsibility_center_id' => $center->id]);
            return \App\BusinessModules\Features\Budgeting\Models\BudgetAmount::query()->create([
                'budget_line_id' => $line->id, 'month' => now()->startOfMonth()->toDateString(), 'plan_amount' => '500.00', 'forecast_amount' => '500.00', 'currency' => 'RUB']);
        });
        $reader = app(AssistantProjectPaymentReader::class);
        $before = $document->fresh()->getAttributes();
        $checkCount = \App\BusinessModules\Features\Budgeting\Models\BudgetLimitCheck::query()->count();
        $read = $reader->read($this->fixture->owner, $organizationId, ['project_id' => $this->project->id]);
        $check = $read['data']['items'][0]['budget_limit_check'];
        self::assertSame('requires_exception', $check['status']);
        self::assertSame('require_exception', $check['decision']);
        self::assertSame('500.00', $check['excess_amount']);
        self::assertFalse($check['override_applied']);
        self::assertStringContainsString('обоснование исключения в документе не записано', $read['server_formatted_answer']);
        self::assertStringNotContainsString('SSSSSS', json_encode($read));
        self::assertArrayNotHasKey('can_override', $check);
        self::assertSame($before, $document->fresh()->getAttributes());
        self::assertSame($checkCount, \App\BusinessModules\Features\Budgeting\Models\BudgetLimitCheck::query()->count());
        self::assertTrue($reader->matchesReference($this->fixture->owner, $organizationId, $read['source_refs'][0]));
        $document->updateQuietly(['budget_limit_override_reason' => 'PRIVATE-OVERRIDE-REASON']);
        self::assertFalse($reader->matchesReference($this->fixture->owner, $organizationId, $read['source_refs'][0]));
        $overridden = $reader->read($this->fixture->owner, $organizationId, ['project_id' => $this->project->id]);
        self::assertTrue($overridden['data']['items'][0]['budget_limit_check']['override_applied']);
        self::assertStringNotContainsString('PRIVATE-OVERRIDE-REASON', json_encode($overridden));
        self::assertStringContainsString('записано обоснование исключения', $overridden['server_formatted_answer']);
        $budget->updateQuietly(['plan_amount' => '2000.00']);
        self::assertFalse($reader->matchesReference($this->fixture->owner, $organizationId, $overridden['source_refs'][0]));
        $available = $reader->read($this->fixture->owner, $organizationId, ['project_id' => $this->project->id]);
        self::assertSame('allow', $available['data']['items'][0]['budget_limit_check']['decision']);
        $budget->updateQuietly(['plan_amount' => '500.00']);
        $document->updateQuietly(['budget_limit_override_reason' => null]);
        for ($i = 0; $i < 20; $i++) {
            Model::withoutEvents(fn () => PaymentDocument::query()->create([
                'organization_id' => $organizationId, 'project_id' => $this->project->id, 'document_type' => 'invoice',
                'document_number' => 'EARLIER-'.$i, 'document_date' => today(), 'direction' => 'outgoing', 'invoice_type' => 'other',
                'amount' => '1.00', 'paid_amount' => '0.00', 'remaining_amount' => '1.00', 'currency' => 'RUB', 'status' => 'draft',
                'due_date' => today()->subDay(),
            ]));
        }
        $firstPage = $reader->read($this->fixture->owner, $organizationId, ['project_id' => $this->project->id]);
        self::assertSame(21, $firstPage['data']['total']);
        self::assertSame(20, $firstPage['data']['next_offset']);
        self::assertSame(1, $firstPage['data']['budget_check_coverage']['outside_page']);
        self::assertNull($firstPage['data']['items'][0]['budget_limit_check']['excess_amount']);
        self::assertStringContainsString('начиная с 21-го', $firstPage['server_formatted_answer']);
        self::assertStringContainsString('не входят в эту проверку', $firstPage['server_formatted_answer']);
        self::assertNotContains($document->id, array_column($firstPage['data']['items'], 'id'));
        $lastPage = $reader->read($this->fixture->owner, $organizationId, ['project_id' => $this->project->id, 'offset' => 20]);
        self::assertSame($document->id, $lastPage['data']['items'][0]['id']);
        self::assertSame('500.00', $lastPage['data']['items'][0]['budget_limit_check']['excess_amount']);
        self::assertNull($lastPage['data']['next_offset']);
        self::assertFalse($lastPage['data']['has_more']);
        self::assertNotNull($reader->verifiedAnswer($lastPage, $this->fixture->owner, $organizationId));
        $wrongPage = $lastPage['source_refs'][0]; $wrongPage['offset'] = 0;
        self::assertFalse($reader->matchesReference($this->fixture->owner, $organizationId, $wrongPage));
        self::assertSame($checkCount, \App\BusinessModules\Features\Budgeting\Models\BudgetLimitCheck::query()->count());
    }

    public function test_payment_deadline_directions_and_bounded_approval_unknown_preserve_invoice_totals(): void
    {
        $base = ['organization_id' => $this->fixture->organization->id, 'project_id' => $this->project->id, 'document_type' => 'invoice',
            'document_date' => today(), 'invoice_type' => 'other', 'amount' => '100.00', 'paid_amount' => '0.00', 'remaining_amount' => '100.00', 'currency' => 'RUB', 'status' => 'approved'];
        $first = null;
        Model::withoutEvents(function () use ($base, &$first): void {
            $first = PaymentDocument::query()->create(['document_number' => 'OUTGOING-OVERDUE', 'direction' => 'outgoing', 'due_date' => today()->subDay()] + $base);
            PaymentDocument::query()->create(['document_number' => 'INCOMING-FLAGGED', 'direction' => 'incoming', 'due_date' => today()->addWeek(), 'overdue_since' => now()->subDay()] + $base);
            PaymentDocument::query()->create(['document_number' => 'INCOMING-FUTURE', 'direction' => 'incoming', 'due_date' => today()->addWeek()] + $base);
            PaymentDocument::query()->create(['document_number' => 'INCOMING-NO-DATE', 'direction' => 'incoming', 'due_date' => null] + $base);
        });
        $reader = app(AssistantProjectPaymentReader::class); $args = ['project_id' => (int) $this->project->id];
        $read = $reader->read($this->fixture->owner, $this->fixture->organization->id, $args);
        self::assertSame(4, $read['data']['total']); self::assertSame(1, $read['data']['overdue_outgoing']); self::assertSame(1, $read['data']['overdue_incoming']);
        $approvals = [];
        for ($i = 0; $i < 2001; $i++) { $approvals[] = ['organization_id' => $this->fixture->organization->id, 'payment_document_id' => $first->id,
            'approval_role' => 'finance_approver', 'approval_order' => $i + 1, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]; }
        \App\BusinessModules\Core\Payments\Models\PaymentApproval::query()->insert($approvals);
        $bounded = $reader->read($this->fixture->owner, $this->fixture->organization->id, $args);
        self::assertSame(4, $bounded['data']['total']); self::assertFalse($bounded['data']['approval_read_available']);
        self::assertNull($bounded['data']['items'][0]['approvals']['total']); self::assertNull($bounded['data']['items'][0]['approvals']['unnamed']);
        self::assertStringContainsString('ответственный за устранение препятствий не подтверждён', $bounded['server_formatted_answer']);
        self::assertFalse($reader->matchesReference($this->fixture->owner, $this->fixture->organization->id, $read['source_refs'][0]));
        self::assertSame(2001, \App\BusinessModules\Core\Payments\Models\PaymentApproval::query()->count());
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
