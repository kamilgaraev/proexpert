<?php

namespace Tests\Feature\Api\V1\Admin;

use Tests\TestCase;
use App\Models\User;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Material;
use App\Models\Supplier;
use App\Models\WorkType;
use App\Models\Models\Log\MaterialUsageLog;
use App\Models\MaterialReceipt;
use Illuminate\Foundation\Testing\RefreshDatabase;

class OfficialMaterialReportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Organization $organization;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->organization = Organization::factory()->create();
        $this->user = User::factory()->create();
        $this->user->organizations()->attach($this->organization->id);
        
        $this->project = Project::factory()->create([
            'organization_id' => $this->organization->id
        ]);
    }

    public function test_official_material_report_requires_authentication()
    {
        $response = $this->getJson('/api/v1/admin/reports/official-material-usage');
        
        $response->assertStatus(401);
    }

    public function test_official_material_report_requires_project_id()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/admin/reports/official-material-usage?' . http_build_query([
                'date_from' => '2024-01-01',
                'date_to' => '2024-01-31'
            ]));
        
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['project_id']);
    }

    public function test_official_material_report_requires_dates()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/admin/reports/official-material-usage?' . http_build_query([
                'project_id' => $this->project->id
            ]));
        
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['date_from', 'date_to']);
    }

    public function test_official_material_report_basic_functionality()
    {
        $material = Material::factory()->create(['organization_id' => $this->organization->id]);
        $supplier = Supplier::factory()->create(['organization_id' => $this->organization->id]);
        $workType = WorkType::factory()->create(['organization_id' => $this->organization->id]);

        MaterialUsageLog::factory()->create([
            'project_id' => $this->project->id,
            'material_id' => $material->id,
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'supplier_id' => $supplier->id,
            'work_type_id' => $workType->id,
            'usage_date' => '2024-01-15',
            'operation_type' => 'write_off',
            'quantity' => 100
        ]);

        MaterialReceipt::factory()->create([
            'project_id' => $this->project->id,
            'material_id' => $material->id,
            'organization_id' => $this->organization->id,
            'supplier_id' => $supplier->id,
            'receipt_date' => '2024-01-10',
            'quantity' => 150
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/admin/reports/official-material-usage?' . http_build_query([
                'project_id' => $this->project->id,
                'date_from' => '2024-01-01',
                'date_to' => '2024-01-31'
            ]));

        $response->assertStatus(200)
            ->assertJsonStructure([
                'title',
                'data' => [
                    'header',
                    'organizations',
                    'materials',
                    'summary'
                ],
                'filters',
                'generated_at'
            ]);
    }

    public function test_official_material_report_with_material_filter()
    {
        $material1 = Material::factory()->create(['organization_id' => $this->organization->id]);
        $material2 = Material::factory()->create(['organization_id' => $this->organization->id]);

        MaterialUsageLog::factory()->create([
            'project_id' => $this->project->id,
            'material_id' => $material1->id,
            'organization_id' => $this->organization->id,
            'usage_date' => '2024-01-15'
        ]);

        MaterialUsageLog::factory()->create([
            'project_id' => $this->project->id,
            'material_id' => $material2->id,
            'organization_id' => $this->organization->id,
            'usage_date' => '2024-01-15'
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/admin/reports/official-material-usage?' . http_build_query([
                'project_id' => $this->project->id,
                'date_from' => '2024-01-01',
                'date_to' => '2024-01-31',
                'material_id' => $material1->id
            ]));

        $response->assertStatus(200);
        
        $data = $response->json();
        $this->assertArrayHasKey('filters', $data);
        $this->assertEquals($material1->id, $data['filters']['material_id']);
    }

    public function test_official_material_report_with_operation_type_filter()
    {
        $material = Material::factory()->create(['organization_id' => $this->organization->id]);

        MaterialUsageLog::factory()->create([
            'project_id' => $this->project->id,
            'material_id' => $material->id,
            'organization_id' => $this->organization->id,
            'operation_type' => 'receipt',
            'usage_date' => '2024-01-15'
        ]);

        MaterialUsageLog::factory()->create([
            'project_id' => $this->project->id,
            'material_id' => $material->id,
            'organization_id' => $this->organization->id,
            'operation_type' => 'write_off',
            'usage_date' => '2024-01-15'
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/admin/reports/official-material-usage?' . http_build_query([
                'project_id' => $this->project->id,
                'date_from' => '2024-01-01',
                'date_to' => '2024-01-31',
                'operation_type' => 'write_off'
            ]));

        $response->assertStatus(200);
        
        $data = $response->json();
        $this->assertEquals('write_off', $data['filters']['operation_type']);
    }

    public function test_official_material_report_validates_quantity_range()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/admin/reports/official-material-usage?' . http_build_query([
                'project_id' => $this->project->id,
                'date_from' => '2024-01-01',
                'date_to' => '2024-01-31',
                'min_quantity' => 100,
                'max_quantity' => 50
            ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['max_quantity']);
    }

    public function test_official_material_report_validates_price_range()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/admin/reports/official-material-usage?' . http_build_query([
                'project_id' => $this->project->id,
                'date_from' => '2024-01-01',
                'date_to' => '2024-01-31',
                'min_price' => 1000,
                'max_price' => 500
            ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['max_price']);
    }
} 