<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeRequest;
use App\BusinessModules\Contractors\Brigades\Support\BrigadeStatuses;
use App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborWorkOrder;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Modules\Core\AccessController;
use App\Services\Mobile\MobileProjectAccessResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MobileProjectRoleTestContext;
use Tests\TestCase;

final class MobileProjectRoleRouteScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_field_admin_uses_project_grants_and_keeps_assignment_denied_without_grant(): void
    {
        $foreman = MobileProjectRoleTestContext::create('foreman');
        $this->withHeaders($foreman->headers())
            ->getJson('/api/v1/mobile/field-admin/team/projects/'.$foreman->project->id.'/participants')
            ->assertOk()
            ->assertJsonPath('data.0.id', $foreman->user->id);

        $worker = MobileProjectRoleTestContext::create('worker');
        $target = \App\Models\User::factory()->create([
            'current_organization_id' => $worker->organization->id,
        ]);
        $this->withHeaders($worker->headers())
            ->putJson('/api/v1/mobile/field-admin/team/projects/'.$worker->project->id.'/participants/'.$target->id)
            ->assertForbidden();
        $this->assertDatabaseMissing('project_user', [
            'project_id' => $worker->project->id,
            'user_id' => $target->id,
        ]);
    }

    public function test_personnel_read_requires_selected_project_or_organization_permission(): void
    {
        $context = MobileProjectRoleTestContext::create('worker');
        $otherProject = Project::factory()->create([
            'organization_id' => $context->organization->id,
        ]);
        $context->user->assignedProjects()->attach($otherProject->id, [
            'is_active' => true,
            'role' => 'member',
            'assigned_by_user_id' => $context->user->id,
            'assigned_at' => now(),
        ]);
        $context->activatePackages(['workforce-output']);

        $this->withHeaders($context->headers())
            ->getJson('/api/v1/mobile/field-admin/personnel/employees?project_id='.$context->project->id)
            ->assertOk();

        $this->withHeaders($context->headers())
            ->getJson('/api/v1/mobile/field-admin/personnel/employees?project_id='.$otherProject->id)
            ->assertForbidden();

        $this->withHeaders($context->headers())
            ->getJson('/api/v1/mobile/field-admin/personnel/employees')
            ->assertForbidden();
    }

    public function test_brigade_request_registry_uses_project_permission_and_filters_ungranted_project(): void
    {
        $foreman = MobileProjectRoleTestContext::create('foreman');
        $foreman->activateAutoModules(['brigades']);
        $request = BrigadeRequest::query()->create([
            'contractor_organization_id' => $foreman->organization->id,
            'project_id' => $foreman->project->id,
            'title' => 'Нужна бригада для испытания',
            'description' => 'Запрос для проверки доступа к реестру проекта.',
            'status' => BrigadeStatuses::REQUEST_OPEN,
        ]);

        $this->withHeaders($foreman->headers())
            ->getJson('/api/v1/mobile/team-expansion/brigade-requests?project_id='.$foreman->project->id)
            ->assertOk()
            ->assertJsonPath('data.0.id', $request->id);

        $worker = MobileProjectRoleTestContext::create('worker');
        $worker->activateAutoModules(['brigades']);

        $this->assertTrue(app(AccessController::class)->hasModuleAccess(
            (int) $worker->organization->id,
            'brigades',
        ));
        $this->assertTrue(app(MobileProjectAccessResolver::class)
            ->query($worker->user, (int) $worker->organization->id)
            ->whereKey($worker->project->id)
            ->exists());
        $this->assertFalse(app(AuthorizationService::class)->can(
            $worker->user,
            'brigades.requests.view',
            [
                'organization_id' => (int) $worker->organization->id,
                'project_id' => (int) $worker->project->id,
                'strict_project_scope' => true,
            ],
        ));

        $this->withHeaders($worker->headers())
            ->getJson('/api/v1/mobile/team-expansion/brigade-requests?project_id='.$worker->project->id)
            ->assertNotFound();
    }

    public function test_time_approvals_return_only_projects_with_the_view_grant(): void
    {
        $context = MobileProjectRoleTestContext::create('foreman');
        $otherProject = Project::factory()->create([
            'organization_id' => $context->organization->id,
        ]);
        $context->user->assignedProjects()->attach($otherProject->id, [
            'is_active' => true,
            'role' => 'member',
            'assigned_by_user_id' => $context->user->id,
            'assigned_at' => now(),
        ]);
        $allowedEntry = $this->submittedEntry($context, $context->project);
        $this->submittedEntry($context, $otherProject);
        $context->activatePackages(['workforce-output']);

        $this->withHeaders($context->headers())
            ->getJson('/api/v1/mobile/time-tracking/pending-approvals?project_id='.$context->project->id)
            ->assertOk()
            ->assertJsonPath('data.0.id', $allowedEntry->id);

        $this->withHeaders($context->headers())
            ->getJson('/api/v1/mobile/time-tracking/pending-approvals?project_id='.$otherProject->id)
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_work_orders_require_project_grant_and_keep_results_inside_selected_project(): void
    {
        $context = MobileProjectRoleTestContext::create('foreman');
        $otherProject = Project::factory()->create([
            'organization_id' => $context->organization->id,
        ]);
        $context->user->assignedProjects()->attach($otherProject->id, [
            'is_active' => true,
            'role' => 'member',
            'assigned_by_user_id' => $context->user->id,
            'assigned_at' => now(),
        ]);
        ProductionLaborWorkOrder::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $context->project->id,
            'created_by_user_id' => $context->user->id,
            'order_number' => 'PROJECT-GRANT-1',
            'title' => 'Разрешённый объект',
            'assignee_type' => 'brigade',
            'status' => 'in_progress',
        ]);
        ProductionLaborWorkOrder::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $otherProject->id,
            'created_by_user_id' => $context->user->id,
            'order_number' => 'PROJECT-GRANT-2',
            'title' => 'Без права',
            'assignee_type' => 'brigade',
            'status' => 'in_progress',
        ]);
        $context->activatePackages(['workforce-output']);

        $this->withHeaders($context->headers())
            ->getJson('/api/v1/mobile/production-labor/work-orders?project_id='.$context->project->id)
            ->assertOk()
            ->assertJsonPath('data.data.0.order_number', 'PROJECT-GRANT-1');

        $this->withHeaders($context->headers())
            ->getJson('/api/v1/mobile/production-labor/work-orders?project_id='.$otherProject->id)
            ->assertForbidden();
    }

    private function submittedEntry(MobileProjectRoleTestContext $context, Project $project): TimeEntry
    {
        return TimeEntry::query()->create([
            'organization_id' => $context->organization->id,
            'user_id' => $context->user->id,
            'worker_type' => 'user',
            'project_id' => $project->id,
            'work_date' => '2026-09-28',
            'hours_worked' => 4,
            'break_time' => 0,
            'title' => 'Проверка проекта',
            'status' => 'submitted',
            'is_billable' => true,
            'custom_fields' => [
                'mobile_time_tracking' => [
                    'active_timer' => false,
                    'corrections' => [],
                ],
            ],
        ]);
    }
}
