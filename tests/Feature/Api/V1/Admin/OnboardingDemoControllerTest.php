<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\ScheduleManagement\Models\ProjectEvent;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\CompletedWork;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Estimate;
use App\Models\Material;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use App\Models\User;
use App\Models\WorkType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class OnboardingDemoControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_delete_demo_data_removes_only_current_organization_demo_records(): void
    {
        $context = AdminApiTestContext::create();
        $foreignOrganization = Organization::factory()->verified()->create();
        $this->allowAdminAccess();

        $current = $this->createDemoGraph($context->organization, $context->user, true);
        $currentProduction = $this->createDemoGraph($context->organization, $context->user, false);
        $foreign = $this->createDemoGraph($foreignOrganization, $context->user, true);

        $demoContractorOrganization = Organization::factory()->verified()->create([
            'is_onboarding_demo' => true,
        ]);
        $safeDemoContractorOrganization = Organization::factory()->verified()->create([
            'is_onboarding_demo' => true,
        ]);
        $current['project']->organizations()->attach($demoContractorOrganization->id, [
            'role' => 'contractor',
            'role_new' => 'contractor',
            'is_active' => true,
            'invited_at' => now(),
            'accepted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->deleteJson('/api/v1/admin/onboarding/demo-data');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.deleted.projects', 1);
        $response->assertJsonPath('data.deleted.contracts', 1);
        $response->assertJsonPath('data.deleted.contractors', 1);
        $response->assertJsonPath('data.deleted.estimates', 1);
        $response->assertJsonPath('data.deleted.schedules', 1);
        $response->assertJsonPath('data.deleted.schedule_tasks', 1);
        $response->assertJsonPath('data.deleted.events', 1);
        $response->assertJsonPath('data.deleted.materials', 1);
        $response->assertJsonPath('data.deleted.completed_works', 1);
        $response->assertJsonPath('data.total_deleted', 9);

        $this->assertGraphSoftDeleted($current);
        $this->assertGraphNotSoftDeleted($currentProduction);
        $this->assertGraphNotSoftDeleted($foreign);
        $this->assertSoftDeleted('organizations', ['id' => $demoContractorOrganization->id]);
        $this->assertNotSoftDeleted('organizations', ['id' => $safeDemoContractorOrganization->id]);
    }

    private function allowAdminAccess(): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturn(true);
            $mock->shouldReceive('hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['web_admin']);
            $mock->shouldReceive('getUserRoles')->andReturnUsing(
                static function (User $user, ?AuthorizationContext $context = null) {
                    return $user->roleAssignments()
                        ->where('is_active', true)
                        ->when($context !== null, static fn ($query) => $query->where('context_id', $context->id))
                        ->get();
                }
            );
        });
    }

    private function createDemoGraph(Organization $organization, User $user, bool $isDemo): array
    {
        $project = Project::factory()->create([
            'organization_id' => $organization->id,
            'is_onboarding_demo' => $isDemo,
        ]);
        $workType = WorkType::query()->create([
            'organization_id' => $organization->id,
            'name' => ($isDemo ? 'Demo' : 'Production') . ' work type',
        ]);
        $contractor = Contractor::query()->create([
            'organization_id' => $organization->id,
            'name' => ($isDemo ? 'Demo' : 'Production') . ' contractor',
            'inn' => fake()->unique()->numerify('##########'),
        ]);
        $contract = Contract::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'contractor_id' => $contractor->id,
            'number' => fake()->unique()->numerify('DEMO-#####'),
            'date' => now()->toDateString(),
            'subject' => 'Onboarding contract',
            'total_amount' => 1000,
            'status' => 'draft',
            'is_onboarding_demo' => $isDemo,
        ]);
        $estimate = Estimate::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'contract_id' => $contract->id,
            'number' => fake()->unique()->numerify('EST-#####'),
            'name' => 'Onboarding estimate',
            'estimate_date' => now()->toDateString(),
            'is_onboarding_demo' => $isDemo,
        ]);
        $material = Material::query()->create([
            'organization_id' => $organization->id,
            'name' => ($isDemo ? 'Demo' : 'Production') . ' material',
            'code' => fake()->unique()->bothify('MAT-####'),
            'is_onboarding_demo' => $isDemo,
        ]);
        $schedule = ProjectSchedule::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'created_by_user_id' => $user->id,
            'name' => 'Onboarding schedule',
            'planned_start_date' => now()->toDateString(),
            'planned_end_date' => now()->addDays(7)->toDateString(),
            'status' => 'draft',
            'is_onboarding_demo' => $isDemo,
        ]);
        $task = ScheduleTask::query()->create([
            'organization_id' => $organization->id,
            'schedule_id' => $schedule->id,
            'created_by_user_id' => $user->id,
            'name' => 'Onboarding task',
            'task_type' => 'task',
            'planned_start_date' => now()->toDateString(),
            'planned_end_date' => now()->addDay()->toDateString(),
            'planned_duration_days' => 1,
            'status' => 'not_started',
            'priority' => 'normal',
            'is_onboarding_demo' => $isDemo,
        ]);
        $event = ProjectEvent::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'schedule_id' => $schedule->id,
            'related_task_id' => $task->id,
            'created_by_user_id' => $user->id,
            'event_type' => 'meeting',
            'title' => 'Onboarding event',
            'event_date' => now()->toDateString(),
            'priority' => 'normal',
            'status' => 'scheduled',
            'is_onboarding_demo' => $isDemo,
        ]);
        $completedWork = CompletedWork::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'work_type_id' => $workType->id,
            'user_id' => $user->id,
            'contract_id' => $contract->id,
            'schedule_task_id' => $task->id,
            'quantity' => 1,
            'price' => 1000,
            'total_amount' => 1000,
            'completion_date' => now()->toDateString(),
            'status' => 'confirmed',
            'is_onboarding_demo' => $isDemo,
        ]);

        return compact('project', 'contract', 'estimate', 'material', 'schedule', 'task', 'event', 'completedWork');
    }

    private function assertGraphSoftDeleted(array $graph): void
    {
        $this->assertSoftDeleted('completed_works', ['id' => $graph['completedWork']->id]);
        $this->assertSoftDeleted('schedule_tasks', ['id' => $graph['task']->id]);
        $this->assertSoftDeleted('project_events', ['id' => $graph['event']->id]);
        $this->assertSoftDeleted('project_schedules', ['id' => $graph['schedule']->id]);
        $this->assertSoftDeleted('estimates', ['id' => $graph['estimate']->id]);
        $this->assertSoftDeleted('contracts', ['id' => $graph['contract']->id]);
        $this->assertSoftDeleted('materials', ['id' => $graph['material']->id]);
        $this->assertSoftDeleted('projects', ['id' => $graph['project']->id]);
    }

    private function assertGraphNotSoftDeleted(array $graph): void
    {
        $this->assertNotSoftDeleted('completed_works', ['id' => $graph['completedWork']->id]);
        $this->assertNotSoftDeleted('schedule_tasks', ['id' => $graph['task']->id]);
        $this->assertNotSoftDeleted('project_events', ['id' => $graph['event']->id]);
        $this->assertNotSoftDeleted('project_schedules', ['id' => $graph['schedule']->id]);
        $this->assertNotSoftDeleted('estimates', ['id' => $graph['estimate']->id]);
        $this->assertNotSoftDeleted('contracts', ['id' => $graph['contract']->id]);
        $this->assertNotSoftDeleted('materials', ['id' => $graph['material']->id]);
        $this->assertNotSoftDeleted('projects', ['id' => $graph['project']->id]);
    }
}
