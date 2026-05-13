<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\Models\Material;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModuleActivation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class ReportRequestTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_profitability_report_rejects_foreign_project_filter(): void
    {
        $context = AdminApiTestContext::create();
        $this->activateReportsModule($context->organization->id);
        $foreignProject = $this->createForeignProject();

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/reports/project-profitability?' . http_build_query([
                'project_id' => $foreignProject->id,
            ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('project_id');
    }

    public function test_project_timelines_report_rejects_foreign_project_filter(): void
    {
        $context = AdminApiTestContext::create();
        $this->activateReportsModule($context->organization->id);
        $foreignProject = $this->createForeignProject();

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/reports/project-timelines?' . http_build_query([
                'project_id' => $foreignProject->id,
            ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('project_id');
    }

    public function test_material_movements_report_rejects_foreign_filter_entities(): void
    {
        $context = AdminApiTestContext::create();
        $this->activateReportsModule($context->organization->id);
        $foreignProject = $this->createForeignProject();
        $foreignMaterial = $this->createForeignMaterial();
        $foreignWarehouse = $this->createForeignWarehouse();

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/reports/material-movements?' . http_build_query([
                'date_from' => '2026-01-01',
                'date_to' => '2026-01-31',
                'project_id' => $foreignProject->id,
                'material_id' => $foreignMaterial->id,
                'warehouse_id' => $foreignWarehouse->id,
            ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['project_id', 'material_id', 'warehouse_id']);
    }

    public function test_material_movements_report_rejects_foreign_user_filter(): void
    {
        $context = AdminApiTestContext::create();
        $this->activateReportsModule($context->organization->id);
        $foreignUser = User::factory()->create();

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/reports/material-movements?' . http_build_query([
                'date_from' => '2026-01-01',
                'date_to' => '2026-01-31',
                'user_id' => $foreignUser->id,
            ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('user_id');
    }

    public function test_warehouse_stock_report_rejects_foreign_filter_entities(): void
    {
        $context = AdminApiTestContext::create();
        $this->activateReportsModule($context->organization->id);
        $foreignMaterial = $this->createForeignMaterial();
        $foreignWarehouse = $this->createForeignWarehouse();

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/reports/warehouse-stock?' . http_build_query([
                'material_id' => $foreignMaterial->id,
                'warehouse_id' => $foreignWarehouse->id,
            ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['material_id', 'warehouse_id']);
    }

    private function createForeignProject(): Project
    {
        $foreignOrganization = Organization::factory()->verified()->create();

        return Project::factory()->create([
            'organization_id' => $foreignOrganization->id,
        ]);
    }

    private function createForeignMaterial(): Material
    {
        $foreignOrganization = Organization::factory()->verified()->create();

        return Material::query()->create([
            'organization_id' => $foreignOrganization->id,
            'name' => 'Foreign material',
            'code' => 'FOREIGN-MATERIAL-' . $foreignOrganization->id,
            'is_active' => true,
        ]);
    }

    private function createForeignWarehouse(): OrganizationWarehouse
    {
        $foreignOrganization = Organization::factory()->verified()->create();

        return OrganizationWarehouse::query()->create([
            'organization_id' => $foreignOrganization->id,
            'name' => 'Foreign warehouse',
            'code' => 'FOREIGN-WAREHOUSE-' . $foreignOrganization->id,
            'warehouse_type' => OrganizationWarehouse::TYPE_CENTRAL,
            'is_main' => true,
            'is_active' => true,
        ]);
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
