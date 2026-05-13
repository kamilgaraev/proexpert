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
use App\Models\WorkType;
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

    public function test_optional_basic_report_filters_ignore_empty_query_values(): void
    {
        $context = AdminApiTestContext::create();
        $this->activateReportsModule($context->organization->id);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/reports/contract-payments?' . http_build_query([
                'project_id' => '',
                'contractor_id' => '',
                'status' => '',
                'work_type_category' => '',
                'date_from' => '',
                'date_to' => '',
                'show_overdue' => '',
                'show_with_debt' => '',
                'format' => 'json',
                'page' => 1,
                'per_page' => 15,
            ]));

        $response->assertOk();
        $response->assertJsonPath('success', true);
    }

    public function test_optional_report_filters_ignore_empty_query_values_across_report_endpoints(): void
    {
        $context = AdminApiTestContext::create();
        $this->activateReportsModule($context->organization->id);
        $project = Project::factory()->create([
            'organization_id' => $context->organization->id,
        ]);

        $requests = [
            '/api/v1/admin/reports/contractor-settlements' => [
                'project_id' => '',
                'contractor_id' => '',
                'settlement_status' => '',
                'min_debt_amount' => '',
                'date_from' => '',
                'date_to' => '',
                'format' => 'json',
                'page' => 1,
                'per_page' => 15,
            ],
            '/api/v1/admin/reports/project-profitability' => [
                'project_id' => '',
                'status' => '',
                'customer' => '',
                'date_from' => '',
                'date_to' => '',
                'min_profitability' => '',
                'max_profitability' => '',
                'show_losses_only' => '',
                'include_labor_costs' => '',
                'format' => 'json',
                'page' => 1,
                'per_page' => 15,
            ],
            '/api/v1/admin/reports/project-timelines' => [
                'project_id' => '',
                'status' => '',
                'customer' => '',
                'date_from' => '',
                'date_to' => '',
                'show_overdue_only' => '',
                'show_at_risk' => '',
                'min_delay_days' => '',
                'format' => 'json',
                'page' => 1,
                'per_page' => 15,
            ],
            '/api/v1/admin/reports/warehouse-stock' => [
                'warehouse_id' => '',
                'material_id' => '',
                'category' => '',
                'show_critical_only' => '',
                'show_reserved' => '',
                'show_expired' => '',
                'expiring_days' => '',
                'min_quantity' => '',
                'format' => 'json',
                'page' => 1,
                'per_page' => 15,
            ],
            '/api/v1/admin/reports/official-material-usage' => [
                'project_id' => $project->id,
                'date_from' => '2026-01-01',
                'date_to' => '2026-01-31',
                'report_number' => '',
                'material_id' => '',
                'material_name' => '',
                'operation_type' => '',
                'supplier_id' => '',
                'document_number' => '',
                'work_type_id' => '',
                'work_description' => '',
                'user_id' => '',
                'foreman_id' => '',
                'invoice_date_from' => '',
                'invoice_date_to' => '',
                'min_quantity' => '',
                'max_quantity' => '',
                'min_price' => '',
                'max_price' => '',
                'has_photo' => '',
                'format' => 'json',
            ],
        ];

        foreach ($requests as $endpoint => $query) {
            $response = $this->withHeaders($context->authHeaders())
                ->getJson($endpoint . '?' . http_build_query($query));

            $response->assertOk($endpoint . ' rejected empty optional filters: ' . $response->getContent());
            $response->assertJsonPath('success', true);
        }
    }

    public function test_time_tracking_report_rejects_foreign_user_and_work_type_filters(): void
    {
        $context = AdminApiTestContext::create();
        $this->activateReportsModule($context->organization->id);
        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignUser = User::factory()->create([
            'current_organization_id' => $foreignOrganization->id,
        ]);
        $foreignWorkType = WorkType::query()->create([
            'organization_id' => $foreignOrganization->id,
            'name' => 'Foreign work type',
            'code' => 'FOREIGN-WORK-TYPE-' . $foreignOrganization->id,
            'category' => 'smr',
            'is_active' => true,
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/reports/time-tracking?' . http_build_query([
                'date_from' => '2026-01-01',
                'date_to' => '2026-01-31',
                'user_id' => $foreignUser->id,
                'work_type_id' => $foreignWorkType->id,
            ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['user_id', 'work_type_id']);
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
