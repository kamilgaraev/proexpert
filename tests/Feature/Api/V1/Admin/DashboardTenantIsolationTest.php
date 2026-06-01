<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\ProjectOrganizationRole;
use App\Models\Organization;
use App\Models\Project;
use App\Services\Project\ProjectParticipantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class DashboardTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_analytics_ignore_requested_foreign_organization_id(): void
    {
        $context = AdminApiTestContext::create();
        Project::factory()->create([
            'organization_id' => $context->organization->id,
            'budget_amount' => 1000,
        ]);

        $foreignOrganization = Organization::factory()->verified()->create();
        Project::factory()->count(2)->create([
            'organization_id' => $foreignOrganization->id,
            'budget_amount' => 9000,
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/dashboard/projects-analytics?' . http_build_query([
                'organization_id' => $foreignOrganization->id,
            ]));

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.total', 1);
        $response->assertJsonPath('data.total_budget', 1000);
    }

    public function test_dashboard_summary_rejects_foreign_project_id(): void
    {
        $context = AdminApiTestContext::create();
        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignProject = Project::factory()->create([
            'organization_id' => $foreignOrganization->id,
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/dashboard/summary?' . http_build_query([
                'project_id' => $foreignProject->id,
            ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('project_id');
    }

    public function test_dashboard_summary_accepts_project_participant_project_id(): void
    {
        $ownerContext = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $participantContext = AdminApiTestContext::create(
            organizationAttributes: [
                'capabilities' => ['general_contracting'],
                'primary_business_type' => 'general_contracting',
            ],
            roleSlug: 'organization_owner'
        );
        $project = Project::factory()->create([
            'organization_id' => $ownerContext->organization->id,
        ]);

        app(ProjectParticipantService::class)->attach(
            $project,
            $participantContext->organization->id,
            ProjectOrganizationRole::CONTRACTOR,
            $ownerContext->user
        );

        $response = $this->withHeaders($participantContext->authHeaders())
            ->getJson('/api/v1/admin/dashboard/summary?' . http_build_query([
                'project_id' => $project->id,
            ]));

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.summary.projects.total', 1);
    }

    public function test_dashboard_optional_project_filters_reject_foreign_project_id(): void
    {
        $context = AdminApiTestContext::create();
        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignProject = Project::factory()->create([
            'organization_id' => $foreignOrganization->id,
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/dashboard/financial-metrics?' . http_build_query([
                'project_id' => $foreignProject->id,
            ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('project_id');
    }
}
