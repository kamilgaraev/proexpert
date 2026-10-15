<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class AssistantStatusCoverageCompactionTest extends TestCase
{
    protected function connectionsToTransact(): array
    {
        return [];
    }

    public function test_concurrent_compaction_preserves_data_definition_and_timeouts_and_removes_dead_index_versions(): void
    {
        $this->withSourceTable(function (string $schema): void {
            DB::statement("INSERT INTO ai_rag_status_sources
                SELECT value, 1, 'design_additional', 'ifc_element', value::text, 2, 0, 0 FROM generate_series(1, 8000) value");
            $configuration = DB::connection()->getConfig();
            config(['database.connections.rag_compaction_reader' => $configuration]);
            $reader = DB::connection('rag_compaction_reader');
            try {
                $reader->beginTransaction();
                $reader->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
                self::assertSame(8000, (int) $reader->selectOne('SELECT count(*) AS total FROM "'.$schema.'".ai_rag_status_sources')->total);
                foreach (range(1, 8) as $iteration) {
                    DB::statement('UPDATE ai_rag_status_sources SET chunk_count = ?, indexed_chunk_count = ?', [$iteration, $iteration]);
                }
            } finally {
                if ($reader->transactionLevel() !== 0) {
                    $reader->rollBack();
                }
                DB::purge('rag_compaction_reader');
            }
            $before = $this->indexState();
            $data = $this->dataDigest();
            foreach ([1, 2] as $attempt) {
                $this->migration()->up();
                $after = $this->indexState();
                self::assertNotSame($before->oid, $after->oid);
                self::assertSame($before->definition, $after->definition);
                self::assertSame(1, (int) $after->healthy);
                self::assertSame($data, $this->dataDigest());
                $this->assertTimeoutsRestored();
            }
            self::assertLessThan((int) $before->bytes / 2, (int) $after->bytes);
            $this->migration()->down();
            self::assertSame($data, $this->dataDigest());
        });
    }

    public function test_unknown_compaction_residue_is_preserved_and_blocks_reindex(): void
    {
        $this->withSourceTable(function (): void {
            DB::statement('CREATE INDEX ai_rag_status_coverage_cover_idx_ccnew2 ON ai_rag_status_sources (id)');
            $before = $this->indexState();
            try {
                $this->migration()->up();
                self::fail('Unverified compaction residue must not be removed.');
            } catch (RuntimeException $exception) {
                self::assertSame('RAG coverage index compaction residue requires inspection', $exception->getMessage());
            }
            self::assertSame($before->oid, $this->indexState()->oid);
            self::assertNotNull(DB::selectOne("SELECT to_regclass('ai_rag_status_coverage_cover_idx_ccnew2') AS relation")->relation);
            $this->assertTimeoutsRestored();
        });
    }

    public function test_changed_index_definition_is_preserved_and_blocks_reindex(): void
    {
        $this->withSourceTable(function (): void {
            DB::statement('DROP INDEX ai_rag_status_coverage_cover_idx');
            DB::statement('CREATE INDEX ai_rag_status_coverage_cover_idx ON ai_rag_status_sources (organization_id)');
            $before = $this->indexState();
            try {
                $this->migration()->up();
                self::fail('A different index must not be rebuilt silently.');
            } catch (RuntimeException $exception) {
                self::assertSame('RAG coverage index definition or validity differs from the expected index', $exception->getMessage());
            }
            self::assertSame($before->oid, $this->indexState()->oid);
            self::assertSame($before->definition, $this->indexState()->definition);
            $this->assertTimeoutsRestored();
        });
    }

    private function withSourceTable(callable $test): void
    {
        self::assertSame('pgsql', DB::connection()->getDriverName());
        self::assertMatchesRegularExpression('/^most_phpunit_[a-f0-9]+_testing$/', DB::connection()->getDatabaseName());
        self::assertSame(0, DB::transactionLevel());
        $settings = DB::selectOne("SELECT current_setting('search_path') AS search_path,
            current_setting('statement_timeout') AS statement_timeout, current_setting('lock_timeout') AS lock_timeout");
        $schema = 'rag_compaction_'.bin2hex(random_bytes(6));
        DB::statement('CREATE SCHEMA "'.$schema.'"');
        try {
            DB::selectOne("SELECT set_config('search_path', ?, false), set_config('statement_timeout', '17s', false), set_config('lock_timeout', '7s', false)", [$schema.', public']);
            DB::statement('CREATE TABLE ai_rag_status_sources (id bigint, organization_id bigint, source_type text, entity_type text,
                entity_id text, project_id bigint, chunk_count integer, indexed_chunk_count integer) WITH (autovacuum_enabled = false)');
            DB::statement('CREATE INDEX ai_rag_status_coverage_cover_idx ON ai_rag_status_sources
                (organization_id, source_type, entity_type, entity_id) INCLUDE (id, project_id, chunk_count, indexed_chunk_count)');
            $test($schema);
        } finally {
            DB::statement('DROP SCHEMA "'.$schema.'" CASCADE');
            DB::selectOne("SELECT set_config('search_path', ?, false), set_config('statement_timeout', ?, false), set_config('lock_timeout', ?, false)",
                [$settings->search_path, $settings->statement_timeout, $settings->lock_timeout]);
        }
    }

    private function indexState(): object
    {
        return DB::selectOne("SELECT relation.oid::text, pg_get_indexdef(relation.oid) AS definition, pg_relation_size(relation.oid) AS bytes,
            CASE WHEN index.indisvalid AND index.indisready AND index.indislive THEN 1 ELSE 0 END AS healthy
            FROM pg_class relation JOIN pg_index index ON index.indexrelid = relation.oid
            WHERE relation.oid = 'ai_rag_status_coverage_cover_idx'::regclass");
    }

    private function dataDigest(): string
    {
        return DB::selectOne("SELECT md5(string_agg(ROW(id, organization_id, source_type, entity_type, entity_id,
            project_id, chunk_count, indexed_chunk_count)::text, ',' ORDER BY id)) AS digest FROM ai_rag_status_sources")->digest;
    }

    private function assertTimeoutsRestored(): void
    {
        self::assertSame('17s', DB::selectOne("SELECT current_setting('statement_timeout') AS value")->value);
        self::assertSame('7s', DB::selectOne("SELECT current_setting('lock_timeout') AS value")->value);
    }

    private function migration(): Migration
    {
        return require base_path('database/migrations/2026_10_15_225000_compact_rag_status_coverage_index.php');
    }
}
