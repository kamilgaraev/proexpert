<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Domain\Authorization\Services\AuthorizationService;
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
