<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Authorization\Http\Middleware\AuthorizeMiddleware;
use App\Domain\Authorization\Http\Middleware\InterfaceMiddleware;
use App\Http\Middleware\JwtMiddleware;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProjectTeamMemberTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_select_and_assign_existing_organization_employee_without_foreman_role(): void
    {
        [$organization, $admin, $project] = $this->createProjectContext();

        $employee = User::factory()->create([
            'email' => 'employee-' . Str::uuid() . '@example.test',
            'name' => 'Иван Сотрудник',
            'current_organization_id' => $organization->id,
            'is_active' => true,
        ]);

        $employee->organizations()->attach($organization->id, [
            'is_owner' => false,
            'is_active' => true,
        ]);

        $this->withoutMiddleware($this->adminAccessMiddleware())
            ->actingAs($admin, 'api_admin')
            ->getJson("/api/v1/admin/projects/{$project->id}/team-members?search=Иван")
            ->assertOk()
            ->assertJsonFragment([
                'id' => $employee->id,
                'email' => $employee->email,
            ]);

        $this->withoutMiddleware($this->adminAccessMiddleware())
            ->actingAs($admin, 'api_admin')
            ->postJson("/api/v1/admin/projects/{$project->id}/team-members/{$employee->id}")
            ->assertOk();

        $this->assertDatabaseHas('project_user', [
            'project_id' => $project->id,
            'user_id' => $employee->id,
            'role' => 'member',
            'is_active' => true,
            'assigned_by_user_id' => $admin->id,
        ]);
    }

    public function test_project_team_assignment_rejects_user_from_another_organization(): void
    {
        [, $admin, $project] = $this->createProjectContext();

        $foreignOrganization = Organization::factory()->create();
        $foreignUser = User::factory()->create([
            'email' => 'foreign-' . Str::uuid() . '@example.test',
            'current_organization_id' => $foreignOrganization->id,
            'is_active' => true,
        ]);

        $foreignUser->organizations()->attach($foreignOrganization->id, [
            'is_owner' => false,
            'is_active' => true,
        ]);

        $this->withoutMiddleware($this->adminAccessMiddleware())
            ->actingAs($admin, 'api_admin')
            ->postJson("/api/v1/admin/projects/{$project->id}/team-members/{$foreignUser->id}")
            ->assertNotFound();
    }

    private function createProjectContext(): array
    {
        $organization = Organization::factory()->create();

        $admin = User::factory()->create([
            'email' => 'admin-' . Str::uuid() . '@example.test',
            'current_organization_id' => $organization->id,
            'is_active' => true,
        ]);

        $admin->organizations()->attach($organization->id, [
            'is_owner' => true,
            'is_active' => true,
        ]);

        $project = Project::factory()->create([
            'organization_id' => $organization->id,
            'is_archived' => false,
        ]);

        return [$organization, $admin, $project];
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
