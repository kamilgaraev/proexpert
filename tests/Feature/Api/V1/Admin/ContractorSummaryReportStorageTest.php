<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Models\PersonalFile;
use App\Models\Project;
use App\Models\Module;
use App\Models\OrganizationModuleActivation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class ContractorSummaryReportStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_generated_contractor_summary_json_report_is_saved_to_report_storage(): void
    {
        Storage::fake('s3');

        $context = AdminApiTestContext::create();
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
            'organization_id' => $context->organization->id,
            'module_id' => $reportsModule->id,
            'status' => 'active',
            'activated_at' => now(),
        ]);
        $project = Project::factory()->create([
            'organization_id' => $context->organization->id,
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/reports/contractor-summary?' . http_build_query([
                'project_id' => $project->id,
                'date_from' => '',
                'date_to' => '',
                'contract_status' => 'active',
                'include_completed_works' => 'true',
                'include_payments' => 'true',
                'include_materials' => 'false',
                'sort_by' => 'total_amount',
                'sort_direction' => 'desc',
                'filter_multi_project' => 'all',
                'show_allocation_details' => 'false',
                'allocation_type_filter' => 'all',
            ]));

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $file = PersonalFile::query()
            ->where('user_id', $context->user->id)
            ->where('path', 'like', $context->user->id . '/reports/%')
            ->where('filename', 'like', 'contractor_summary_report_%.json')
            ->first();

        $this->assertInstanceOf(PersonalFile::class, $file);
        $this->assertGreaterThan(0, $file->size);
        Storage::disk('s3')->assertExists($file->path);

        $indexResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/report-files?sort_by=created_at&sort_dir=desc&per_page=100');

        $indexResponse->assertOk();
        $indexResponse->assertJsonPath('success', true);
        $indexResponse->assertJsonPath('meta.total', 1);
        $indexResponse->assertJsonPath('data.0.id', $file->id);
    }
}
