<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RagProjectionIndexCompactionTest extends TestCase
{
    private ?int $organizationId = null;

    private bool $createdLedgerRows = false;

    public function beginDatabaseTransaction(): void
    {
        self::assertMatchesRegularExpression('/^most_phpunit_[a-z0-9]+_testing$/i', DB::connection()->getDatabaseName());
    }

    protected function tearDown(): void
    {
        if ($this->organizationId !== null) { DB::table('organizations')->where('id', $this->organizationId)->delete(); }
        if ($this->createdLedgerRows) {
            foreach (['ai_assistant_status_snapshot_changes', 'ai_assistant_status_snapshot_scoped_changes'] as $table) {
                DB::delete('DELETE FROM '.$table.' WHERE xid >= ?::xid8 AND xid <= ?::xid8', ['90000001', '90020000']);
            }
        }
        parent::tearDown();
    }

    public function test_rebuild_reclaims_deleted_index_entries_and_preserves_rows_definitions_and_timeouts(): void
    {
        $this->organizationId = Organization::withoutEvents(fn () => Organization::factory()->create())->id;
        $generation = (string) Str::uuid();
        DB::insert("INSERT INTO ai_rag_expected_sources (organization_id, generation, source_type, entity_type, entity_id, checksum, pending_since, created_at, updated_at) "
            ."SELECT ?, ?::uuid, 'project', 'project', n::text, repeat('a', 64), now(), now(), now() FROM generate_series(1, 20000) n", [$this->organizationId, $generation]);
        DB::table('ai_rag_expected_sources')->where('organization_id', $this->organizationId)->where('entity_id', '<>', '1')->delete();
        $before = $this->indexes();
        $settings = DB::selectOne("SELECT current_setting('statement_timeout') AS statement_timeout, current_setting('lock_timeout') AS lock_timeout");
        $migration = require base_path('database/migrations/2026_10_10_120000_rebuild_retained_rag_projection_indexes.php');
        $migration->up();
        $after = $this->indexes();

        self::assertSame(array_keys($before), array_keys($after));
        foreach ($before as $name => $index) {
            self::assertSame($index->definition, $after[$name]->definition);
            self::assertTrue($after[$name]->indisvalid);
        }
        self::assertLessThan(array_sum(array_column($before, 'bytes')) / 2, array_sum(array_column($after, 'bytes')));
        self::assertSame(['1'], DB::table('ai_rag_expected_sources')->where('organization_id', $this->organizationId)->pluck('entity_id')->all());
        self::assertEquals($settings, DB::selectOne("SELECT current_setting('statement_timeout') AS statement_timeout, current_setting('lock_timeout') AS lock_timeout"));
        $migration->up();
        self::assertSame(1, DB::table('ai_rag_expected_sources')->where('organization_id', $this->organizationId)->count());
    }

    public function test_storage_compaction_reclaims_all_three_tables_and_preserves_rows_and_settings(): void
    {
        $this->organizationId = Organization::withoutEvents(fn () => Organization::factory()->create())->id;
        DB::insert("INSERT INTO ai_rag_expected_sources (organization_id, generation, source_type, entity_type, entity_id, checksum, pending_since, created_at, updated_at) "
            ."SELECT ?, ?::uuid, 'project', 'project', n::text, repeat('a', 64), now(), now(), now() FROM generate_series(1, 20000) n", [$this->organizationId, (string) Str::uuid()]);
        DB::table('ai_rag_expected_sources')->where('organization_id', $this->organizationId)->where('entity_id', '<>', '1')->delete();
        $tables = ['ai_rag_expected_sources', 'ai_assistant_status_snapshot_changes', 'ai_assistant_status_snapshot_scoped_changes'];
        $this->createdLedgerRows = true;
        DB::insert("INSERT INTO ai_assistant_status_snapshot_changes (xid, relation_oid) SELECT (90000000 + n)::text::xid8, 0::oid FROM generate_series(1, 20000) n");
        DB::insert("INSERT INTO ai_assistant_status_snapshot_scoped_changes (xid, relation_oid, organization_id) SELECT (90000000 + n)::text::xid8, 0::oid, 0 FROM generate_series(1, 20000) n");
        foreach (array_slice($tables, 1) as $table) {
            DB::delete('DELETE FROM '.$table.' WHERE xid > ?::xid8 AND xid <= ?::xid8', ['90000001', '90020000']);
        }
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = $this->tableIndexes($table);
        }
        $expectedRow = DB::table('ai_rag_expected_sources')->where('organization_id', $this->organizationId)->first();
        $settings = DB::selectOne("SELECT current_setting('statement_timeout') AS statement_timeout, current_setting('lock_timeout') AS lock_timeout");
        $migration = require base_path('database/migrations/2026_10_11_120000_restore_rag_maintenance_and_compact_indexes.php');
        $migration->up();
        foreach ($tables as $table) {
            $after = $this->tableIndexes($table);
            self::assertSame(array_column($before[$table], 'definition', 'name'), array_column($after, 'definition', 'name'));
            self::assertLessThan(array_sum(array_column($before[$table], 'bytes')) / 2, array_sum(array_column($after, 'bytes')));
            foreach ($after as $index) {
                self::assertTrue($index->healthy);
            }
        }
        self::assertEquals($expectedRow, DB::table('ai_rag_expected_sources')->where('organization_id', $this->organizationId)->first());
        foreach (array_slice($tables, 1) as $table) {
            self::assertSame(1, DB::table($table)->whereRaw('xid = ?::xid8', ['90000001'])->count());
        }
        self::assertEquals($settings, DB::selectOne("SELECT current_setting('statement_timeout') AS statement_timeout, current_setting('lock_timeout') AS lock_timeout"));
        $migration->up();
        self::assertEquals($expectedRow, DB::table('ai_rag_expected_sources')->where('organization_id', $this->organizationId)->first());
    }

    private function tableIndexes(string $table): array
    {
        return DB::select("SELECT c.relname AS name, pg_get_indexdef(c.oid) AS definition, pg_relation_size(c.oid) AS bytes, i.indisvalid AND i.indisready AND i.indislive AS healthy FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE i.indrelid = ?::regclass ORDER BY c.relname", ['public.'.$table]);
    }

    private function indexes(): array
    {
        $indexes = DB::select("SELECT c.relname, pg_get_indexdef(c.oid) AS definition, pg_relation_size(c.oid) AS bytes, i.indisvalid "
            ."FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE i.indrelid = 'public.ai_rag_expected_sources'::regclass "
            ."AND c.relname IN ('ai_rag_expected_coverage_cover_idx', 'ai_rag_expected_identity_unique', 'ai_rag_expected_type_entity_idx', "
            ."'ai_rag_expected_retention_idx', 'ai_rag_expected_sources_pkey', 'ai_rag_expected_scope_idx') ORDER BY c.relname");
        self::assertCount(6, $indexes);
        return array_column($indexes, null, 'relname');
    }
}
