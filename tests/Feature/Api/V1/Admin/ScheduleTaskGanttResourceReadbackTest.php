<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\EstimatePositionItemType;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateSection;
use App\Models\MeasurementUnit;
use App\Models\Module;
use App\Models\OrganizationCommercialAccount;
use App\Models\OrganizationPackageSubscription;
use App\Models\Project;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use App\Models\User;
use App\Modules\Core\AccessController;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class ScheduleTaskGanttResourceReadbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigned_task_resources_are_returned_in_a_fresh_gantt_response(): void
    {
        $context = AdminApiTestContext::create();
        $this->activateSchedulePackage($context);
        $project = Project::factory()->create([
            'organization_id' => $context->organization->id,
        ]);
        $this->assertScheduleEditGranted($context, $project);
        $schedule = ProjectSchedule::query()->create([
            'project_id' => $project->id,
            'organization_id' => $context->organization->id,
            'created_by_user_id' => $context->user->id,
            'name' => 'Resource readback schedule',
            'planned_start_date' => '2026-06-01',
            'planned_end_date' => '2026-06-30',
            'status' => 'active',
            'is_template' => false,
            'critical_path_calculated' => false,
            'overall_progress_percent' => 0,
        ]);
        $task = ScheduleTask::query()->create([
            'schedule_id' => $schedule->id,
            'organization_id' => $context->organization->id,
            'created_by_user_id' => $context->user->id,
            'name' => 'Resource readback task',
            'task_type' => 'task',
            'planned_start_date' => '2026-06-01',
            'planned_end_date' => '2026-06-05',
            'planned_duration_days' => 5,
            'planned_work_hours' => 40,
            'quantity' => 1,
            'progress_percent' => 0,
            'status' => 'not_started',
            'priority' => 'normal',
            'constraint_type' => 'none',
            'level' => 0,
            'sort_order' => 1,
        ]);
        $assignee = User::factory()->create([
            'current_organization_id' => $context->organization->id,
        ]);
        $context->organization->users()->attach($assignee->id, [
            'is_owner' => false,
            'is_active' => true,
            'settings' => null,
        ]);

        $baseUrl = "/api/v1/admin/projects/{$project->id}/schedules/{$schedule->id}/tasks/{$task->id}/resources";
        $userAssignment = $this->withHeaders($context->authHeaders())
            ->postJson($baseUrl, [
                'resource_type' => 'user',
                'user_id' => $assignee->id,
                'allocation_percent' => 60,
                'assignment_start_date' => '2026-06-01',
                'assignment_end_date' => '2026-06-05',
            ])
            ->assertCreated()
            ->json('data');
        $equipmentAssignment = $this->withHeaders($context->authHeaders())
            ->postJson($baseUrl, [
                'resource_type' => 'equipment',
                'equipment_name' => 'Excavator E-12',
                'allocation_percent' => 40,
                'assignment_start_date' => '2026-06-01',
                'assignment_end_date' => '2026-06-05',
            ])
            ->assertCreated()
            ->json('data');

        $this->assertDatabaseHas('task_resources', [
            'id' => $userAssignment['id'],
            'task_id' => $task->id,
            'user_id' => $assignee->id,
        ]);
        $this->assertDatabaseHas('task_resources', [
            'id' => $equipmentAssignment['id'],
            'task_id' => $task->id,
            'equipment_name' => 'Excavator E-12',
        ]);

        $gantt = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/projects/{$project->id}/schedules/{$schedule->id}?format=gantt")
            ->assertOk()
            ->json('data');
        $resources = collect($gantt['tasks'][0]['resources'] ?? []);

        $this->assertSame($assignee->id, $resources->firstWhere('id', $userAssignment['id'])['user']['id'] ?? null);
        $this->assertSame($assignee->name, $resources->firstWhere('id', $userAssignment['id'])['user']['name'] ?? null);
        $this->assertSame('Excavator E-12', $resources->firstWhere('id', $equipmentAssignment['id'])['equipment_name'] ?? null);
    }

    public function test_gantt_does_not_return_another_projects_schedule_resources(): void
    {
        $context = AdminApiTestContext::create();
        $this->activateSchedulePackage($context);
        $projectA = Project::factory()->create([
            'organization_id' => $context->organization->id,
        ]);
        $projectB = Project::factory()->create([
            'organization_id' => $context->organization->id,
        ]);
        $this->assertScheduleEditGranted($context, $projectA);
        $this->assertScheduleEditGranted($context, $projectB);
        $scheduleB = ProjectSchedule::query()->create([
            'project_id' => $projectB->id,
            'organization_id' => $context->organization->id,
            'created_by_user_id' => $context->user->id,
            'name' => 'Foreign project schedule',
            'planned_start_date' => '2026-06-01',
            'planned_end_date' => '2026-06-30',
            'status' => 'active',
            'is_template' => false,
            'critical_path_calculated' => false,
            'overall_progress_percent' => 0,
        ]);
        $task = ScheduleTask::query()->create([
            'schedule_id' => $scheduleB->id,
            'organization_id' => $context->organization->id,
            'created_by_user_id' => $context->user->id,
            'name' => 'Foreign project task',
            'task_type' => 'task',
            'planned_start_date' => '2026-06-01',
            'planned_end_date' => '2026-06-05',
            'planned_duration_days' => 5,
            'planned_work_hours' => 40,
            'quantity' => 1,
            'progress_percent' => 0,
            'status' => 'not_started',
            'priority' => 'normal',
            'constraint_type' => 'none',
            'level' => 0,
            'sort_order' => 1,
        ]);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/projects/{$projectB->id}/schedules/{$scheduleB->id}/tasks/{$task->id}/resources", [
                'resource_type' => 'equipment',
                'equipment_name' => 'Foreign project equipment secret',
                'allocation_percent' => 100,
                'assignment_start_date' => '2026-06-01',
                'assignment_end_date' => '2026-06-05',
            ])
            ->assertCreated();

        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/projects/{$projectA->id}/schedules/{$scheduleB->id}?format=gantt")
            ->assertNotFound()
            ->assertDontSee('Foreign project equipment secret');
    }

    public function test_schedule_created_from_estimate_includes_zero_cost_unsectioned_work(): void
    {
        $context = AdminApiTestContext::create();
        $this->activateSchedulePackage($context);
        $project = Project::factory()->create([
            'organization_id' => $context->organization->id,
        ]);

        $authorization = app(AuthorizationService::class);
        $this->assertTrue($authorization->can($context->user, 'schedule.create', [
            'organization_id' => (int) $context->organization->id,
            'project_id' => (int) $project->id,
        ]));
        $this->assertTrue($authorization->can($context->user, 'schedule.view', [
            'organization_id' => (int) $context->organization->id,
            'project_id' => (int) $project->id,
        ]));
        $this->assertTrue(app(AccessController::class)->hasModuleAccess(
            (int) $context->organization->id,
            'budget-estimates'
        ));

        $estimate = Estimate::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'number' => 'UNSECTIONED-' . random_int(10000, 99999),
            'name' => 'Unsectioned work import',
            'type' => 'local',
            'status' => 'approved',
            'estimate_date' => '2026-06-01',
            'total_direct_costs' => 20,
            'total_overhead_costs' => 0,
            'total_estimated_profit' => 0,
            'total_amount' => 20,
            'total_amount_with_vat' => 20,
        ]);
        $section = EstimateSection::query()->create([
            'estimate_id' => $estimate->id,
            'section_number' => '1',
            'name' => 'Sectioned work',
            'sort_order' => 1,
            'is_summary' => false,
        ]);
        $unit = MeasurementUnit::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Piece',
            'short_name' => 'pc',
            'type' => 'work',
            'is_default' => false,
            'is_system' => false,
        ]);
        $item = EstimateItem::query()->create([
            'estimate_id' => $estimate->id,
            'estimate_section_id' => null,
            'position_number' => '1',
            'item_type' => EstimatePositionItemType::WORK->value,
            'name' => 'QA Mobile daily submit validation',
            'measurement_unit_id' => $unit->id,
            'quantity' => 1,
            'unit_price' => 0,
            'direct_costs' => 0,
            'total_amount' => 0,
            'is_manual' => true,
        ]);
        $sectionedItem = EstimateItem::query()->create([
            'estimate_id' => $estimate->id,
            'estimate_section_id' => $section->id,
            'position_number' => '2',
            'item_type' => EstimatePositionItemType::WORK->value,
            'name' => 'Sectioned imported work',
            'measurement_unit_id' => $unit->id,
            'quantity' => 2,
            'unit_price' => 10,
            'direct_costs' => 20,
            'total_amount' => 20,
            'is_manual' => true,
        ]);

        $createResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/projects/{$project->id}/schedules/from-estimate", [
                'estimate_id' => $estimate->id,
                'options' => ['auto_calculate_dates' => true],
            ])
            ->assertCreated()
            ->assertJsonPath('success', true);
        $scheduleId = $createResponse->json('data.id');
        $this->assertNotNull($scheduleId);

        $task = ScheduleTask::query()
            ->where('schedule_id', $scheduleId)
            ->where('estimate_item_id', $item->id)
            ->firstOrFail();
        $sectionedTask = ScheduleTask::query()
            ->where('schedule_id', $scheduleId)
            ->where('estimate_item_id', $sectionedItem->id)
            ->firstOrFail();
        $sectionTask = ScheduleTask::query()
            ->where('schedule_id', $scheduleId)
            ->where('estimate_section_id', $section->id)
            ->whereNull('estimate_item_id')
            ->firstOrFail();

        $this->assertSame((int) $project->id, (int) $task->schedule->project_id);
        $this->assertSame((int) $context->organization->id, (int) $task->organization_id);
        $this->assertNull($task->estimate_section_id);
        $this->assertNull($task->parent_task_id);
        $this->assertSame(0, (int) $task->level);
        $this->assertSame($createResponse->json('data.planned_start_date'), $sectionedTask->planned_start_date->toDateString());
        $this->assertGreaterThan($sectionTask->planned_end_date->toDateString(), $task->planned_start_date->toDateString());
        $this->assertSame('1.0000', $task->quantity);
        $this->assertSame((int) $unit->id, (int) $task->measurement_unit_id);
        $this->assertSame('0.00', $task->estimated_cost);
        $this->assertSame('0.00', $task->resource_cost);
        $this->assertSame((int) $sectionTask->id, (int) $sectionedTask->parent_task_id);
        $this->assertSame((int) $section->id, (int) $sectionedTask->estimate_section_id);
        $this->assertSame(1, (int) $sectionedTask->level);
        $this->assertSame('2.0000', $sectionedTask->quantity);
        $this->assertSame((int) $unit->id, (int) $sectionedTask->measurement_unit_id);
        $this->assertSame('20.00', $sectionedTask->estimated_cost);

        $gantt = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/projects/{$project->id}/schedules/{$scheduleId}?format=gantt")
            ->assertOk()
            ->json('data');
        $rootTasks = collect($gantt['tasks']);
        $readBack = $rootTasks->firstWhere('id', $task->id);
        $sectionGroupReadBack = $rootTasks->firstWhere('id', $sectionTask->id);

        $this->assertNotNull($readBack);
        $this->assertSame('QA Mobile daily submit validation', $readBack['name']);
        $this->assertNull($readBack['parent_task_id']);
        $this->assertSame(0, $readBack['level']);
        $this->assertSame($task->planned_start_date->toDateString(), $readBack['planned_start_date']);
        $this->assertSame(0.0, (float) $readBack['estimated_cost']);
        $this->assertSame(1.0, (float) $readBack['quantity']);
        $this->assertSame((int) $unit->id, (int) $readBack['measurement_unit_id']);
        $this->assertNotNull($sectionGroupReadBack);
        $sectionedReadBack = collect($sectionGroupReadBack['children'] ?? [])->firstWhere('id', $sectionedTask->id);
        $this->assertNotNull($sectionedReadBack);
        $this->assertSame($sectionTask->id, $sectionedReadBack['parent_task_id']);
        $this->assertSame(1, $sectionedReadBack['level']);
        $this->assertSame(2.0, (float) $sectionedReadBack['quantity']);
        $this->assertSame((int) $unit->id, (int) $sectionedReadBack['measurement_unit_id']);

        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/projects/{$project->id}/schedules/{$scheduleId}/tasks/{$task->id}")
            ->assertOk()
            ->assertJsonPath('data.schedule_id', $scheduleId)
            ->assertJsonPath('data.organization_id', $context->organization->id)
            ->assertJsonPath('data.parent_task_id', null)
            ->assertJsonPath('data.level', 0)
            ->assertJsonPath('data.estimate_item_id', $item->id)
            ->assertJsonPath('data.estimate_section_id', null)
            ->assertJsonPath('data.quantity', 1)
            ->assertJsonPath('data.measurement_unit_id', $unit->id)
            ->assertJsonPath('data.estimated_cost', 0)
            ->assertJsonPath('data.resource_cost', 0)
            ->assertJsonPath('data.measurement_unit.short_name', 'pc');

        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/projects/{$project->id}/schedules/{$scheduleId}/tasks/{$sectionedTask->id}")
            ->assertOk()
            ->assertJsonPath('data.schedule_id', $scheduleId)
            ->assertJsonPath('data.organization_id', $context->organization->id)
            ->assertJsonPath('data.parent_task_id', $sectionTask->id)
            ->assertJsonPath('data.level', 1)
            ->assertJsonPath('data.estimate_item_id', $sectionedItem->id)
            ->assertJsonPath('data.estimate_section_id', $section->id)
            ->assertJsonPath('data.quantity', 2)
            ->assertJsonPath('data.measurement_unit_id', $unit->id)
            ->assertJsonPath('data.estimated_cost', 20)
            ->assertJsonPath('data.resource_cost', 20)
            ->assertJsonPath('data.measurement_unit.short_name', 'pc');
    }

    private function activateSchedulePackage(AdminApiTestContext $context): void
    {
        $catalog = app(PackageCatalogService::class);
        $definitions = $catalog->moduleDefinitions();

        foreach ($catalog->tierModules('working-entry', 'standard') as $moduleSlug) {
            $definition = $definitions[$moduleSlug] ?? null;
            if ($definition === null) {
                continue;
            }

            Module::query()->updateOrCreate(
                ['slug' => $moduleSlug],
                [
                    'name' => $definition['name'],
                    'version' => $definition['version'] ?? '1.0.0',
                    'type' => $definition['type'],
                    'billing_model' => $definition['billing_model'] ?? 'free',
                    'category' => $definition['category'] ?? 'general',
                    'description' => $definition['description'] ?? null,
                    'pricing_config' => $definition['pricing'] ?? null,
                    'features' => $definition['features'] ?? null,
                    'permissions' => $definition['permissions'] ?? [],
                    'dependencies' => $definition['dependencies'] ?? [],
                    'conflicts' => $definition['conflicts'] ?? [],
                    'limits' => $definition['limits'] ?? [],
                    'class_name' => $definition['class_name'] ?? null,
                    'config_file' => $definition['_config_file'] ?? null,
                    'icon' => $definition['icon'] ?? null,
                    'display_order' => $definition['display_order'] ?? 0,
                    'is_active' => true,
                    'is_system_module' => $definition['is_system_module'] ?? false,
                    'can_deactivate' => $definition['can_deactivate'] ?? true,
                ],
            );
        }

        $now = now();
        $account = OrganizationCommercialAccount::query()->create([
            'organization_id' => $context->organization->id,
            'responsible_user_id' => $context->user->id,
            'status' => 'active',
            'offer_type' => 'packages',
            'quote_version' => 1,
            'current_period_start_at' => $now,
            'current_period_end_at' => $now->copy()->addDays(30),
        ]);

        OrganizationPackageSubscription::query()->create([
            'organization_id' => $context->organization->id,
            'commercial_account_id' => $account->id,
            'package_slug' => 'working-entry',
            'status' => 'active',
            'access_source' => 'paid_package',
            'price_paid' => 0,
            'current_period_start_at' => $now,
            'current_period_end_at' => $now->copy()->addDays(30),
        ]);

        $access = app(AccessController::class);
        $access->clearAccessCache((int) $context->organization->id);
        $this->assertTrue($access->hasModuleAccess((int) $context->organization->id, 'schedule-management'));
    }

    private function assertScheduleEditGranted(AdminApiTestContext $context, Project $project): void
    {
        $authorization = app(AuthorizationService::class);
        $organizationId = (int) $context->organization->id;

        $this->assertTrue($authorization->can($context->user, 'schedule.edit', [
            'organization_id' => $organizationId,
            'context_type' => 'organization',
        ]));
        $this->assertTrue($authorization->can($context->user, 'schedule.edit', [
            'organization_id' => $organizationId,
            'project_id' => (int) $project->id,
        ]));
    }
}
