<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\ProjectOrganizationRole;
use App\Models\Organization;
use App\Models\Project;
use App\Services\Project\ProjectParticipantService;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class ProjectCommandCenterControllerTest extends TestCase
{
    public function test_it_requires_a_project_id(): void
    {
        $context = AdminApiTestContext::create();

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/project-command-center');

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('project_id');
    }

    public function test_it_forbids_a_foreign_project(): void
    {
        $context = AdminApiTestContext::create();
        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignProject = Project::factory()->create([
            'organization_id' => $foreignOrganization->id,
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/project-command-center?project_id='.$foreignProject->id);

        $response->assertForbidden();
    }

    public function test_it_returns_the_default_project_period_for_a_participant_project(): void
    {
        $ownerContext = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $participantContext = AdminApiTestContext::create(
            organizationAttributes: [
                'capabilities' => ['general_contracting'],
                'primary_business_type' => 'general_contracting',
            ],
            roleSlug: 'organization_owner',
        );
        $project = Project::factory()->create([
            'organization_id' => $ownerContext->organization->id,
            'name' => 'Проект участника',
        ]);

        app(ProjectParticipantService::class)->attach(
            $project,
            $participantContext->organization->id,
            ProjectOrganizationRole::CONTRACTOR,
            $ownerContext->user,
        );

        $response = $this->withHeaders($participantContext->authHeaders())
            ->getJson('/api/v1/admin/project-command-center?project_id='.$project->id);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.project.id', $project->id);
        $response->assertJsonPath('data.period.preset', 'project');
        $response->assertJsonStructure([
            'data' => [
                'project',
                'period',
                'generated_at',
                'problems',
                'finance',
                'delivery',
                'analytics',
            ],
        ]);
    }
}
