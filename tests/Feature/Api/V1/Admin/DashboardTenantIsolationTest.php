<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Organization;
use App\Models\Project;
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
}
