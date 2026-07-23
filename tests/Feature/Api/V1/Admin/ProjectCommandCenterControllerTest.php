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

        $foreignResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/project-command-center?project_id='.$foreignProject->id);

        $missingResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/project-command-center?project_id=999999999');

        $foreignResponse->assertNotFound();
        $missingResponse->assertNotFound();
        self::assertSame($missingResponse->json(), $foreignResponse->json());
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

    public function test_it_requires_both_dates_for_a_custom_period(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create([
            'organization_id' => $context->organization->id,
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/project-command-center?project_id='.$project->id.'&period=custom');

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['date_from', 'date_to']);
    }

    public function test_it_rejects_custom_period_dates_in_reverse_order(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create([
            'organization_id' => $context->organization->id,
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/project-command-center?project_id='.$project->id.'&period=custom&date_from=2026-08-01&date_to=2026-07-01');

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('date_to');
    }

    public function test_it_rejects_dates_outside_a_custom_period(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create([
            'organization_id' => $context->organization->id,
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/project-command-center?project_id='.$project->id.'&period=month&date_from=2026-07-01&date_to=2026-07-31');

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['date_from', 'date_to']);
    }

    public function test_it_withholds_finance_for_a_contractor_scope(): void
    {
        $ownerContext = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $contractorContext = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $project = Project::factory()->create([
            'organization_id' => $ownerContext->organization->id,
        ]);

        app(ProjectParticipantService::class)->attach(
            $project,
            $contractorContext->organization->id,
            ProjectOrganizationRole::CONTRACTOR,
            $ownerContext->user,
        );

        $response = $this->withHeaders($contractorContext->authHeaders())
            ->getJson('/api/v1/admin/project-command-center?project_id='.$project->id);

        $response->assertOk();
        $response->assertJsonPath('data.finance.available', false);
        $response->assertJsonMissingPath('data.finance.margin');
        $response->assertJsonMissingPath('data.finance.cash_flow');
        $response->assertJsonMissingPath('data.finance.evm');
    }
}
