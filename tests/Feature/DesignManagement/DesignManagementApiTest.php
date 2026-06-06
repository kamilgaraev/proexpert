<?php

declare(strict_types=1);

namespace Tests\Feature\DesignManagement;

use App\BusinessModules\Features\DesignManagement\Models\DesignArtifact;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignDocumentSheet;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelDerivative;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackageSection;
use App\BusinessModules\Features\DesignManagement\Models\DesignReviewComment;
use App\BusinessModules\Features\DesignManagement\Models\DesignWorkflowEvent;
use App\BusinessModules\Features\DesignManagement\Jobs\PrepareDesignModelViewerJob;
use App\BusinessModules\Features\DesignManagement\Services\DesignManagementService;
use App\BusinessModules\Features\DesignManagement\Services\Contracts\DesignModelMultipartUploader;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use App\Services\Storage\FileService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Mockery;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class DesignManagementApiTest extends TestCase
{
    public function test_design_management_routes_require_expected_permissions(): void
    {
        $this->assertRoutePermission('GET', 'api/v1/admin/design-management/packages', 'design-management.view');
        $this->assertRoutePermission('POST', 'api/v1/admin/design-management/packages', 'design-management.create');
        $this->assertRoutePermission('GET', 'api/v1/admin/design-management/packages/{packageId}', 'design-management.view');
        $this->assertRoutePermission('POST', 'api/v1/admin/design-management/packages/{packageId}/workflow', 'design-management.view');
        $this->assertRoutePermission('GET', 'api/v1/admin/design-management/normative-sources', 'design-management.normative_catalog.view');
        $this->assertRoutePermission('GET', 'api/v1/admin/design-management/document-templates', 'design-management.normative_catalog.view');
        $this->assertRoutePermission('GET', 'api/v1/admin/design-management/packages/{packageId}/sections', 'design-management.documents.view');
        $this->assertRoutePermission('POST', 'api/v1/admin/design-management/packages/{packageId}/sections/generate', 'design-management.documents.manage_structure');
        $this->assertRoutePermission('POST', 'api/v1/admin/design-management/packages/{packageId}/sections/{sectionId}/documents', 'design-management.documents.upload');
        $this->assertRoutePermission('PUT', 'api/v1/admin/design-management/document-versions/{versionId}/sheets', 'design-management.documents.edit');
        $this->assertRoutePermission('GET', 'api/v1/admin/design-management/document-versions/{versionId}/source-file', 'design-management.documents.view');
        $this->assertRoutePermission('POST', 'api/v1/admin/design-management/packages/{packageId}/completeness-checks', 'design-management.norm_control.run');
        $this->assertRoutePermission('GET', 'api/v1/admin/design-management/packages/{packageId}/review-comments', 'design-management.review');
        $this->assertRoutePermission('POST', 'api/v1/admin/design-management/packages/{packageId}/review-comments', 'design-management.review');
        $this->assertRoutePermission('PATCH', 'api/v1/admin/design-management/review-comments/{commentId}', 'design-management.review');
        $this->assertRoutePermission('GET', 'api/v1/admin/design-management/packages/{packageId}/issue-register', 'design-management.export');
        $this->assertRoutePermission('POST', 'api/v1/admin/design-management/packages/{packageId}/models', 'design-management.models.upload');
        $this->assertRoutePermission('POST', 'api/v1/admin/design-management/packages/{packageId}/models/multipart/start', 'design-management.models.upload');
        $this->assertRoutePermission('POST', 'api/v1/admin/design-management/model-uploads/{uploadId}/parts/{partNumber}', 'design-management.models.upload');
        $this->assertRoutePermission('POST', 'api/v1/admin/design-management/model-uploads/{uploadId}/complete', 'design-management.models.upload');
        $this->assertRoutePermission('DELETE', 'api/v1/admin/design-management/model-uploads/{uploadId}', 'design-management.models.upload');
        $this->assertRoutePermission('POST', 'api/v1/admin/design-management/model-versions/{versionId}/derivatives', 'design-management.models.prepare_viewer');
        $this->assertRoutePermission('POST', 'api/v1/admin/design-management/model-versions/{versionId}/viewer/preparation', 'design-management.models.prepare_viewer');
        $this->assertRoutePermission('GET', 'api/v1/admin/design-management/model-versions/{versionId}/viewer', 'design-management.models.view');
        $this->assertRoutePermission('GET', 'api/v1/admin/design-management/model-versions/{versionId}/source-file', 'design-management.models.view');
        $this->assertRoutePermission('GET', 'api/v1/admin/design-management/model-versions/{versionId}/derivative-file', 'design-management.models.view');
        $this->assertRoutePermission('POST', 'api/v1/admin/design-management/model-versions/{versionId}/mark-current', 'design-management.edit');
    }

    public function test_project_manager_can_create_package(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/design-management/packages', [
                'project_id' => $project->id,
                'title' => 'Раздел АР',
                'stage' => 'rd',
                'discipline' => 'architecture',
                'planned_issue_date' => now()->addDays(10)->toDateString(),
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.project_id', $project->id);
        $response->assertJsonPath('data.title', 'Раздел АР');
        $response->assertJsonPath('data.status', 'draft');
        $response->assertJsonPath('data.status_label', 'Черновик');
        $response->assertJsonPath('data.project_stage', 'rd');
        $response->assertJsonPath('data.normative_profile_code', 'rf_rd_gost_21_101_2026');
        $response->assertJsonPath('data.derivative.status', 'missing');
        $response->assertJsonPath('data.workflow_summary.models_count', 0);
        $response->assertJsonPath('data.workflow_summary.sections_count', 6);
        $this->assertSame([], $response->json('data.problem_flags'));
        $this->assertSame('GD', $response->json('data.sections.0.code'));
    }

    public function test_project_manager_can_upload_ifc_model(): void
    {
        $this->fakeFileStorage();
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $packageId = $this->createPackage($context, $project);

        $response = $this->withHeaders($context->authHeaders())
            ->post("/api/v1/admin/design-management/packages/{$packageId}/models", [
                'title' => 'Архитектурная модель',
                'discipline' => 'architecture',
                'version_number' => '1',
                'revision' => 'R01',
                'model_date' => now()->toDateString(),
                'file' => UploadedFile::fake()->createWithContent(
                    'building.ifc',
                    "ISO-10303-21;\nHEADER;\nENDSEC;\nEND-ISO-10303-21;"
                ),
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.title', 'Архитектурная модель');
        $response->assertJsonPath('data.source_format', 'ifc');
        $response->assertJsonPath('data.version_number', '1');
        $response->assertJsonPath('data.revision', 'R01');
        $response->assertJsonPath('data.is_current', true);

        $version = DesignArtifactVersion::query()->firstOrFail();
        $this->assertStringStartsWith(
            "org-{$context->organization->id}/pir/projects/{$project->id}/packages/{$packageId}/models/",
            $version->source_file_path
        );
        Storage::disk('s3')->assertExists($version->source_file_path);
    }

    public function test_project_manager_can_start_multipart_ifc_upload(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $packageId = $this->createPackage($context, $project);

        $this->mock(DesignModelMultipartUploader::class, function (MockInterface $mock): void {
            $mock->shouldReceive('start')
                ->once()
                ->andReturn([
                    'upload_id' => 'upload-123',
                    'part_size_bytes' => 5_242_880,
                    'parts_count' => 2,
                    'parts' => [
                        ['part_number' => 1, 'method' => 'POST'],
                        ['part_number' => 2, 'method' => 'POST'],
                    ],
                ]);
        });

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/design-management/packages/{$packageId}/models/multipart/start", [
                'title' => 'Архитектурная модель',
                'version_number' => '1',
                'revision' => 'R01',
                'original_name' => 'building.ifc',
                'file_size_bytes' => 12_000_000,
                'content_type' => 'application/octet-stream',
                'make_current' => true,
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.upload_id', 'upload-123');
        $response->assertJsonPath('data.part_size_bytes', 5_242_880);
        $response->assertJsonPath('data.parts.0.method', 'POST');
    }

    public function test_project_manager_can_upload_multipart_ifc_part_through_api(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $this->mock(DesignModelMultipartUploader::class, function (MockInterface $mock): void {
            $mock->shouldReceive('uploadPart')
                ->once()
                ->withArgs(static fn (
                    int $organizationId,
                    int $userId,
                    string $uploadId,
                    int $partNumber,
                    UploadedFile $chunk
                ): bool => $uploadId === 'upload-123'
                    && $partNumber === 1
                    && $chunk->getClientOriginalName() === 'building.ifc.part-1')
                ->andReturn([
                    'upload_id' => 'upload-123',
                    'part_number' => 1,
                    'etag' => '"etag-1"',
                    'size_bytes' => 1024,
                ]);
        });

        $response = $this->withHeaders($context->authHeaders())
            ->post('/api/v1/admin/design-management/model-uploads/upload-123/parts/1', [
                'chunk' => UploadedFile::fake()->createWithContent('building.ifc.part-1', str_repeat('A', 1024)),
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.upload_id', 'upload-123');
        $response->assertJsonPath('data.part_number', 1);
        $response->assertJsonPath('data.size_bytes', 1024);
    }

    public function test_project_manager_can_complete_multipart_ifc_upload(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $packageId = $this->createPackage($context, $project);
        $package = DesignPackage::query()->findOrFail($packageId);
        $version = $this->storedVersion($package, $context->user);

        $this->mock(DesignModelMultipartUploader::class, function (MockInterface $mock) use ($version): void {
            $mock->shouldReceive('complete')
                ->once()
                ->withArgs(static fn (int $organizationId, int $userId, string $uploadId): bool => $uploadId === 'upload-123')
                ->andReturn($version);
        });

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/design-management/model-uploads/upload-123/complete');

        $response->assertCreated();
        $response->assertJsonPath('data.id', $version->id);
        $response->assertJsonPath('data.source_original_name', 'building.ifc');
    }

    public function test_ifc_upload_streams_file_to_storage(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $packageId = $this->createPackage($context, $project);
        $disk = Mockery::mock(Filesystem::class);

        $disk->shouldReceive('put')
            ->once()
            ->with(
                Mockery::type('string'),
                Mockery::on(static fn (mixed $contents): bool => is_resource($contents)),
                'private'
            )
            ->andReturn(true);

        $this->app->forgetInstance(DesignManagementService::class);
        $this->mock(FileService::class, function (MockInterface $mock) use ($disk): void {
            $mock->shouldReceive('disk')->andReturn($disk)->byDefault();
        });
        $this->app->forgetInstance(DesignManagementService::class);

        $response = $this->withHeaders($context->authHeaders())
            ->post("/api/v1/admin/design-management/packages/{$packageId}/models", [
                'title' => 'Архитектурная модель',
                'version_number' => '1',
                'file' => UploadedFile::fake()->createWithContent(
                    'building.ifc',
                    str_repeat("ISO-10303-21;\n", 64)
                ),
            ]);

        $response->assertCreated();
    }

    public function test_viewer_endpoint_returns_source_and_derivative_blocks(): void
    {
        $this->fakeFileStorage();
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $version = $this->uploadModel($context, $project);
        $this->uploadDerivative($context, $version);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/design-management/model-versions/{$version->id}/viewer");

        $response->assertOk();
        $response->assertJsonPath('data.version.id', $version->id);
        $response->assertJsonPath('data.source.mime_type', 'application/octet-stream');
        $response->assertJsonPath('data.derivative.status', 'ready');
        $response->assertJsonPath('data.derivative.viewer_provider', 'thatopen');
        $response->assertJsonPath('data.derivative.derivative_format', 'thatopen_frag');
        $this->assertStringStartsWith('https://files.example.test/', (string) $response->json('data.source.download_url'));
        $this->assertStringStartsWith('https://files.example.test/', (string) $response->json('data.derivative.download_url'));
        $this->assertArrayNotHasKey('path', $response->json('data.source'));
    }

    public function test_viewer_endpoint_marks_old_converter_derivative_as_missing(): void
    {
        $this->fakeFileStorage();
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $version = $this->uploadModel($context, $project);
        $path = "org-{$context->organization->id}/pir/projects/{$project->id}/packages/1/models/{$version->id}/viewer/model.frag";
        Storage::disk('s3')->put($path, 'old fragment binary');

        DesignModelDerivative::query()->create([
            'organization_id' => $version->organization_id,
            'project_id' => $version->project_id,
            'version_id' => $version->id,
            'created_by' => $context->user->id,
            'updated_by' => $context->user->id,
            'prepared_by' => $context->user->id,
            'viewer_provider' => 'thatopen',
            'derivative_format' => 'thatopen_frag',
            'derivative_file_path' => $path,
            'status' => 'ready',
            'progress_percent' => 100,
            'processing_stage' => 'ready',
            'metadata' => ['prepared_on' => 'server'],
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/design-management/model-versions/{$version->id}/viewer");

        $response->assertOk();
        $response->assertJsonPath('data.derivative.status', 'missing');
        $response->assertJsonPath('data.derivative.download_url', null);
        $response->assertJsonPath('data.derivative.processing_stage', 'stale');
        $response->assertJsonPath('data.derivative.metadata.is_stale', true);
        $response->assertJsonPath('data.derivative.metadata.required_converter_version', 4);

        $downloadResponse = $this->withHeaders($context->authHeaders())
            ->get("/api/v1/admin/design-management/model-versions/{$version->id}/derivative-file");

        $downloadResponse->assertStatus(422);
    }

    public function test_derivative_upload_accepts_frag_file(): void
    {
        $this->fakeFileStorage();
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $version = $this->uploadModel($context, $project);

        $response = $this->uploadDerivative($context, $version);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'ready');
        $response->assertJsonPath('data.viewer_provider', 'thatopen');
        $response->assertJsonPath('data.derivative_format', 'thatopen_frag');

        $derivative = DesignModelDerivative::query()->firstOrFail();
        Storage::disk('s3')->assertExists((string) $derivative->derivative_file_path);
    }

    public function test_project_manager_can_move_package_through_rf_documentation_workflow(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $packageId = $this->createPackage($context, $project);
        $package = DesignPackage::query()->findOrFail($packageId);
        $this->completeRequiredDocuments($package, $context->user);

        $submitResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/design-management/packages/{$package->id}/workflow", [
                'action' => 'submit_norm_control',
                'comment' => 'Комплект готов к нормоконтролю',
            ]);

        $submitResponse->assertOk();
        $submitResponse->assertJsonPath('data.status', 'under_norm_control');
        $submitResponse->assertJsonPath('data.workflow_summary.next_action', 'return_to_work');
        $submitResponse->assertJsonPath('data.workflow_summary.available_action_details.0.requires_comment', true);
        $submitResponse->assertJsonPath('data.workflow_events.0.action', 'submit_norm_control');
        $this->assertContains('submit_customer_review', $submitResponse->json('data.available_actions'));

        $returnResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/design-management/packages/{$package->id}/workflow", [
                'action' => 'return_to_work',
                'comment' => 'Нужно уточнить ведомость изменений',
            ]);

        $returnResponse->assertOk();
        $returnResponse->assertJsonPath('data.status', 'returned');
        $returnResponse->assertJsonPath('data.workflow_history.1.action', 'return_to_work');
        $returnResponse->assertJsonPath('data.workflow_history.1.comment', 'Нужно уточнить ведомость изменений');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/design-management/packages/{$package->id}/workflow", [
                'action' => 'submit_norm_control',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'under_norm_control');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/design-management/packages/{$package->id}/workflow", [
                'action' => 'submit_customer_review',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'under_customer_review');

        $approveResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/design-management/packages/{$package->id}/workflow", [
                'action' => 'approve',
            ]);

        $approveResponse->assertOk();
        $approveResponse->assertJsonPath('data.status', 'approved');
        $approveResponse->assertJsonPath('data.workflow_summary.next_action', 'issue');

        $issueResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/design-management/packages/{$package->id}/workflow", [
                'action' => 'issue',
            ]);

        $issueResponse->assertOk();
        $issueResponse->assertJsonPath('data.status', 'issued');
        $issueResponse->assertJsonPath('data.available_actions.0', 'archive');

        $this->assertSame('issued', DesignPackage::query()->findOrFail($package->id)->status->value);
        $this->assertSame(6, DesignWorkflowEvent::query()->where('package_id', $package->id)->count());
    }

    public function test_return_to_work_requires_comment(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $packageId = $this->createPackage($context, $project);
        $package = DesignPackage::query()->findOrFail($packageId);
        $this->completeRequiredDocuments($package, $context->user);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/design-management/packages/{$package->id}/workflow", [
                'action' => 'submit_norm_control',
            ])
            ->assertOk();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/design-management/packages/{$package->id}/workflow", [
                'action' => 'return_to_work',
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', trans_message('design_management.errors.workflow_comment_required'));
        $response->assertJsonValidationErrors(['comment']);
        $this->assertSame('under_norm_control', $package->fresh()->status->value);
    }

    public function test_package_under_norm_control_rejects_model_changes(): void
    {
        $this->fakeFileStorage();
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $version = $this->uploadModel($context, $project);
        $package = $version->artifact->package;
        $this->completeRequiredDocuments($package, $context->user);
        $versionsBefore = DesignArtifactVersion::query()->count();

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/design-management/packages/{$package->id}/workflow", [
                'action' => 'submit_norm_control',
            ])
            ->assertOk();

        $response = $this->withHeaders($context->authHeaders())
            ->post("/api/v1/admin/design-management/packages/{$package->id}/models", [
                'title' => 'Обновленная архитектурная модель',
                'version_number' => '2',
                'file' => UploadedFile::fake()->createWithContent(
                    'building-v2.ifc',
                    "ISO-10303-21;\nHEADER;\nENDSEC;\nEND-ISO-10303-21;"
                ),
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', trans_message('design_management.errors.package_locked_for_model_changes'));
        $this->assertSame($versionsBefore, DesignArtifactVersion::query()->count());
    }

    public function test_package_cannot_enter_norm_control_without_required_documents(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $packageId = $this->createPackage($context, $project);
        $package = DesignPackage::query()->findOrFail($packageId);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/design-management/packages/{$package->id}/workflow", [
                'action' => 'submit_norm_control',
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', trans_message('design_management.errors.completeness_blocked'));
        $this->assertSame('draft', $package->fresh()->status->value);
    }

    public function test_package_workflow_actions_are_idempotent_for_retried_requests(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $packageId = $this->createPackage($context, $project);
        $package = DesignPackage::query()->findOrFail($packageId);
        $this->completeRequiredDocuments($package, $context->user);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/design-management/packages/{$package->id}/workflow", [
                'action' => 'submit_norm_control',
                'comment' => 'ready for norm control',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'under_norm_control');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/design-management/packages/{$package->id}/workflow", [
                'action' => 'submit_norm_control',
                'comment' => 'ready for norm control',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'under_norm_control');

        $history = $package->fresh()->metadata['workflow_history'] ?? [];

        $this->assertCount(1, $history);
        $this->assertSame('submit_norm_control', $history[0]['action']);
        $this->assertSame('draft', $history[0]['from_status']);
        $this->assertSame('under_norm_control', $history[0]['to_status']);
        $this->assertSame(1, DesignWorkflowEvent::query()->where('package_id', $package->id)->count());
    }

    public function test_package_approval_requires_approve_permission(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess(['design-management.approve']);
        $this->allowModuleAccess();
        $packageId = $this->createPackage($context, $project);
        $package = DesignPackage::query()->findOrFail($packageId);
        $this->completeRequiredDocuments($package, $context->user);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/design-management/packages/{$package->id}/workflow", [
                'action' => 'submit_norm_control',
            ])
            ->assertOk();

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/design-management/packages/{$package->id}/workflow", [
                'action' => 'submit_customer_review',
            ])
            ->assertOk();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/design-management/packages/{$package->id}/workflow", [
                'action' => 'approve',
            ]);

        $response->assertForbidden();
        $response->assertJsonPath('message', trans_message('design_management.errors.workflow_action_forbidden'));
        $this->assertSame('under_customer_review', $package->fresh()->status->value);
    }

    public function test_review_comment_rejects_target_from_another_package(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $packageId = $this->createPackage($context, $project);
        $otherPackageId = $this->createPackage($context, $project, ['title' => 'Другой комплект']);
        $foreignSection = DesignPackageSection::query()
            ->where('package_id', $otherPackageId)
            ->firstOrFail();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/design-management/packages/{$packageId}/review-comments", [
                'section_id' => $foreignSection->id,
                'severity' => 'blocking',
                'body' => 'Проверить раздел другого комплекта',
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', trans_message('design_management.errors.review_target_not_found'));
        $this->assertSame(0, DesignReviewComment::query()->where('package_id', $packageId)->count());
    }

    public function test_prepare_viewer_endpoint_queues_server_side_derivative_processing(): void
    {
        Queue::fake();
        $this->fakeFileStorage();
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $version = $this->uploadModel($context, $project);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/design-management/model-versions/{$version->id}/viewer/preparation");

        $response->assertStatus(202);
        $response->assertJsonPath('data.version_id', $version->id);
        $response->assertJsonPath('data.status', 'queued');
        $response->assertJsonPath('data.progress_percent', 0);
        $response->assertJsonPath('data.processing_stage', 'queued');

        $derivative = DesignModelDerivative::query()->firstOrFail();
        $this->assertSame('queued', $derivative->status->value);
        $this->assertSame(0, $derivative->progress_percent);
        $this->assertSame('queued', $derivative->processing_stage);

        Queue::assertPushedOn('ifc-processing', PrepareDesignModelViewerJob::class);
    }

    public function test_prepare_viewer_endpoint_is_idempotent_for_running_processing(): void
    {
        Queue::fake();
        $this->fakeFileStorage();
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $version = $this->uploadModel($context, $project);

        DesignModelDerivative::query()->create([
            'organization_id' => $version->organization_id,
            'project_id' => $version->project_id,
            'version_id' => $version->id,
            'created_by' => $context->user->id,
            'updated_by' => $context->user->id,
            'prepared_by' => $context->user->id,
            'viewer_provider' => 'thatopen',
            'derivative_format' => 'thatopen_frag',
            'status' => 'processing',
            'progress_percent' => 35,
            'processing_stage' => 'converting',
            'metadata' => ['prepared_on' => 'server'],
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/design-management/model-versions/{$version->id}/viewer/preparation");

        $response->assertStatus(202);
        $response->assertJsonPath('data.status', 'processing');
        $response->assertJsonPath('data.progress_percent', 35);
        $response->assertJsonPath('data.processing_stage', 'converting');

        Queue::assertNothingPushed();
    }

    public function test_prepare_viewer_endpoint_requeues_old_ready_derivative(): void
    {
        Queue::fake();
        $this->fakeFileStorage();
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $version = $this->uploadModel($context, $project);

        $derivative = DesignModelDerivative::query()->create([
            'organization_id' => $version->organization_id,
            'project_id' => $version->project_id,
            'version_id' => $version->id,
            'created_by' => $context->user->id,
            'updated_by' => $context->user->id,
            'prepared_by' => $context->user->id,
            'viewer_provider' => 'thatopen',
            'derivative_format' => 'thatopen_frag',
            'derivative_file_path' => 'org-1/pir/old/model.frag',
            'status' => 'ready',
            'progress_percent' => 100,
            'processing_stage' => 'ready',
            'metadata' => ['prepared_on' => 'server'],
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/design-management/model-versions/{$version->id}/viewer/preparation");

        $response->assertStatus(202);
        $response->assertJsonPath('data.status', 'queued');
        $response->assertJsonPath('data.progress_percent', 0);
        $response->assertJsonPath('data.metadata.converter_version', 4);

        $derivative->refresh();
        $this->assertSame('queued', $derivative->status->value);
        $this->assertNull($derivative->derivative_file_path);

        Queue::assertPushedOn('ifc-processing', PrepareDesignModelViewerJob::class);
    }

    public function test_source_file_endpoint_streams_uploaded_ifc_through_api(): void
    {
        $this->fakeFileStorage();
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $version = $this->uploadModel($context, $project);

        $response = $this->withHeaders($context->authHeaders())
            ->get("/api/v1/admin/design-management/model-versions/{$version->id}/source-file");

        $response->assertOk();
        $response->assertDownload('building.ifc');
        $this->assertSame(
            "ISO-10303-21;\nHEADER;\nENDSEC;\nEND-ISO-10303-21;",
            $response->streamedContent()
        );
    }

    public function test_derivative_file_endpoint_streams_ready_frag_through_api(): void
    {
        $this->fakeFileStorage();
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $version = $this->uploadModel($context, $project);
        $this->uploadDerivative($context, $version)->assertCreated();

        $response = $this->withHeaders($context->authHeaders())
            ->get("/api/v1/admin/design-management/model-versions/{$version->id}/derivative-file");

        $response->assertOk();
        $this->assertSame('fragment binary', $response->streamedContent());
    }

    public function test_derivative_upload_rejects_non_frag_file(): void
    {
        $this->fakeFileStorage();
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $version = $this->uploadModel($context, $project);

        $response = $this->withHeaders($context->authHeaders())
            ->post("/api/v1/admin/design-management/model-versions/{$version->id}/derivatives", [
                'file' => UploadedFile::fake()->createWithContent('model.txt', 'not a viewer file'),
                'viewer_provider' => 'thatopen',
                'derivative_format' => 'thatopen_frag',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['file']);
    }

    public function test_user_from_another_organization_cannot_access_package(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $foreignContext = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $packageId = $this->createPackage($context, $project);

        $response = $this->withHeaders($foreignContext->authHeaders())
            ->getJson("/api/v1/admin/design-management/packages/{$packageId}");

        $response->assertForbidden();
    }

    public function test_project_viewer_cannot_upload_model(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_viewer');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $package = DesignPackage::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'created_by' => $context->user->id,
            'updated_by' => $context->user->id,
            'title' => 'Раздел КЖ',
            'status' => 'draft',
            'metadata' => [],
        ]);
        $this->allowAdminAccess(['design-management.models.upload'], ['project_viewer']);
        $this->allowModuleAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->post("/api/v1/admin/design-management/packages/{$package->id}/models", [
                'title' => 'Конструктивная модель',
                'version_number' => '1',
                'file' => UploadedFile::fake()->createWithContent('building.ifc', 'IFC'),
            ]);

        $response->assertForbidden();
    }

    private function createPackage(AdminApiTestContext $context, Project $project): int
    {
        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/design-management/packages', [
                'project_id' => $project->id,
                'title' => 'Раздел АР',
                'stage' => 'rd',
                'discipline' => 'architecture',
            ]);

        $response->assertCreated();

        return (int) $response->json('data.id');
    }

    private function uploadModel(AdminApiTestContext $context, Project $project): DesignArtifactVersion
    {
        $packageId = $this->createPackage($context, $project);

        $response = $this->withHeaders($context->authHeaders())
            ->post("/api/v1/admin/design-management/packages/{$packageId}/models", [
                'title' => 'Архитектурная модель',
                'version_number' => '1',
                'revision' => 'R01',
                'file' => UploadedFile::fake()->createWithContent(
                    'building.ifc',
                    "ISO-10303-21;\nHEADER;\nENDSEC;\nEND-ISO-10303-21;"
                ),
            ]);

        $response->assertCreated();

        return DesignArtifactVersion::query()->latest('id')->firstOrFail();
    }

    private function uploadDerivative(AdminApiTestContext $context, DesignArtifactVersion $version): TestResponse
    {
        return $this->withHeaders($context->authHeaders())
            ->post("/api/v1/admin/design-management/model-versions/{$version->id}/derivatives", [
                'file' => UploadedFile::fake()->createWithContent('model.frag', 'fragment binary'),
                'viewer_provider' => 'thatopen',
                'derivative_format' => 'thatopen_frag',
                'metadata' => [
                    'converted_in_browser' => true,
                ],
            ]);
    }

    private function storedVersion(DesignPackage $package, User $user): DesignArtifactVersion
    {
        $artifact = $package->artifacts()->create([
            'organization_id' => $package->organization_id,
            'project_id' => $package->project_id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'artifact_type' => 'model',
            'title' => 'Архитектурная модель',
            'status' => 'active',
            'metadata' => [],
        ]);

        return $artifact->versions()->create([
            'organization_id' => $package->organization_id,
            'project_id' => $package->project_id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'uploaded_by' => $user->id,
            'title' => 'Архитектурная модель',
            'version_number' => '1',
            'source_format' => 'ifc',
            'source_file_path' => 'org-' . $package->organization_id . '/pir/model-uploads/upload-123/building.ifc',
            'source_original_name' => 'building.ifc',
            'source_mime_type' => 'application/octet-stream',
            'source_size_bytes' => 12_000_000,
            'status' => 'uploaded',
            'is_current' => true,
            'metadata' => [],
        ]);
    }

    private function completeRequiredDocuments(DesignPackage $package, User $user): void
    {
        $package->loadMissing('sections');

        foreach ($package->sections as $section) {
            if (!$section instanceof DesignPackageSection) {
                continue;
            }

            $documents = is_array($section->metadata['documents'] ?? null) ? $section->metadata['documents'] : [];

            foreach ($documents as $document) {
                if (!($document['required'] ?? false)) {
                    continue;
                }

                $format = (string) (($document['allowed_formats'][0] ?? null) ?: 'pdf');
                $documentCode = (string) $document['document_code'];
                $artifact = DesignArtifact::query()->create([
                    'organization_id' => $package->organization_id,
                    'project_id' => $package->project_id,
                    'package_id' => $package->id,
                    'section_id' => $section->id,
                    'created_by' => $user->id,
                    'updated_by' => $user->id,
                    'artifact_type' => (string) $document['artifact_type'],
                    'document_code' => $documentCode,
                    'document_title' => (string) $document['document_title'],
                    'requires_sheet_registry' => (bool) ($document['sheet_registry_required'] ?? false),
                    'title' => (string) $document['document_title'],
                    'discipline' => $section->code,
                    'stage' => $section->project_stage instanceof \BackedEnum ? $section->project_stage->value : $section->project_stage,
                    'status' => 'active',
                    'metadata' => [],
                ]);
                $version = $artifact->versions()->create([
                    'organization_id' => $package->organization_id,
                    'project_id' => $package->project_id,
                    'created_by' => $user->id,
                    'updated_by' => $user->id,
                    'uploaded_by' => $user->id,
                    'title' => (string) $document['document_title'],
                    'version_number' => '1',
                    'revision' => 'R01',
                    'revision_label' => 'R01',
                    'source_format' => $format,
                    'file_format' => $format,
                    'source_file_path' => sprintf('org-%d/pir/projects/%d/packages/%d/test/%s.%s', $package->organization_id, $package->project_id, $package->id, strtolower($documentCode), $format),
                    'source_original_name' => strtolower($documentCode) . '.' . $format,
                    'source_mime_type' => 'application/octet-stream',
                    'source_size_bytes' => 1024,
                    'source_sha256' => str_repeat('a', 64),
                    'page_count' => (bool) ($document['sheet_registry_required'] ?? false) ? 1 : null,
                    'sheet_count' => (bool) ($document['sheet_registry_required'] ?? false) ? 1 : null,
                    'extracted_metadata' => [],
                    'status' => 'current',
                    'is_current' => true,
                    'metadata' => [],
                ]);

                if ((bool) ($document['sheet_registry_required'] ?? false)) {
                    DesignDocumentSheet::query()->create([
                        'organization_id' => $package->organization_id,
                        'project_id' => $package->project_id,
                        'package_id' => $package->id,
                        'section_id' => $section->id,
                        'artifact_id' => $artifact->id,
                        'version_id' => $version->id,
                        'sheet_number' => '1',
                        'sheet_code' => $documentCode . '-1',
                        'sheet_title' => (string) $document['document_title'],
                        'revision' => 'R01',
                        'file_page_number' => 1,
                        'total_sheets' => 1,
                        'status' => 'active',
                        'metadata' => [],
                    ]);
                }
            }
        }
    }

    private function fakeFileStorage(): void
    {
        Storage::fake('s3');
        $disk = Storage::disk('s3');

        $this->app->forgetInstance(DesignManagementService::class);
        $this->mock(FileService::class, function (MockInterface $mock) use ($disk): void {
            $mock->shouldReceive('disk')->andReturn($disk)->byDefault();
            $mock->shouldReceive('temporaryUrl')->andReturnUsing(
                static fn (?string $path, int $minutes = 5, mixed $organization = null): ?string => $path
                    ? 'https://files.example.test/' . ltrim($path, '/')
                    : null
            )->byDefault();
        });
        $this->app->forgetInstance(DesignManagementService::class);
    }

    private function allowModuleAccess(): void
    {
        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')->andReturnUsing(
                static fn (int $organizationId, string $moduleSlug): bool => in_array($moduleSlug, [
                    'design-management',
                    'project-management',
                    'file-management',
                ], true)
            );
        });
    }

    private function allowAdminAccess(array $deniedPermissions = [], array $roleSlugs = ['project_manager']): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock) use ($deniedPermissions, $roleSlugs): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturnUsing(
                static fn (User $user, string $permission, ?array $context = null): bool => !in_array($permission, $deniedPermissions, true)
            );
            $mock->shouldReceive('hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn($roleSlugs);
            $mock->shouldReceive('getUserRoles')->andReturnUsing(
                static function (User $user, ?AuthorizationContext $context = null) {
                    return $user->roleAssignments()
                        ->where('is_active', true)
                        ->when($context !== null, static fn ($query) => $query->where('context_id', $context->id))
                        ->get();
                }
            );
        });
    }

    private function assertRoutePermission(string $method, string $uri, string $permission): void
    {
        $route = $this->findRoute($method, $uri);

        $this->assertNotNull($route, "Маршрут {$method} {$uri} не найден.");
        $this->assertContains("authorize:{$permission}", $route->gatherMiddleware(), "{$method} {$uri}");
    }

    private function findRoute(string $method, string $uri): ?LaravelRoute
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if ($route->uri() === $uri && in_array($method, $route->methods(), true)) {
                return $route;
            }
        }

        return null;
    }
}
