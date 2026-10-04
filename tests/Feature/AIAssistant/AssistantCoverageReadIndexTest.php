<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AssistantCoverageReadIndexTest extends TestCase
{
    public function test_coverage_indexes_cover_reads_recover_invalid_builds_and_keep_other_schemas(): void
    {
        while (DB::connection()->transactionLevel() > 0) { DB::rollBack(); }
        $migration = require base_path('database/migrations/2026_10_04_190000_add_rag_coverage_read_covering_indexes.php');
        $originalPath = DB::selectOne('SHOW search_path')->search_path;
        DB::statement('CREATE SCHEMA assistant_coverage_index_test');
        try {
            DB::statement('SET search_path TO assistant_coverage_index_test, public');
            DB::statement('CREATE TABLE assistant_coverage_index_test.ai_rag_expected_sources '
                .'(LIKE public.ai_rag_expected_sources INCLUDING DEFAULTS)');
            DB::statement('CREATE TABLE assistant_coverage_index_test.ai_rag_status_sources '
                .'(LIKE public.ai_rag_status_sources INCLUDING DEFAULTS)');
            DB::statement("INSERT INTO ai_rag_expected_sources (organization_id, project_id, identity_project_id, generation, source_type, entity_type, entity_id, identity_part_key, checksum, pending_since) "
                ."SELECT 38, 10, 10, '00000000-0000-4000-8000-000000000001'::uuid, 'project', CASE WHEN n <= 10 THEN 'project' ELSE 'other' END, n::text, '', md5(n::text), NOW() FROM generate_series(1, 1000) n");
            DB::statement("INSERT INTO ai_rag_status_sources (id, organization_id, project_id, source_type, entity_type, entity_id, metadata, chunk_count, indexed_chunk_count) "
                ."SELECT n, 38, 10, 'project', 'project', n::text, jsonb_build_object('large', repeat(md5(n::text), 1000)), n, n - 1 FROM generate_series(1, 1000) n");
            $indexes = [
                'ai_rag_expected_sources' => 'ai_rag_expected_coverage_cover_idx',
                'ai_rag_status_sources' => 'ai_rag_status_coverage_cover_idx',
            ];
            foreach ($indexes as $table => $index) {
                try {
                    DB::statement('CREATE UNIQUE INDEX CONCURRENTLY '.$index.' ON assistant_coverage_index_test.'.$table.' (organization_id)');
                    self::fail('Duplicate organizations must leave an invalid concurrent index.');
                } catch (QueryException $exception) {
                    self::assertSame('23505', $exception->errorInfo[0]);
                }
                self::assertSame(0, (int) DB::selectOne('SELECT indisvalid::integer AS valid FROM pg_index WHERE indexrelid = to_regclass(?)', ['assistant_coverage_index_test.'.$index])->valid);
            }

            $migration->up();
            $migration->up();
            DB::statement('ANALYZE assistant_coverage_index_test.ai_rag_expected_sources');
            DB::statement('ANALYZE assistant_coverage_index_test.ai_rag_status_sources');
            $expected = DB::table('ai_rag_expected_sources')->select([
                'id', 'organization_id', 'project_id', 'generation', 'identity_project_id', 'identity_part_key',
                'source_type', 'entity_type', 'entity_id', 'checksum', 'pending_since', 'created_at', 'updated_at',
            ])->where('organization_id', 38)->where('generation', '00000000-0000-4000-8000-000000000001')
                ->where('source_type', 'project')->where('entity_type', 'project');
            self::assertCount(10, $expected->get());
            $stored = DB::table('ai_rag_status_sources')->select([
                'id', 'organization_id', 'project_id', 'source_type', 'entity_type', 'entity_id', 'chunk_count', 'indexed_chunk_count',
            ])->where('organization_id', 38)->where('source_type', 'project')->where('entity_type', 'project')->where('entity_id', '5');
            self::assertSame(4, (int) (clone $stored)->first()->indexed_chunk_count);
            foreach ([$expected, $stored] as $query) {
                $result = DB::select('EXPLAIN (FORMAT JSON) '.$query->toSql(), $query->getBindings());
                $plan = json_decode($result[0]->{'QUERY PLAN'}, true, 512, JSON_THROW_ON_ERROR)[0]['Plan'];
                self::assertSame('Index Only Scan', $plan['Node Type']);
                self::assertSame($indexes[$query->from], $plan['Index Name']);
            }

            $migration->down();
            $migration->down();
            foreach ($indexes as $index) {
                self::assertNull(DB::selectOne('SELECT to_regclass(?) AS name', ['assistant_coverage_index_test.'.$index])->name);
                self::assertNotNull(DB::selectOne('SELECT to_regclass(?) AS name', ['public.'.$index])->name);
            }
        } finally {
            DB::select('SELECT set_config(?, ?, false)', ['search_path', $originalPath]);
            DB::statement('DROP SCHEMA assistant_coverage_index_test CASCADE');
        }
    }
}
