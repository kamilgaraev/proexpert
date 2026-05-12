<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Contractor;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModuleActivation;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class FinancialReportsTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_contract_payments_report_rejects_foreign_project_filter(): void
    {
        $context = AdminApiTestContext::create();
        $this->activateReportsModule($context->organization->id);
        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignProject = Project::factory()->create([
            'organization_id' => $foreignOrganization->id,
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/reports/contract-payments?' . http_build_query([
                'project_id' => $foreignProject->id,
            ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('project_id');
    }

    public function test_contract_payments_report_rejects_foreign_contractor_filter(): void
    {
        $context = AdminApiTestContext::create();
        $this->activateReportsModule($context->organization->id);
        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignContractor = Contractor::query()->create([
            'organization_id' => $foreignOrganization->id,
            'name' => 'Foreign contractor',
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/reports/contract-payments?' . http_build_query([
                'contractor_id' => $foreignContractor->id,
            ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('contractor_id');
    }

    public function test_contractor_settlements_report_rejects_foreign_project_filter(): void
    {
        $context = AdminApiTestContext::create();
        $this->activateReportsModule($context->organization->id);
        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignProject = Project::factory()->create([
            'organization_id' => $foreignOrganization->id,
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/reports/contractor-settlements?' . http_build_query([
                'project_id' => $foreignProject->id,
            ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('project_id');
    }

    public function test_contractor_settlements_report_rejects_foreign_contractor_filter(): void
    {
        $context = AdminApiTestContext::create();
        $this->activateReportsModule($context->organization->id);
        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignContractor = Contractor::query()->create([
            'organization_id' => $foreignOrganization->id,
            'name' => 'Foreign contractor',
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/reports/contractor-settlements?' . http_build_query([
                'contractor_id' => $foreignContractor->id,
            ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('contractor_id');
    }

    private function activateReportsModule(int $organizationId): void
    {
        $reportsModule = Module::query()->firstOrCreate(
            ['slug' => 'reports'],
            [
                'name' => 'Reports',
                'version' => '1.0.0',
                'type' => 'core',
                'billing_model' => 'free',
                'category' => 'core',
                'is_active' => true,
                'can_deactivate' => true,
            ]
        );

        OrganizationModuleActivation::query()->create([
            'organization_id' => $organizationId,
            'module_id' => $reportsModule->id,
            'status' => 'active',
            'activated_at' => now(),
        ]);
    }
}
