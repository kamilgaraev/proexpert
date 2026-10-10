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

    public function beginDatabaseTransaction(): void
    {
        self::assertMatchesRegularExpression('/^most_phpunit_[a-z0-9]+_testing$/i', DB::connection()->getDatabaseName());
    }

    protected function tearDown(): void
    {
        if ($this->organizationId !== null) { DB::table('organizations')->where('id', $this->organizationId)->delete(); }
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
