<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class ProjectTeamMemberTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_select_and_assign_existing_organization_employee_without_foreman_role(): void
    {
        [$organization, $admin, $project] = $this->createProjectContext();

        $employee = User::factory()->create([
            'email' => 'employee-'.Str::uuid().'@example.test',
            'name' => 'Иван Сотрудник',
            'current_organization_id' => $organization->id,
            'is_active' => true,
        ]);

        $employee->organizations()->attach($organization->id, [
            'is_owner' => false,
            'is_active' => true,
        ]);

        $this->getJson("/api/v1/admin/projects/{$project->id}/team-members?search=Иван")
            ->assertOk()
            ->assertJsonFragment([
                'id' => $employee->id,
                'email' => $employee->email,
            ]);

        $this->postJson("/api/v1/admin/projects/{$project->id}/team-members/{$employee->id}")
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
        [, , $project] = $this->createProjectContext();

        $foreignOrganization = Organization::factory()->create();
        $foreignUser = User::factory()->create([
            'email' => 'foreign-'.Str::uuid().'@example.test',
            'current_organization_id' => $foreignOrganization->id,
            'is_active' => true,
        ]);

        $foreignUser->organizations()->attach($foreignOrganization->id, [
            'is_owner' => false,
            'is_active' => true,
        ]);

        $this->postJson("/api/v1/admin/projects/{$project->id}/team-members/{$foreignUser->id}")
            ->assertNotFound();
    }

    private function createProjectContext(): array
    {
        $context = AdminApiTestContext::create(userAttributes: ['is_active' => true]);
        $organization = $context->organization;
        $admin = $context->user;
        $this->withHeaders($context->authHeaders());

        $project = Project::factory()->create([
            'organization_id' => $organization->id,
            'is_archived' => false,
        ]);

        return [$organization, $admin, $project];
    }
}
