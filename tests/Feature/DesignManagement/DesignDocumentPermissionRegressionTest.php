<?php

declare(strict_types=1);

namespace Tests\Feature\DesignManagement;

use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackageSection;
use App\BusinessModules\Features\DesignManagement\Models\DesignWorkflowEvent;
use App\BusinessModules\Features\DesignManagement\Services\DesignCompletenessService;
use App\BusinessModules\Features\DesignManagement\Services\DesignDocumentArtifactService;
use App\BusinessModules\Features\DesignManagement\Services\DesignPackageIssueRegisterService;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Services\Storage\FileService;
use GuzzleHttp\Psr7\Request;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Cache;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class DesignDocumentPermissionRegressionTest extends TestCase
{
    public static function streamOwnership(): array
    {
        return ['storage closes stream' => [true], 'caller closes stream' => [false]];
    }

    #[DataProvider('streamOwnership')]
    public function test_upload_preserves_document_when_storage_may_close_stream(bool $storageCloses): void
    {
        $actor = $this->context(['documents.upload']);
        [$package, $section] = $this->package($actor);
        $storage = Storage::fake('s3');
        $received = null;
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andReturnUsing(
            static function (string $path, mixed $stream, string $visibility) use ($storage, $storageCloses, &$received): bool {
                $received = $stream;
                self::assertIsResource($stream);
                self::assertSame('private', $visibility);
                $stored = $storage->put($path, $stream, $visibility);
                if ($storageCloses) {
                    $request = new Request('PUT', 'https://storage.example.invalid/document', [], $stream);
                    unset($request);
                    self::assertFalse(is_resource($stream));
                }

                return $stored;
            },
        );
        $this->mock(FileService::class, static function (MockInterface $mock) use ($disk): void {
            $mock->shouldReceive('disk')->once()->andReturn($disk);
        });

        $version = app(DesignDocumentArtifactService::class)->uploadDocument(
            $section,
            (int) $actor->user->id,
            UploadedFile::fake()->createWithContent('plan.pdf', '%PDF-1.4 document'),
            ['document_code' => 'AR-01', 'title' => 'План', 'version_number' => '1'],
        );

        $this->assertFalse(is_resource($received));
        $this->assertTrue($version->is_current);
        $this->assertSame((int) $package->organization_id, (int) $version->organization_id);
        $this->assertSame((int) $package->project_id, (int) $version->project_id);
        $this->assertSame('%PDF-1.4 document', $storage->get($version->source_file_path));
        $this->assertSame(1, $section->artifacts()->count());
        $this->assertSame(1, $version->artifact->versions()->count());
    }

    #[DataProvider('streamOwnership')]
    public function test_storage_exception_is_not_masked_by_document_stream_cleanup(bool $storageCloses): void
    {
        $actor = $this->context(['documents.upload']);
        [, $section] = $this->package($actor);
        $received = null;
        $failure = new RuntimeException('storage unavailable');
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andReturnUsing(
            static function (string $path, mixed $stream) use ($storageCloses, $failure, &$received): never {
                $received = $stream;
                if ($storageCloses) {
                    $request = new Request('PUT', 'https://storage.example.invalid/document', [], $stream);
                    unset($request);
                }
                throw $failure;
            },
        );
        $this->mock(FileService::class, static function (MockInterface $mock) use ($disk): void {
            $mock->shouldReceive('disk')->once()->andReturn($disk);
        });

        try {
            app(DesignDocumentArtifactService::class)->uploadDocument(
                $section,
                (int) $actor->user->id,
                UploadedFile::fake()->createWithContent('plan.pdf', '%PDF-1.4 document'),
                ['document_code' => 'AR-01', 'title' => 'План', 'version_number' => '1'],
            );
            $this->fail('Storage failure must propagate');
        } catch (\Throwable $exception) {
            $this->assertSame($failure, $exception);
            $this->assertFalse(is_resource($received));
            $this->assertSame(0, $section->artifacts()->count());
        }
    }

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

    public function test_csv_versions_preserve_legacy_columns_and_selected_snapshot(): void
    {
        $actor = $this->context(['export']);
        [$package] = $this->package($actor);
        $snapshot = ['package' => ['title' => '=Комплект'], 'sections' => [[
            'code' => 'AR', 'documents' => [[
                'document_code' => 'AR-01', 'title' => "План; \"учебный\"\nЛист", 'current_version_id' => 987,
                'version_number' => '+03', 'revision' => 'УЧ-01', 'source_original_name' => 'plan.pdf',
                'source_sha256' => str_repeat('a', 64), 'sheets_count' => 1,
            ]],
        ]]];
        $event = $this->releaseSnapshot($actor, $package, $snapshot);
        $newer = $snapshot;
        $newer['sections'][0]['documents'][0]['version_number'] = '4';
        $this->releaseSnapshot($actor, $package, $newer);
        $url = '/api/v1/admin/design-management/composition/packages/'.$package->id.'/issue-register/export?workflow_event_id='.$event->id;
        $legacyHeader = ['Комплект', 'Выдача', 'Дата', 'Раздел', 'Документ', 'Название', 'Версия', 'Ревизия', 'Файл', 'SHA256', 'Листов'];
        $date = $event->created_at->toISOString();
        $legacyRow = ["'=Комплект", (string) $event->id, $date, 'AR', 'AR-01', "План; \"учебный\"\nЛист", '987', 'УЧ-01', 'plan.pdf', str_repeat('a', 64), '1'];
        foreach (['' => [$legacyHeader, $legacyRow], '&format_version=1' => [$legacyHeader, $legacyRow], '&format_version=2' => [
            ['Комплект', 'Выдача', 'Дата', 'Раздел', 'Документ', 'Название', 'Номер версии', 'ID версии', 'Ревизия', 'Файл', 'SHA256', 'Листов'],
            ["'=Комплект", (string) $event->id, $date, 'AR', 'AR-01', "План; \"учебный\"\nЛист", "'+03", '987', 'УЧ-01', 'plan.pdf', str_repeat('a', 64), '1'],
        ]] as $query => $expected) {
            $response = $this->withHeaders($actor->authHeaders())->get($url.$query);
            $response->assertOk()->assertDownload('design-register-'.$package->id.'.csv');
            $this->assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
            $body = $response->streamedContent();
            $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
            $this->assertSame($expected, $this->csvRows($body));
        }
    }

    public function test_csv_version_two_keeps_missing_historical_number_empty(): void
    {
        $actor = $this->context(['export']);
        [$package] = $this->package($actor);
        $snapshot = ['package' => ['title' => 'Исторический комплект'], 'sections' => [[
            'code' => 'AR', 'documents' => [[
                'document_code' => 'AR-01', 'title' => 'Исторический план', 'current_version_id' => 987,
                'revision' => '1', 'source_original_name' => 'old.pdf', 'sheets_count' => 0,
            ]],
        ]]];
        $event = $this->releaseSnapshot($actor, $package, $snapshot);
        $storedSnapshot = $event->fresh()->metadata['release_snapshot'];
        $response = $this->withHeaders($actor->authHeaders())->get('/api/v1/admin/design-management/composition/packages/'.$package->id.'/issue-register/export?format_version=2&workflow_event_id='.$event->id);
        $response->assertOk();
        $rows = $this->csvRows($response->streamedContent());
        $this->assertCount(12, $rows[0]);
        $this->assertSame('', $rows[1][6]);
        $this->assertSame('987', $rows[1][7]);
        $this->assertSame($storedSnapshot, $event->fresh()->metadata['release_snapshot']);
    }

    public function test_csv_rejects_unknown_format_version(): void
    {
        $actor = $this->context(['export']);
        [$package] = $this->package($actor);
        $this->withHeaders($actor->authHeaders())->get('/api/v1/admin/design-management/composition/packages/'.$package->id.'/issue-register/export?format_version=3')
            ->assertUnprocessable()->assertJsonValidationErrors('format_version');
    }

    public function test_workflow_check_uses_action_permission_without_manual_norm_control_right(): void
    {
        $actor = $this->context(['edit', 'review', 'approve']);
        [$package, $section] = $this->package($actor);
        $section->update(['required' => false, 'metadata' => ['documents' => []]]);
        $artifact = $section->artifacts()->create([
            'organization_id' => $package->organization_id, 'project_id' => $package->project_id, 'package_id' => $package->id,
            'artifact_type' => 'text_document', 'title' => 'Необязательный документ', 'document_code' => 'OPTIONAL-01',
        ]);
        $artifact->versions()->create([
            'organization_id' => $package->organization_id, 'project_id' => $package->project_id, 'title' => $artifact->title,
            'version_number' => '1', 'revision' => '1', 'source_format' => 'pdf', 'file_format' => 'pdf',
            'source_file_path' => 'org-'.$package->organization_id.'/optional.pdf', 'source_original_name' => 'optional.pdf',
            'source_mime_type' => 'application/pdf', 'source_size_bytes' => 100, 'is_current' => true,
        ]);
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

    private function releaseSnapshot(AdminApiTestContext $actor, DesignPackage $package, array $snapshot): DesignWorkflowEvent
    {
        return DesignWorkflowEvent::query()->create([
            'organization_id' => $package->organization_id, 'project_id' => $package->project_id,
            'package_id' => $package->id, 'actor_id' => $actor->user->id, 'action' => 'issue', 'to_status' => 'issued',
            'metadata' => ['release_snapshot' => $snapshot],
        ]);
    }

    private function csvRows(string $body): array
    {
        $stream = fopen('php://temp', 'w+b');
        $this->assertIsResource($stream);
        fwrite($stream, substr($body, 3));
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, null, ';', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);
        return $rows;
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
