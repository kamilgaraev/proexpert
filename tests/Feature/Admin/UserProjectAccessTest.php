<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\UserProjectAccessMode;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Project\UserProjectAccessService;
use App\Domain\Authorization\Http\Middleware\AuthorizeMiddleware;
use App\Domain\Authorization\Http\Middleware\InterfaceMiddleware;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Http\Middleware\JwtMiddleware;
use App\Http\Middleware\SetOrganizationContext;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class UserProjectAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_project_access_modes_and_organization_user_column_exist(): void
    {
        $this->assertSame('all_projects', UserProjectAccessMode::ALL_PROJECTS->value);
        $this->assertSame('assigned_projects', UserProjectAccessMode::ASSIGNED_PROJECTS->value);
        $this->assertTrue(Schema::hasColumn('organization_user', 'project_access_mode'));
    }

    public function test_all_projects_mode_allows_every_project_available_to_current_organization(): void
    {
        [$organization, $user, $projectA, $projectB] = $this->createOrganizationUserWithProjects(
            UserProjectAccessMode::ALL_PROJECTS->value
        );

        $service = app(UserProjectAccessService::class);

        $projects = $service->queryAccessibleProjects($user, $organization->id)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertSame([$projectA->id, $projectB->id], $projects);
        $this->assertTrue($service->canAccessProject($user, $projectA, $organization->id));
        $this->assertTrue($service->canAccessProject($user, $projectB, $organization->id));
    }

    public function test_assigned_projects_mode_allows_only_active_project_user_assignments(): void
    {
        [$organization, $user, $projectA, $projectB] = $this->createOrganizationUserWithProjects(
            UserProjectAccessMode::ASSIGNED_PROJECTS->value
        );

        $user->assignedProjects()->attach($projectA->id, [
            'role' => 'member',
            'is_active' => true,
            'assigned_at' => now(),
        ]);

        $service = app(UserProjectAccessService::class);

        $projects = $service->queryAccessibleProjects($user, $organization->id)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertSame([$projectA->id], $projects);
        $this->assertTrue($service->canAccessProject($user, $projectA, $organization->id));
        $this->assertFalse($service->canAccessProject($user, $projectB, $organization->id));
    }

    public function test_admin_can_update_member_project_access_scope(): void
    {
        [$organization, $admin, $member, $projectA] = $this->createAdminMemberAndProjects();

        $this->withoutMiddleware($this->adminAccessMiddleware())
            ->actingAs($admin, 'api_admin')
            ->putJson("/api/v1/admin/users/{$member->id}/project-access", [
                'project_access_mode' => UserProjectAccessMode::ASSIGNED_PROJECTS->value,
                'project_ids' => [$projectA->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.project_access_mode', UserProjectAccessMode::ASSIGNED_PROJECTS->value)
            ->assertJsonPath('data.project_ids', [$projectA->id]);

        $this->assertDatabaseHas('organization_user', [
            'organization_id' => $organization->id,
            'user_id' => $member->id,
            'project_access_mode' => UserProjectAccessMode::ASSIGNED_PROJECTS->value,
        ]);

        $this->assertDatabaseHas('project_user', [
            'project_id' => $projectA->id,
            'user_id' => $member->id,
            'is_active' => true,
        ]);
    }

    public function test_project_access_update_rejects_projects_outside_current_organization(): void
    {
        [, $admin, $member] = $this->createAdminMemberAndProjects();

        $otherOrganization = Organization::factory()->create();
        $foreignProject = Project::factory()->create([
            'organization_id' => $otherOrganization->id,
            'name' => 'Чужой проект',
            'is_archived' => false,
        ]);

        $this->withoutMiddleware($this->adminAccessMiddleware())
            ->actingAs($admin, 'api_admin')
            ->putJson("/api/v1/admin/users/{$member->id}/project-access", [
                'project_access_mode' => UserProjectAccessMode::ASSIGNED_PROJECTS->value,
                'project_ids' => [$foreignProject->id],
            ])
            ->assertStatus(422);
    }

    public function test_new_ordinary_user_defaults_to_assigned_projects(): void
    {
        [$organization, $admin] = $this->createAdminMemberAndProjects();
        $email = 'ordinary-' . Str::uuid() . '@example.test';

        $response = $this->withoutMiddleware($this->adminAccessMiddleware())
            ->actingAs($admin, 'api_admin')
            ->postJson('/api/v1/admin/users', [
                'name' => 'Новый сотрудник',
                'email' => $email,
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'role_slug' => 'supplier',
            ]);

        $response->assertCreated();

        $userId = $response->json('data.id');

        $this->assertDatabaseHas('organization_user', [
            'organization_id' => $organization->id,
            'user_id' => $userId,
            'project_access_mode' => UserProjectAccessMode::ASSIGNED_PROJECTS->value,
        ]);
    }

    private function createOrganizationUserWithProjects(string $mode): array
    {
        $organization = Organization::factory()->create();

        $user = User::factory()->create([
            'current_organization_id' => $organization->id,
            'is_active' => true,
        ]);

        $user->organizations()->attach($organization->id, [
            'is_owner' => false,
            'is_active' => true,
            'project_access_mode' => $mode,
        ]);

        $projectA = Project::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Проект 1',
            'is_archived' => false,
        ]);

        $projectB = Project::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Проект 2',
            'is_archived' => false,
        ]);

        return [$organization, $user, $projectA, $projectB];
    }

    private function createAdminMemberAndProjects(): array
    {
        [$organization, $member, $projectA, $projectB] = $this->createOrganizationUserWithProjects(
            UserProjectAccessMode::ALL_PROJECTS->value
        );

        $admin = User::factory()->create([
            'email' => 'admin-' . Str::uuid() . '@example.test',
            'current_organization_id' => $organization->id,
            'is_active' => true,
        ]);

        $admin->organizations()->attach($organization->id, [
            'is_owner' => true,
            'is_active' => true,
            'project_access_mode' => UserProjectAccessMode::ALL_PROJECTS->value,
        ]);

        app(AuthorizationService::class)->assignRole(
            $admin,
            'organization_owner',
            AuthorizationContext::getOrganizationContext($organization->id)
        );

        return [$organization, $admin, $member, $projectA, $projectB];
    }

    private function adminAccessMiddleware(): array
    {
        return [
            Authenticate::class,
            JwtMiddleware::class,
            AuthorizeMiddleware::class,
            InterfaceMiddleware::class,
        ];
    }
}
