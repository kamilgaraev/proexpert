<?php

declare(strict_types=1);

namespace Tests\Feature\DesignManagement;

use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackageSection;
use App\BusinessModules\Features\DesignManagement\Services\DesignCompletenessService;
use App\BusinessModules\Features\DesignManagement\Services\DesignDocumentArtifactService;
use App\BusinessModules\Features\DesignManagement\Services\DesignPackageIssueRegisterService;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use Illuminate\Support\Facades\Cache;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class DesignDocumentPermissionRegressionTest extends TestCase
{
    public function test_specialized_document_permissions_work_without_general_edit_or_view(): void
    {
        $actor = $this->context(['documents.upload', 'documents.edit', 'documents.view']);
        [$package, $section] = $this->package($actor);
        $authorization = app(AuthorizationService::class);
        $this->assertFalse($authorization->can($actor->user, 'design-management.edit', ['organization_id' => $package->organization_id, 'project_id' => $package->project_id]));
        $this->assertFalse($authorization->can($actor->user, 'design-management.view', ['organization_id' => $package->organization_id, 'project_id' => $package->project_id]));
        $this->assertTrue($actor->user->belongsToOrganization((int) $package->organization_id));
        $this->assertTrue(app(\App\Services\Project\UserProjectAccessService::class)->canAccessProject($actor->user, $package->project, (int) $package->organization_id));
        $this->assertTrue($authorization->can($actor->user, 'design-management.documents.upload', ['organization_id' => $package->organization_id, 'project_id' => $package->project_id]));
        Storage::fake('s3');
        $service = app(DesignDocumentArtifactService::class);
        $version = $service->uploadDocument($section, (int) $actor->user->id, UploadedFile::fake()->createWithContent('plan.pdf', '%PDF-1.4 document'), ['document_code' => 'AR-01', 'title' => 'План', 'version_number' => '1']);
        $this->assertSame($version->id, $service->findVersion((int) $package->organization_id, (int) $version->id, $actor->user)->id);
        $updated = $service->replaceSheets($version, (int) $actor->user->id, [['sheet_number' => '1', 'sheet_title' => 'План этажа']]);
        $this->assertSame('План этажа', $updated->sheets->sole()->sheet_title);
        $file = $service->sourceFileStream($version, $actor->user);
        $this->assertIsResource($file['stream']);
        fclose($file['stream']);
        $this->withHeaders($actor->authHeaders())->getJson('/api/v1/admin/design-management/packages/'.$package->id.'/sections')->assertOk();
    }

    public function test_norm_control_runner_does_not_need_review_permission(): void
    {
        $actor = $this->context(['norm_control.run']);
        [$package] = $this->package($actor);
        $this->assertFalse(app(AuthorizationService::class)->can($actor->user, 'design-management.review', ['organization_id' => $package->organization_id, 'project_id' => $package->project_id]));
        $check = app(DesignCompletenessService::class)->run($package, (int) $actor->user->id);
        $this->assertSame($package->id, $check->package_id);
        $this->withHeaders($actor->authHeaders())->postJson('/api/v1/admin/design-management/packages/'.$package->id.'/completeness-checks')->assertCreated();
    }

    public function test_general_viewer_cannot_export_csv_or_call_register_service(): void
    {
        $actor = $this->context(['view']);
        [$package] = $this->package($actor);
        $this->withHeaders($actor->authHeaders())->get('/api/v1/admin/design-management/composition/packages/'.$package->id.'/issue-register/export')->assertForbidden();
        $this->expectException(DomainException::class);
        app(DesignPackageIssueRegisterService::class)->build($package, null, $actor->user);
    }

    public function test_export_permission_is_sufficient_without_generic_view(): void
    {
        $actor = $this->context(['export']);
        [$package] = $this->package($actor);
        $this->withHeaders($actor->authHeaders())->get('/api/v1/admin/design-management/composition/packages/'.$package->id.'/issue-register/export')->assertOk();
    }

    public function test_workflow_check_uses_action_permission_without_manual_norm_control_right(): void
    {
        $actor = $this->context(['edit', 'review', 'approve']);
        [$package, $section] = $this->package($actor);
        $section->update(['required' => false, 'metadata' => ['documents' => []]]);
        $this->assertFalse(app(AuthorizationService::class)->can($actor->user, 'design-management.norm_control.run', ['organization_id' => $package->organization_id, 'project_id' => $package->project_id]));
        $service = app(DesignCompletenessService::class);
        $check = $service->runForWorkflow($package, (int) $actor->user->id, 'submit_norm_control');
        $this->assertSame($package->id, $check->package_id);
        try {
            $service->run($package, (int) $actor->user->id);
            $this->fail('Manual norm control requires its own permission.');
        } catch (DomainException) {
            $this->assertSame(1, $package->completenessChecks()->count());
        }
        foreach (['submit_norm_control', 'submit_customer_review', 'approve', 'issue'] as $index => $action) {
            $metadata = $package->metadata ?? [];
            $metadata['completeness_invalidated_at'] = now()->addSeconds($index + 1)->toISOString();
            $package->update(['metadata' => $metadata]);
            $package = app(\App\BusinessModules\Features\DesignManagement\Services\DesignWorkflowService::class)->transition($package, (int) $actor->user->id, $action);
        }
        $this->assertSame('issued', $package->status->value);
        $this->assertSame(5, $package->completenessChecks()->count());
        $this->expectException(DomainException::class);
        $service->runForWorkflow($package, (int) $actor->user->id, 'submit_norm_control');
    }

    public function test_pto_signed_files_follow_real_document_and_model_permissions(): void
    {
        $this->mock(\App\Services\Storage\FileService::class, static function (MockInterface $mock): void {
            $mock->shouldReceive('temporaryUrl')->andReturn('https://storage.test/signed');
        });
        foreach ([['view'], ['view', 'documents.view'], ['view', 'models.view']] as $permissions) {
            $actor = $this->context($permissions);
            [$package, $section] = $this->package($actor);
            $scope = ['organization_id' => $package->organization_id, 'project_id' => $package->project_id];
            $versions = [];
            foreach (['document', 'standalone', 'bound_ifc'] as $kind) {
                $artifact = \App\BusinessModules\Features\DesignManagement\Models\DesignArtifact::query()->create($scope + [
                    'package_id' => $package->id, 'section_id' => $kind === 'standalone' ? null : $section->id,
                    'artifact_type' => $kind === 'document' ? 'text_document' : 'model',
                    'title' => $kind, 'document_code' => $kind === 'standalone' ? null : $kind,
                    'created_by' => $actor->user->id, 'updated_by' => $actor->user->id,
                ]);
                $format = $kind === 'document' ? 'pdf' : 'ifc';
                $versions[$kind] = \App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion::query()->create($scope + [
                    'artifact_id' => $artifact->id, 'title' => $artifact->title, 'version_number' => '1', 'source_format' => $format, 'file_format' => $format,
                    'source_file_path' => 'org-'.$package->organization_id.'/'.$kind.'.'.$format,
                    'source_original_name' => $kind.'.'.$format, 'source_mime_type' => 'application/octet-stream',
                    'source_size_bytes' => 10, 'is_current' => true, 'status' => 'current',
                ]);
            }
            $data = app(\App\Services\Mobile\MobilePtoService::class)->designPackage($actor->user, (int) $package->organization_id, (int) $package->id);
            $files = collect($data['files'])->keyBy('id');
            foreach ($versions as $kind => $version) {
                $permission = $kind === 'standalone' ? 'models.view' : 'documents.view';
                $allowed = in_array($permission, $permissions, true);
                $this->assertSame($allowed, $files[$version->id]['can_download']);
                $this->assertSame($allowed ? 'https://storage.test/signed' : null, $files[$version->id]['download_url']);
            }
            if (in_array('documents.view', $permissions, true)) {
                $service = app(DesignDocumentArtifactService::class);
                $this->assertSame($versions['bound_ifc']->id, $service->findVersion((int) $package->organization_id, (int) $versions['bound_ifc']->id, $actor->user)->id);
                try {
                    $service->findVersion((int) $package->organization_id, (int) $versions['standalone']->id, $actor->user);
                    $this->fail('A standalone model cannot use the document endpoint.');
                } catch (DomainException) {
                    $this->assertNull($files[$versions['standalone']->id]['download_url']);
                }
            }
        }
    }

    private function context(array $permissions): AdminApiTestContext
    {
        $actor = AdminApiTestContext::create(roleSlug: 'project_manager');
        $actor->organization->users()->updateExistingPivot($actor->user->id, ['project_access_mode' => 'all_projects']);
        $actor->user->roleAssignments()->delete();
        $role = OrganizationCustomRole::query()->create([
            'organization_id' => $actor->organization->id, 'name' => 'Специализированная роль ПИР',
            'slug' => 'pir-document-permissions-'.$actor->user->id, 'system_permissions' => ['admin.access'],
            'module_permissions' => ['design-management' => array_map(static fn (string $permission): string => 'design-management.'.$permission, $permissions)],
            'interface_access' => ['admin'], 'is_active' => true, 'created_by' => $actor->user->id,
        ]);
        UserRoleAssignment::assignRole($actor->user, $role->slug, AuthorizationContext::getOrganizationContext($actor->organization->id), UserRoleAssignment::TYPE_CUSTOM);
        $this->entitlements($actor);
        Cache::store('array')->flush();
        return $actor;
    }

    private function entitlements(AdminApiTestContext $context): void
    {
        \Tests\Support\DesignManagementTestEntitlements::activate($context);
    }

    private function package(AdminApiTestContext $actor): array
    {
        $project = Project::factory()->create(['organization_id' => $actor->organization->id]);
        UserRoleAssignment::assignRole($actor->user, 'pir-document-permissions-'.$actor->user->id, AuthorizationContext::getProjectContext((int) $project->id, (int) $actor->organization->id), UserRoleAssignment::TYPE_CUSTOM);
        $scope = ['organization_id' => $actor->organization->id, 'project_id' => $project->id];
        $package = DesignPackage::query()->create($scope + ['title' => 'Комплект', 'project_stage' => 'pd', 'status' => 'draft', 'created_by' => $actor->user->id, 'updated_by' => $actor->user->id]);
        $section = DesignPackageSection::query()->create($scope + ['package_id' => $package->id, 'code' => 'AR', 'title' => 'АР', 'project_stage' => 'pd', 'required' => true, 'metadata' => ['documents' => [['document_code' => 'AR-01', 'document_title' => 'План', 'required' => true, 'allowed_formats' => ['pdf']]]]]);
        return [$package, $section];
    }
}
