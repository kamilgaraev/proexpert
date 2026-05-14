<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Authorization\Http\Middleware\AuthorizeMiddleware;
use App\Domain\Authorization\Http\Middleware\InterfaceMiddleware;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\UserProjectAccessMode;
use App\Http\Middleware\JwtMiddleware;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProjectSelectorAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_available_projects_respects_assigned_project_scope(): void
    {
        [, $user, $projectA, $projectB] = $this->createScopedUser(UserProjectAccessMode::ASSIGNED_PROJECTS->value);

        $user->assignedProjects()->attach($projectA->id, [
            'role' => 'member',
            'is_active' => true,
            'assigned_at' => now(),
        ]);

        $this->withoutMiddleware($this->adminAccessMiddleware())
            ->actingAs($user, 'api_admin')
            ->getJson('/api/v1/admin/available-projects')
            ->assertOk()
            ->assertJsonPath('data.projects.0.id', $projectA->id)
            ->assertJsonMissing(['id' => $projectB->id]);
    }

    public function test_available_projects_preserves_all_projects_scope(): void
    {
        [, $user, $projectA, $projectB] = $this->createScopedUser(UserProjectAccessMode::ALL_PROJECTS->value);

        $this->withoutMiddleware($this->adminAccessMiddleware())
            ->actingAs($user, 'api_admin')
            ->getJson('/api/v1/admin/available-projects')
            ->assertOk()
            ->assertJsonFragment(['id' => $projectA->id])
            ->assertJsonFragment(['id' => $projectB->id]);
    }

    private function createScopedUser(string $mode): array
    {
        $organization = Organization::factory()->create();

        $user = User::factory()->create([
            'email' => 'scoped-' . Str::uuid() . '@example.test',
            'current_organization_id' => $organization->id,
            'is_active' => true,
        ]);

        $user->organizations()->attach($organization->id, [
            'is_owner' => false,
            'is_active' => true,
            'project_access_mode' => $mode,
        ]);

        app(AuthorizationService::class)->assignRole(
            $user,
            'organization_admin',
            AuthorizationContext::getOrganizationContext($organization->id)
        );

        $projectA = Project::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Доступный проект',
            'is_archived' => false,
        ]);

        $projectB = Project::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Недоступный проект',
            'is_archived' => false,
        ]);

        return [$organization, $user, $projectA, $projectB];
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
