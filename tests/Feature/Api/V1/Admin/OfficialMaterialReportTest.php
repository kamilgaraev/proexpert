<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use Tests\TestCase;
use App\Models\User;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Material;
use App\Models\Module;
use App\Models\OrganizationModuleActivation;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\Models\PersonalFile;
use App\Models\ReportFile;
use App\Services\Export\ExcelExporterService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AdminApiTestContext;

class OfficialMaterialReportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Organization $organization;
    private Project $project;
    private OrganizationWarehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $context = AdminApiTestContext::create();
        $this->organization = $context->organization;
        $this->user = $context->user;
        $this->withHeaders($context->authHeaders());

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
            'organization_id' => $this->organization->id,
            'module_id' => $reportsModule->id,
            'status' => 'active',
            'activated_at' => now(),
        ]);
        
        $this->project = Project::factory()->create([
            'organization_id' => $this->organization->id
        ]);

        $this->warehouse = OrganizationWarehouse::query()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Основной склад',
            'code' => 'MAIN',
            'warehouse_type' => OrganizationWarehouse::TYPE_CENTRAL,
            'is_main' => true,
            'is_active' => true,
        ]);
    }

    public function test_official_material_report_requires_authentication()
    {
        $this->flushHeaders();

        $response = $this->getJson('/api/v1/admin/reports/official-material-usage');
        
        $response->assertStatus(401);
    }

    public function test_official_material_report_requires_project_id()
    {
        $response = $this
            ->getJson('/api/v1/admin/reports/official-material-usage?' . http_build_query([
                'date_from' => '2024-01-01',
                'date_to' => '2024-01-31'
            ]));
        
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['project_id']);
    }

    public function test_official_material_report_requires_dates()
    {
        $response = $this
            ->getJson('/api/v1/admin/reports/official-material-usage?' . http_build_query([
                'project_id' => $this->project->id
            ]));
        
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['date_from', 'date_to']);
    }

    public function test_official_material_report_basic_functionality()
    {
        $material = $this->createMaterial('Цемент', 'M-001');
        $this->createMovement($material, 'receipt', 150, '2024-01-10', 'П-1');
        $this->createMovement($material, 'write_off', 100, '2024-01-15', 'С-1');

        $response = $this
            ->getJson('/api/v1/admin/reports/official-material-usage?' . http_build_query([
                'project_id' => $this->project->id,
                'date_from' => '2024-01-01',
                'date_to' => '2024-01-31'
            ]));

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'title',
                    'data' => [
                        'header',
                        'materials',
                    ],
                    'filters',
                    'generated_at'
                ],
            ]);

        $this->assertSame('Цемент', $response->json('data.data.materials.0.material_name'));
        $this->assertSame(150.0, (float) $response->json('data.data.materials.0.received_from_customer.volume'));
        $this->assertSame(100.0, (float) $response->json('data.data.materials.0.usage.fact_used'));
        $this->assertSame(50.0, (float) $response->json('data.data.materials.0.usage.balance'));
    }

    public function test_official_material_report_export_updates_existing_report_file_for_same_path(): void
    {
        Storage::fake('s3');
        Carbon::setTestNow(Carbon::parse('2026-05-13 23:15:00'));

        try {
            $this->actingAs($this->user, 'api_admin');

            $reportData = [
                'header' => [
                    'report_number' => '1',
                    'report_date' => '13.05.2026',
                    'project_name' => $this->project->name,
                    'project_address' => $this->project->address,
                ],
                'organizations' => [
                    'contractor' => 'ПроХелпер',
                    'customer' => 'Заказчик',
                    'contractor_director' => 'Директор',
                    'contract_number' => 'Д-1',
                    'contract_date' => '01.05.2026',
                ],
                'materials' => [],
            ];

            $firstUrl = app(ExcelExporterService::class)->uploadOfficialMaterialReport($reportData);
            $secondUrl = app(ExcelExporterService::class)->uploadOfficialMaterialReport($reportData);

            $this->assertNotNull($firstUrl);
            $this->assertNotNull($secondUrl);

            $reportPath = 'org-' . $this->organization->id
                . '/reports/official-material-usage/' . date('Y/m/d/')
                . 'official_material_report_13-05-2026_23-15.xlsx';

            $this->assertSame(1, ReportFile::query()->where('path', $reportPath)->count());
            $this->assertSame(2, PersonalFile::query()->where('user_id', $this->user->id)->count());
            Storage::disk('s3')->assertExists($reportPath);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_official_material_report_with_material_filter()
    {
        $material1 = $this->createMaterial('Material 1', 'M-001');
        $material2 = $this->createMaterial('Material 2', 'M-002');
        $this->createMovement($material1, 'write_off', 10, '2024-01-15');
        $this->createMovement($material2, 'write_off', 20, '2024-01-15');

        $response = $this
            ->getJson('/api/v1/admin/reports/official-material-usage?' . http_build_query([
                'project_id' => $this->project->id,
                'date_from' => '2024-01-01',
                'date_to' => '2024-01-31',
                'material_id' => $material1->id
            ]));

        $response->assertStatus(200);
        
        $data = $response->json();
        $this->assertArrayHasKey('filters', $data['data']);
        $this->assertEquals($material1->id, $data['data']['filters']['material_id']);
        $this->assertCount(1, $data['data']['data']['materials']);
        $this->assertSame('Material 1', $data['data']['data']['materials'][0]['material_name']);
    }

    public function test_official_material_report_with_operation_type_filter()
    {
        $material = $this->createMaterial('Щебень', 'M-003');
        $this->createMovement($material, 'receipt', 50, '2024-01-10');
        $this->createMovement($material, 'write_off', 30, '2024-01-15');

        $response = $this
            ->getJson('/api/v1/admin/reports/official-material-usage?' . http_build_query([
                'project_id' => $this->project->id,
                'date_from' => '2024-01-01',
                'date_to' => '2024-01-31',
                'operation_type' => 'write_off'
            ]));

        $response->assertStatus(200);
        
        $data = $response->json();
        $this->assertEquals('write_off', $data['data']['filters']['operation_type']);
        $this->assertSame(0.0, (float) $data['data']['data']['materials'][0]['received_from_customer']['volume']);
        $this->assertSame(30.0, (float) $data['data']['data']['materials'][0]['usage']['fact_used']);
    }

    public function test_official_material_report_filters_by_document_number()
    {
        $matchedMaterial = $this->createMaterial('Matched material', 'M-004');
        $otherMaterial = $this->createMaterial('Other material', 'M-005');

        $this->createMovement($matchedMaterial, 'receipt', 40, '2024-01-10', 'M-15-001');
        $this->createMovement($matchedMaterial, 'write_off', 15, '2024-01-15', 'M-15-002');
        $this->createMovement($otherMaterial, 'write_off', 20, '2024-01-15', 'M-11-003');

        $response = $this
            ->getJson('/api/v1/admin/reports/official-material-usage?' . http_build_query([
                'project_id' => $this->project->id,
                'date_from' => '2024-01-01',
                'date_to' => '2024-01-31',
                'document_number' => 'M-15',
            ]));

        $response->assertStatus(200);

        $data = $response->json();
        $this->assertSame('M-15', $data['data']['filters']['document_number']);
        $this->assertCount(1, $data['data']['data']['materials']);
        $this->assertSame('Matched material', $data['data']['data']['materials'][0]['material_name']);
        $this->assertSame(40.0, (float) $data['data']['data']['materials'][0]['received_from_customer']['volume']);
        $this->assertSame(15.0, (float) $data['data']['data']['materials'][0]['usage']['fact_used']);
    }

    public function test_official_material_report_rejects_unknown_operation_type()
    {
        $response = $this
            ->getJson('/api/v1/admin/reports/official-material-usage?' . http_build_query([
                'project_id' => $this->project->id,
                'date_from' => '2024-01-01',
                'date_to' => '2024-01-31',
                'operation_type' => 'transfer_out',
            ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['operation_type']);
    }

    public function test_official_material_report_validates_quantity_range()
    {
        $response = $this
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
        $response = $this
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

    private function createMaterial(string $name, string $code): Material
    {
        return Material::query()->create([
            'organization_id' => $this->organization->id,
            'name' => $name,
            'code' => $code,
            'default_price' => 100,
            'is_active' => true,
        ]);
    }

    private function createMovement(
        Material $material,
        string $type,
        float $quantity,
        string $date,
        ?string $documentNumber = null
    ): WarehouseMovement {
        return WarehouseMovement::query()->create([
            'organization_id' => $this->organization->id,
            'warehouse_id' => $this->warehouse->id,
            'material_id' => $material->id,
            'movement_type' => $type,
            'quantity' => $quantity,
            'price' => 100,
            'project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'document_number' => $documentNumber,
            'movement_date' => $date,
        ]);
    }
}
