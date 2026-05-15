<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin\ProjectPulse;

use App\BusinessModules\Features\AIAssistant\Models\ProjectPulseReport;
use App\Models\Project;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use Illuminate\Support\Facades\DB;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class ProjectPulseReportTest extends TestCase
{
    public function test_owner_can_load_current_project_pulse_report(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $organization = $context->organization;
        $user = $context->user;
        $project = Project::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'ЖК Северный',
        ]);
        ProjectPulseReport::create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'scope_type' => 'project',
            'report_date' => now()->toDateString(),
            'period_preset' => 'today',
            'period_from' => now()->startOfDay(),
            'period_to' => now()->endOfDay(),
            'status' => 'good',
            'ai_status' => 'rules_only',
            'summary' => [
                'title' => 'Ситуация стабильная',
                'text' => 'Критичных событий за выбранный период не найдено.',
            ],
            'metrics' => [],
            'urgent_actions' => [],
            'risk_groups' => [],
            'finance' => [
                'performed_amount' => 0,
                'paid_amount' => 0,
                'pending_acts_amount' => 0,
                'deviation_items' => [],
            ],
            'activity' => [],
            'recommendations' => [],
            'raw_facts' => [],
            'created_by_user_id' => $user->id,
            'generated_at' => now(),
        ]);

        $response = $this
            ->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/ai-assistant/project-pulse/current?project_id=' . $project->id);

        $response->assertOk();
        $response->assertJsonPath('data.scope.organization_id', $organization->id);
        $response->assertJsonPath('data.scope.project_id', $project->id);
        $response->assertJsonStructure([
            'data' => [
                'report_date',
                'period' => ['preset', 'from', 'to'],
                'scope' => ['type', 'organization_id', 'project_id'],
                'status',
                'ai_mode' => ['status', 'provider', 'message'],
                'summary' => ['title', 'text'],
                'metrics',
                'urgent_actions',
                'risk_groups',
                'finance',
                'activity',
                'recommendations',
                'generated_at',
            ],
        ]);
    }

    public function test_owner_can_open_project_pulse_report_from_history(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $organization = $context->organization;
        $user = $context->user;
        $project = Project::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'ЖК Северный',
        ]);
        $report = ProjectPulseReport::create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'scope_type' => 'project',
            'report_date' => now()->toDateString(),
            'period_preset' => 'today',
            'period_from' => now()->startOfDay(),
            'period_to' => now()->endOfDay(),
            'status' => 'warning',
            'ai_status' => 'rules_only',
            'summary' => [
                'title' => 'Есть вопросы для контроля',
                'text' => 'Система нашла события, которые стоит проверить в рабочем порядке.',
            ],
            'metrics' => [],
            'urgent_actions' => [],
            'risk_groups' => [],
            'finance' => [
                'performed_amount' => 0,
                'paid_amount' => 0,
                'pending_acts_amount' => 0,
                'deviation_items' => [],
            ],
            'activity' => [],
            'recommendations' => [],
            'raw_facts' => [],
            'created_by_user_id' => $user->id,
            'generated_at' => now(),
        ]);

        $response = $this
            ->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/ai-assistant/project-pulse/reports/' . $report->id);

        $response->assertOk();
        $response->assertJsonPath('data.id', $report->id);
        $response->assertJsonPath('data.scope.organization_id', $organization->id);
        $response->assertJsonPath('data.scope.project_id', $project->id);
    }

    public function test_generate_stores_project_pulse_report(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $organization = $context->organization;

        $response = $this
            ->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/ai-assistant/project-pulse/generate', [
                'period' => 'today',
                'use_ai' => false,
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.ai_mode.status', 'rules_only');
        $this->assertDatabaseHas('project_pulse_reports', [
            'organization_id' => $organization->id,
            'scope_type' => 'organization',
        ]);
    }

    public function test_project_pulse_contains_approved_purchase_request_without_order(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $organization = $context->organization;

        $purchaseRequestId = DB::table('purchase_requests')->insertGetId([
            'organization_id' => $organization->id,
            'request_number' => '33-202604-0001',
            'status' => 'approved',
            'budget_amount' => 35000,
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);

        $response = $this
            ->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/ai-assistant/project-pulse/generate', [
                'period' => 'today',
                'use_ai' => false,
            ]);

        $response->assertOk();
        $fact = collect($response->json('data.facts'))
            ->firstWhere('id', 'purchase_request:' . $purchaseRequestId . ':no_order');

        self::assertNotNull($fact);
        self::assertSame('procurement', $fact['category']);
        self::assertSame('/procurement/purchase-requests/' . $purchaseRequestId, $fact['related_entity']['route']);
        self::assertSame('/procurement/purchase-requests/' . $purchaseRequestId, $fact['primary_action']['route']);
        self::assertStringContainsString('33-202604-0001', $fact['text']);
    }

    public function test_project_pulse_does_not_mix_unlinked_purchase_requests_into_project_scope(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $organization = $context->organization;
        $project = Project::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Строительство склада Литер А',
            'status' => 'active',
        ]);

        $purchaseRequestId = DB::table('purchase_requests')->insertGetId([
            'organization_id' => $organization->id,
            'request_number' => '33-202604-0002',
            'status' => 'approved',
            'budget_amount' => 35000,
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);

        $response = $this
            ->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/ai-assistant/project-pulse/generate', [
                'project_id' => $project->id,
                'period' => 'today',
                'use_ai' => false,
            ]);

        $response->assertOk();
        $fact = collect($response->json('data.facts'))
            ->firstWhere('id', 'purchase_request:' . $purchaseRequestId . ':no_order');

        self::assertNull($fact);
    }

    public function test_project_pulse_contains_overdue_open_work_constraint_in_project_scope(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $organization = $context->organization;
        $project = Project::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'РљРѕСЂРїСѓСЃ Рђ',
            'status' => 'active',
        ]);
        $schedule = ProjectSchedule::query()->create([
            'project_id' => $project->id,
            'organization_id' => $organization->id,
            'created_by_user_id' => $context->user->id,
            'name' => 'Р“СЂР°С„РёРє РєРѕСЂРїСѓСЃР° Рђ',
            'planned_start_date' => now()->subDays(10)->toDateString(),
            'planned_end_date' => now()->addDays(20)->toDateString(),
            'status' => 'active',
            'is_template' => false,
            'calculation_settings' => [],
            'display_settings' => [],
            'critical_path_calculated' => false,
            'overall_progress_percent' => 20,
        ]);
        $task = ScheduleTask::query()->create([
            'schedule_id' => $schedule->id,
            'organization_id' => $organization->id,
            'created_by_user_id' => $context->user->id,
            'name' => 'РњРѕРЅРѕР»РёС‚ РїРµСЂРІРѕРіРѕ СЌС‚Р°Р¶Р°',
            'task_type' => 'task',
            'planned_start_date' => now()->subDays(6)->toDateString(),
            'planned_end_date' => now()->addDays(3)->toDateString(),
            'planned_duration_days' => 10,
            'planned_work_hours' => 80,
            'quantity' => 100,
            'progress_percent' => 40,
            'status' => 'in_progress',
            'priority' => 'normal',
            'constraint_type' => 'none',
            'level' => 0,
            'sort_order' => 1,
        ]);
        $lookaheadPlanId = DB::table('lookahead_plans')->insertGetId([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'schedule_id' => $schedule->id,
            'created_by_user_id' => $context->user->id,
            'title' => 'РџР»Р°РЅ РЅР° РґРІРµ РЅРµРґРµР»Рё',
            'start_date' => now()->startOfWeek()->toDateString(),
            'end_date' => now()->startOfWeek()->addDays(13)->toDateString(),
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $planTaskId = DB::table('lookahead_plan_tasks')->insertGetId([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'schedule_id' => $schedule->id,
            'lookahead_plan_id' => $lookaheadPlanId,
            'schedule_task_id' => $task->id,
            'planned_start_date' => now()->toDateString(),
            'planned_end_date' => now()->addDays(2)->toDateString(),
            'readiness_status' => 'blocked',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $constraintId = DB::table('work_constraints')->insertGetId([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'schedule_id' => $schedule->id,
            'lookahead_plan_task_id' => $planTaskId,
            'schedule_task_id' => $task->id,
            'created_by_user_id' => $context->user->id,
            'constraint_type' => 'material',
            'title' => 'РќРµ РїСЂРёРІРµР·РµРЅР° Р°СЂРјР°С‚СѓСЂР°',
            'severity' => 'hard',
            'status' => 'open',
            'due_date' => now()->subDay()->toDateString(),
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDay(),
        ]);

        $response = $this
            ->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/ai-assistant/project-pulse/generate', [
                'project_id' => $project->id,
                'period' => 'today',
                'use_ai' => false,
            ]);

        $response->assertOk();
        $fact = collect($response->json('data.facts'))
            ->firstWhere('id', 'work_constraint:' . $constraintId . ':overdue');

        self::assertNotNull($fact);
        self::assertSame('schedule', $fact['category']);
        self::assertSame('work_constraint', $fact['type']);
        self::assertSame('critical', $fact['priority']);
        self::assertSame($project->id, $fact['project_id']);
        self::assertSame($constraintId, $fact['related_entity']['id']);
        self::assertSame('/projects/' . $project->id . '/schedules/' . $schedule->id . '/lookahead', $fact['primary_action']['route']);
    }

    public function test_project_pulse_collects_construction_erp_risk_sources_in_project_scope(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $organization = $context->organization;
        $project = Project::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Корпус А',
            'status' => 'active',
        ]);
        $schedule = ProjectSchedule::query()->create([
            'project_id' => $project->id,
            'organization_id' => $organization->id,
            'created_by_user_id' => $context->user->id,
            'name' => 'График корпуса А',
            'planned_start_date' => now()->subDays(10)->toDateString(),
            'planned_end_date' => now()->addDays(20)->toDateString(),
            'status' => 'active',
            'is_template' => false,
            'calculation_settings' => [],
            'display_settings' => [],
            'critical_path_calculated' => false,
            'overall_progress_percent' => 20,
        ]);
        $task = ScheduleTask::query()->create([
            'schedule_id' => $schedule->id,
            'organization_id' => $organization->id,
            'created_by_user_id' => $context->user->id,
            'name' => 'Монолит первого этажа',
            'task_type' => 'task',
            'planned_start_date' => now()->subDays(6)->toDateString(),
            'planned_end_date' => now()->addDays(3)->toDateString(),
            'planned_duration_days' => 10,
            'planned_work_hours' => 80,
            'quantity' => 100,
            'progress_percent' => 40,
            'status' => 'in_progress',
            'priority' => 'normal',
            'constraint_type' => 'none',
            'level' => 0,
            'sort_order' => 1,
        ]);
        $lookaheadPlanId = DB::table('lookahead_plans')->insertGetId([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'schedule_id' => $schedule->id,
            'created_by_user_id' => $context->user->id,
            'title' => 'План на две недели',
            'start_date' => now()->startOfWeek()->toDateString(),
            'end_date' => now()->startOfWeek()->addDays(13)->toDateString(),
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $lookaheadPlanTaskId = DB::table('lookahead_plan_tasks')->insertGetId([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'schedule_id' => $schedule->id,
            'lookahead_plan_id' => $lookaheadPlanId,
            'schedule_task_id' => $task->id,
            'planned_start_date' => now()->toDateString(),
            'planned_end_date' => now()->addDays(2)->toDateString(),
            'readiness_status' => 'blocked',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('quality_defects')->insert([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'created_by' => $context->user->id,
            'defect_number' => 'QC-ERP-001',
            'title' => 'Раковины бетона в стене',
            'severity' => 'critical',
            'status' => 'in_progress',
            'location_name' => 'Секция 1',
            'due_date' => now()->subDay()->toDateString(),
            'inspection_required' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $documentSetId = DB::table('executive_document_sets')->insertGetId([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'created_by' => $context->user->id,
            'set_number' => 'ИД-ERP-001',
            'title' => 'Комплект ИД по монолиту',
            'status' => 'draft',
            'planned_transmittal_date' => now()->addDays(2)->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('executive_documents')->insert([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'document_set_id' => $documentSetId,
            'created_by' => $context->user->id,
            'document_type' => 'act_hidden_works',
            'title' => 'Акт скрытых работ',
            'status' => 'draft',
            'inspection_date' => now()->subDay()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('safety_work_permits')->insert([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'created_by_user_id' => $context->user->id,
            'permit_number' => 'HSE-ERP-001',
            'title' => 'Огневые работы',
            'permit_type' => 'hot_work',
            'risk_level' => 'high',
            'valid_from' => now()->subDays(3),
            'valid_until' => now()->subDay(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('safety_incidents')->insert([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'reported_by_user_id' => $context->user->id,
            'incident_number' => 'INC-ERP-001',
            'title' => 'Падение инструмента',
            'incident_type' => 'near_miss',
            'severity' => 'major',
            'status' => 'investigation',
            'occurred_at' => now()->subHours(4),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $assetId = DB::table('machinery_assets')->insertGetId([
            'organization_id' => $organization->id,
            'current_project_id' => $project->id,
            'asset_code' => 'MCH-ERP-001',
            'name' => 'Башенный кран',
            'status' => 'assigned',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('machinery_downtimes')->insert([
            'organization_id' => $organization->id,
            'asset_id' => $assetId,
            'project_id' => $project->id,
            'reason' => 'breakdown',
            'started_at' => now()->subHours(5),
            'ended_at' => null,
            'duration_minutes' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $workOrderId = DB::table('production_labor_work_orders')->insertGetId([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'schedule_task_id' => $task->id,
            'created_by_user_id' => $context->user->id,
            'order_number' => 'PL-ERP-001',
            'title' => 'Армирование стен',
            'assignee_type' => 'brigade',
            'assignee_name' => 'Бригада 12',
            'status' => 'in_progress',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('production_labor_work_order_lines')->insert([
            'organization_id' => $organization->id,
            'work_order_id' => $workOrderId,
            'schedule_task_id' => $task->id,
            'name' => 'Армирование',
            'unit' => 'м2',
            'planned_quantity' => 100,
            'accepted_quantity' => 45,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('change_management_rfis')->insert([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'created_by_user_id' => $context->user->id,
            'rfi_number' => 'RFI-ERP-001',
            'subject' => 'Узел армирования',
            'question' => 'Подтвердить решение по узлу армирования.',
            'addressee_type' => 'designer',
            'status' => 'sent',
            'response_due_date' => now()->subDay()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('change_management_change_requests')->insert([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'created_by_user_id' => $context->user->id,
            'change_number' => 'CR-ERP-001',
            'title' => 'Замена арматуры',
            'reason' => 'design_change',
            'description' => 'Требуется согласовать замену класса арматуры.',
            'initiator_type' => 'contractor',
            'status' => 'customer_review',
            'submitted_at' => now()->subDays(2),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('work_constraints')->insert([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'schedule_id' => $schedule->id,
            'lookahead_plan_task_id' => $lookaheadPlanTaskId,
            'schedule_task_id' => $task->id,
            'created_by_user_id' => $context->user->id,
            'constraint_type' => 'material',
            'title' => 'Не привезена арматура',
            'severity' => 'hard',
            'status' => 'open',
            'due_date' => now()->subDay()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $acceptanceScopeId = DB::table('acceptance_scopes')->insertGetId([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'created_by_user_id' => $context->user->id,
            'title' => 'Секция 1',
            'status' => 'accepted',
            'planned_acceptance_date' => now()->addDay()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $handoverPackageId = DB::table('handover_packages')->insertGetId([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'acceptance_scope_id' => $acceptanceScopeId,
            'created_by_user_id' => $context->user->id,
            'title' => 'Комплект передачи секции 1',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('handover_package_documents')->insert([
            'handover_package_id' => $handoverPackageId,
            'title' => 'Акт приемки зоны',
            'document_type' => 'handover_act',
            'is_required' => true,
            'status' => 'missing',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this
            ->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/ai-assistant/project-pulse/generate', [
                'project_id' => $project->id,
                'period' => 'today',
                'use_ai' => false,
            ]);

        $response->assertOk();
        $factTypes = collect($response->json('data.facts'))->pluck('type')->all();
        $categoryKeys = collect($response->json('data.categories'))->pluck('key')->all();
        $riskGroupKeys = collect($response->json('data.risk_groups'))->pluck('key')->all();

        foreach ([
            'open_critical_defects',
            'overdue_reinspections',
            'missing_executive_documents',
            'expired_work_permits',
            'open_safety_incidents',
            'machinery_downtime',
            'labor_underproduction',
            'unapproved_changes',
            'unanswered_rfi',
            'lookahead_hard_constraints',
            'handover_blocked_locations',
        ] as $expectedType) {
            self::assertContains($expectedType, $factTypes);
        }

        foreach (['quality', 'documentation', 'safety', 'machinery', 'labor', 'change', 'schedule', 'handover'] as $expectedCategory) {
            self::assertContains($expectedCategory, $categoryKeys);
            self::assertContains($expectedCategory, $riskGroupKeys);
        }
    }
}
