<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Models\PersonalFile;
use App\Models\Contractor;
use App\Models\Project;
use App\Models\Module;
use App\Models\OrganizationModuleActivation;
use App\Models\Organization;
use App\Models\ReportFile;
use App\Services\Storage\FileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class ContractorSummaryReportStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_generated_contractor_summary_json_report_is_saved_to_report_storage(): void
    {
        Storage::fake('s3');

        $context = AdminApiTestContext::create();
        $this->activateReportsModule($context->organization->id);
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
            ->where('path', 'like', 'org-' . $context->organization->id . '/reports/%')
            ->where('filename', 'like', 'contractor_summary_report_%.json')
            ->first();

        $this->assertInstanceOf(PersonalFile::class, $file);
        $this->assertGreaterThan(0, $file->size);
        Storage::disk('s3')->assertExists($file->path);

        $reportFile = ReportFile::query()
            ->where('organization_id', $context->organization->id)
            ->where('user_id', $context->user->id)
            ->where('path', $file->path)
            ->where('filename', $file->filename)
            ->first();

        $this->assertInstanceOf(ReportFile::class, $reportFile);

        $indexResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/report-files?sort_by=created_at&sort_dir=desc&per_page=100');

        $indexResponse->assertOk();
        $indexResponse->assertJsonPath('success', true);
        $indexResponse->assertJsonPath('meta.total', 1);
        $indexResponse->assertJsonPath('data.0.id', $reportFile->id);
    }

    public function test_contractor_summary_rejects_foreign_project_before_generation(): void
    {
        Storage::fake('s3');

        $context = AdminApiTestContext::create();
        $this->activateReportsModule($context->organization->id);
        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignProject = Project::factory()->create([
            'organization_id' => $foreignOrganization->id,
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/reports/contractor-summary?' . http_build_query([
                'project_id' => $foreignProject->id,
                'contract_status' => 'active',
            ]));

        $response->assertStatus(422);
        $this->assertSame(0, ReportFile::query()->where('organization_id', $context->organization->id)->count());
    }

    public function test_contractor_summary_rejects_foreign_contractor_filters(): void
    {
        Storage::fake('s3');

        $context = AdminApiTestContext::create();
        $this->activateReportsModule($context->organization->id);
        $project = Project::factory()->create([
            'organization_id' => $context->organization->id,
        ]);
        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignContractor = Contractor::query()->create([
            'organization_id' => $foreignOrganization->id,
            'name' => 'Foreign contractor',
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/reports/contractor-summary?' . http_build_query([
                'project_id' => $project->id,
                'contractor_ids' => [$foreignContractor->id],
                'contract_status' => 'active',
            ]));

        $response->assertStatus(422);
    }

    public function test_contractor_summary_does_not_report_success_when_report_file_storage_fails(): void
    {
        $context = AdminApiTestContext::create();
        $this->activateReportsModule($context->organization->id);
        $project = Project::factory()->create([
            'organization_id' => $context->organization->id,
        ]);

        $storage = Mockery::mock(Filesystem::class);
        $storage->shouldReceive('put')->once()->andThrow(new \RuntimeException('Storage unavailable'));

        $fileService = Mockery::mock(FileService::class);
        $fileService->shouldReceive('disk')->once()->andReturn($storage);

        $this->app->instance(FileService::class, $fileService);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/reports/contractor-summary?' . http_build_query([
                'project_id' => $project->id,
                'contract_status' => 'active',
            ]));

        $response->assertStatus(500);
        $response->assertJsonPath('success', false);

        $this->assertSame(0, PersonalFile::query()->where('user_id', $context->user->id)->count());
        $this->assertSame(0, ReportFile::query()->where('organization_id', $context->organization->id)->count());
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
