<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use App\Enums\UserProjectAccessMode;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProjectControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_projects_include_active_projects_where_current_organization_is_participant(): void
    {
        $organization = Organization::factory()->create();
        $ownerOrganization = Organization::factory()->create();
        $user = User::factory()->create([
            'current_organization_id' => $organization->id,
        ]);

        $user->organizations()->attach($organization->id, [
            'is_owner' => false,
            'is_active' => true,
            'project_access_mode' => UserProjectAccessMode::ALL_PROJECTS->value,
        ]);

        $project = Project::factory()->create([
            'organization_id' => $ownerOrganization->id,
            'name' => 'Partner organization project',
            'is_archived' => false,
        ]);

        $project->organizations()->attach($organization->id, [
            'role' => 'contractor',
            'role_new' => 'contractor',
            'is_active' => true,
            'invited_at' => now(),
            'accepted_at' => now(),
        ]);

        $this->withoutMiddleware()
            ->actingAs($user, 'api_mobile')
            ->getJson('/api/v1/mobile/projects')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $project->id,
                'name' => $project->name,
            ]);
    }
}
