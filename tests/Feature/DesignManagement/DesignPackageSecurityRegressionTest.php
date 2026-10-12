<?php

declare(strict_types=1);

namespace Tests\Feature\DesignManagement;

use App\BusinessModules\Features\DesignManagement\Models\DesignArtifact;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Models\DesignCompositionRevision;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackageSection;
use App\BusinessModules\Features\DesignManagement\Models\DesignReviewComment;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelDerivative;
use App\BusinessModules\Features\DesignManagement\Services\DesignCompletenessService;
use App\BusinessModules\Features\DesignManagement\Services\DesignCompositionService;
use App\BusinessModules\Features\DesignManagement\Services\DesignManagementService;
use App\BusinessModules\Features\DesignManagement\Services\DesignAccessService;
use App\BusinessModules\Features\DesignManagement\Services\DesignDocumentArtifactService;
use App\BusinessModules\Features\DesignManagement\Support\DesignViewerConverter;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Modules\Core\AccessController;
use App\Services\Storage\FileService;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\Support\DesignManagementTestEntitlements;
use Tests\TestCase;

final class DesignPackageSecurityRegressionTest extends TestCase
{
    public function test_new_package_cannot_skip_workflow_through_initial_status_over_http(): void
    {
        $actor = $this->context(['view', 'create', 'composition.edit']);
        $project = Project::factory()->create(['organization_id' => $actor->organization->id]);
        self::assertTrue(app(DesignAccessService::class)->canAccessProject($actor->user, (int) $actor->organization->id, (int) $project->id, 'design-management.create'));
        self::assertFalse(app(AuthorizationService::class)->can($actor->user, 'design-management.approve', ['organization_id' => $actor->organization->id, 'project_id' => $project->id]));

        foreach (['approved', 'issued', 'archived', 'under_norm_control', 'under_customer_review'] as $status) {
            $this->withHeaders($actor->authHeaders())->postJson('/api/v1/admin/design-management/packages',
                $this->payload($project) + ['status' => $status]
            )->assertUnprocessable();
        }

        self::assertSame(0, DesignPackage::query()->where('organization_id', $actor->organization->id)->count());
        $this->withHeaders($actor->authHeaders())->postJson('/api/v1/admin/design-management/packages', $this->payload($project))
            ->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.composition_status', 'draft');
    }

    public function test_direct_creation_checks_initial_status_and_rolls_back_failed_first_composition(): void
    {
        $actor = $this->context(['view', 'create', 'composition.edit']);
        $project = Project::factory()->create(['organization_id' => $actor->organization->id]);
        $service = app(DesignManagementService::class);

        foreach ([['status' => 'approved'], ['expected_revision' => 999]] as $invalid) {
            try {
                $service->createPackage($actor->organization->id, $actor->user->id, $this->payload($project) + $invalid);
                self::fail('Неправильное создание должно отклоняться');
            } catch (DomainException) {
                self::assertSame(0, DesignPackage::query()->where('project_id', $project->id)->count());
                self::assertSame(0, DB::table('design_package_sections')->where('project_id', $project->id)->count());
                self::assertSame(0, DB::table('design_artifacts')->where('project_id', $project->id)->count());
                self::assertSame(0, DB::table('design_composition_revisions')->where('project_id', $project->id)->count());
            }
        }

        $package = $service->createPackage($actor->organization->id, $actor->user->id, $this->payload($project));
        self::assertNotNull($package->composition_revision_id);
        self::assertSame(1, DB::table('design_composition_revisions')->where('package_id', $package->id)->count());
    }

    public function test_model_routes_reject_document_versions_without_reading_private_storage(): void
    {
        $actor = $this->context(['view', 'models.view']);
        $project = Project::factory()->create(['organization_id' => $actor->organization->id]);
        $package = $this->package($actor, $project);
        $document = $this->version($actor, $package, 'text_document', 'pdf');
        self::assertTrue(app(AuthorizationService::class)->can($actor->user, 'design-management.models.view', ['organization_id' => $actor->organization->id, 'project_id' => $project->id]));
        self::assertFalse(app(AuthorizationService::class)->can($actor->user, 'design-management.documents.view', ['organization_id' => $actor->organization->id, 'project_id' => $project->id]));
        $this->mock(FileService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('disk');
            $mock->shouldNotReceive('temporaryUrl');
        });

        foreach (['source-file', 'viewer'] as $endpoint) {
            $this->withHeaders($actor->authHeaders())->getJson("/api/v1/admin/design-management/model-versions/{$document->id}/{$endpoint}")->assertNotFound();
        }
        self::assertNull(app(DesignManagementService::class)->findVersion($actor->organization->id, $document->id, $actor->user));
        $this->expectException(DomainException::class);
        app(DesignManagementService::class)->sourceFileStream($document, $actor->user);
    }

    public function test_packages_and_direct_files_share_assigned_project_access_over_http_and_service(): void
    {
        $actor = $this->context(['view', 'models.view', 'documents.view', 'models.upload', 'create', 'composition.edit']);
        $allowed = Project::factory()->create(['organization_id' => $actor->organization->id]);
        $hidden = Project::factory()->create(['organization_id' => $actor->organization->id]);
        $actor->organization->users()->updateExistingPivot($actor->user->id, ['project_access_mode' => 'assigned_projects']);
        $allowed->users()->attach($actor->user->id, ['role' => 'project_manager', 'is_active' => true]);
        $visiblePackage = $this->package($actor, $allowed);
        $hiddenPackage = $this->package($actor, $hidden);
        $model = $this->version($actor, $hiddenPackage, 'model', 'ifc');
        $document = $this->version($actor, $hiddenPackage, 'text_document', 'pdf');
        $this->mock(FileService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('disk');
            $mock->shouldNotReceive('temporaryUrl');
        });
        $this->app->forgetInstance(DesignManagementService::class);

        $this->withHeaders($actor->authHeaders())->getJson('/api/v1/admin/design-management/packages')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $visiblePackage->id)
            ->assertJsonPath('summary.packages_count', 1);
        $this->withHeaders($actor->authHeaders())->getJson("/api/v1/admin/design-management/packages/{$hiddenPackage->id}")->assertNotFound();
        $this->withHeaders($actor->authHeaders())->getJson("/api/v1/admin/design-management/model-versions/{$model->id}/source-file")->assertNotFound();
        $this->withHeaders($actor->authHeaders())->getJson("/api/v1/admin/design-management/model-versions/{$model->id}/viewer")->assertNotFound();
        $this->withHeaders($actor->authHeaders())->getJson("/api/v1/admin/design-management/document-versions/{$document->id}/source-file")->assertNotFound();
        $this->withHeaders($actor->authHeaders())->postJson('/api/v1/admin/design-management/packages', $this->payload($hidden))->assertUnprocessable();

        $service = app(DesignManagementService::class);
        self::assertNull($service->findPackage($actor->organization->id, $hiddenPackage->id, $actor->user));
        self::assertNull($service->findVersion($actor->organization->id, $model->id, $actor->user));
        $documents = app(DesignDocumentArtifactService::class);
        self::assertNull($documents->findVersion($actor->organization->id, $document->id, $actor->user));
        try {
            $documents->sourceFileStream($document, $actor->user);
            self::fail('Прямое чтение документа недоступного проекта должно отклоняться');
        } catch (DomainException) {
            self::assertFalse(app(DesignAccessService::class)->canAccessProject($actor->user, (int) $actor->organization->id, (int) $hidden->id, 'design-management.documents.view'));
        }
        $this->expectException(DomainException::class);
        $service->uploadIfcModel($hiddenPackage, $actor->user->id, UploadedFile::fake()->createWithContent('model.ifc', 'IFC'), ['title' => 'Модель', 'version_number' => '1']);
    }

    public function test_all_projects_mode_allows_organization_reader_without_project_assignment(): void
    {
        $actor = $this->context(['view', 'models.view']);
        $project = Project::factory()->create(['organization_id' => $actor->organization->id]);
        $package = $this->package($actor, $project);
        $version = $this->version($actor, $package, 'model', 'ifc');
        self::assertFalse($project->users()->where('users.id', $actor->user->id)->exists());

        $this->withHeaders($actor->authHeaders())->getJson("/api/v1/admin/design-management/packages/{$package->id}")->assertOk();
        self::assertSame($version->id, app(DesignManagementService::class)->findVersion($actor->organization->id, $version->id, $actor->user)?->id);
        $actor->organization->users()->updateExistingPivot($actor->user->id, ['project_access_mode' => 'assigned_projects']);
        $project->users()->attach($actor->user->id, ['role' => 'project_manager', 'is_active' => false]);
        self::assertNull(app(DesignManagementService::class)->findPackage($actor->organization->id, $package->id, $actor->user));
    }

    public function test_version_lookup_rechecks_artifact_and_package_parent_scope(): void
    {
        $actor = $this->context(['view', 'models.view']);
        $project = Project::factory()->create(['organization_id' => $actor->organization->id]);
        $other = Project::factory()->create(['organization_id' => $actor->organization->id]);
        $package = $this->package($actor, $project);
        $version = $this->version($actor, $package, 'model', 'ifc');
        $version->update(['project_id' => $other->id]);

        self::assertNull(app(DesignManagementService::class)->findVersion($actor->organization->id, $version->id, $actor->user));
        $this->withHeaders($actor->authHeaders())->getJson("/api/v1/admin/design-management/model-versions/{$version->id}/viewer")->assertNotFound();
    }

    public function test_model_upload_fills_the_explicit_composition_slot_and_invalidates_readiness(): void
    {
        $actor = $this->context(['view', 'create', 'composition.edit', 'composition.approve', 'models.upload', 'models.view', 'norm_control.run']);
        $project = Project::factory()->create(['organization_id' => $actor->organization->id]);
        $payload = $this->payload($project);
        $payload['composition']['sections'][0]['documents'][0]['artifact_type'] = 'model';
        $payload['composition']['sections'][0]['documents'][0]['allowed_formats'] = ['ifc'];
        $service = app(DesignManagementService::class);
        $package = $service->createPackage($actor->organization->id, $actor->user->id, $payload);
        app(DesignCompositionService::class)->approve(DesignCompositionRevision::query()->findOrFail($package->composition_revision_id), $actor->user);
        $slot = DesignArtifact::query()->where('package_id', $package->id)->sole();
        Storage::fake('s3');
        $disk = Storage::disk('s3');
        $this->mock(FileService::class, function (MockInterface $mock) use ($disk): void {
            $mock->shouldReceive('disk')->andReturn($disk);
            $mock->shouldReceive('temporaryUrl')->andReturn(null)->byDefault();
        });
        $this->app->forgetInstance(DesignManagementService::class);

        $upload = $this->withHeaders($actor->authHeaders())->post("/api/v1/admin/design-management/packages/{$package->id}/models", [
            'artifact_id' => $slot->id, 'title' => 'Модель раздела', 'version_number' => '1', 'revision' => 'R01',
            'file' => UploadedFile::fake()->createWithContent('building.ifc', 'ISO-10303-21;'),
        ])->assertCreated();
        self::assertSame($slot->id, $upload->json('data.artifact_id'));
        self::assertSame(1, DesignArtifact::query()->where('package_id', $package->id)->count());
        self::assertSame('in_work', $slot->fresh('section')->section->status->value);
        $check = app(DesignCompletenessService::class)->run($package->fresh(), $actor->user->id);
        self::assertSame('ready', $check->status->value);
        self::assertSame(1, app(DesignManagementService::class)->listPackages($actor->organization->id, ['readiness_status' => 'ready'], $actor->user)->total());

        $this->withHeaders($actor->authHeaders())->post("/api/v1/admin/design-management/packages/{$package->id}/models", [
            'artifact_id' => $slot->id, 'title' => 'Новая редакция', 'version_number' => '2', 'revision' => 'R02',
            'file' => UploadedFile::fake()->createWithContent('building.ifc', 'ISO-10303-21;'),
        ])->assertCreated();
        self::assertSame(0, app(DesignManagementService::class)->listPackages($actor->organization->id, ['readiness_status' => 'ready'], $actor->user)->total());
        self::assertSame(1, app(DesignManagementService::class)->listPackages($actor->organization->id, ['readiness_status' => 'not_checked'], $actor->user)->total());
    }

    public function test_removed_model_document_slot_cannot_receive_another_version(): void
    {
        $actor = $this->context(['view', 'create', 'composition.edit', 'models.upload']);
        $project = Project::factory()->create(['organization_id' => $actor->organization->id]);
        $payload = $this->payload($project);
        $payload['composition']['sections'][0]['documents'][0]['artifact_type'] = 'model';
        $service = app(DesignManagementService::class);
        $package = $service->createPackage($actor->organization->id, $actor->user->id, $payload);
        $slot = DesignArtifact::query()->where('package_id', $package->id)->sole();
        $slot->versions()->create([
            'organization_id' => $package->organization_id, 'project_id' => $package->project_id,
            'created_by' => $actor->user->id, 'updated_by' => $actor->user->id, 'uploaded_by' => $actor->user->id,
            'title' => 'Первая редакция', 'version_number' => '1', 'source_format' => 'ifc', 'file_format' => 'ifc',
            'source_file_path' => 'org-' . $package->organization_id . '/pir/first.ifc', 'source_original_name' => 'first.ifc',
            'source_mime_type' => 'application/x-step', 'source_size_bytes' => 1, 'is_current' => true, 'status' => 'current',
        ]);
        $revision = DesignCompositionRevision::query()->findOrFail($package->composition_revision_id);
        app(DesignCompositionService::class)->createRevision($package, $actor->user, [
            'composition' => ['sections' => [['code' => 'AR', 'title' => 'Раздел сохранён', 'documents' => []]]],
            'expected_revision' => $revision->revision_number, 'expected_state_version' => $revision->state_version,
        ]);
        self::assertFalse($slot->fresh()->metadata['composition_active']);
        self::assertTrue($slot->fresh('section')->section->metadata['composition_active']);
        $this->mock(FileService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('disk');
        });
        $this->app->forgetInstance(DesignManagementService::class);

        try {
            app(DesignManagementService::class)->uploadIfcModel($package->fresh(), $actor->user->id,
                UploadedFile::fake()->createWithContent('building.ifc', 'ISO-10303-21;'),
                ['artifact_id' => $slot->id, 'title' => 'Запрещённая редакция', 'version_number' => '2']
            );
            self::fail('Исключённый документ не должен принимать новую версию');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('artifact_id', $exception->errors());
            self::assertSame(1, DesignArtifactVersion::query()->where('artifact_id', $slot->id)->count());
        }
        $this->withHeaders($actor->authHeaders())->postJson("/api/v1/admin/design-management/packages/{$package->id}/models/multipart/start", [
            'artifact_id' => $slot->id, 'title' => 'Запрещённая редакция', 'version_number' => '2',
            'original_name' => 'building.ifc', 'file_size_bytes' => 1024,
        ])->assertUnprocessable()->assertJsonValidationErrors('artifact_id');
    }

    public function test_registry_summary_and_filters_cover_the_scoped_selection_before_pagination(): void
    {
        $actor = $this->context(['view']);
        $project = Project::factory()->create(['organization_id' => $actor->organization->id]);
        $first = $this->package($actor, $project);
        $first->update(['title' => 'АР корпус А']);
        $second = $this->package($actor, $project);
        $second->update(['title' => 'КР корпус Б']);
        foreach ([[$first, 'AR'], [$second, 'KR']] as [$package, $code]) {
            DesignPackageSection::query()->create(['organization_id' => $actor->organization->id, 'project_id' => $project->id,
                'package_id' => $package->id, 'code' => $code, 'title' => $code, 'project_stage' => 'pd']);
        }
        $this->version($actor, $first, 'text_document', 'pdf');
        $this->version($actor, $second, 'model', 'ifc');

        $this->withHeaders($actor->authHeaders())->getJson('/api/v1/admin/design-management/packages?per_page=1')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2)
            ->assertJsonPath('summary.packages_count', 2)->assertJsonPath('summary.documents_count', 1)
            ->assertJsonPath('summary.uploaded_documents_count', 1)->assertJsonPath('summary.models_count', 1)
            ->assertJsonCount(2, 'summary.section_options');
        $this->withHeaders($actor->authHeaders())->getJson('/api/v1/admin/design-management/packages?search=' . urlencode('АР'))
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('summary.models_count', 0)->assertJsonPath('summary.documents_count', 1);
        $this->withHeaders($actor->authHeaders())->getJson('/api/v1/admin/design-management/packages?section_code=KR')
            ->assertOk()->assertJsonPath('data.0.id', $second->id)->assertJsonPath('meta.total', 1);
    }

    public function test_ready_model_summary_does_not_repeat_derivative_scans(): void
    {
        $actor = $this->context(['view']);
        $project = Project::factory()->create(['organization_id' => $actor->organization->id]);
        $package = $this->package($actor, $project);
        for ($index = 0; $index < 16; $index++) {
            $version = $this->version($actor, $package, 'model', 'ifc');
            if ($index < 15) {
                $this->readyDerivative($version, ['payload' => str_repeat(hash('sha256', (string) $index), 2000)]);
            }
        }

        DB::enableQueryLog();
        try {
            $summary = app(DesignManagementService::class)->packageSummary($actor->organization->id, ['project_id' => $project->id], $actor->user);
            $queries = array_values(array_filter(DB::getQueryLog(), static fn (array $query): bool =>
                str_contains($query['query'], 'select count(*) as aggregate from "design_artifacts"')
                && str_contains($query['query'], 'design_model_derivatives')));
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        self::assertSame(16, $summary['models_count']);
        self::assertSame(15, $summary['ready_models_count']);
        self::assertCount(1, $queries);
        $rows = DB::select('EXPLAIN (ANALYZE, TIMING FALSE, FORMAT JSON) '.$queries[0]['query'], $queries[0]['bindings']);
        $plan = json_decode($rows[0]->{'QUERY PLAN'}, true, 512, JSON_THROW_ON_ERROR)[0]['Plan'];
        $visited = 0;
        $walk = function (array $node) use (&$walk, &$visited): void {
            if (str_contains($node['Filter'] ?? '', 'converter_version')) {
                $visited += (($node['Actual Rows'] ?? 0) + ($node['Rows Removed by Filter'] ?? 0)) * ($node['Actual Loops'] ?? 0);
            }
            foreach ($node['Plans'] ?? [] as $child) {
                $walk($child);
            }
        };
        $walk($plan);
        self::assertGreaterThan(0, $visited);
        self::assertLessThanOrEqual(15, $visited, 'Converter metadata must be evaluated once per eligible derivative.');
    }

    public function test_ready_model_summary_preserves_version_viewer_composition_and_scope_filters(): void
    {
        $actor = $this->context(['view']);
        $project = Project::factory()->create(['organization_id' => $actor->organization->id]);
        $package = $this->package($actor, $project);
        $ready = $this->version($actor, $package, 'model', 'ifc');
        $this->readyDerivative($ready);
        $alsoCurrent = $ready->replicate();
        $alsoCurrent->version_number = '2';
        $alsoCurrent->save();
        $this->readyDerivative($alsoCurrent);

        $newer = $this->version($actor, $package, 'model', 'ifc');
        $this->readyDerivative($newer, ['converter_version' => DesignViewerConverter::version() + 1]);
        $outdated = $this->version($actor, $package, 'model', 'ifc');
        $this->readyDerivative($outdated, ['converter_version' => DesignViewerConverter::version() - 1]);
        $missing = $this->version($actor, $package, 'model', 'ifc');
        $this->readyDerivative($missing)->update(['metadata' => []]);
        foreach (['status' => 'failed', 'viewer_provider' => 'other', 'derivative_format' => 'other'] as $key => $value) {
            $version = $this->version($actor, $package, 'model', 'ifc');
            $this->readyDerivative($version, ['converter_version' => 'broken'])->update([$key => $value]);
        }
        $historical = $this->version($actor, $package, 'model', 'ifc');
        $this->readyDerivative($historical, ['converter_version' => 'broken']);
        $historical->update(['is_current' => false]);
        $document = $this->version($actor, $package, 'text_document', 'pdf');
        $this->readyDerivative($document, ['converter_version' => 'broken']);
        $inactive = $this->version($actor, $package, 'model', 'ifc');
        $this->readyDerivative($inactive, ['converter_version' => 'broken']);
        $inactive->artifact->update(['metadata' => ['composition_active' => false]]);
        $section = DesignPackageSection::query()->create([
            'organization_id' => $actor->organization->id, 'project_id' => $project->id,
            'package_id' => $package->id, 'code' => 'AR', 'title' => 'AR', 'project_stage' => 'pd',
            'metadata' => ['composition_active' => false],
        ]);
        $sectionModel = $this->version($actor, $package, 'model', 'ifc');
        $this->readyDerivative($sectionModel, ['converter_version' => 'broken']);
        $sectionModel->artifact->update(['section_id' => $section->id]);
        $otherProject = Project::factory()->create(['organization_id' => $actor->organization->id]);
        $otherPackage = $this->package($actor, $otherProject);
        $this->readyDerivative($this->version($actor, $otherPackage, 'model', 'ifc'), ['converter_version' => 'broken']);
        $foreign = $this->context(['view']);
        $foreignProject = Project::factory()->create(['organization_id' => $foreign->organization->id]);
        $foreignPackage = $this->package($foreign, $foreignProject);
        $this->readyDerivative($this->version($foreign, $foreignPackage, 'model', 'ifc'), ['converter_version' => '2147483648']);

        $summary = app(DesignManagementService::class)->packageSummary($actor->organization->id, ['project_id' => $project->id], $actor->user);
        self::assertSame(2, $summary['ready_models_count']);
        self::assertSame(8, $summary['models_count']);
        self::assertSame(0, app(DesignManagementService::class)->packageSummary($actor->organization->id, ['project_id' => $foreignProject->id], $actor->user)['ready_models_count']);
    }

    public function test_package_metadata_edit_checks_lock_and_the_displayed_update_timestamp(): void
    {
        $actor = $this->context(['view', 'edit']);
        $project = Project::factory()->create(['organization_id' => $actor->organization->id]);
        $package = $this->package($actor, $project);
        $url = "/api/v1/admin/design-management/packages/{$package->id}";
        $timestamp = $this->withHeaders($actor->authHeaders())->getJson($url)->assertOk()->json('data.updated_at');
        $this->withHeaders($actor->authHeaders())->patchJson($url, ['title' => 'Исправленное название', 'planned_issue_date' => '2026-12-01', 'expected_updated_at' => $timestamp])
            ->assertOk()->assertJsonPath('data.title', 'Исправленное название')->assertJsonPath('data.planned_issue_date', '2026-12-01');
        $this->withHeaders($actor->authHeaders())->patchJson($url, ['title' => 'Старая форма', 'expected_updated_at' => now()->subDay()->toIso8601String()])->assertConflict();
        self::assertSame('Исправленное название', $package->fresh()->title);
        $package->update(['status' => 'issued']);
        $this->withHeaders($actor->authHeaders())->patchJson($url, ['title' => 'Запертая правка', 'expected_updated_at' => $package->updated_at->toIso8601String()])->assertConflict();
    }

    public function test_legacy_comment_create_update_and_delete_invalidate_sql_readiness_and_resource_freshness(): void
    {
        $this->freezeTime();
        $actor = $this->context(['view', 'create', 'composition.edit', 'composition.approve', 'norm_control.run']);
        $project = Project::factory()->create(['organization_id' => $actor->organization->id]);
        $package = $this->readyPackage($actor, $project);
        $completeness = app(DesignCompletenessService::class);
        $filter = '/api/v1/admin/design-management/packages?project_id=' . $project->id . '&readiness_status=';
        $this->withHeaders($actor->authHeaders())->getJson($filter . 'ready')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.completeness_is_fresh', true);
        $comment = DesignReviewComment::query()->create([
            'organization_id' => $actor->organization->id, 'project_id' => $project->id,
            'package_id' => $package->id, 'author_id' => $actor->user->id,
            'severity' => 'blocking', 'status' => 'open', 'body' => 'Старое блокирующее замечание',
        ]);
        $this->withHeaders($actor->authHeaders())->getJson($filter . 'ready')->assertOk()->assertJsonPath('meta.total', 0);
        $this->withHeaders($actor->authHeaders())->getJson($filter . 'not_checked')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.completeness_is_fresh', false);
        self::assertSame('blocked', $completeness->run($package->fresh(), $actor->user->id)->status->value);
        $this->withHeaders($actor->authHeaders())->getJson($filter . 'blocked')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.completeness_is_fresh', true);

        $comment->timestamps = false;
        $comment->update(['status' => 'accepted']);
        $this->withHeaders($actor->authHeaders())->getJson($filter . 'blocked')->assertOk()->assertJsonPath('meta.total', 0);
        self::assertSame('ready', $completeness->run($package->fresh(), $actor->user->id)->status->value);
        $comment->update(['body' => 'Уточнённый текст', 'response' => 'Согласовано']);
        $this->withHeaders($actor->authHeaders())->getJson($filter . 'not_checked')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.completeness_is_fresh', false);
        self::assertSame('ready', $completeness->run($package->fresh(), $actor->user->id)->status->value);
        $comment->delete();
        $this->withHeaders($actor->authHeaders())->getJson($filter . 'ready')->assertOk()->assertJsonPath('meta.total', 0);
        $this->withHeaders($actor->authHeaders())->getJson($filter . 'not_checked')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.completeness_is_fresh', false);
    }

    public function test_viewer_filter_matches_the_canonical_effective_state_for_every_supported_option(): void
    {
        $actor = $this->context(['view']);
        $project = Project::factory()->create(['organization_id' => $actor->organization->id]);
        $packages = [];
        foreach (['missing', 'queued', 'processing', 'ready', 'failed'] as $status) {
            $package = $this->package($actor, $project);
            $version = $this->version($actor, $package, 'model', 'ifc');
            if ($status !== 'missing') {
                $this->derivative($actor, $version, $status, DesignViewerConverter::version());
            }
            $packages[$status] = $package;
        }
        foreach ($packages as $status => $package) {
            $this->withHeaders($actor->authHeaders())->getJson('/api/v1/admin/design-management/packages?viewer_status=' . $status)
                ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $package->id)
                ->assertJsonPath('data.0.viewer_status', $status)
                ->assertJsonPath('data.0.workflow_summary.derivative_status', $status);
        }
        $stale = $this->package($actor, $project);
        $staleVersion = $this->version($actor, $stale, 'model', 'ifc');
        $this->derivative($actor, $staleVersion, 'ready', DesignViewerConverter::version() - 1);
        $newest = $this->version($actor, $packages['ready'], 'model', 'ifc');
        self::assertTrue($newest->is_current);
        $this->withHeaders($actor->authHeaders())->getJson('/api/v1/admin/design-management/packages?viewer_status=ready')
            ->assertOk()->assertJsonPath('meta.total', 0);
        $missing = $this->withHeaders($actor->authHeaders())->getJson('/api/v1/admin/design-management/packages?viewer_status=missing')
            ->assertOk()->assertJsonPath('meta.total', 3);
        foreach ($missing->json('data') as $row) {
            self::assertSame('missing', $row['workflow_summary']['derivative_status']);
        }
        $this->withHeaders($actor->authHeaders())->getJson('/api/v1/admin/design-management/packages?viewer_status=blocked')->assertUnprocessable();
    }

    private function derivative(AdminApiTestContext $actor, DesignArtifactVersion $version, string $status, int $converterVersion): void
    {
        DesignModelDerivative::query()->create([
            'organization_id' => $version->organization_id, 'project_id' => $version->project_id, 'version_id' => $version->id,
            'created_by' => $actor->user->id, 'updated_by' => $actor->user->id, 'prepared_by' => $actor->user->id,
            'status' => $status, 'derivative_file_path' => 'org-' . $version->organization_id . '/pir/model.frag',
            'metadata' => ['prepared_on' => 'server', 'converter_version' => $converterVersion],
        ]);
    }

    private function readyPackage(AdminApiTestContext $actor, Project $project): DesignPackage
    {
        $package = app(DesignManagementService::class)->createPackage($actor->organization->id, $actor->user->id, $this->payload($project));
        app(DesignCompositionService::class)->approve(DesignCompositionRevision::query()->findOrFail($package->composition_revision_id), $actor->user);
        $slot = DesignArtifact::query()->where('package_id', $package->id)->sole();
        $slot->versions()->create([
            'organization_id' => $package->organization_id, 'project_id' => $package->project_id,
            'created_by' => $actor->user->id, 'updated_by' => $actor->user->id, 'uploaded_by' => $actor->user->id,
            'title' => 'План', 'version_number' => '1', 'revision' => 'R01', 'source_format' => 'pdf', 'file_format' => 'pdf',
            'source_file_path' => 'org-' . $package->organization_id . '/pir/plan.pdf', 'source_original_name' => 'plan.pdf',
            'source_mime_type' => 'application/pdf', 'source_size_bytes' => 1, 'status' => 'current', 'is_current' => true,
        ]);
        self::assertSame('ready', app(DesignCompletenessService::class)->run($package->fresh(), $actor->user->id)->status->value);

        return $package->fresh();
    }

    private function readyDerivative(DesignArtifactVersion $version, array $metadata = []): DesignModelDerivative
    {
        return DesignModelDerivative::query()->create([
            'organization_id' => $version->organization_id, 'project_id' => $version->project_id,
            'version_id' => $version->id, 'viewer_provider' => 'thatopen', 'derivative_format' => 'thatopen_frag',
            'status' => 'ready', 'metadata' => array_replace(['converter_version' => DesignViewerConverter::version()], $metadata),
        ]);
    }

    private function context(array $permissions): AdminApiTestContext
    {
        $actor = AdminApiTestContext::create(roleSlug: 'project_manager');
        $actor->organization->users()->updateExistingPivot($actor->user->id, ['is_owner' => false, 'project_access_mode' => 'all_projects']);
        $actor->user->roleAssignments()->delete();
        $role = OrganizationCustomRole::query()->create([
            'organization_id' => $actor->organization->id,
            'name' => 'Проверка доступа ПИР',
            'slug' => 'pir-security-' . $actor->user->id,
            'system_permissions' => ['admin.access'],
            'module_permissions' => ['design-management' => array_map(static fn (string $permission): string => 'design-management.' . $permission, $permissions)],
            'interface_access' => ['admin'],
            'is_active' => true,
            'created_by' => $actor->user->id,
        ]);
        UserRoleAssignment::assignRole($actor->user, $role->slug, AuthorizationContext::getOrganizationContext($actor->organization->id), UserRoleAssignment::TYPE_CUSTOM);
        DesignManagementTestEntitlements::activate($actor);
        Cache::store('array')->flush();
        self::assertTrue(app(AccessController::class)->hasModuleAccess((int) $actor->organization->id, 'design-management'));
        self::assertTrue($actor->user->belongsToOrganization((int) $actor->organization->id));
        foreach ($permissions as $permission) {
            self::assertTrue(app(AuthorizationService::class)->can($actor->user, 'design-management.' . $permission, [
                'organization_id' => (int) $actor->organization->id,
            ]), 'Проверка объявленного права роли: ' . $permission);
        }

        return $actor;
    }

    private function payload(Project $project): array
    {
        return [
            'project_id' => $project->id, 'title' => 'Проверочный комплект', 'project_stage' => 'pd',
            'composition' => ['sections' => [[
                'code' => 'AR', 'title' => 'Архитектурные решения',
                'documents' => [['document_code' => 'AR-01', 'document_title' => 'План', 'required' => true]],
            ]]],
        ];
    }

    private function package(AdminApiTestContext $actor, Project $project): DesignPackage
    {
        return DesignPackage::query()->create([
            'organization_id' => $actor->organization->id, 'project_id' => $project->id,
            'created_by' => $actor->user->id, 'updated_by' => $actor->user->id,
            'title' => 'Комплект ' . $project->id, 'project_stage' => 'pd', 'status' => 'draft',
        ]);
    }

    private function version(AdminApiTestContext $actor, DesignPackage $package, string $type, string $format): DesignArtifactVersion
    {
        $artifact = DesignArtifact::query()->create([
            'organization_id' => $package->organization_id, 'project_id' => $package->project_id,
            'package_id' => $package->id, 'created_by' => $actor->user->id, 'updated_by' => $actor->user->id,
            'artifact_type' => $type, 'title' => 'Файл ' . $format,
        ]);

        return $artifact->versions()->create([
            'organization_id' => $package->organization_id, 'project_id' => $package->project_id,
            'created_by' => $actor->user->id, 'updated_by' => $actor->user->id, 'uploaded_by' => $actor->user->id,
            'title' => 'Файл ' . $format, 'version_number' => '1', 'source_format' => $format, 'file_format' => $format,
            'source_file_path' => 'org-' . $package->organization_id . '/pir/file.' . $format,
            'source_original_name' => 'file.' . $format, 'source_mime_type' => 'application/octet-stream',
            'source_size_bytes' => 1, 'status' => 'current', 'is_current' => true,
        ]);
    }
}
