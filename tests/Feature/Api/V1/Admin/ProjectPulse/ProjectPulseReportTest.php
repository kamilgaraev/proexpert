<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin\ProjectPulse;

use App\BusinessModules\Features\AIAssistant\Models\ProjectPulseReport;
use App\BusinessModules\Features\AIAssistant\DTOs\ProjectPulse\ProjectPulseContext;
use App\BusinessModules\Features\AIAssistant\DTOs\ProjectPulse\ProjectPulseFact;
use App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\ProjectPulseFactCollector;
use App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\ProjectPulseService;
use App\Enums\ProjectOrganizationRole;
use App\Models\Contract;
use App\Models\ContractOrganizationView;
use App\Models\Contractor;
use App\Models\Project;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use App\Services\Project\ProjectParticipantService;
use ArrayObject;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class ProjectPulseReportTest extends TestCase
{
    public function test_site_request_label_bounds_long_multibyte_locations(): void
    {
        $formatter = new class {
            use \App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\Sources\Concerns\BuildsProjectPulseFacts {
                siteRequestLabel as public label;
            }
        };
        $location = 'Учебная площадка: '.str_repeat('многоэтажный корпус ', 1000).'LOCATION_TAIL_SENTINEL';
        foreach (['personnel', 'material'] as $type) {
            $row = (object) [
                'title' => 'Учебная заявка', 'request_type' => $type,
                'personnel_type' => 'engineer', 'personnel_count' => 3,
                'work_location' => $location, 'location' => $location,
                'work_start_date' => '2026-10-21', 'work_end_date' => '2026-10-23',
            ];
            $label = $formatter->label($row);
            self::assertLessThanOrEqual(500, mb_strlen($label));
            self::assertStringContainsString('Учебная площадка:', $label);
            self::assertStringContainsString('…', $label);
            self::assertStringNotContainsString('LOCATION_TAIL_SENTINEL', $label);
            self::assertSame(1, preg_match('//u', $label));
            if ($type === 'personnel') {
                self::assertStringContainsString('21.10.2026 — 23.10.2026', $label);
            }
            $row->work_location = $row->location = 'Короткое место';
            self::assertStringContainsString('Короткое место', $formatter->label($row));
            self::assertStringNotContainsString('…', $formatter->label($row));
        }
    }

    public function test_site_request_fact_labels_use_business_context_without_internal_numbers(): void
    {
        $context = $this->context(roleSlug: 'organization_owner');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $otherProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $foreign = $this->context(roleSlug: 'organization_owner');
        $foreignProject = Project::factory()->create(['organization_id' => $foreign->organization->id]);
        $ids = [];
        foreach ([
            'engineer' => [$context, $project, 'engineer', 3, 'Учебный офис'],
            'worker' => [$context, $project, 'general_worker', 2, 'Учебная площадка'],
            'other_project' => [$context, $otherProject, 'engineer', 5, 'Другой проект'],
            'foreign' => [$foreign, $foreignProject, 'engineer', 9, 'Другая организация'],
        ] as $key => [$actor, $targetProject, $personnelType, $count, $location]) {
            $ids[$key] = DB::table('site_requests')->insertGetId([
                'organization_id' => $actor->organization->id,
                'project_id' => $targetProject->id,
                'user_id' => $actor->user->id,
                'title' => 'Учебный персонал',
                'request_type' => 'personnel',
                'status' => 'pending',
                'personnel_type' => $personnelType,
                'personnel_count' => $count,
                'work_location' => $location,
                'work_start_date' => '2026-10-21',
                'work_end_date' => '2026-10-23',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $ids['material'] = DB::table('site_requests')->insertGetId([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'user_id' => $context->user->id,
            'title' => '',
            'request_type' => 'material',
            'status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $pulseContext = ProjectPulseContext::fromValidated(
            ['project_id' => $project->id, 'use_ai' => false],
            $context->organization->id,
            $context->user->id,
        );
        foreach ([
            \App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\Sources\ProjectPulseSiteRequestFactSource::class,
            \App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\Sources\ProjectPulsePeopleFactSource::class,
            \App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\Sources\ProjectPulseProcurementFactSource::class,
        ] as $source) {
            $facts = app($source)->collect($pulseContext)
                ->filter(fn (ProjectPulseFact $fact) => ($fact->relatedEntity['type'] ?? null) === 'site_request');
            self::assertNotEmpty($facts, $source);
            self::assertEqualsCanonicalizing(
                str_ends_with($source, 'ProjectPulseProcurementFactSource')
                    ? [$ids['material']]
                    : [$ids['engineer'], $ids['worker'], $ids['material']],
                $facts->map(fn (ProjectPulseFact $fact) => $fact->relatedEntity['id'])->all(),
            );
            foreach ($facts as $fact) {
                $entity = $fact->relatedEntity;
                self::assertNotContains($entity['id'], [$ids['other_project'], $ids['foreign']]);
                self::assertStringNotContainsString('#' . $entity['id'], $entity['label']);
                self::assertStringNotContainsString('#' . $entity['id'], $fact->text);
                self::assertSame('/site-requests/' . $entity['id'], $entity['route']);
                if ($entity['id'] === $ids['material']) {
                    self::assertSame('Заявка с объекта · Заявка на материалы', $entity['label']);
                } else {
                    self::assertStringContainsString('Учебный персонал · Заявка на персонал', $entity['label']);
                    self::assertStringContainsString('21.10.2026 — 23.10.2026', $entity['label']);
                    self::assertStringContainsString(
                        $entity['id'] === $ids['engineer'] ? 'Инженер · 3 чел. · Учебный офис' : 'Разнорабочий · 2 чел. · Учебная площадка',
                        $entity['label'],
                    );
                }
            }
        }
    }

    public function test_owner_can_load_current_project_pulse_report(): void
    {
        $context = $this->context(roleSlug: 'organization_owner');
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
            'source_refs' => [['entity_type' => 'project', 'entity_id' => (string) $project->id]],
            'required_domains' => ['reports', 'finance'],
            'created_by_user_id' => $user->id,
            'generated_at' => now(),
        ]);

        $timings = $this->capturePulseTimings();
        $response = $this
            ->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/ai-assistant/project-pulse/current?project_id=' . $project->id);

        $response->assertOk();
        self::assertCount(1, $timings);
        $this->assertPulsePhases($timings[0], ['pulse_actor', 'pulse_current', 'pulse_format']);
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

    public function test_project_participant_can_load_current_project_pulse_report(): void
    {
        $ownerContext = $this->context(roleSlug: 'organization_owner');
        $participantContext = $this->context(
            organizationAttributes: [
                'capabilities' => ['general_contracting'],
                'primary_business_type' => 'general_contracting',
            ],
            roleSlug: 'organization_owner'
        );
        $project = Project::factory()->create([
            'organization_id' => $ownerContext->organization->id,
            'name' => 'Contractor project',
        ]);

        app(ProjectParticipantService::class)->attach(
            $project,
            $participantContext->organization->id,
            ProjectOrganizationRole::CONTRACTOR,
            $ownerContext->user
        );

        ProjectPulseReport::create([
            'organization_id' => $participantContext->organization->id,
            'project_id' => $project->id,
            'scope_type' => 'project',
            'report_date' => now()->toDateString(),
            'period_preset' => 'today',
            'period_from' => now()->startOfDay(),
            'period_to' => now()->endOfDay(),
            'status' => 'good',
            'ai_status' => 'rules_only',
            'summary' => [
                'title' => 'Works are on track',
                'text' => 'No critical events found.',
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
            'source_refs' => [['entity_type' => 'project', 'entity_id' => (string) $project->id]],
            'required_domains' => ['reports', 'finance'],
            'created_by_user_id' => $participantContext->user->id,
            'generated_at' => now(),
        ]);

        $response = $this
            ->withHeaders($participantContext->authHeaders())
            ->getJson('/api/v1/admin/ai-assistant/project-pulse/current?project_id=' . $project->id);

        $response->assertOk();
        $response->assertJsonPath('data.scope.organization_id', $participantContext->organization->id);
        $response->assertJsonPath('data.scope.project_id', $project->id);
    }

    public function test_owner_can_open_project_pulse_report_from_history(): void
    {
        $context = $this->context(roleSlug: 'organization_owner');
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
            'source_refs' => [['entity_type' => 'project', 'entity_id' => (string) $project->id]],
            'required_domains' => ['reports', 'finance'],
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
        $context = $this->context(roleSlug: 'organization_owner');
        $organization = $context->organization;

        $timings = $this->capturePulseTimings();
        $response = $this
            ->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/ai-assistant/project-pulse/generate', [
                'period' => 'today',
                'use_ai' => false,
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.ai_mode.status', 'rules_only');
        self::assertCount(1, $timings);
        $this->assertPulsePhases($timings[0], ['pulse_actor', 'pulse_facts', 'pulse_access', 'pulse_rules',
            'pulse_synthesis', 'pulse_finance', 'pulse_write', 'pulse_format']);
        $this->assertDatabaseHas('project_pulse_reports', [
            'organization_id' => $organization->id,
            'scope_type' => 'organization',
        ]);
    }

    public function test_project_pulse_contains_approved_purchase_request_without_order(): void
    {
        $context = $this->context(roleSlug: 'organization_owner');
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
        $context = $this->context(roleSlug: 'organization_owner');
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

    public function test_project_pulse_contract_risks_include_only_active_unarchived_contracts(): void
    {
        $context = $this->context(roleSlug: 'organization_owner');
        $organizationId = $context->organization->id;
        $project = Project::factory()->create(['organization_id' => $organizationId, 'is_archived' => false]);
        $contractor = Contractor::withoutEvents(fn () => Contractor::query()->create([
            'organization_id' => $organizationId,
            'name' => 'Pulse contract supplier',
        ]));
        $contracts = [];
        foreach ([
            'legacy_active' => ['active', null],
            'visible_active' => ['active', 'active'],
            'archived_placement' => ['active', 'archived'],
            'trashed_placement' => ['active', 'trashed'],
            'draft' => ['draft', null],
            'archived_status' => ['archived', null],
            'terminated' => ['terminated', null],
            'completed' => ['completed', null],
        ] as $key => [$status, $visibility]) {
            $contract = Contract::withoutEvents(fn () => Contract::query()->create([
                'organization_id' => $organizationId,
                'project_id' => $project->id,
                'contractor_id' => $contractor->id,
                'contract_side_type' => 'contract',
                'number' => 'PULSE-CONTRACT-' . $key,
                'date' => now()->subDays(10)->toDateString(),
                'subject' => 'Pulse contract ' . $key,
                'work_type_category' => 'smr',
                'base_amount' => 100,
                'total_amount' => 100,
                'gp_percentage' => 0,
                'planned_advance_amount' => 0,
                'actual_advance_amount' => 0,
                'status' => $status,
                'start_date' => now()->subDays(10)->toDateString(),
                'end_date' => now()->subDays(2)->toDateString(),
                'is_fixed_amount' => true,
                'is_multi_project' => false,
                'is_self_execution' => false,
            ]));
            if ($visibility !== null) {
                ContractOrganizationView::query()->create([
                    'contract_id' => $contract->id,
                    'organization_id' => $organizationId,
                    'visibility' => $visibility,
                    'version' => 1,
                ]);
            }
            $contracts[$key] = $contract->id;
        }

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/ai-assistant/project-pulse/generate', [
                'project_id' => $project->id,
                'period' => 'today',
                'use_ai' => false,
            ])->assertOk();
        $contractIds = collect($response->json('data.facts'))
            ->where('type', 'contract')->pluck('related_entity.id')->all();

        self::assertEqualsCanonicalizing([
            $contracts['legacy_active'],
            $contracts['visible_active'],
        ], $contractIds);
    }

    public function test_project_pulse_delivery_risks_exclude_delivered_and_other_project_orders(): void
    {
        $context = $this->context(roleSlug: 'organization_owner');
        $organizationId = $context->organization->id;
        $project = Project::factory()->create(['organization_id' => $organizationId, 'is_archived' => false]);
        $otherProject = Project::factory()->create(['organization_id' => $organizationId, 'is_archived' => false]);
        $supplierId = DB::table('suppliers')->insertGetId([
            'organization_id' => $organizationId,
            'name' => 'Pulse delivery supplier',
            'code' => 'PULSE-DELIVERY-' . $organizationId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $orders = [];
        foreach ([
            'selected_open' => [$project->id, 'confirmed'],
            'selected_delivered' => [$project->id, 'delivered'],
            'other_open' => [$otherProject->id, 'confirmed'],
            'unlinked_open' => [null, 'confirmed'],
        ] as $key => [$projectId, $status]) {
            $siteRequestId = $projectId === null ? null : DB::table('site_requests')->insertGetId([
                'organization_id' => $organizationId,
                'project_id' => $projectId,
                'user_id' => $context->user->id,
                'title' => 'Pulse delivery ' . $key,
                'request_type' => 'material',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $requestId = DB::table('purchase_requests')->insertGetId([
                'organization_id' => $organizationId,
                'site_request_id' => $siteRequestId,
                'request_number' => 'PULSE-REQUEST-' . $key,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $orders[$key] = DB::table('purchase_orders')->insertGetId([
                'organization_id' => $organizationId,
                'purchase_request_id' => $requestId,
                'supplier_id' => $supplierId,
                'order_number' => 'PULSE-ORDER-' . $key,
                'order_date' => now()->subDays(5)->toDateString(),
                'delivery_date' => now()->subDays(2)->toDateString(),
                'status' => $status,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/ai-assistant/project-pulse/generate', [
                'project_id' => $project->id,
                'period' => 'today',
                'use_ai' => false,
            ])->assertOk();
        $orderIds = collect($response->json('data.facts'))
            ->where('type', 'purchase_order')->pluck('related_entity.id')->all();

        self::assertContains($orders['selected_open'], $orderIds);
        self::assertNotContains($orders['selected_delivered'], $orderIds);
        self::assertNotContains($orders['other_open'], $orderIds);
        self::assertNotContains($orders['unlinked_open'], $orderIds);

        $organizationResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/ai-assistant/project-pulse/generate', [
                'period' => 'today',
                'use_ai' => false,
            ])->assertOk();
        $organizationOrderIds = collect($organizationResponse->json('data.facts'))
            ->where('type', 'purchase_order')->pluck('related_entity.id')->all();

        self::assertContains($orders['selected_open'], $organizationOrderIds);
        self::assertContains($orders['other_open'], $organizationOrderIds);
        self::assertContains($orders['unlinked_open'], $organizationOrderIds);
        self::assertNotContains($orders['selected_delivered'], $organizationOrderIds);
    }

    public function test_project_pulse_contains_overdue_open_work_constraint_in_project_scope(): void
    {
        $context = $this->context(roleSlug: 'organization_owner');
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
            'title' => 'Не привезена арматура',
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
        $context = $this->context(roleSlug: 'organization_owner');
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
        $violationId = DB::table('safety_violations')->insertGetId([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'created_by_user_id' => $context->user->id,
            'violation_number' => 'HSE-V-ERP-001',
            'title' => 'Нет страховочной привязи',
            'severity' => 'critical',
            'status' => 'open',
            'location_name' => 'Кровля',
            'due_date' => now()->subDay()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('safety_corrective_actions')->insert([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'violation_id' => $violationId,
            'created_by_user_id' => $context->user->id,
            'action_number' => 'HSE-C-ERP-001',
            'title' => 'Выдать страховочные привязи',
            'source_type' => 'violation',
            'severity' => 'critical',
            'status' => 'open',
            'due_date' => now()->subDay()->toDateString(),
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
            'overdue_safety_violations',
            'overdue_safety_corrective_actions',
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

    public function test_rfi_alias_keeps_fresh_organization_and_soft_delete_checks_outside_http(): void
    {
        $context = $this->context(roleSlug: 'organization_owner');
        $foreignContext = $this->context(roleSlug: 'organization_owner');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $foreignProject = Project::factory()->create(['organization_id' => $foreignContext->organization->id]);
        $insert = static fn (AdminApiTestContext $owner, Project $parent, string $number, bool $deleted = false): int => DB::table('change_management_rfis')->insertGetId([
            'organization_id' => $owner->organization->id,
            'project_id' => $parent->id,
            'created_by_user_id' => $owner->user->id,
            'rfi_number' => $number,
            'subject' => 'RFI guard fixture',
            'question' => 'RFI guard question',
            'addressee_type' => 'designer',
            'status' => 'sent',
            'response_due_date' => now()->subDay()->toDateString(),
            'deleted_at' => $deleted ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $ownId = $insert($context, $project, 'RFI-OWN');
        $foreignId = $insert($foreignContext, $foreignProject, 'RFI-FOREIGN');
        $deletedId = $insert($context, $project, 'RFI-DELETED', true);
        $facts = collect([$ownId, $foreignId, $deletedId])->map(static fn (int $id): ProjectPulseFact => new ProjectPulseFact(
            id: 'rfi:'.$id.':guard',
            type: 'unanswered_rfi',
            priority: 'warning',
            title: 'RFI guard',
            text: 'RFI guard fact',
            relatedEntity: ['type' => 'rfi', 'id' => $id],
            category: 'change',
        ));
        $collector = $this->mock(ProjectPulseFactCollector::class);
        $collector->shouldReceive('collect')->twice()->andReturn($facts);
        $collector->shouldReceive('finance')->twice()->andReturn([]);
        $service = app(ProjectPulseService::class);
        $requestContext = ProjectPulseContext::fromValidated(['project_id' => $project->id, 'use_ai' => false], $context->organization->id, $context->user->id);

        $first = $service->generate($requestContext);

        self::assertSame(['rfi:'.$ownId.':guard'], array_column($first['facts'], 'id'));
        self::assertSame('rfi', $first['facts'][0]['related_entity']['type']);
        $stored = ProjectPulseReport::findOrFail($first['id']);
        $referenceIdentities = static fn (ProjectPulseReport $report): array => array_map(
            static fn (array $reference): array => [$reference['entity_type'], $reference['entity_id']],
            $report->source_refs,
        );
        self::assertContains(['change_management_rfi', (string) $ownId], $referenceIdentities($stored));
        self::assertNotContains(['change_management_rfi', (string) $foreignId], $referenceIdentities($stored));
        self::assertNotContains(['change_management_rfi', (string) $deletedId], $referenceIdentities($stored));

        DB::table('change_management_rfis')->where('id', $ownId)->update(['deleted_at' => now()]);
        $second = $service->generate($requestContext);

        self::assertSame([], $second['facts']);
        self::assertNotContains(['change_management_rfi', (string) $ownId], $referenceIdentities(ProjectPulseReport::findOrFail($second['id'])));
    }

    private function capturePulseTimings(): ArrayObject
    {
        $timings = new ArrayObject;
        Event::listen(MessageLogged::class, static function (MessageLogged $event) use ($timings): void {
            if ($event->message === 'api_response_timing'
                && in_array($event->context['route'] ?? null, ['api/v1/admin/ai-assistant/project-pulse/current',
                    'api/v1/admin/ai-assistant/project-pulse/generate'], true)) {
                $timings[] = $event->context;
            }
        });

        return $timings;
    }

    private function assertPulsePhases(array $timing, array $expected): void
    {
        self::assertArrayHasKey('sql_sources', $timing);
        $phases = array_filter($timing['processing_phases'], static fn (string $name): bool => str_starts_with($name, 'pulse_'), ARRAY_FILTER_USE_KEY);
        self::assertSame($expected, array_keys($phases));
        foreach ($phases as $phase) {
            self::assertSame(1, $phase['count']);
            self::assertGreaterThanOrEqual(0, $phase['total_ms']);
            self::assertGreaterThanOrEqual(0, $phase['sql_count']);
            self::assertGreaterThanOrEqual(0, $phase['sql_total_ms']);
            self::assertSame([], array_diff(array_keys($phase), ['count', 'total_ms', 'max_ms', 'sql_count', 'sql_total_ms', 'process_cpu_ms']));
        }
        self::assertLessThanOrEqual($timing['sql_count'], array_sum(array_column($phases, 'sql_count')));
    }

    private function context(array $userAttributes = [], array $organizationAttributes = [], string $roleSlug = 'web_admin'): AdminApiTestContext
    {
        $context = AdminApiTestContext::create($userAttributes, $organizationAttributes, $roleSlug);
        $catalogs = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(config_path('ModuleList'), \FilesystemIterator::SKIP_DOTS));
        foreach ($catalogs as $catalog) {
            if (! $catalog->isFile() || strtolower($catalog->getExtension()) !== 'json') { continue; }
            $definition = json_decode(file_get_contents($catalog->getPathname()), true, 512, JSON_THROW_ON_ERROR);
            \App\Models\Module::query()->firstOrCreate(['slug' => $definition['slug']], [
                'name' => $definition['name'], 'version' => $definition['version'] ?? '1.0.0', 'type' => $definition['type'] ?? 'feature',
                'billing_model' => $definition['billing_model'] ?? 'free', 'category' => $definition['category'] ?? 'test',
                'permissions' => $definition['permissions'] ?? [], 'is_active' => true,
                'can_deactivate' => $definition['can_deactivate'] ?? false,
            ]);
        }
        $account = \App\Models\OrganizationCommercialAccount::query()->updateOrCreate(['organization_id' => $context->organization->id],
            ['status' => 'active', 'offer_type' => 'packages', 'quote_version' => 1]);
        foreach (glob(config_path('Packages/*.json')) as $package) {
            $definition = json_decode(file_get_contents($package), true, 512, JSON_THROW_ON_ERROR);
            \App\Models\OrganizationPackageSubscription::query()->create(['organization_id' => $context->organization->id,
                'commercial_account_id' => $account->id, 'package_slug' => $definition['slug'], 'tier' => 'standard',
                'status' => 'active', 'access_source' => 'paid_package', 'price_paid' => 0,
                'current_period_start_at' => now(), 'current_period_end_at' => now()->addMonth()]);
        }

        return $context;
    }
}
