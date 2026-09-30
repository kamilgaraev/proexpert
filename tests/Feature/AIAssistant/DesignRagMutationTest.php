<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Jobs\IndexRagSourceJob;
use App\BusinessModules\Features\AIAssistant\Models\RagIndexRun;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\DesignRagMutationBridge;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexingCoordinator;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagJobDispatcher;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifact;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignCompositionRevision;
use App\BusinessModules\Features\DesignManagement\Models\DesignDocumentSheet;
use App\BusinessModules\Features\DesignManagement\Models\DesignIfcModelElement;
use App\BusinessModules\Features\DesignManagement\Models\DesignIfcUploadSession;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelDerivative;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Services\DesignDocumentArtifactService;
use App\BusinessModules\Features\DesignManagement\Services\DesignIfcElementIndexer;
use App\BusinessModules\Features\DesignManagement\Services\DesignModelMultipartUploadService;
use App\BusinessModules\Features\DesignManagement\Services\LegacyDesignCompositionBackfillService;
use App\Models\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use ReflectionClass;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class DesignRagMutationTest extends TestCase
{
    public function test_ifc_upsert_queues_real_old_and_new_row_ids_after_commit_without_unrelated_express_ids(): void
    {
        [$fixture, $package, $version, $other, $derivative] = $this->fixture();
        [$old, $unrelated] = $this->elements($version, $other, $derivative);
        Queue::fake([IndexRagSourceJob::class]);
        DB::beginTransaction();
        $this->index($version, $derivative);
        Queue::assertNotPushed(IndexRagSourceJob::class);
        $new = DesignIfcModelElement::query()->where('version_id', $version->id)->where('express_id', 740002)->sole();
        DB::commit();
        $runs = $this->runs($fixture->organization->id, 'design_ifc_model_element')->get();
        self::assertEqualsCanonicalizing([(string) $old->id, (string) $new->id], $runs->pluck('entity_id')->all());
        self::assertNotContains((string) $unrelated->id, $runs->pluck('entity_id')->all());
        self::assertSame([$package->project_id], $runs->pluck('project_id')->unique()->all());
        self::assertSame('Updated element', $old->fresh()->name);
        self::assertSame('Other version', $unrelated->fresh()->name);
        foreach ($runs as $run) { Queue::assertPushed(IndexRagSourceJob::class, static fn (IndexRagSourceJob $job): bool => $job->runId === $run->id); }
    }

    public function test_ifc_business_rollback_restores_content_and_discards_runs_and_jobs(): void
    {
        [$fixture, , $version, $other, $derivative] = $this->fixture();
        [$old] = $this->elements($version, $other, $derivative);
        Queue::fake([IndexRagSourceJob::class]);
        $before = RagIndexRun::query()->count();
        DB::beginTransaction();
        $this->index($version, $derivative);
        Queue::assertNotPushed(IndexRagSourceJob::class);
        DB::rollBack();
        self::assertSame('Old element', $old->fresh()->name);
        self::assertFalse(DesignIfcModelElement::query()->where('version_id', $version->id)->where('express_id', 740002)->exists());
        self::assertSame($before, RagIndexRun::query()->count());
        Queue::assertNotPushed(IndexRagSourceJob::class);
    }

    public function test_real_postgres_queue_failure_rolls_back_ifc_business_write_and_intent(): void
    {
        [$fixture, , $version, $other, $derivative] = $this->fixture();
        [$old] = $this->elements($version, $other, $derivative);
        Queue::fake([IndexRagSourceJob::class]);
        $coordinator = new DesignMutationSqlFailureCoordinator(app(RagIndexer::class), app(RagJobDispatcher::class));
        $this->app->instance(RagIndexingCoordinator::class, $coordinator);
        DB::beginTransaction();
        try {
            $this->index($version, $derivative);
            self::fail('Expected a database error while persisting the RAG intent.');
        } catch (QueryException $exception) {
            self::assertSame('22012', $coordinator->sqlState);
        }
        self::assertGreaterThan(0, $coordinator->failures);
        self::assertSame(1, (int) DB::selectOne('SELECT 1 AS one')->one);
        DB::commit();
        self::assertSame('Old element', $old->fresh()->name);
        self::assertFalse(DesignIfcModelElement::query()->where('version_id', $version->id)->where('express_id', 740002)->exists());
        self::assertSame(0, $this->runs($fixture->organization->id, 'design_ifc_model_element')->count());
        Queue::assertNotPushed(IndexRagSourceJob::class);
    }

    public function test_sheet_replacement_preserves_unrelated_version_and_queues_deleted_and_created_native_ids(): void
    {
        [$fixture, $package, $version, $other] = $this->fixture();
        [$old, $unrelated] = Model::withoutEvents(function () use ($package, $version, $other): array {
            $data = ['organization_id' => $package->organization_id, 'project_id' => $package->project_id, 'package_id' => $package->id, 'artifact_id' => $version->artifact_id, 'sheet_number' => '1', 'sheet_title' => 'Old sheet'];
            return [DesignDocumentSheet::query()->create($data + ['version_id' => $version->id]), DesignDocumentSheet::query()->create($data + ['version_id' => $other->id])];
        });
        Queue::fake([IndexRagSourceJob::class]);
        DB::beginTransaction();
        $service = (new ReflectionClass(DesignDocumentArtifactService::class))->newInstanceWithoutConstructor();
        $service->replaceSheets($version, $fixture->owner->id, [['sheet_number' => '2', 'sheet_title' => 'New sheet']]);
        Queue::assertNotPushed(IndexRagSourceJob::class);
        $new = DesignDocumentSheet::query()->where('version_id', $version->id)->sole();
        DB::commit();
        self::assertFalse(DesignDocumentSheet::query()->whereKey($old->id)->exists());
        self::assertTrue(DesignDocumentSheet::query()->whereKey($unrelated->id)->exists());
        self::assertEqualsCanonicalizing([(string) $old->id, (string) $new->id], $this->runs($fixture->organization->id, 'design_document_sheet')->pluck('entity_id')->all());
        self::assertSame(1, $version->fresh()->sheet_count);
    }

    public function test_multipart_cleanup_delete_queues_exact_native_uuid_only_after_commit(): void
    {
        [$fixture, $package] = $this->fixture();
        [$old, $unrelated] = Model::withoutEvents(function () use ($fixture, $package): array {
            $data = ['organization_id' => $package->organization_id, 'project_id' => $package->project_id, 'package_id' => $package->id, 'user_id' => $fixture->owner->id, 'file_identity' => 'synthetic-design-qa', 's3_upload_id' => 'synthetic-upload', 'source_path' => 'synthetic/model.ifc', 'original_name' => 'model.ifc', 'mime_type' => 'application/octet-stream', 'size_bytes' => 1, 'part_size_bytes' => 1, 'parts_count' => 1, 'expires_at' => now()->addHour()];
            return [DesignIfcUploadSession::query()->create($data + ['id' => (string) Str::uuid()]), DesignIfcUploadSession::query()->create($data + ['id' => (string) Str::uuid()])];
        });
        Queue::fake([IndexRagSourceJob::class]);
        $class = new ReflectionClass(DesignModelMultipartUploadService::class);
        $service = $class->newInstanceWithoutConstructor();
        DB::beginTransaction();
        $class->getMethod('deleteUploadSession')->invoke($service, $old->id);
        Queue::assertNotPushed(IndexRagSourceJob::class);
        DB::commit();
        self::assertFalse(DesignIfcUploadSession::query()->whereKey($old->id)->exists());
        self::assertTrue(DesignIfcUploadSession::query()->whereKey($unrelated->id)->exists());
        self::assertSame([$old->id], $this->runs($fixture->organization->id, 'design_ifc_upload_session')->pluck('entity_id')->all());
        Queue::assertPushed(IndexRagSourceJob::class, static fn (IndexRagSourceJob $job): bool => $job->entityType === 'design_ifc_upload_session' && $job->entityId === $old->id);
    }

    public function test_legacy_revision_insert_queues_actual_revision_and_package_with_idempotent_replay(): void
    {
        [$fixture, $package] = $this->fixture();
        Queue::fake([IndexRagSourceJob::class]);
        DB::beginTransaction();
        app(LegacyDesignCompositionBackfillService::class)->run();
        Queue::assertNotPushed(IndexRagSourceJob::class);
        $revision = DesignCompositionRevision::query()->where('package_id', $package->id)->sole();
        DB::commit();
        self::assertSame($revision->id, $package->fresh()->composition_revision_id);
        self::assertSame([(string) $revision->id], $this->runs($fixture->organization->id, 'design_composition_revision')->pluck('entity_id')->all());
        self::assertSame([(string) $package->id], RagIndexRun::query()->where('organization_id', $fixture->organization->id)->where('source_type', 'design')->where('entity_type', 'design_package')->pluck('entity_id')->all());
        app(LegacyDesignCompositionBackfillService::class)->run();
        self::assertSame(1, $this->runs($fixture->organization->id, 'design_composition_revision')->count());
        Queue::assertPushed(IndexRagSourceJob::class, static fn (IndexRagSourceJob $job): bool => $job->entityType === 'design_composition_revision' && $job->entityId === (string) $revision->id);
    }

    public function test_bridge_preserves_actual_old_and_new_organization_project_identities_for_native_row_move(): void
    {
        [$oldFixture, $oldPackage, $oldVersion, $other, $oldDerivative] = $this->fixture();
        [$newFixture, $newPackage, $newVersion, , $newDerivative] = $this->fixture();
        [$element] = $this->elements($oldVersion, $other, $oldDerivative);
        RagSource::query()->create(['organization_id' => $oldFixture->organization->id, 'project_id' => $oldPackage->project_id, 'source_type' => 'design_additional', 'entity_type' => 'design_ifc_model_element', 'entity_id' => (string) $element->id, 'title' => 'Stored before native move', 'checksum' => hash('sha256', 'native-before-move'), 'metadata' => []]);
        Queue::fake([IndexRagSourceJob::class]);
        DB::beginTransaction();
        DesignIfcModelElement::query()->whereKey($element->id)->update(['organization_id' => $newFixture->organization->id, 'project_id' => $newPackage->project_id, 'version_id' => $newVersion->id, 'derivative_id' => $newDerivative->id]);
        app(DesignRagMutationBridge::class)->changedRows(DesignIfcModelElement::class, DesignIfcModelElement::query()->whereKey($element->id));
        Queue::assertNotPushed(IndexRagSourceJob::class);
        DB::commit();
        foreach ([[$oldFixture->organization->id, $oldPackage->project_id], [$newFixture->organization->id, $newPackage->project_id]] as [$organizationId, $projectId]) {
            $run = $this->runs($organizationId, 'design_ifc_model_element')->sole();
            self::assertSame((string) $element->id, $run->entity_id);
            self::assertSame($projectId, $run->project_id);
            Queue::assertPushed(IndexRagSourceJob::class, static fn (IndexRagSourceJob $job): bool => $job->runId === $run->id);
        }
    }

    private function runs(int $organizationId, string $type): \Illuminate\Database\Eloquent\Builder
    {
        return RagIndexRun::query()->where('organization_id', $organizationId)->where('source_type', 'design_additional')->where('entity_type', $type);
    }

    private function index(DesignArtifactVersion $version, DesignModelDerivative $derivative): void
    {
        $path = tempnam(sys_get_temp_dir(), 'most-design-rag-');
        self::assertIsString($path);
        file_put_contents($path, json_encode(['express_id' => 740001, 'name' => 'Updated element'], JSON_THROW_ON_ERROR)."\n".json_encode(['express_id' => 740002, 'name' => 'New element'], JSON_THROW_ON_ERROR)."\n");
        try { (new DesignIfcElementIndexer)->index($version, $derivative, $path, ['indexed_element_count' => 2]); }
        finally { unlink($path); }
    }

    private function elements(DesignArtifactVersion $version, DesignArtifactVersion $other, DesignModelDerivative $derivative): array
    {
        return Model::withoutEvents(function () use ($version, $other, $derivative): array {
            $data = ['organization_id' => $version->organization_id, 'project_id' => $version->project_id, 'express_id' => 740001];
            return [DesignIfcModelElement::query()->create($data + ['version_id' => $version->id, 'derivative_id' => $derivative->id, 'name' => 'Old element']), DesignIfcModelElement::query()->create($data + ['version_id' => $other->id, 'name' => 'Other version'])];
        });
    }

    private function fixture(): array
    {
        return Model::withoutEvents(function (): array {
            $fixture = AssistantRealAuthorizationFixture::create();
            $project = Project::factory()->create(['organization_id' => $fixture->organization->id]);
            $scope = ['organization_id' => $fixture->organization->id, 'project_id' => $project->id, 'created_by' => $fixture->owner->id, 'updated_by' => $fixture->owner->id];
            $package = DesignPackage::query()->create($scope + ['title' => 'Synthetic mutation package', 'project_stage' => 'pd', 'status' => 'draft']);
            $artifact = DesignArtifact::query()->create($scope + ['package_id' => $package->id, 'title' => 'Synthetic model', 'artifact_type' => 'model']);
            $versionData = $scope + ['artifact_id' => $artifact->id, 'uploaded_by' => $fixture->owner->id, 'title' => 'Synthetic version', 'source_file_path' => 'synthetic/model.ifc', 'source_original_name' => 'model.ifc', 'source_mime_type' => 'application/octet-stream', 'source_size_bytes' => 1, 'file_format' => 'ifc'];
            $version = DesignArtifactVersion::query()->create($versionData + ['version_number' => 1]);
            $other = DesignArtifactVersion::query()->create($versionData + ['version_number' => 2]);
            $derivative = DesignModelDerivative::query()->create($scope + ['version_id' => $version->id, 'viewer_provider' => 'fragments', 'derivative_format' => 'fragments']);
            return [$fixture, $package, $version, $other, $derivative];
        });
    }
}

final class DesignMutationSqlFailureCoordinator extends RagIndexingCoordinator
{
    public int $failures = 0;
    public ?string $sqlState = null;

    public function queueEntity(int $organizationId, ?int $projectId, string $sourceType, string $entityType, string|int $entityId): RagIndexRun
    {
        if ($entityType === 'design_ifc_model_element') {
            $this->failures++;
            try { DB::select('SELECT 1 / 0'); }
            catch (QueryException $exception) { $this->sqlState = $exception->errorInfo[0] ?? null; throw $exception; }
        }
        return parent::queueEntity($organizationId, $projectId, $sourceType, $entityType, $entityId);
    }
}
