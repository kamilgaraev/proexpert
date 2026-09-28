<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceScope;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use App\Models\User;
use App\Modules\Core\AccessController;
use App\Services\Auth\JwtTokenIssuer;
use App\Services\Mobile\MobileProjectAccessResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Tests\Support\MobileProjectRoleTestContext;
use Tests\TestCase;

final class MobileHandoverScheduleProjectAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_handover_project_role_is_scoped_to_resource_project_and_required_permission(): void
    {
        $context = MobileProjectRoleTestContext::create('foreman');
        $allowedProject = $context->project;
        $siblingProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $foreignContext = MobileProjectRoleTestContext::create('worker');
        $foreignProject = $foreignContext->project;
        $this->grantProjectAccess($context->user, $allowedProject, $siblingProject);
        $allowedScope = $this->createScope($context->user, (int) $context->organization->id, $allowedProject);
        $siblingScope = $this->createScope($context->user, (int) $context->organization->id, $siblingProject);
        $foreignScope = $this->createScope($foreignContext->user, (int) $foreignContext->organization->id, $foreignProject);
        $context->activatePackages(['working-entry']);
        $this->allowMobileAppGate();
        $this->assertTrue(app(AccessController::class)->hasModuleAccess((int) $context->organization->id, 'handover-acceptance'));
        $this->assertTrue(in_array($allowedProject->id, app(MobileProjectAccessResolver::class)->ids($context->user, (int) $context->organization->id), true));
        $authorization = app(AuthorizationService::class);
        $this->assertTrue($authorization->can($context->user, 'handover-acceptance.view', [
            'organization_id' => $context->organization->id,
            'project_id' => $allowedProject->id,
            'strict_project_scope' => true,
        ]));
        $this->assertTrue($authorization->can($context->user, 'handover-acceptance.inspect', [
            'organization_id' => $context->organization->id,
            'project_id' => $allowedProject->id,
            'strict_project_scope' => true,
        ]));
        $this->assertFalse($authorization->can($context->user, 'handover-acceptance.view', [
            'organization_id' => $context->organization->id,
            'project_id' => $siblingProject->id,
            'strict_project_scope' => true,
        ]));

        $allowedScopeResponse = $this->withHeaders($context->headers())
            ->getJson("/api/v1/mobile/handover-acceptance/scopes/{$allowedScope->id}");
        $this->assertSame(200, $allowedScopeResponse->getStatusCode(), $allowedScopeResponse->getContent());

        $this->withHeaders($context->headers())
            ->postJson("/api/v1/mobile/handover-acceptance/scopes/{$allowedScope->id}/start")
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');

        $this->withHeaders($context->headers())
            ->postJson("/api/v1/mobile/handover-acceptance/scopes/{$siblingScope->id}/start")
            ->assertForbidden();
        $this->assertDatabaseHas('acceptance_scopes', ['id' => $siblingScope->id, 'status' => 'planned']);

        $aggregateResponse = $this->withHeaders($context->headers())
            ->getJson('/api/v1/mobile/handover-acceptance/scopes');
        $this->assertSame(200, $aggregateResponse->getStatusCode(), $aggregateResponse->getContent());
        $aggregateResponse
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $allowedScope->id)
            ->assertJsonPath('data.items.0.project_id', $allowedProject->id);

        $this->withHeaders($context->headers())
            ->getJson("/api/v1/mobile/handover-acceptance/scopes/{$foreignScope->id}")
            ->assertForbidden();

    }

    public function test_handover_aggregate_denies_member_without_project_permission(): void
    {
        $context = MobileProjectRoleTestContext::create('foreman');
        $project = $context->project;
        $this->createScope($context->user, (int) $context->organization->id, $project);
        [$member, $headers] = $this->createMobileInterfaceOnlyUser($context->organization);
        $this->grantProjectAccess($member, $project);
        $context->activatePackages(['working-entry']);
        $this->allowMobileAppGate();

        $this->assertTrue(app(AccessController::class)->hasModuleAccess((int) $context->organization->id, 'handover-acceptance'));
        $this->assertContains($project->id, app(MobileProjectAccessResolver::class)->ids($member, (int) $context->organization->id));
        $this->assertFalse(app(AuthorizationService::class)->can($member, 'handover-acceptance.view', [
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'strict_project_scope' => true,
        ]));

        Auth::forgetGuards();
        $this->withHeaders($headers)
            ->getJson('/api/v1/mobile/handover-acceptance/scopes')
            ->assertForbidden();
    }

    public function test_organization_owner_wildcard_keeps_all_assigned_projects_in_handover_aggregate(): void
    {
        $context = MobileProjectRoleTestContext::create('worker');
        UserRoleAssignment::assignRole(
            $context->user,
            'organization_owner',
            AuthorizationContext::getOrganizationContext((int) $context->organization->id),
        );
        $firstProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $secondProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->grantProjectAccess($context->user, $firstProject, $secondProject);
        $firstScope = $this->createScope($context->user, (int) $context->organization->id, $firstProject);
        $secondScope = $this->createScope($context->user, (int) $context->organization->id, $secondProject);
        $context->activatePackages(['working-entry']);
        $this->allowMobileAppGate();
        $this->assertTrue(app(AccessController::class)->hasModuleAccess((int) $context->organization->id, 'handover-acceptance'));
        $authorization = app(AuthorizationService::class);
        foreach ([$firstProject, $secondProject] as $project) {
            $this->assertTrue($authorization->can($context->user, 'handover-acceptance.view', [
                'organization_id' => $context->organization->id,
                'project_id' => $project->id,
                'strict_project_scope' => true,
            ]));
        }

        $aggregateResponse = $this->withHeaders($context->headers())
            ->getJson('/api/v1/mobile/handover-acceptance/scopes');
        $this->assertSame(200, $aggregateResponse->getStatusCode(), $aggregateResponse->getContent());
        $aggregateResponse
            ->assertJsonCount(2, 'data.items')
            ->assertJsonFragment(['id' => $firstScope->id, 'project_id' => $firstProject->id])
            ->assertJsonFragment(['id' => $secondScope->id, 'project_id' => $secondProject->id]);
    }

    public function test_schedule_project_role_can_view_only_its_project_and_cannot_edit_without_edit_permission(): void
    {
        $context = MobileProjectRoleTestContext::create('foreman');
        $allowedProject = $context->project;
        $siblingProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $foreignContext = MobileProjectRoleTestContext::create('worker');
        $foreignProject = $foreignContext->project;
        $this->grantProjectAccess($context->user, $allowedProject, $siblingProject);
        $allowedSchedule = $this->createSchedule($context->user, (int) $context->organization->id, $allowedProject);
        $siblingSchedule = $this->createSchedule($context->user, (int) $context->organization->id, $siblingProject);
        $foreignSchedule = $this->createSchedule($foreignContext->user, (int) $foreignContext->organization->id, $foreignProject);
        $allowedTask = $this->createTask($context->user, (int) $context->organization->id, $allowedSchedule);
        $siblingTask = $this->createTask($context->user, (int) $context->organization->id, $siblingSchedule);
        $foreignTask = $this->createTask($foreignContext->user, (int) $foreignContext->organization->id, $foreignSchedule);
        $context->activatePackages(['working-entry']);
        $this->allowMobileAppGate();
        $this->assertTrue(app(AccessController::class)->hasModuleAccess((int) $context->organization->id, 'schedule-management'));
        $this->assertTrue(in_array($allowedProject->id, app(MobileProjectAccessResolver::class)->ids($context->user, (int) $context->organization->id), true));
        $authorization = app(AuthorizationService::class);
        $this->assertTrue($authorization->can($context->user, 'schedule.view', [
            'organization_id' => $context->organization->id,
            'project_id' => $allowedProject->id,
            'strict_project_scope' => true,
        ]));
        $this->assertFalse($authorization->can($context->user, 'schedule.view', [
            'organization_id' => $context->organization->id,
            'project_id' => $siblingProject->id,
            'strict_project_scope' => true,
        ]));
        $this->assertFalse($authorization->can($context->user, 'schedule.edit', [
            'organization_id' => $context->organization->id,
            'project_id' => $allowedProject->id,
            'strict_project_scope' => true,
        ]));

        $this->withHeaders($context->headers())
            ->getJson("/api/v1/mobile/schedule/{$allowedSchedule->id}")
            ->assertOk();

        $this->withHeaders($context->headers())
            ->getJson("/api/v1/mobile/schedule/tasks/{$allowedTask->id}")
            ->assertOk();

        $this->withHeaders($context->headers())
            ->getJson("/api/v1/mobile/schedule/{$siblingSchedule->id}")
            ->assertForbidden();

        $this->withHeaders($context->headers())
            ->getJson("/api/v1/mobile/schedule/tasks/{$siblingTask->id}")
            ->assertForbidden();

        $this->withHeaders($context->headers())
            ->getJson("/api/v1/mobile/schedule/{$foreignSchedule->id}")
            ->assertForbidden();

        $this->withHeaders($context->headers())
            ->getJson("/api/v1/mobile/schedule/tasks/{$foreignTask->id}")
            ->assertForbidden();

        $this->withHeaders($context->headers())
            ->postJson("/api/v1/mobile/schedule/{$allowedSchedule->id}/tasks", [])
            ->assertForbidden();
        $this->assertDatabaseCount('schedule_tasks', 3);

    }

    public function test_schedule_denies_member_without_project_permission(): void
    {
        $context = MobileProjectRoleTestContext::create('foreman');
        $project = $context->project;
        $this->createSchedule($context->user, (int) $context->organization->id, $project);
        [$member, $headers] = $this->createMobileInterfaceOnlyUser($context->organization);
        $this->grantProjectAccess($member, $project);
        $context->activatePackages(['working-entry']);
        $this->allowMobileAppGate();

        $this->assertTrue(app(AccessController::class)->hasModuleAccess((int) $context->organization->id, 'schedule-management'));
        $this->assertContains($project->id, app(MobileProjectAccessResolver::class)->ids($member, (int) $context->organization->id));
        $this->assertFalse(app(AuthorizationService::class)->can($member, 'schedule.view', [
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'strict_project_scope' => true,
        ]));

        Auth::forgetGuards();
        $this->withHeaders($headers)
            ->getJson('/api/v1/mobile/schedule?project_id='.$project->id)
            ->assertForbidden();
    }

    private function grantProjectAccess(User $user, Project ...$projects): void
    {
        foreach ($projects as $project) {
            $user->assignedProjects()->syncWithoutDetaching([
                $project->id => [
                    'is_active' => true,
                    'role' => 'member',
                    'assigned_by_user_id' => $user->id,
                    'assigned_at' => now(),
                ],
            ]);
        }
    }

    private function createScope(User $user, int $organizationId, Project $project): AcceptanceScope
    {
        return AcceptanceScope::query()->create([
            'organization_id' => $organizationId,
            'project_id' => $project->id,
            'created_by_user_id' => $user->id,
            'title' => 'Project permission scope',
            'status' => 'planned',
        ]);
    }

    private function createSchedule(User $user, int $organizationId, Project $project): ProjectSchedule
    {
        return ProjectSchedule::query()->create([
            'organization_id' => $organizationId,
            'project_id' => $project->id,
            'created_by_user_id' => $user->id,
            'name' => 'Project permission schedule',
            'planned_start_date' => now()->toDateString(),
            'planned_end_date' => now()->addDays(7)->toDateString(),
            'status' => 'active',
        ]);
    }

    private function createTask(User $user, int $organizationId, ProjectSchedule $schedule): ScheduleTask
    {
        return ScheduleTask::query()->create([
            'organization_id' => $organizationId,
            'schedule_id' => $schedule->id,
            'created_by_user_id' => $user->id,
            'name' => 'Project permission task',
            'planned_start_date' => now()->toDateString(),
            'planned_end_date' => now()->addDay()->toDateString(),
            'planned_duration_days' => 1,
        ]);
    }

    private function allowMobileAppGate(): void
    {
        Gate::define('access-mobile-app', static fn (): bool => true);
    }

    /** @return array{User, array<string, string>} */
    private function createMobileInterfaceOnlyUser(Organization $organization): array
    {
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($user->id, [
            'is_owner' => false,
            'is_active' => true,
            'settings' => null,
        ]);
        $mobileRole = OrganizationCustomRole::query()
            ->where('organization_id', $organization->id)
            ->where('slug', 'mobile_test_access')
            ->firstOrFail();
        UserRoleAssignment::assignRole(
            $user,
            $mobileRole->slug,
            AuthorizationContext::getOrganizationContext((int) $organization->id),
            UserRoleAssignment::TYPE_CUSTOM,
        );
        $token = app(JwtTokenIssuer::class)->issue($user, [
            'guard' => 'api_mobile',
            'organization_id' => $organization->id,
        ]);

        return [$user, ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json']];
    }
}
