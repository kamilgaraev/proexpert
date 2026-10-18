<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocumentUnit;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentCoverageService;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantOperationsNativeFileAdapter;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantSalesNativeFileAdapter;
use App\BusinessModules\Features\Crm\Models\CrmImportBatch;
use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\BusinessModules\Features\QualityControl\Models\QualityDefectPhoto;
use App\Domain\Authorization\Services\AuthorizationService;
use App\BusinessModules\Features\Tenders\Models\Tender;
use App\BusinessModules\Features\Tenders\Models\TenderFile;
use App\Models\File;
use App\Models\Project;
use App\Models\User;
use App\Services\Modules\PackageCatalogService;
use App\Services\Storage\DTO\StoredFile;
use App\Services\Storage\FileService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantDocumentTypeDiscoveryTest extends TestCase
{
    public function beginDatabaseTransaction(): void
    {
        self::assertSame('pgsql', DB::connection()->getDriverName());
        self::assertMatchesRegularExpression('/^most_phpunit_[a-z0-9]+_testing$/i', DB::connection()->getDatabaseName());
    }

    private function fixture(): AssistantRealAuthorizationFixture
    {
        Queue::fake();
        config(['cache.default' => 'array']);

        return Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
    }

    private function file(AssistantRealAuthorizationFixture $fixture, Project $project, string $type = Project::class): File
    {
        return File::withoutEvents(fn () => File::query()->create([
            'organization_id' => $fixture->organization->id, 'user_id' => $fixture->owner->id,
            'fileable_type' => $type, 'fileable_id' => $project->id,
            'name' => 'coverage.txt', 'original_name' => 'coverage.txt',
            'path' => 'coverage-discovery/'.bin2hex(random_bytes(12)).'.txt', 'disk' => 's3', 'mime_type' => 'text/plain', 'size' => 7,
        ]));
    }

    private function read(AssistantRealAuthorizationFixture $fixture, User $actor, array $scope = [], string $mode = 'READ COMMITTED READ WRITE', bool $countersOnly = true): array
    {
        return DB::transaction(function () use ($fixture, $actor, $scope, $mode, $countersOnly): array {
            if (! in_array($mode, ['READ COMMITTED READ WRITE', 'REPEATABLE READ READ WRITE', 'REPEATABLE READ READ ONLY', 'SERIALIZABLE READ ONLY'], true)) {
                throw new \InvalidArgumentException;
            }
            DB::statement('SET TRANSACTION ISOLATION LEVEL '.$mode);

            return app(AssistantDocumentCoverageService::class)->coverage((int) $fixture->organization->id, $actor, scope: $scope, countersOnly: $countersOnly);
        });
    }

    private function watchTypeQueries(int &$heavyQueries): void
    {
        DB::listen(static function ($event) use (&$heavyQueries): void {
            if (preg_match('/select\s+distinct\s+"files"\."fileable_type"/i', $event->sql) === 1 && str_contains($event->sql, 'fileable_id')) {
                $heavyQueries++;
            }
        });
    }

    public function test_read_only_snapshot_batches_native_aggregates_without_changing_full_coverage(): void
    {
        $fixture = $this->fixture();
        $organizationId = (int) $fixture->organization->id;
        $body = 'Synthetic coverage';
        $storage = $this->createMock(FileService::class);
        $storage->method('readCurrentBounded')->willReturnCallback(static function () use ($body) {
            $stream = fopen('php://temp', 'w+b');
            if ($stream === false) { throw new \RuntimeException('synthetic_stream_unavailable'); }
            fwrite($stream, $body);
            rewind($stream);

            return $stream;
        });
        $storage->method('headCurrent')->willReturnCallback(static fn (string $path, int $maxBytes): StoredFile => new StoredFile($path, hash('md5', $body), strlen($body), hash('sha256', $body), 'image/png'));
        $this->app->instance(FileService::class, $storage);
        $policy = app(AssistantDataAccessPolicy::class);
        $sales = new AssistantSalesNativeFileAdapter($policy, app(AuthorizationService::class), $storage);
        $operations = new AssistantOperationsNativeFileAdapter($policy, app(AuthorizationService::class), $storage);
        $this->app->instance(AssistantSalesNativeFileAdapter::class, $sales);
        $this->app->instance(AssistantOperationsNativeFileAdapter::class, $operations);
        [$project, $batch, $photos] = Model::withoutEvents(function () use ($fixture, $organizationId, $body): array {
            $project = Project::factory()->create(['organization_id' => $organizationId]);
            $batch = CrmImportBatch::query()->create([
                'organization_id' => $organizationId, 'entity_type' => 'companies', 'source_format' => 'csv',
                'status' => 'previewed', 'original_filename' => 'coverage.csv',
                'stored_path' => 'org-'.$organizationId.'/crm/imports/'.\Illuminate\Support\Str::uuid().'.csv',
                'total_rows' => 1, 'uploaded_by_user_id' => $fixture->owner->id,
            ]);
            $defect = QualityDefect::query()->create([
                'organization_id' => $organizationId, 'project_id' => $project->id,
                'defect_number' => 'COVERAGE', 'title' => 'Coverage', 'severity' => 'major', 'status' => 'open',
            ]);
            $photos = [];
            for ($index = 0; $index < 8; $index++) {
                $photos[] = QualityDefectPhoto::query()->create([
                    'organization_id' => $organizationId, 'quality_defect_id' => $defect->id,
                    'uploaded_by' => $fixture->owner->id, 'type' => 'before',
                    'url' => 'org-'.$organizationId.'/quality-control/defects/'.$defect->id.'/'.\Illuminate\Support\Str::uuid().'.png',
                    'storage_identity_verified' => true, 'storage_etag' => hash('md5', $body),
                    'storage_sha256' => hash('sha256', $body), 'size_bytes' => strlen($body), 'mime_type' => 'image/png',
                ]);
            }

            return [$project, $batch, $photos];
        });
        $sales->map($fixture->owner, $organizationId, 'crm_import_batch', $batch->id);
        foreach ($photos as $index => $photo) {
            $document = $operations->map($fixture->owner, $organizationId, 'quality_defect_photo', $photo->id);
            $document->forceFill([
                'status' => ['ready', 'ready', 'damaged', 'unsupported', 'ocr_approved', 'ocr_quote_required', 'uploaded', 'ready'][$index],
                'coverage_status' => $index === 7 ? 'empty' : 'full',
                'extracted_text' => $index === 1 ? '' : $body,
                'metadata' => array_merge($document->metadata, ['page_count' => 2]),
            ])->saveQuietly();
            if ($index === 0) {
                foreach (['ocr_page', 'text_chunk'] as $unitType) {
                    AIAssistantDocumentUnit::query()->create([
                        'document_id' => $document->id, 'unit_type' => $unitType, 'unit_index' => 0,
                        'text' => $body, 'checksum' => hash('sha256', $body),
                    ]);
                }
                $older = $document->replicate();
                $older->forceFill(['status' => 'failed', 'coverage_status' => 'failed']);
                $older->saveQuietly();
                $newest = $document->replicate();
                $newest->saveQuietly();
                foreach (['ocr_page', 'text_chunk'] as $unitType) {
                    AIAssistantDocumentUnit::query()->create([
                        'document_id' => $newest->id, 'unit_type' => $unitType, 'unit_index' => 0,
                        'text' => $body, 'checksum' => hash('sha256', $body),
                    ]);
                }
            }
        }
        $queries = 0;
        $cteCounts = [];
        DB::listen(static function ($event) use (&$queries, &$cteCounts): void {
            if (str_contains($event->sql, 'assistant_coverage_documents AS MATERIALIZED') && str_contains($event->sql, 'native_coverage_state')) {
                $queries++;
                $cteCounts[] = substr_count($event->sql, 'assistant_coverage_documents AS MATERIALIZED');
            }
        });
        $baseline = $this->read($fixture, $fixture->owner, countersOnly: false);
        self::assertSame(2, $queries);
        self::assertSame(9, $baseline['document_coverage']['total']);
        self::assertSame(1, $baseline['document_coverage']['ready']);
        self::assertSame(8, $baseline['native_attachment_coverage']['quality_defect_photo']['indexed_file_count']);
        self::assertSame(16, $baseline['document_coverage']['total_pages']);
        self::assertSame(2, $baseline['document_coverage']['processed_units']);
        self::assertSame(1, $baseline['document_coverage']['ocr_completed_pages']);
        foreach (['REPEATABLE READ READ ONLY', 'SERIALIZABLE READ ONLY'] as $mode) {
            $queries = 0;
            $cteCounts = [];
            self::assertSame($baseline, $this->read($fixture, $fixture->owner, mode: $mode, countersOnly: false));
            self::assertSame(1, $queries);
            self::assertSame([1], $cteCounts);
        }
        $queries = 0;
        self::assertSame($baseline, $this->read($fixture, $fixture->owner, mode: 'REPEATABLE READ READ WRITE', countersOnly: false));
        self::assertSame(2, $queries);
        self::assertSame(['document_coverage' => $baseline['document_coverage']], $this->read($fixture, $fixture->owner, mode: 'REPEATABLE READ READ ONLY'));
        $scoped = $this->read($fixture, $fixture->owner, ['project_id' => (int) $project->id], countersOnly: false);
        self::assertSame($scoped, $this->read($fixture, $fixture->owner, ['project_id' => (int) $project->id], 'REPEATABLE READ READ ONLY', false));
        $batch->updateQuietly(['stored_path' => 'org-'.$organizationId.'/crm/imports/'.\Illuminate\Support\Str::uuid().'.csv']);
        $changed = $this->read($fixture, $fixture->owner, countersOnly: false);
        self::assertSame(0, $changed['native_attachment_coverage']['crm_import_batch']['indexed_file_count']);
        self::assertSame(1, $changed['native_attachment_coverage']['crm_import_batch']['unmapped_file_count']);
        self::assertSame($changed, $this->read($fixture, $fixture->owner, mode: 'REPEATABLE READ READ ONLY', countersOnly: false));
        $denied = $this->read($fixture, $fixture->member, countersOnly: false);
        self::assertSame(0, $denied['document_coverage']['total']);
        self::assertSame($denied, $this->read($fixture, $fixture->member, mode: 'REPEATABLE READ READ ONLY', countersOnly: false));
        $proof = DB::transaction(function () use ($fixture): array {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');

            return app(\App\BusinessModules\Features\AIAssistant\Services\StatusSnapshots\AssistantStatusSnapshotFootprint::class)->capture(
                fn (): array => app(AssistantDocumentCoverageService::class)->coverage((int) $fixture->organization->id, $fixture->owner),
            );
        });
        self::assertSame($changed, $proof['value']);
        self::assertIsArray($proof['relations'], $proof['reason'] ?? '');
        foreach (['public.ai_assistant_documents', 'public.ai_assistant_document_units', 'public.crm_import_batches', 'public.quality_defect_photos'] as $relation) {
            self::assertContains($relation, $proof['relations']);
        }
    }

    public function test_read_only_snapshot_reuses_discovery_and_preserves_populated_project_and_alias_scope(): void
    {
        $fixture = $this->fixture();
        $projects = Project::withoutEvents(fn () => Project::factory()->count(2)->create(['organization_id' => $fixture->organization->id]));
        $originalMap = Relation::morphMap();
        Relation::morphMap(['coverage_project_alias' => Project::class, 'coverage_unused_alias' => Project::class], false);
        try {
            $readyFile = $this->file($fixture, $projects[0]);
            $this->file($fixture, $projects[0], 'coverage_project_alias');
            $this->file($fixture, $projects[0], 'coverage_unused_alias');
            $this->file($fixture, $projects[1]);
            $this->file($fixture, $projects[0], 'Unknown\\CoverageParent');
            AIAssistantDocument::withoutEvents(fn () => AIAssistantDocument::query()->create([
                'organization_id' => $fixture->organization->id, 'project_id' => $projects[0]->id, 'file_id' => $readyFile->id,
                'parent_entity_type' => 'project', 'parent_entity_id' => (string) $projects[0]->id,
                'storage_path' => $readyFile->path, 'filename' => $readyFile->name, 'mime_type' => $readyFile->mime_type,
                'checksum' => hash('sha256', $readyFile->path), 'size_bytes' => 7, 'status' => 'ready', 'extracted_text' => 'Prepared text',
            ]));
            $policy = app(AssistantDataAccessPolicy::class);
            $publicQuery = $policy->accessibleFiles($fixture->owner, (int) $fixture->organization->id, false);
            $candidates = $policy->accessibleFilesWithTypes($fixture->owner, (int) $fixture->organization->id, false);
            self::assertSame($publicQuery->toSql(), $candidates['query']->toSql());
            self::assertSame($publicQuery->getBindings(), $candidates['query']->getBindings());
            self::assertContains('coverage_unused_alias', $candidates['types']);
            self::assertContains('Unknown\\CoverageParent', $candidates['types']);
            $heavyQueries = 0;
            $this->watchTypeQueries($heavyQueries);
            $baseline = $this->read($fixture, $fixture->owner);
            self::assertGreaterThan(0, $heavyQueries);
            self::assertSame(3, $baseline['document_coverage']['total']);
            self::assertSame(1, $baseline['document_coverage']['ready']);
            $heavyQueries = 0;
            self::assertSame($baseline, $this->read($fixture, $fixture->owner, mode: 'REPEATABLE READ READ ONLY'));
            self::assertSame(0, $heavyQueries);
            $scope = ['project_id' => (int) $projects[0]->id];
            $scoped = $this->read($fixture, $fixture->owner, $scope);
            self::assertSame(2, $scoped['document_coverage']['total']);
            self::assertSame(1, $scoped['document_coverage']['ready']);
            $scopedDiscoveryQueries = $heavyQueries;
            self::assertGreaterThanOrEqual(2, $scopedDiscoveryQueries);
            $heavyQueries = 0;
            self::assertSame($scoped, $this->read($fixture, $fixture->owner, $scope, 'SERIALIZABLE READ ONLY'));
            self::assertSame($scopedDiscoveryQueries - 1, $heavyQueries);
        } finally {
            Relation::morphMap($originalMap, false);
        }
    }

    public function test_mutable_snapshots_and_null_or_empty_candidates_keep_the_original_discovery(): void
    {
        $fixture = $this->fixture();
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $fixture->organization->id]));
        $this->file($fixture, $project);
        $baseline = $this->read($fixture, $fixture->owner);
        self::assertSame(1, $baseline['document_coverage']['total']);
        $heavyQueries = 0;
        $this->watchTypeQueries($heavyQueries);
        self::assertSame($baseline, $this->read($fixture, $fixture->owner, mode: 'REPEATABLE READ READ WRITE'));
        self::assertGreaterThan(0, $heavyQueries);
        DB::transaction(function (): void {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            $method = new \ReflectionMethod(AssistantDocumentCoverageService::class, 'canReuseFileTypes');
            self::assertFalse($method->invoke(app(AssistantDocumentCoverageService::class), File::query(), [null]));
        });
        $candidate = $this->file($fixture, $project, '');
        try {
            $heavyQueries = 0;
            self::assertSame($baseline, $this->read($fixture, $fixture->owner, mode: 'REPEATABLE READ READ ONLY'));
            self::assertGreaterThan(0, $heavyQueries);
        } finally {
            $candidate->deleteQuietly();
        }
    }

    public function test_empty_and_denied_scopes_keep_identical_counts(): void
    {
        $fixture = $this->fixture();
        $empty = $this->read($fixture, $fixture->owner);
        self::assertSame(0, $empty['document_coverage']['total']);
        self::assertSame($empty, $this->read($fixture, $fixture->owner, mode: 'REPEATABLE READ READ ONLY'));
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $fixture->organization->id]));
        $this->file($fixture, $project);
        $denied = $this->read($fixture, $fixture->member);
        self::assertSame(0, $denied['document_coverage']['total']);
        self::assertSame($denied, $this->read($fixture, $fixture->member, mode: 'REPEATABLE READ READ ONLY'));
        $fixture->organization->users()->updateExistingPivot($fixture->owner->id, ['is_active' => false]);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException::class);
        $this->read($fixture, $fixture->owner, mode: 'REPEATABLE READ READ ONLY');
    }

    public function test_native_only_files_preserve_current_counters(): void
    {
        $fixture = $this->fixture();
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $fixture->organization->id]));
        Model::withoutEvents(function () use ($fixture, $project): void {
            $tender = Tender::query()->create(['organization_id' => $fixture->organization->id, 'project_id' => $project->id, 'number' => 'TYPE-DISCOVERY', 'title' => 'Tender']);
            TenderFile::query()->create(['tender_id' => $tender->id, 'category' => 'general', 'original_name' => 'native.pdf',
                'stored_path' => 'coverage-discovery/native.pdf', 'mime_type' => 'application/pdf', 'size' => 7, 'uploaded_at' => now()]);
        });
        $baseline = $this->read($fixture, $fixture->owner);
        self::assertSame(1, $baseline['document_coverage']['total']);
        self::assertSame($baseline, $this->read($fixture, $fixture->owner, mode: 'REPEATABLE READ READ ONLY'));
    }

    public function test_type_reuse_rejects_a_different_connection_even_in_a_read_only_snapshot(): void
    {
        $name = 'coverage_discovery_guard';
        $original = config('database.connections.'.$name);
        config(['database.connections.'.$name => DB::connection()->getConfig()]);
        try {
            $other = DB::connection($name);
            self::assertSame(DB::connection()->getDatabaseName(), $other->getDatabaseName());
            $other->transaction(function () use ($name, $other): void {
                $other->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
                $files = (new File)->setConnection($name)->newQuery();
                $method = new \ReflectionMethod(AssistantDocumentCoverageService::class, 'canReuseFileTypes');
                self::assertFalse($method->invoke(app(AssistantDocumentCoverageService::class), $files, [Project::class]));
            });
        } finally {
            DB::purge($name);
            config(['database.connections.'.$name => $original]);
        }
    }
}
