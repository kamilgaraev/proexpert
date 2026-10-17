<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantFinanceTenderSourceSchema;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceRegistry;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifact;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelDerivative;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\KnowledgeHub\Enums\KnowledgeSurface;
use App\Models\Project;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantStatusDiscoveryIndexTest extends TestCase
{
    private const INDEX = 'ai_rag_status_discovery_scope_idx';

    protected function connectionsToTransact(): array
    {
        return [];
    }

    public function test_migration_preserves_real_discovery_and_nonzero_counters_across_reapply(): void
    {
        $this->inPrivateSchema(function (Migration $migration, string $schema): void {
            $fixture = AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug'));
            $organizationId = (int) $fixture->organization->id;
            $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $organizationId, 'is_archived' => false]));
            $foreignProject = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $fixture->foreignOrganization->id, 'is_archived' => false]));
            $package = DesignPackage::withoutEvents(fn () => DesignPackage::query()->create([
                'organization_id' => $organizationId, 'project_id' => $project->id, 'title' => 'Комплект', 'status' => 'draft',
            ]));
            $artifact = DesignArtifact::withoutEvents(fn () => DesignArtifact::query()->create([
                'organization_id' => $organizationId, 'project_id' => $project->id, 'package_id' => $package->id,
                'title' => 'Модель', 'artifact_type' => 'model',
            ]));
            $version = DesignArtifactVersion::withoutEvents(fn () => DesignArtifactVersion::query()->create([
                'organization_id' => $organizationId, 'project_id' => $project->id, 'artifact_id' => $artifact->id,
                'title' => 'Версия', 'version_number' => '1', 'source_file_path' => 'private.ifc',
                'source_original_name' => 'model.ifc', 'source_mime_type' => 'application/octet-stream', 'source_size_bytes' => 1,
            ]));
            $derivative = DesignModelDerivative::withoutEvents(fn () => DesignModelDerivative::query()->create([
                'organization_id' => $organizationId, 'project_id' => $project->id, 'version_id' => $version->id,
            ]));
            DB::statement('INSERT INTO design_ifc_model_elements (organization_id, project_id, version_id, derivative_id, express_id)
                SELECT ?, ?, ?, ?, n FROM generate_series(1, 20000) n', [$organizationId, $project->id, $version->id, $derivative->id]);
            DB::statement("INSERT INTO ai_rag_status_sources (id, organization_id, project_id, source_type, entity_type, entity_id, metadata, chunk_count, indexed_chunk_count)
                SELECT row_number() OVER (), organization_id, project_id, 'design_additional', 'design_ifc_model_element', id::text,
                    jsonb_build_object('fixture', repeat(md5(id::text), 8)), 2, 1 FROM design_ifc_model_elements WHERE version_id=?", [$version->id]);
            DB::statement("INSERT INTO ai_rag_status_sources (id, organization_id, project_id, source_type, entity_type, entity_id, metadata, chunk_count, indexed_chunk_count)
                SELECT 20000+n, ?, ?, 'design_additional', 'design_ifc_model_element', 'foreign-'||n::text, '{}', 2, 1
                FROM generate_series(1, 10000) n", [$fixture->foreignOrganization->id, $foreignProject->id]);
            foreach ([$foreignProject->id, null] as $offset => $projectId) {
                DB::statement("INSERT INTO ai_rag_status_sources (id, organization_id, project_id, source_type, entity_type, entity_id, metadata, chunk_count, indexed_chunk_count)
                    SELECT ?+n, ?, ?, 'design_additional', 'design_ifc_model_element', 'excluded-'||?::text||'-'||n::text, '{}', 2, 1
                    FROM generate_series(1, 100) n", [30000 + $offset * 100, $organizationId, $projectId, $offset]);
            }
            foreach ([true, false] as $current) {
                for ($i = 0; $i < 2; $i++) {
                    DB::table('ai_rag_status_sources')->insert([
                        'id' => 30201 + ($current ? 0 : 2) + $i, 'organization_id' => $organizationId, 'project_id' => null,
                        'source_type' => 'budgeting', 'entity_type' => 'epm_data_mart_snapshot', 'entity_id' => ($current ? 'current-' : 'legacy-').$i,
                        'metadata' => json_encode([AssistantFinanceTenderSourceSchema::FIELD => $current
                            ? AssistantFinanceTenderSourceSchema::revision('epm_data_mart_snapshot') : 'old'], JSON_THROW_ON_ERROR),
                        'chunk_count' => 2, 'indexed_chunk_count' => 1,
                    ]);
                }
            }
            DB::statement('CREATE INDEX ai_rag_status_sources_identity_idx ON ai_rag_status_sources (organization_id, source_type, entity_type, entity_id) INCLUDE (id, project_id)');
            DB::statement('CREATE INDEX ai_rag_status_coverage_cover_idx ON ai_rag_status_sources (organization_id, source_type, entity_type, entity_id) INCLUDE (id, project_id, chunk_count, indexed_chunk_count)');
            foreach (['ai_rag_status_sources', 'design_ifc_model_elements'] as $table) {
                DB::statement('VACUUM (ANALYZE) '.$table);
            }
            $fixturePath = DB::selectOne('SHOW search_path')->search_path;
            DB::selectOne("SELECT set_config('search_path', 'public', false)");
            $policy = app(AssistantDataAccessPolicy::class);
            $policy->setTrustedSurface(KnowledgeSurface::ADMIN);
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                $batches = $policy->withCurrentChecks($fixture->owner, $organizationId, function () use ($policy, $fixture, $organizationId, $schema): array {
                    $policy->prefetchEntitySchemaMetadata();
                    $types = $policy->allowedSourceTypes($fixture->owner, $organizationId, app(RagSourceRegistry::class)->enabledSourceTypes());
                    $sources = RagSource::query()->where('ai_rag_sources.organization_id', $organizationId)
                        ->whereIn('ai_rag_sources.source_type', $types)->from($schema.'.ai_rag_status_sources as ai_rag_sources');
                    $partial = static fn ($visible) => DB::query()->fromSub($visible, 'accessible_sources')->groupBy('accessible_sources.source_type')
                        ->selectRaw('accessible_sources.source_type, COUNT(*) AS stored_count, COALESCE(pg_catalog.SUM(accessible_sources.chunk_count), 0) AS chunk_count, COUNT(CASE WHEN accessible_sources.indexed_chunk_count > 0 THEN 1 END) AS indexed_count');
                    $combine = static fn ($visible) => DB::query()->fromSub($visible, 'accessible_sources')->groupBy('accessible_sources.source_type')
                        ->selectRaw('accessible_sources.source_type, pg_catalog.SUM(accessible_sources.stored_count) AS stored_count, pg_catalog.SUM(accessible_sources.chunk_count) AS chunk_count, pg_catalog.SUM(accessible_sources.indexed_count) AS indexed_count');

                    return $policy->aggregateSourceIdentityBatches($sources, $fixture->owner, $organizationId,
                        ['ai_rag_sources.id', 'ai_rag_sources.source_type', 'ai_rag_sources.project_id', 'ai_rag_sources.chunk_count', 'ai_rag_sources.indexed_chunk_count'],
                        $combine, aggregateBranch: $partial);
                }, fresh: true);
                $captured = array_values(array_filter(DB::getQueryLog(), static fn (array $query): bool => str_contains($query['query'], 'COUNT(*) AS identity_count')));
            } finally {
                DB::disableQueryLog();
                DB::flushQueryLog();
                DB::selectOne("SELECT set_config('search_path', ?, false)", [$fixturePath]);
            }
            self::assertCount(1, $captured);
            self::assertNotEmpty($batches);
            $discovery = $captured[0];
            $read = fn (): array => $this->normalize(DB::select($discovery['query'], $discovery['bindings']));
            $counts = fn (): array => $this->normalize(array_merge(...array_map(static fn ($batch): array => $batch->get()->all(), $batches)));
            $before = $read();
            $beforeCounts = $counts();
            self::assertContains(['source_type' => 'design_additional', 'entity_type' => 'design_ifc_model_element', 'identity_count' => '20100'], $before);
            self::assertContains(['source_type' => 'budgeting', 'entity_type' => 'epm_data_mart_snapshot', 'identity_count' => '2'], $before);
            self::assertContains(['source_type' => 'design_additional', 'stored_count' => '20000', 'chunk_count' => '40000', 'indexed_count' => '20000'], $beforeCounts);
            $migration->up();
            $oid = $this->indexOid();
            $migration->up();
            self::assertSame($oid, $this->indexOid());
            self::assertSame($before, $read());
            self::assertSame($beforeCounts, $counts());
            $plan = json_decode(DB::selectOne('EXPLAIN (ANALYZE, BUFFERS, TIMING FALSE, FORMAT JSON) '.$discovery['query'], $discovery['bindings'])->{'QUERY PLAN'}, true, 512, JSON_THROW_ON_ERROR)[0]['Plan'];
            $scans = [];
            $walk = static function (array $node) use (&$walk, &$scans): void {
                if (($node['Index Name'] ?? null) === self::INDEX) { $scans[] = $node; }
                foreach ($node['Plans'] ?? [] as $child) { $walk($child); }
            };
            $walk($plan);
            self::assertNotEmpty($scans);
            self::assertSame('Index Only Scan', $scans[0]['Node Type']);
            self::assertStringNotContainsString('<> ALL', $scans[0]['Filter'] ?? '');
            $migration->down();
            $migration->down();
            self::assertNull($this->indexOid());
            self::assertSame($before, $read());
            self::assertSame($beforeCounts, $counts());
            $migration->up();
            self::assertNotNull($this->indexOid());
            self::assertSame($before, $read());
        });
    }

    public function test_expected_invalid_concurrent_build_is_recovered_and_settings_are_restored(): void
    {
        $this->inPrivateSchema(function (Migration $migration, string $schema): void {
            DB::statement("INSERT INTO ai_rag_status_sources (id,organization_id,project_id,source_type,entity_type,entity_id,metadata,chunk_count,indexed_chunk_count)
                SELECT n,1,NULL,'design_additional','design_ifc_model_element',n::text,'{}',2,1 FROM generate_series(1,100) n");
            $migration->up();
            $definition = DB::selectOne('SELECT pg_get_indexdef(to_regclass(?)) AS definition', [$schema.'.'.self::INDEX])->definition;
            $migration->down();
            $connectionName = 'rag_discovery_blocker';
            $originalConfig = config('database.connections.'.$connectionName);
            config(['database.connections.'.$connectionName => DB::connection()->getConfig()]);
            $blocker = DB::connection($connectionName);
            self::assertSame(DB::connection()->getDatabaseName(), $blocker->getDatabaseName());
            try {
                $blocker->beginTransaction();
                $blocker->update('UPDATE "'.$schema.'".ai_rag_status_sources SET chunk_count=chunk_count WHERE id=1');
                DB::statement("SET lock_timeout='20ms'");
                try {
                    DB::statement(str_replace('CREATE INDEX ', 'CREATE INDEX CONCURRENTLY ', $definition));
                    self::fail('A writer must leave the concurrent build incomplete.');
                } catch (QueryException $exception) {
                    self::assertSame('55P03', $exception->errorInfo[0]);
                }
            } finally {
                if ($blocker->transactionLevel() > 0) { $blocker->rollBack(); }
                DB::purge($connectionName);
                config(['database.connections.'.$connectionName => $originalConfig]);
            }
            $invalid = DB::selectOne('SELECT indisvalid::integer AS valid FROM pg_index WHERE indexrelid=to_regclass(?)', [$schema.'.'.self::INDEX]);
            self::assertNotNull($invalid);
            self::assertSame(0, (int) $invalid->valid);
            $invalidOid = $this->indexOid();
            $settings = DB::selectOne("SELECT current_setting('statement_timeout') AS statement_timeout, current_setting('lock_timeout') AS lock_timeout");
            $migration->up();
            self::assertNotSame($invalidOid, $this->indexOid());
            self::assertSame(1, (int) DB::selectOne('SELECT indisvalid::integer AS valid FROM pg_index WHERE indexrelid=to_regclass(?)', [$schema.'.'.self::INDEX])->valid);
            $migration->down();
            self::assertEquals($settings, DB::selectOne("SELECT current_setting('statement_timeout') AS statement_timeout, current_setting('lock_timeout') AS lock_timeout"));
        });
    }

    public function test_conflicting_objects_and_active_transactions_are_preserved(): void
    {
        $this->inPrivateSchema(function (Migration $migration): void {
            self::assertFalse($migration->withinTransaction);
            DB::statement('CREATE TABLE other_source (id bigint)');
            $conflicts = [
                ['CREATE INDEX '.self::INDEX.' ON ai_rag_status_sources (project_id)', 'definition'],
                ['CREATE INDEX '.self::INDEX.' ON other_source (id)', 'target'],
                ['CREATE TABLE '.self::INDEX.' (id bigint)', 'target'],
            ];
            foreach ($conflicts as [$sql, $reason]) {
                DB::statement($sql);
                $oid = $this->indexOid();
                foreach (['up', 'down'] as $direction) {
                    try {
                        $migration->{$direction}();
                        self::fail('An unexpected object must be preserved.');
                    } catch (\RuntimeException $exception) {
                        self::assertSame('rag_discovery_index_'.$reason.'_mismatch', $exception->getMessage());
                    }
                    self::assertSame($oid, $this->indexOid());
                }
                DB::statement((str_starts_with($sql, 'CREATE TABLE') ? 'DROP TABLE ' : 'DROP INDEX ').self::INDEX);
            }
            DB::statement("INSERT INTO ai_rag_status_sources (id,organization_id,source_type,entity_type,entity_id,metadata,chunk_count,indexed_chunk_count)
                VALUES (1,1,'project','project','1','{}',1,1),(2,1,'project','project','2','{}',1,1)");
            try {
                DB::statement('CREATE UNIQUE INDEX CONCURRENTLY '.self::INDEX.' ON ai_rag_status_sources (organization_id)');
                self::fail('Duplicate organizations must leave a conflicting invalid index.');
            } catch (QueryException $exception) {
                self::assertSame('23505', $exception->errorInfo[0]);
            }
            $invalidOid = $this->indexOid();
            foreach (['up', 'down'] as $direction) {
                try {
                    $migration->{$direction}();
                    self::fail('A conflicting invalid definition must be preserved.');
                } catch (\RuntimeException $exception) {
                    self::assertSame('rag_discovery_index_definition_mismatch', $exception->getMessage());
                }
                self::assertSame($invalidOid, $this->indexOid());
            }
            DB::statement('DROP INDEX CONCURRENTLY '.self::INDEX);
            $migration->up();
            $oid = $this->indexOid();
            DB::beginTransaction();
            try {
                foreach (['up', 'down'] as $direction) {
                    try {
                        $migration->{$direction}();
                        self::fail('Concurrent DDL must refuse an active transaction.');
                    } catch (\RuntimeException $exception) {
                        self::assertSame('rag_discovery_index_requires_no_transaction', $exception->getMessage());
                    }
                    self::assertSame($oid, $this->indexOid());
                }
            } finally {
                DB::rollBack();
            }
            $pdo = DB::connection()->getPdo();
            $pdo->beginTransaction();
            try {
                self::assertSame(0, DB::transactionLevel());
                foreach (['up', 'down'] as $direction) {
                    try {
                        $migration->{$direction}();
                        self::fail('Concurrent DDL must refuse a raw PDO transaction.');
                    } catch (\RuntimeException $exception) {
                        self::assertSame('rag_discovery_index_requires_no_transaction', $exception->getMessage());
                    }
                    self::assertTrue($pdo->inTransaction());
                    self::assertSame($oid, $this->indexOid());
                }
            } finally {
                $pdo->rollBack();
            }
        });
    }

    private function inPrivateSchema(callable $test): void
    {
        self::assertSame('pgsql', DB::connection()->getDriverName());
        self::assertMatchesRegularExpression('/^most_phpunit_[a-f0-9]+_testing$/', DB::connection()->getDatabaseName());
        self::assertSame(0, DB::transactionLevel());
        $settings = DB::selectOne("SELECT current_setting('search_path') AS search_path, current_setting('statement_timeout') AS statement_timeout,
            current_setting('lock_timeout') AS lock_timeout, current_setting('work_mem') AS work_mem, current_setting('jit') AS jit");
        $publicOid = DB::selectOne('SELECT to_regclass(?)::oid AS oid', ['public.'.self::INDEX])->oid;
        $schema = 'rag_discovery_'.bin2hex(random_bytes(6));
        $migration = require base_path('database/migrations/2026_10_17_090000_add_rag_status_discovery_index.php');
        DB::statement('CREATE SCHEMA "'.$schema.'"');
        try {
            DB::selectOne("SELECT set_config('search_path', ?, false), set_config('work_mem', '16MB', false), set_config('jit', 'off', false)", [$schema.', public']);
            DB::statement('CREATE TABLE ai_rag_status_sources (LIKE public.ai_rag_status_sources INCLUDING DEFAULTS)');
            $test($migration, $schema);
            self::assertSame($publicOid, DB::selectOne('SELECT to_regclass(?)::oid AS oid', ['public.'.self::INDEX])->oid);
        } finally {
            DB::statement('DROP SCHEMA "'.$schema.'" CASCADE');
            DB::selectOne("SELECT set_config('search_path', ?, false), set_config('statement_timeout', ?, false),
                set_config('lock_timeout', ?, false), set_config('work_mem', ?, false), set_config('jit', ?, false)",
                [$settings->search_path, $settings->statement_timeout, $settings->lock_timeout, $settings->work_mem, $settings->jit]);
        }
    }

    private function indexOid(): mixed
    {
        return DB::selectOne("SELECT to_regclass(format('%I.%I', current_schema(), CAST(? AS text)))::oid AS oid", [self::INDEX])->oid;
    }

    private function normalize(array $rows): array
    {
        $rows = array_map(static fn ($row): array => array_map(static fn ($value): string => (string) $value, (array) $row), $rows);
        usort($rows, static fn (array $a, array $b): int => serialize($a) <=> serialize($b));

        return $rows;
    }
}
