<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexingCoordinator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AssistantIndexRunCountIndexTest extends TestCase
{
    protected function connectionsToTransact(): array
    {
        return [];
    }

    public function test_run_count_indexes_cover_large_scopes_recover_invalid_builds_and_preserve_counts(): void
    {
        self::assertSame('pgsql', DB::connection()->getDriverName());
        self::assertMatchesRegularExpression('/^most_phpunit_[a-f0-9]+_testing$/', DB::connection()->getDatabaseName());
        self::assertSame(0, DB::transactionLevel());
        $settings = DB::selectOne("SELECT current_setting('search_path') AS search_path, current_setting('work_mem') AS work_mem, current_setting('jit') AS jit");
        $schema = 'rag_run_counts_'.bin2hex(random_bytes(6));
        $migration = require base_path('database/migrations/2026_10_16_123000_add_rag_index_run_count_indexes.php');
        $indexNames = ['ai_rag_status_source_type_counts_idx', 'ai_rag_status_chunk_project_counts_idx'];
        $publicIndexes = [];
        foreach ($indexNames as $name) { $publicIndexes[$name] = DB::selectOne('SELECT to_regclass(?)::oid AS oid', ['public.'.$name])->oid; }
        DB::statement('CREATE SCHEMA "'.$schema.'"');
        try {
            DB::selectOne("SELECT set_config('search_path', ?, false), set_config('work_mem', '4MB', false), set_config('jit', 'off', false)", [$schema.', public']);
            DB::statement('CREATE TABLE ai_rag_status_sources (LIKE public.ai_rag_status_sources INCLUDING DEFAULTS)');
            DB::statement('CREATE TABLE ai_rag_status_chunks (LIKE public.ai_rag_status_chunks INCLUDING DEFAULTS)');
            DB::statement("INSERT INTO ai_rag_status_sources (id,organization_id,project_id,source_type,entity_type,entity_id,metadata,chunk_count,indexed_chunk_count)
                SELECT n, CASE WHEN n<=203000 THEN 1 ELSE 2 END, CASE WHEN n<=203000 THEN 1 ELSE 2 END,
                    CASE WHEN n<=203000 THEN 'design_additional' ELSE 'file_document' END, 'design_ifc_model_element', n::text,
                    jsonb_build_object('fixture',repeat(md5(n::text),8)),1,1 FROM generate_series(1,360000) n");
            DB::statement('INSERT INTO ai_rag_status_chunks (id,source_id,organization_id,project_id,embedding_present)
                SELECT n,n,CASE WHEN n<=203000 THEN 1 ELSE 2 END,CASE WHEN n<=203000 THEN 1 ELSE 2 END,true FROM generate_series(1,360000) n');
            DB::statement("INSERT INTO ai_rag_status_sources (id,organization_id,project_id,source_type,entity_type,entity_id,metadata,chunk_count,indexed_chunk_count)
                VALUES (360001,2,2,'design_additional','design_ifc_model_element','cross-source','{}',2,2),
                    (360002,1,1,'design_additional','design_ifc_model_element','cross-chunk','{}',1,1)");
            DB::statement('INSERT INTO ai_rag_status_chunks (id,source_id,organization_id,project_id,embedding_present)
                VALUES (360001,360001,1,1,true),(360002,360001,1,1,true),(360003,360002,2,2,true)');
            DB::statement('CREATE UNIQUE INDEX source_pk ON ai_rag_status_sources(id)');
            DB::statement('CREATE INDEX source_identity ON ai_rag_status_sources(organization_id,source_type,entity_type,entity_id) INCLUDE(id,project_id)');
            DB::statement('CREATE INDEX source_coverage ON ai_rag_status_sources(organization_id,source_type,entity_type,entity_id) INCLUDE(id,project_id,chunk_count,indexed_chunk_count)');
            DB::statement('CREATE UNIQUE INDEX chunk_pk ON ai_rag_status_chunks(id)');
            DB::statement('CREATE INDEX chunk_scope ON ai_rag_status_chunks(organization_id,source_id,project_id)');
            DB::statement('CREATE INDEX chunk_indexed_scope ON ai_rag_status_chunks(organization_id,source_id,project_id) WHERE embedding_present');
            DB::statement('VACUUM (ANALYZE) ai_rag_status_sources');
            DB::statement('VACUUM (ANALYZE) ai_rag_status_chunks');
            $coordinator = app(RagIndexingCoordinator::class);
            $counts = $coordinator->countsForScope(1,1,'design_additional');
            self::assertSame(['source_count'=>203001,'chunk_count'=>203002], $counts);
            $samples = [$this->plans('before')];
            foreach (['ai_rag_status_sources','ai_rag_status_chunks'] as $offset => $table) {
                try {
                    DB::statement('CREATE UNIQUE INDEX CONCURRENTLY '.$indexNames[$offset].' ON '.$table.'(organization_id)');
                    self::fail('Expected a failed concurrent unique build.');
                } catch (QueryException $exception) { self::assertSame('23505', $exception->errorInfo[0]); }
            }
            $migration->up();
            $migration->up();
            self::assertSame($counts, $coordinator->countsForScope(1,1,'design_additional'));
            $samples[] = $this->plans('after');
            $samples[] = $this->plans('after');
            $chunkPlan = $samples[1]['chunks'];
            self::assertContains('ai_rag_status_chunk_project_counts_idx', $chunkPlan['indexes']);
            self::assertContains('ai_rag_status_source_type_counts_idx', $chunkPlan['indexes']);
            self::assertSame(0, $chunkPlan['temp_written_blocks']);
            $migration->down();
            $migration->down();
            self::assertSame($counts, $coordinator->countsForScope(1,1,'design_additional'));
            $samples[] = $this->plans('before');
            DB::statement('ALTER TABLE ai_rag_status_sources SET (autovacuum_enabled=false)');
            DB::statement('ALTER TABLE ai_rag_status_chunks SET (autovacuum_enabled=false)');
            DB::statement('UPDATE ai_rag_status_sources SET chunk_count=chunk_count+1 WHERE id<=80000');
            $samples[] = $this->plans('source_dirty_before');
            $migration->up();
            self::assertSame($counts, $coordinator->countsForScope(1,1,'design_additional'));
            $samples[] = $this->plans('source_dirty_after');
            $samples[] = $this->plans('source_dirty_after');
            $migration->down();
            $samples[] = $this->plans('source_dirty_before');
            DB::statement('UPDATE ai_rag_status_chunks SET embedding_present=false WHERE id<=80000');
            DB::statement('ANALYZE ai_rag_status_sources');
            DB::statement('ANALYZE ai_rag_status_chunks');
            $samples[] = $this->plans('dirty_before');
            $migration->up();
            self::assertSame($counts, $coordinator->countsForScope(1,1,'design_additional'));
            $samples[] = $this->plans('dirty_after');
            $samples[] = $this->plans('dirty_after');
            $migration->down();
            $samples[] = $this->plans('dirty_before');
            foreach ($publicIndexes as $name => $oid) {
                self::assertSame($oid, DB::selectOne('SELECT to_regclass(?)::oid AS oid', ['public.'.$name])->oid);
                self::assertNull(DB::selectOne('SELECT to_regclass(?) AS name', [$schema.'.'.$name])->name);
            }
            fwrite(STDOUT, "\nRAG_RUN_COUNT_PLANS ".json_encode($samples,JSON_THROW_ON_ERROR)."\n");
        } finally {
            DB::statement('DROP SCHEMA "'.$schema.'" CASCADE');
            DB::selectOne("SELECT set_config('search_path', ?, false),set_config('work_mem', ?, false),set_config('jit', ?, false)", [$settings->search_path,$settings->work_mem,$settings->jit]);
        }
    }

    public function test_migration_preserves_conflicting_definitions_and_indexes_on_other_tables(): void
    {
        self::assertSame('pgsql', DB::connection()->getDriverName());
        self::assertMatchesRegularExpression('/^most_phpunit_[a-f0-9]+_testing$/', DB::connection()->getDatabaseName());
        self::assertSame(0, DB::transactionLevel());
        $originalPath = DB::selectOne('SHOW search_path')->search_path;
        $schema = 'rag_run_conflicts_'.bin2hex(random_bytes(6));
        $migration = require base_path('database/migrations/2026_10_16_123000_add_rag_index_run_count_indexes.php');
        $name = 'ai_rag_status_source_type_counts_idx';
        DB::statement('CREATE SCHEMA "'.$schema.'"');
        try {
            DB::selectOne("SELECT set_config('search_path', ?, false)", [$schema.', public']);
            DB::statement('CREATE TABLE ai_rag_status_sources (LIKE public.ai_rag_status_sources INCLUDING DEFAULTS)');
            DB::statement('CREATE TABLE ai_rag_status_chunks (LIKE public.ai_rag_status_chunks INCLUDING DEFAULTS)');
            foreach (['ai_rag_status_sources'=>'definition', 'ai_rag_status_chunks'=>'target'] as $table => $reason) {
                DB::statement('CREATE INDEX '.$name.' ON '.$table.'(project_id)');
                $oid = DB::selectOne('SELECT to_regclass(?)::oid AS oid', [$schema.'.'.$name])->oid;
                foreach (['up','down'] as $direction) {
                    try {
                        $migration->{$direction}();
                        self::fail('Conflicting indexes must be preserved.');
                    } catch (\RuntimeException $exception) {
                        self::assertSame('rag_run_count_index_'.$reason.'_mismatch', $exception->getMessage());
                    }
                    self::assertSame($oid, DB::selectOne('SELECT to_regclass(?)::oid AS oid', [$schema.'.'.$name])->oid);
                }
                DB::statement('DROP INDEX "'.$schema.'".'.$name);
            }
        } finally {
            DB::statement('DROP SCHEMA "'.$schema.'" CASCADE');
            DB::selectOne("SELECT set_config('search_path', ?, false)", [$originalPath]);
        }
    }

    private function plans(string $phase): array
    {
        $queries = [
            'sources'=>'SELECT count(*) FROM ai_rag_status_sources WHERE organization_id=? AND project_id=? AND source_type=?',
            'chunks'=>'SELECT count(*) FROM ai_rag_status_chunks chunks WHERE chunks.organization_id=? AND chunks.project_id=? AND EXISTS(SELECT 1 FROM ai_rag_status_sources sources WHERE sources.id=chunks.source_id AND sources.source_type=?)',
        ];
        $result = ['phase'=>$phase];
        foreach ($queries as $name => $sql) {
            $raw = DB::selectOne('EXPLAIN (ANALYZE, BUFFERS, TIMING OFF, FORMAT JSON) '.$sql,[1,1,'design_additional']);
            $plan = json_decode($raw->{'QUERY PLAN'},true,512,JSON_THROW_ON_ERROR)[0];
            $indexes = []; $nodes = []; $heapFetches = 0;
            $walk = static function (array $node) use (&$walk,&$indexes,&$nodes,&$heapFetches): void {
                $nodes[] = $node['Node Type'];
                $heapFetches += ($node['Heap Fetches'] ?? 0) * ($node['Actual Loops'] ?? 1);
                if (isset($node['Index Name'])) { $indexes[] = $node['Index Name']; }
                foreach ($node['Plans'] ?? [] as $child) { $walk($child); }
            };
            $walk($plan['Plan']);
            $result[$name] = ['execution_ms'=>$plan['Execution Time'],'planning_ms'=>$plan['Planning Time'],
                'shared_blocks'=>($plan['Plan']['Shared Hit Blocks']??0)+($plan['Plan']['Shared Read Blocks']??0),
                'temp_written_blocks'=>$plan['Plan']['Temp Written Blocks']??0,'heap_fetches'=>$heapFetches,'indexes'=>array_values(array_unique($indexes)), 'nodes'=>$nodes];
        }

        return $result;
    }
}
