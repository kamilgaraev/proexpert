<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentCoverageService;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDesignFileAdapter;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantNativeFileAdapter;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantNativeFileMetadata;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantLegalNativeFileAdapter;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantLegalNativeFileMetadata;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifact;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentApprovedList;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentVersion;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentFile;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentAccessGrant;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\Project;
use App\Models\File;
use App\Services\Modules\PackageCatalogService;
use App\Services\Storage\FileService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantNativeMissingCoverageBatchTest extends TestCase
{
    public function beginDatabaseTransaction(): void
    {
        self::assertSame('pgsql', DB::connection()->getDriverName());
        self::assertMatchesRegularExpression('/^most_phpunit_[a-z0-9]+_testing$/i', DB::connection()->getDatabaseName());
    }

    public function test_native_missing_counts_share_one_read_only_snapshot_query_without_changing_coverage(): void
    {
        $profile = static function (string $phase): void {
            if (getenv('MOST_RAG_STORED_QUERY_PROFILE') === '1') {
                fwrite(STDERR, 'native_missing_phase='.json_encode(['phase' => $phase, 'memory_bytes' => memory_get_usage(true), 'peak_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR).PHP_EOL);
            }
        };
        $profile('fixture_start');
        Queue::fake();
        config(['cache.default' => 'array']);
        $storage = Mockery::mock(FileService::class)->makePartial();
        $storage->shouldNotReceive('readCurrentBounded');
        $storage->shouldNotReceive('headCurrent');
        $this->app->instance(FileService::class, $storage);
        $fixture = $this->fixture();
        $profile('fixture_ready');
        $read = function (string $mode, bool $countersOnly = false) use ($fixture, $profile): array {
            $profile('read_start_'.$mode.($countersOnly ? '_counters' : '_full'));
            $result = DB::transaction(function () use ($fixture, $mode, $countersOnly): array {
                if (! in_array($mode, ['READ COMMITTED READ WRITE', 'REPEATABLE READ READ WRITE', 'REPEATABLE READ READ ONLY', 'SERIALIZABLE READ ONLY'], true)) {
                    throw new \InvalidArgumentException;
                }
                DB::statement('SET TRANSACTION ISOLATION LEVEL '.$mode);
                return app(AssistantDataAccessPolicy::class)->withCurrentChecks($fixture->owner, (int) $fixture->organization->id,
                    fn (): array => app(AssistantDocumentCoverageService::class)->coverage((int) $fixture->organization->id, $fixture->owner, countersOnly: $countersOnly), fresh: true);
            });
            $profile('read_complete_'.$mode.($countersOnly ? '_counters' : '_full'));

            return $result;
        };
        $baseline = $read('READ COMMITTED READ WRITE');
        $types = ['design_artifact_version', 'workforce_export_package_file', 'legal_document_version', 'executive_version', 'executive_approved_list'];
        foreach ($types as $type) {
            self::assertSame(1, $baseline['native_attachment_coverage'][$type]['expected_file_count'], $type);
            self::assertSame(1, $baseline['native_attachment_coverage'][$type]['unmapped_file_count'], $type);
        }
        self::assertSame(5, $baseline['document_coverage']['needs_access_review']);
        self::assertSame($baseline, $read('REPEATABLE READ READ WRITE'));
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $snapshot = $read('REPEATABLE READ READ ONLY');
            $queries = array_values(array_filter(DB::getQueryLog(), static fn (array $query): bool =>
                str_contains($query['query'], 'assistant_native_mapping_counts')
                || preg_match('/^select count\(\*\) as aggregate from "(?:design_artifact_versions|workforce_export_package_files|legal_archive_document_versions|executive_document_versions|executive_document_approved_lists)"/i', $query['query']) === 1));
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        self::assertSame($baseline, $snapshot);
        self::assertSame(['document_coverage' => $baseline['document_coverage']], $read('REPEATABLE READ READ ONLY', true));
        self::assertSame($baseline, $read('SERIALIZABLE READ ONLY'));
        fwrite(STDERR, 'native_missing_coverage_synthetic='.json_encode(['types' => count($types), 'count_queries' => count($queries)], JSON_THROW_ON_ERROR).PHP_EOL);
        self::assertCount(1, $queries, 'Expected and unmapped native attachment counts must share one query in a proven immutable snapshot.');
    }

    public function test_deadline_exception_after_native_compilation_prevents_the_batch_select(): void
    {
        Queue::fake();
        config(['cache.default' => 'array']);
        $fixture = $this->fixture();
        $compiled = false;
        $scopes = ExecutiveDocumentApprovedList::getAllGlobalScopes();
        $sentinel = new \RuntimeException('synthetic_native_deadline');
        ExecutiveDocumentApprovedList::addGlobalScope('synthetic_native_compiled', static function (\Illuminate\Database\Eloquent\Builder $query) use (&$compiled): void {
            foreach ($query->getQuery()->columns ?? [] as $column) {
                if ($column instanceof \Illuminate\Contracts\Database\Query\Expression
                    && str_contains($column->getValue($query->getQuery()->getGrammar()), 'AS expected_count')) {
                    $compiled = true;
                }
            }
        });
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            try {
                DB::transaction(function () use ($fixture, &$compiled, $sentinel): void {
                    DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
                    app(AssistantDataAccessPolicy::class)->withCurrentChecks($fixture->owner, (int) $fixture->organization->id,
                        function () use ($fixture, &$compiled, $sentinel): array {
                            return app(AssistantDocumentCoverageService::class)->coverage((int) $fixture->organization->id, $fixture->owner,
                                checkDeadline: static function () use (&$compiled, $sentinel): void { if ($compiled) { throw $sentinel; } }, countersOnly: true);
                        }, fresh: true);
                });
                self::fail('The deadline exception must propagate.');
            } catch (\RuntimeException $exception) {
                self::assertSame($sentinel, $exception);
            }
            self::assertTrue($compiled);
            self::assertSame([], array_values(array_filter(DB::getQueryLog(), static fn (array $query): bool => str_contains($query['query'], 'assistant_native_mapping_counts'))));
        } finally {
            ExecutiveDocumentApprovedList::setAllGlobalScopes($scopes);
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    public function test_current_alias_typed_collisions_and_revoked_access_match_the_mutable_path(): void
    {
        Queue::fake();
        config(['cache.default' => 'array']);
        $storage = Mockery::mock(FileService::class)->makePartial();
        $storage->shouldNotReceive('readCurrentBounded');
        $storage->shouldNotReceive('headCurrent');
        $this->app->instance(FileService::class, $storage);
        $fixture = $this->fixture();
        $organizationId = (int) $fixture->organization->id;
        $permissions = ['ai-assistant' => ['ai_assistant.chat'], 'project-management' => ['projects.view'],
            'design-management' => ['design-management.view', 'design-management.documents.view'],
            'workforce-management' => ['workforce.view', 'workforce.payroll-source.manage', 'workforce.exports.generate'],
            'executive-documentation' => ['executive-documentation.view']];
        $systemPermissions = ['finance.view', 'legal_archive.view', 'legal_archive.files.view'];
        $fixture->memberRole->update(['module_permissions' => $permissions, 'system_permissions' => $systemPermissions]);
        $design = DesignArtifactVersion::query()->where('organization_id', $organizationId)->sole();
        $legal = LegalArchiveDocumentVersion::query()->where('organization_id', $organizationId)->sole();
        $projectId = (int) $design->project_id;
        $fixture->member->assignedProjects()->attach($projectId, ['is_active' => true, 'role' => 'member']);
        $legal->document->updateQuietly(['primary_project_id' => $projectId]);
        $grant = LegalDocumentAccessGrant::query()->where('organization_id', $organizationId)->sole()->replicate();
        $grant->subject_user_id = $fixture->member->id;
        $grant->saveQuietly();
        foreach (array_chunk($this->mappingRows($fixture), 200) as $chunk) { DB::table('files')->insert($chunk); }
        $deniedProject = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $organizationId, 'is_archived' => false]));
        $list = ExecutiveDocumentApprovedList::query()->where('organization_id', $organizationId)->sole();
        $denied = $list->replicate()->forceFill(['project_id' => $deniedProject->id, 'revision' => '2',
            'file_url' => 'org-'.$organizationId.'/executive-documentation/project-'.$deniedProject->id.'/approved-lists/12345678-1234-1234-1234-123456789abc.pdf']);
        $denied->saveQuietly();
        $foreignProject = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $fixture->foreignOrganization->id, 'is_archived' => false]));
        $foreign = $list->replicate()->forceFill(['organization_id' => $fixture->foreignOrganization->id, 'project_id' => $foreignProject->id, 'revision' => '3',
            'uploaded_by' => $fixture->foreignOwner->id,
            'file_url' => 'org-'.$fixture->foreignOrganization->id.'/executive-documentation/project-'.$foreignProject->id.'/approved-lists/12345678-1234-1234-1234-123456789abc.pdf']);
        $foreign->saveQuietly();
        $sharedId = max((int) DesignArtifactVersion::query()->max('id'), (int) LegalArchiveDocumentVersion::query()->max('id')) + 10000;
        $collisionDesign = $design->replicate()->forceFill(['id' => $sharedId, 'version_number' => '2',
            'source_file_path' => 'org-'.$organizationId.'/pir/projects/'.$projectId.'/packages/'.$design->artifact->package_id.'/documents/'.$sharedId.'/source/drawing.pdf']);
        $collisionDesign->saveQuietly();
        $collisionLegal = $legal->replicate()->forceFill(['id' => $sharedId, 'version_number' => '2', 'is_current' => false]);
        $collisionLegal->saveQuietly();
        foreach ([$collisionDesign, $collisionLegal] as $collision) {
            $table = $collision->getTable();
            DB::selectOne("SELECT pg_catalog.setval(pg_catalog.pg_get_serial_sequence(?, 'id'), GREATEST(?::bigint,
                COALESCE((SELECT last_value FROM pg_catalog.pg_sequences WHERE pg_catalog.format('%I.%I', schemaname, sequencename) = pg_catalog.pg_get_serial_sequence(?, 'id')), 0)), true)", [$table, $sharedId, $table]);
        }
        self::assertSame($collisionDesign->getKey(), $collisionLegal->getKey());
        $morphMap = Relation::morphMap();
        try {
            Relation::morphMap(['synthetic_current_design' => DesignArtifactVersion::class] + $morphMap, false);
            self::assertSame('synthetic_current_design', $collisionDesign->getMorphClass());
            self::assertSame('design_artifact_version', app(AssistantDataAccessPolicy::class)->entityTypeForModel('synthetic_current_design'));
            $file = app(AssistantDesignFileAdapter::class)->map($fixture->owner, $organizationId, $sharedId, $collisionDesign->source_file_path);
            self::assertSame('synthetic_current_design', $file->fileable_type);
            $readFiles = function (bool $partitionTypes) use ($fixture, $organizationId): array {
                return app(AssistantDataAccessPolicy::class)->withCurrentChecks($fixture->member, $organizationId,
                    function () use ($fixture, $organizationId, $partitionTypes): array {
                        $result = app(AssistantDataAccessPolicy::class)->accessibleFilesWithTypes($fixture->member, $organizationId, false, $partitionTypes);
                        $types = $result['types'];
                        sort($types, SORT_REGULAR);
                        return ['types' => $types, 'rows' => $result['query']->orderBy('files.id')->get()
                            ->map(static fn (File $file): array => $file->getRawOriginal())->all()];
                    }, fresh: true);
            };
            $read = function (string $mode, ?int $scopeProjectId = null) use ($fixture, $organizationId): array {
                return DB::transaction(function () use ($fixture, $organizationId, $mode, $scopeProjectId): array {
                    self::assertContains($mode, ['READ COMMITTED READ WRITE', 'REPEATABLE READ READ ONLY']);
                    DB::statement('SET TRANSACTION ISOLATION LEVEL '.$mode);
                    return app(AssistantDataAccessPolicy::class)->withCurrentChecks($fixture->member, $organizationId,
                        fn (): array => app(AssistantDocumentCoverageService::class)->coverage($organizationId, $fixture->member,
                            scope: $scopeProjectId === null ? [] : ['project_id' => $scopeProjectId]), fresh: true);
                });
            };
            $compare = function (?int $scopeProjectId = null) use ($read, $readFiles): array {
                self::assertSame($readFiles(false), $readFiles(true));
                $baseline = $read('READ COMMITTED READ WRITE', $scopeProjectId);
                self::assertSame($baseline, $read('REPEATABLE READ READ ONLY', $scopeProjectId));
                return $baseline;
            };
            $coverage = $compare();
            self::assertSame(2, $coverage['native_attachment_coverage']['design_artifact_version']['expected_file_count']);
            self::assertSame(0, $coverage['native_attachment_coverage']['design_artifact_version']['unmapped_file_count']);
            self::assertSame(2, $coverage['native_attachment_coverage']['legal_document_version']['expected_file_count']);
            self::assertSame(1, $coverage['native_attachment_coverage']['legal_document_version']['unmapped_file_count']);
            self::assertArrayNotHasKey('workforce_export_package_file', $coverage['native_attachment_coverage']);
            foreach (['executive_version', 'executive_approved_list'] as $type) {
                self::assertSame(1, $coverage['native_attachment_coverage'][$type]['expected_file_count']);
                self::assertSame(0, $coverage['native_attachment_coverage'][$type]['unmapped_file_count']);
            }
            $source = (array) AssistantLegalNativeFileMetadata::sourceQuery('executive_approved_list', $organizationId)
                ->where('native_source.id', $denied->id)->select(AssistantLegalNativeFileMetadata::sourceColumns('executive_approved_list'))->sole();
            $version = AssistantLegalNativeFileMetadata::versionData('executive_approved_list', $source);
            $deniedMapping = File::query()->where('organization_id', $organizationId)->where('fileable_type', ExecutiveDocumentApprovedList::class)->sole()->replicate();
            $deniedMapping->forceFill(['fileable_id' => $denied->id, 'path' => $denied->file_url, 'additional_info' => [
                'assistant_native_source' => AssistantLegalNativeFileMetadata::SOURCE, 'native_entity_type' => 'executive_approved_list',
                'native_source_fields' => $version, 'native_source_version' => AssistantLegalNativeFileMetadata::fingerprint($version),
                'native_source_sha256' => hash('sha256', 'Synthetic native document')]])->saveQuietly();
            app(AssistantLegalNativeFileAdapter::class)->assertMapping($deniedMapping);
            $fixture->member->assignedProjects()->attach($deniedProject->id, ['is_active' => true, 'role' => 'member']);
            $allProjects = $compare()['native_attachment_coverage'];
            self::assertSame(2, $allProjects['executive_approved_list']['expected_file_count']);
            self::assertSame(1, $allProjects['workforce_export_package_file']['expected_file_count']);
            self::assertSame(0, $allProjects['workforce_export_package_file']['unmapped_file_count']);
            $firstProject = $compare($projectId)['native_attachment_coverage'];
            self::assertSame(1, $firstProject['executive_approved_list']['expected_file_count']);
            self::assertSame(2, $firstProject['design_artifact_version']['expected_file_count']);
            self::assertSame(0, $firstProject['design_artifact_version']['unmapped_file_count']);
            $otherProject = $compare((int) $deniedProject->id)['native_attachment_coverage'];
            self::assertSame(['executive_approved_list'], array_keys($otherProject));
            self::assertSame(1, $otherProject['executive_approved_list']['expected_file_count']);
            self::assertSame(0, $otherProject['executive_approved_list']['unmapped_file_count']);
            $fixture->member->assignedProjects()->detach($deniedProject->id);
            $file->updateQuietly(['fileable_type' => 'synthetic_unregistered_design']);
            self::assertSame(1, $compare()['native_attachment_coverage']['design_artifact_version']['unmapped_file_count']);
            $file->updateQuietly(['fileable_type' => 'synthetic_current_design']);
            $executiveFile = File::query()->where('organization_id', $organizationId)->where('fileable_type', ExecutiveDocumentVersion::class)->sole();
            $original = $executiveFile->getAttributes();
            $metadata = $executiveFile->additional_info;
            $metadata['native_source_fields']['content_hash'] = str_repeat('0', 64);
            foreach (['path' => 'synthetic-stale.pdf', 'mime_type' => 'application/x-synthetic', 'additional_info' => $metadata] as $field => $value) {
                $executiveFile->updateQuietly([$field => $value]);
                self::assertSame(1, $compare()['native_attachment_coverage']['executive_version']['unmapped_file_count']);
                $executiveFile->setRawAttributes($original)->saveQuietly();
            }
            $grant->updateQuietly(['revoked_at' => now(), 'revoked_by_user_id' => $fixture->owner->id, 'revocation_reason' => 'Synthetic revoke']);
            self::assertArrayNotHasKey('legal_document_version', $compare()['native_attachment_coverage']);
            $grant->replicate()->forceFill(['revoked_at' => null, 'revoked_by_user_id' => null, 'revocation_reason' => null])->saveQuietly();
            self::assertSame(2, $compare()['native_attachment_coverage']['legal_document_version']['expected_file_count']);
            $fixture->memberRole->update(['system_permissions' => ['finance.view', 'legal_archive.view']]);
            self::assertArrayNotHasKey('legal_document_version', $compare()['native_attachment_coverage']);
            $fixture->memberRole->update(['system_permissions' => $systemPermissions]);
            $fixture->member->assignedProjects()->detach($projectId);
            self::assertSame([], $compare()['native_attachment_coverage']);
        } finally {
            Relation::morphMap($morphMap, false);
        }
    }

    public function test_dense_current_mappings_keep_typed_identity_duplicates_and_bounded_materialization(): void
    {
        $fixtureDeadline = hrtime(true) + 120_000_000_000;
        $fixtureCheckpoint = static function () use ($fixtureDeadline): void {
            if (hrtime(true) > $fixtureDeadline) { throw new \RuntimeException('native_mapping_fixture_budget_exceeded'); }
        };
        Queue::fake();
        config(['cache.default' => 'array']);
        $storage = Mockery::mock(FileService::class)->makePartial();
        $storage->shouldNotReceive('readCurrentBounded');
        $storage->shouldNotReceive('headCurrent');
        $this->app->instance(FileService::class, $storage);
        $fixture = $this->fixture();
        $fixtureCheckpoint();
        $organizationId = (int) $fixture->organization->id;
        $actorId = (int) $fixture->owner->id;
        $seedMappings = $this->mappingRows($fixture);
        self::assertCount(5, $seedMappings);
        self::assertCount(5, array_unique(array_column($seedMappings, 'fileable_type')));
        $seedIds = [];
        foreach ($seedMappings as $mapping) { $seedIds[] = DB::table('files')->insertGetId($mapping); }
        $fixtureCheckpoint();
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            DB::transaction(function () use ($fixture, $organizationId, $fixtureCheckpoint): void {
                DB::statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED READ WRITE');
                DB::statement("SET LOCAL statement_timeout = '8s'");
                app(AssistantDataAccessPolicy::class)->withCurrentChecks($fixture->owner, $organizationId,
                    fn (): array => app(AssistantDocumentCoverageService::class)->coverage($organizationId, $fixture->owner,
                        checkpoint: $fixtureCheckpoint, checkDeadline: $fixtureCheckpoint), fresh: true, checkpoint: $fixtureCheckpoint);
            });
            $baselineQueries = array_values(array_filter(DB::getQueryLog(), static fn (array $query): bool =>
                preg_match('/^select count\(\*\) as aggregate from "(?:design_artifact_versions|workforce_export_package_files|legal_archive_document_versions|executive_document_versions|executive_document_approved_lists)"/i', $query['query']) === 1));
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        self::assertCount(10, $baselineQueries);
        self::assertSame(5, DB::table('files')->where('organization_id', $organizationId)->whereIn('id', $seedIds)->delete());
        $fixtureCheckpoint();
        $design = DesignArtifactVersion::query()->where('organization_id', $organizationId)->sole();
        $template = $design->getAttributes();
        unset($template['id']);
        $template['uploaded_by'] = $actorId;
        $template['source_file_path'] = 'org-'.$organizationId.'/pir/projects/'.$design->project_id.'/packages/'.$design->artifact->package_id.'/model-uploads/user-'.$actorId.'/synthetic-dense/source/drawing.pdf';
        $additionalNativeCount = 20_000;
        $expectedRows = $additionalNativeCount + 5;
        $rows = [];
        for ($i = 1; $i <= $additionalNativeCount; $i++) {
            $rows[] = array_replace($template, ['version_number' => (string) (1000 + $i), 'title' => 'Synthetic dense '.$i]);
        }
        foreach (array_chunk($rows, 200) as $chunk) { $fixtureCheckpoint(); DB::table('design_artifact_versions')->insert($chunk); }
        $mappings = $this->mappingRows($fixture);
        $fixtureCheckpoint();
        foreach (array_chunk($mappings, 200) as $chunk) { $fixtureCheckpoint(); DB::table('files')->insert($chunk); }
        $files = File::query()->where('organization_id', $organizationId)->get();
        foreach ($files->take(5) as $file) {
            if ($file->fileable_type === $design::class) { app(AssistantDesignFileAdapter::class)->assertMapping($file); }
        }
        foreach ([AssistantNativeFileMetadata::MODEL => AssistantNativeFileAdapter::class,
            ...array_fill_keys(array_column(AssistantLegalNativeFileMetadata::definitions(), 'model'), AssistantLegalNativeFileAdapter::class)] as $model => $adapter) {
            app($adapter)->assertMapping($files->firstWhere('fileable_type', $model));
        }
        $fixtureCheckpoint();
        DB::transaction(function (): void {
            self::assertMatchesRegularExpression('/^most_phpunit_[a-z0-9]+_testing$/i', DB::connection()->getDatabaseName());
            DB::statement("SET LOCAL statement_timeout = '8s'");
            DB::statement('ANALYZE files, design_artifact_versions, design_artifacts, design_packages, projects,
                executive_document_versions, executive_documents, executive_document_sets, executive_document_approved_lists,
                legal_archive_document_versions, legal_archive_documents, legal_archive_document_files, legal_document_access_grants,
                workforce_export_package_files, workforce_export_packages, workforce_payroll_periods,
                users, organizations, organization_user, project_user, user_role_assignments, organization_custom_roles');
        });
        $fixtureCheckpoint();
        $acquisitionDeadline = hrtime(true) + 60_000_000_000;
        $deadline = static function () use ($acquisitionDeadline): void {
            if (hrtime(true) > $acquisitionDeadline) { throw new \RuntimeException('native_mapping_acquisition_budget_exceeded'); }
        };
        $read = function (string $mode, bool $countersOnly = false) use ($fixture, $organizationId, $deadline): array {
            $deadline();
            $result = DB::transaction(function () use ($fixture, $organizationId, $mode, $countersOnly, $deadline): array {
                self::assertSame('REPEATABLE READ READ ONLY', $mode);
                DB::statement('SET TRANSACTION ISOLATION LEVEL '.$mode);
                DB::statement("SET LOCAL statement_timeout = '8s'");
                return app(AssistantDataAccessPolicy::class)->withCurrentChecks($fixture->owner, $organizationId,
                    fn (): array => app(AssistantDocumentCoverageService::class)->coverage($organizationId, $fixture->owner,
                        checkpoint: $deadline, checkDeadline: $deadline, countersOnly: $countersOnly), fresh: true, checkpoint: $deadline);
            });
            $deadline();
            return $result;
        };
        $baselineCounts = ['design_artifact_version' => [$additionalNativeCount + 1, intdiv($additionalNativeCount, 2)]];
        foreach (['workforce_export_package_file', ...AssistantLegalNativeFileMetadata::types()] as $type) {
            $baselineCounts[$type] = [1, 0];
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $started = hrtime(true);
        try {
            $baseline = $read('REPEATABLE READ READ ONLY');
            $snapshotMs = (hrtime(true) - $started) / 1_000_000;
            $queries = array_values(array_filter(DB::getQueryLog(), static fn (array $query): bool => str_contains($query['query'], 'assistant_native_mapping_counts')));
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        self::assertCount(1, $queries);
        foreach ($baselineCounts as $type => [$expected, $missing]) {
            self::assertSame($expected, $baseline['native_attachment_coverage'][$type]['expected_file_count']);
            self::assertSame($missing, $baseline['native_attachment_coverage'][$type]['unmapped_file_count']);
        }
        self::assertSame(['document_coverage' => $baseline['document_coverage']], $read('REPEATABLE READ READ ONLY', true));
        DB::transaction(function () use ($queries, $snapshotMs, $expectedRows): void {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            DB::statement("SET LOCAL statement_timeout = '8s'");
            $settings = DB::selectOne("SELECT current_setting('jit') AS jit, current_setting('work_mem') AS work_mem");
            $budgetStarted = hrtime(true);
            $plan = json_decode(DB::selectOne('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$queries[0]['query'], $queries[0]['bindings'])->{'QUERY PLAN'}, true, flags: JSON_THROW_ON_ERROR);
            $nodes = [];
            $walk = function (array $node) use (&$walk, &$nodes): void {
                $nodes[] = $node;
                foreach ($node['Plans'] ?? [] as $child) { $walk($child); }
            };
            $walk($plan[0]['Plan']);
            $materialized = array_values(array_filter($nodes, static fn (array $node): bool => ($node['Subplan Name'] ?? null) === 'CTE assistant_coverage_mapped_files'));
            self::assertCount(1, $materialized);
            self::assertSame(1, $materialized[0]['Actual Loops']);
            self::assertSame($expectedRows, $materialized[0]['Actual Rows']);
            $scans = array_values(array_filter($nodes, static fn (array $node): bool => ($node['CTE Name'] ?? null) === 'assistant_coverage_mapped_files'));
            self::assertCount(5, $scans);
            $scannedRows = 0;
            foreach ($scans as $scan) {
                self::assertLessThanOrEqual(1, $scan['Actual Loops']);
                $scannedRows += ($scan['Actual Rows'] + ($scan['Rows Removed by Filter'] ?? 0)) * $scan['Actual Loops'];
            }
            self::assertLessThanOrEqual($expectedRows * 5, $scannedRows);
            $maxSubplanLoops = 0;
            foreach ($nodes as $node) {
                if (($node['Parent Relationship'] ?? null) === 'SubPlan') { $maxSubplanLoops = max($maxSubplanLoops, $node['Actual Loops']); }
            }
            if ((hrtime(true) - $budgetStarted) / 1_000_000_000 > 60) { throw new \RuntimeException('native_mapping_explain_budget_exceeded'); }
            fwrite(STDERR, 'native_missing_dense_plan='.json_encode(['native_rows' => $expectedRows, 'mapped_rows' => $expectedRows, 'mapping_query_ms' => $queries[0]['time'],
                'snapshot_pipeline_ms' => $snapshotMs,
                'default_jit' => $settings->jit, 'default_work_mem' => $settings->work_mem,
                'planning_ms' => $plan[0]['Planning Time'], 'execution_ms' => $plan[0]['Execution Time'], 'jit_functions' => $plan[0]['JIT']['Functions'] ?? 0,
                'jit_total_ms' => $plan[0]['JIT']['Timing']['Total'] ?? 0, 'max_subplan_loops' => $maxSubplanLoops,
                'cte_estimated_rows' => $materialized[0]['Plan Rows'], 'cte_actual_rows' => $materialized[0]['Actual Rows'],
                'cte_scanned_rows' => $scannedRows, 'cte_scans' => count($scans), 'shared_hit_blocks' => $plan[0]['Plan']['Shared Hit Blocks'] ?? 0,
                'shared_read_blocks' => $plan[0]['Plan']['Shared Read Blocks'] ?? 0, 'temp_read_blocks' => $plan[0]['Plan']['Temp Read Blocks'] ?? 0,
                'temp_written_blocks' => $plan[0]['Plan']['Temp Written Blocks'] ?? 0], JSON_THROW_ON_ERROR).PHP_EOL);
            DB::statement("SET LOCAL work_mem = '16MB'");
            if ((hrtime(true) - $budgetStarted) / 1_000_000_000 > 60) { throw new \RuntimeException('native_mapping_explain_budget_exceeded'); }
            $memoryPlan = json_decode(DB::selectOne('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$queries[0]['query'], $queries[0]['bindings'])->{'QUERY PLAN'}, true, flags: JSON_THROW_ON_ERROR);
            $nodes = [];
            $walk($memoryPlan[0]['Plan']);
            $memoryMaterialized = array_values(array_filter($nodes, static fn (array $node): bool => ($node['Subplan Name'] ?? null) === 'CTE assistant_coverage_mapped_files'));
            self::assertCount(1, $memoryMaterialized);
            self::assertSame(1, $memoryMaterialized[0]['Actual Loops']);
            self::assertSame($expectedRows, $memoryMaterialized[0]['Actual Rows']);
            $memoryScans = array_values(array_filter($nodes, static fn (array $node): bool => ($node['CTE Name'] ?? null) === 'assistant_coverage_mapped_files'));
            self::assertCount(5, $memoryScans);
            $memoryVisits = 0;
            foreach ($memoryScans as $scan) {
                self::assertLessThanOrEqual(1, $scan['Actual Loops']);
                $memoryVisits += ($scan['Actual Rows'] + ($scan['Rows Removed by Filter'] ?? 0)) * $scan['Actual Loops'];
            }
            self::assertLessThanOrEqual($expectedRows * 5, $memoryVisits);
            fwrite(STDERR, 'native_missing_memory_factor='.json_encode(['jit' => $settings->jit, 'work_mem' => '16MB',
                'planning_ms' => $memoryPlan[0]['Planning Time'], 'execution_ms' => $memoryPlan[0]['Execution Time'],
                'cte_rows' => $memoryMaterialized[0]['Actual Rows'], 'cte_scans' => count($memoryScans), 'cte_row_visits' => $memoryVisits,
                'temp_read_blocks' => $memoryPlan[0]['Plan']['Temp Read Blocks'] ?? 0,
                'temp_written_blocks' => $memoryPlan[0]['Plan']['Temp Written Blocks'] ?? 0], JSON_THROW_ON_ERROR).PHP_EOL);
            self::assertSame(0, $memoryPlan[0]['Plan']['Temp Read Blocks'] ?? 0);
            self::assertSame(0, $memoryPlan[0]['Plan']['Temp Written Blocks'] ?? 0);
            if ((hrtime(true) - $budgetStarted) / 1_000_000_000 > 60) { throw new \RuntimeException('native_mapping_explain_budget_exceeded'); }
        });
    }

    private function mappingRows(AssistantRealAuthorizationFixture $fixture): array
    {
        $organizationId = (int) $fixture->organization->id;
        $base = ['organization_id' => $organizationId, 'user_id' => $fixture->owner->id, 'disk' => 's3', 'type' => 'document', 'category' => 'ai_assistant', 'created_at' => now(), 'updated_at' => now()];
        $rows = [];
        foreach (DesignArtifactVersion::query()->where('organization_id', $organizationId)->orderBy('id')->get() as $index => $version) {
            if ($index % 2 === 1) { continue; }
            $fields = array_intersect_key($version->getAttributes(), array_flip(['source_file_path', 'source_original_name', 'source_mime_type', 'source_size_bytes', 'source_sha256']));
            $row = $base + ['fileable_type' => $version::class, 'fileable_id' => $version->id, 'path' => $version->source_file_path,
                'name' => $version->source_original_name, 'original_name' => $version->source_original_name, 'mime_type' => $version->source_mime_type, 'size' => $version->source_size_bytes,
                'additional_info' => json_encode(['assistant_native_source' => 'design', 'design_source_version' => hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR)), 'design_source_sha256' => $version->source_sha256], JSON_THROW_ON_ERROR)];
            $rows[] = $row;
            if ($index > 0) { $rows[] = $row; }
        }
        $source = (array) DB::table('workforce_export_package_files')->where('organization_id', $organizationId)->sole();
        $package = (array) DB::table('workforce_export_packages')->find($source['export_package_id']);
        $period = (array) DB::table('workforce_payroll_periods')->find($package['payroll_period_id']);
        AssistantNativeFileMetadata::assertSource($source, $package, $period);
        $version = AssistantNativeFileMetadata::versionData($source, $package, $period);
        $rows[] = $base + ['fileable_type' => AssistantNativeFileMetadata::MODEL, 'fileable_id' => $source['id'], 'path' => $source['storage_path'],
            'name' => $source['file_name'], 'original_name' => $source['file_name'], 'mime_type' => AssistantNativeFileMetadata::formats()[$source['file_type']][1], 'size' => $source['size_bytes'],
            'additional_info' => json_encode(['assistant_native_source' => AssistantNativeFileMetadata::SOURCE, 'native_entity_type' => AssistantNativeFileMetadata::ENTITY_TYPE,
                'native_source_fields' => $version, 'native_source_version' => AssistantNativeFileMetadata::fingerprint($version), 'native_source_sha256' => hash('sha256', 'Synthetic native document')], JSON_THROW_ON_ERROR)];
        foreach (AssistantLegalNativeFileMetadata::definitions() as $type => $definition) {
            $source = (array) AssistantLegalNativeFileMetadata::sourceQuery($type, $organizationId)->select(AssistantLegalNativeFileMetadata::sourceColumns($type))->sole();
            AssistantLegalNativeFileMetadata::assertSource($type, $source);
            $version = AssistantLegalNativeFileMetadata::versionData($type, $source);
            $rows[] = $base + ['fileable_type' => $definition['model'], 'fileable_id' => $source['id'], 'path' => $source[$definition['path']],
                'name' => AssistantLegalNativeFileMetadata::filename($type, $source), 'original_name' => AssistantLegalNativeFileMetadata::filename($type, $source),
                'mime_type' => AssistantLegalNativeFileMetadata::mime($type, $source), 'size' => 25,
                'additional_info' => json_encode(['assistant_native_source' => AssistantLegalNativeFileMetadata::SOURCE, 'native_entity_type' => $type,
                    'native_source_fields' => $version, 'native_source_version' => AssistantLegalNativeFileMetadata::fingerprint($version), 'native_source_sha256' => hash('sha256', 'Synthetic native document')], JSON_THROW_ON_ERROR)];
        }
        return $rows;
    }

    private function fixture(): AssistantRealAuthorizationFixture
    {
        return Model::withoutEvents(function (): AssistantRealAuthorizationFixture {
            $fixture = AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug'));
            $organizationId = (int) $fixture->organization->id;
            $actorId = (int) $fixture->owner->id;
            $project = Project::factory()->create(['organization_id' => $organizationId, 'is_archived' => false]);
            $role = OrganizationCustomRole::query()->create([
                'organization_id' => $organizationId, 'name' => 'Synthetic finance source', 'slug' => 'native_counts_finance_'.$organizationId,
                'system_permissions' => ['finance.view'], 'module_permissions' => [], 'interface_access' => ['admin', 'lk', 'mobile'],
                'is_active' => true, 'created_by' => $actorId,
            ]);
            $fixture->owner->roleAssignments()->create([
                'role_slug' => $role->slug, 'role_type' => UserRoleAssignment::TYPE_CUSTOM,
                'context_id' => $fixture->ownerAssignment->context_id, 'assigned_by' => $actorId, 'is_active' => true,
            ]);
            $hash = hash('sha256', 'Synthetic native document');
            $package = DesignPackage::query()->create(['organization_id' => $organizationId, 'project_id' => $project->id, 'title' => 'Synthetic package', 'status' => 'draft']);
            $artifact = DesignArtifact::query()->create(['organization_id' => $organizationId, 'project_id' => $project->id, 'package_id' => $package->id, 'title' => 'Synthetic drawing', 'artifact_type' => 'drawing_set']);
            $version = DesignArtifactVersion::query()->create([
                'organization_id' => $organizationId, 'project_id' => $project->id, 'artifact_id' => $artifact->id,
                'title' => 'Synthetic version', 'version_number' => '1', 'source_format' => 'pdf', 'file_format' => 'pdf',
                'source_file_path' => 'pending', 'source_original_name' => 'drawing.pdf', 'source_mime_type' => 'application/pdf', 'source_size_bytes' => 25, 'source_sha256' => $hash,
            ]);
            $version->updateQuietly(['source_file_path' => 'org-'.$organizationId.'/pir/projects/'.$project->id.'/packages/'.$package->id.'/documents/'.$version->id.'/source/drawing.pdf']);
            $set = ExecutiveDocumentSet::query()->create(['organization_id' => $organizationId, 'project_id' => $project->id, 'set_number' => 'NATIVE-COUNTS', 'title' => 'Synthetic set', 'status' => 'draft']);
            $document = ExecutiveDocument::query()->create(['organization_id' => $organizationId, 'project_id' => $project->id, 'document_set_id' => $set->id, 'document_type' => 'hidden_work_act', 'title' => 'Synthetic act', 'status' => 'draft']);
            $prefix = 'org-'.$organizationId.'/executive-documentation/project-'.$project->id.'/';
            ExecutiveDocumentVersion::query()->create([
                'organization_id' => $organizationId, 'document_id' => $document->id, 'uploaded_by' => $actorId, 'version_number' => '1', 'status' => 'draft',
                'file_url' => $prefix.'set-'.$set->id.'/12345678-1234-1234-1234-123456789abc.pdf', 'content_hash' => $hash,
            ]);
            ExecutiveDocumentApprovedList::query()->create([
                'organization_id' => $organizationId, 'project_id' => $project->id, 'revision' => '1', 'uploaded_by' => $actorId,
                'approved_by_party' => 'Synthetic contractor', 'approved_at' => '2026-09-29',
                'file_url' => $prefix.'approved-lists/12345678-1234-1234-1234-123456789abc.pdf', 'file_hash' => $hash, 'original_name' => 'approved.pdf', 'items' => [],
            ]);
            $legal = LegalArchiveDocument::query()->create([
                'organization_id' => $organizationId, 'title' => 'Synthetic agreement', 'document_type' => 'nda', 'status' => 'draft',
                'lifecycle_status' => 'draft', 'approval_status' => 'not_started', 'signature_status' => 'unsigned', 'confidentiality_level' => 'restricted', 'lock_version' => 0,
            ]);
            $legalFile = LegalArchiveDocumentFile::query()->create(['organization_id' => $organizationId, 'document_id' => $legal->id, 'role' => 'primary', 'title' => 'Synthetic file', 'sort_order' => 0, 'is_required' => true]);
            LegalArchiveDocumentVersion::query()->create([
                'organization_id' => $organizationId, 'document_id' => $legal->id, 'document_file_id' => $legalFile->id, 'uploaded_by_user_id' => $actorId,
                'version_number' => '1', 'is_current' => true, 'status' => 'uploaded', 'processing_status' => 'ready',
                'file_path' => 'org-'.$organizationId.'/legal-archive/files/'.$legalFile->id.'/versions/12345678-1234-1234-1234-123456789abc.pdf',
                'original_filename' => 'agreement.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 25, 'content_hash' => $hash,
            ]);
            LegalDocumentAccessGrant::query()->create([
                'organization_id' => $organizationId, 'document_id' => $legal->id, 'subject_kind' => 'internal_user', 'subject_organization_id' => $organizationId,
                'subject_user_id' => $actorId, 'abilities' => ['view'], 'granted_by_user_id' => $actorId,
            ]);
            $periodId = DB::table('workforce_payroll_periods')->insertGetId(['organization_id' => $organizationId, 'project_id' => $project->id, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'status' => 'locked', 'created_at' => now(), 'updated_at' => now()]);
            $key = '20260929140000-0123456789abcdef';
            $exportId = DB::table('workforce_export_packages')->insertGetId([
                'organization_id' => $organizationId, 'payroll_period_id' => $periodId, 'package_number' => 'WF-'.$periodId.'-'.$key,
                'source_hash' => $hash, 'status' => 'created', 'created_by_user_id' => $actorId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('workforce_export_package_files')->insert([
                'organization_id' => $organizationId, 'export_package_id' => $exportId, 'file_type' => 'source_csv', 'file_name' => 'payroll-source.csv',
                'storage_disk' => 's3', 'storage_path' => 'org-'.$organizationId.'/workforce/payroll-exports/period-'.$periodId.'/package-'.$key.'/payroll-source.csv',
                'size_bytes' => 25, 'created_at' => now(), 'updated_at' => now(),
            ]);
            return $fixture;
        });
    }
}
