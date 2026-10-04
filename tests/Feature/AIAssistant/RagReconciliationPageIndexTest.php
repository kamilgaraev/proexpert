<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class RagReconciliationPageIndexTest extends TestCase
{
    public function test_reconcile_index_scopes_ordered_pages_recovers_invalid_builds_and_respects_schema(): void
    {
        while (DB::connection()->transactionLevel() > 0) { DB::rollBack(); }
        $migration = require base_path('database/migrations/2026_10_04_220000_add_rag_reconciliation_page_index.php');
        $originalPath = DB::selectOne('SHOW search_path')->search_path;
        DB::statement('CREATE SCHEMA rag_reconcile_index_test');
        try {
            DB::statement('SET search_path TO rag_reconcile_index_test, public');
            DB::statement('CREATE TABLE rag_reconcile_index_test.ai_rag_sources (LIKE public.ai_rag_sources INCLUDING DEFAULTS)');
            DB::statement("INSERT INTO ai_rag_sources (id, organization_id, project_id, source_type, entity_type, entity_id, title, checksum, metadata, last_reconciled_at) "
                ."SELECT n, CASE WHEN n <= 300 THEN 38 WHEN n > 2997 THEN 84 ELSE 85 END, CASE WHEN n % 2 = 0 THEN 10 ELSE 11 END, 'estimate', 'estimate', n::text, 'Estimate', md5(n::text), "
                ."jsonb_build_object('large', repeat(md5(n::text), 1000)), CASE WHEN n <= 150 THEN NULL WHEN n <= 300 THEN NOW() - INTERVAL '2 days' ELSE NOW() END FROM generate_series(1, 3000) n");
            try {
                DB::statement('CREATE UNIQUE INDEX CONCURRENTLY ai_rag_sources_reconcile_page_idx ON rag_reconcile_index_test.ai_rag_sources (organization_id)');
                self::fail('Duplicate organizations must leave an invalid index.');
            } catch (QueryException $exception) {
                self::assertSame('23505', $exception->errorInfo[0]);
            }
            self::assertSame(0, (int) DB::selectOne("SELECT indisvalid::integer AS valid FROM pg_index WHERE indexrelid = to_regclass('rag_reconcile_index_test.ai_rag_sources_reconcile_page_idx')")->valid);
            $migration->up();
            $migration->up();
            DB::statement('ANALYZE rag_reconcile_index_test.ai_rag_sources');
            $base = DB::table('ai_rag_sources')->select('id')->where('source_type', 'estimate')
                ->where(static fn ($q) => $q->whereNull('last_reconciled_at')->orWhere('last_reconciled_at', '<', now()->subDay()))
                ->orderBy('id')->limit(100);
            $query = (clone $base)->where('organization_id', 38);
            self::assertSame(range(1, 100), (clone $query)->pluck('id')->map(static fn ($id): int => (int) $id)->all());
            self::assertSame(range(101, 200), (clone $query)->where('id', '>', 100)->pluck('id')->map(static fn ($id): int => (int) $id)->all());
            self::assertSame(range(2, 200, 2), (clone $query)->where('project_id', 10)->pluck('id')->map(static fn ($id): int => (int) $id)->all());
            self::assertSame([], (clone $query)->where('source_type', 'project')->get()->all());
            foreach ([[$query, 300], [(clone $query)->where('project_id', 10), 300], [(clone $base)->where('organization_id', 84), 3]] as [$read, $scopeSize]) {
                $result = DB::select('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$read->toSql(), $read->getBindings());
                $plan = json_decode($result[0]->{'QUERY PLAN'}, true, 512, JSON_THROW_ON_ERROR)[0]['Plan'];
                $this->assertScopedPlan($plan, $scopeSize);
            }
            DB::table('ai_rag_sources')->where('id', '<=', 100)->update(['last_reconciled_at' => now()]);
            self::assertSame(range(101, 200), (clone $query)->pluck('id')->map(static fn ($id): int => (int) $id)->all());
            $result = DB::select('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$query->toSql(), $query->getBindings());
            $plan = json_decode($result[0]->{'QUERY PLAN'}, true, 512, JSON_THROW_ON_ERROR)[0]['Plan'];
            $this->assertScopedPlan($plan, 300);
            $definition = DB::selectOne("SELECT pg_get_indexdef(to_regclass('rag_reconcile_index_test.ai_rag_sources_reconcile_page_idx')) AS definition")->definition;
            self::assertStringNotContainsString('last_reconciled_at', $definition);
            $migration->down();
            $migration->down();
            self::assertNull(DB::selectOne("SELECT to_regclass('rag_reconcile_index_test.ai_rag_sources_reconcile_page_idx') AS name")->name);
            self::assertNotNull(DB::selectOne("SELECT to_regclass('public.ai_rag_sources_reconcile_page_idx') AS name")->name);
        } finally {
            DB::select('SELECT set_config(?, ?, false)', ['search_path', $originalPath]);
            DB::statement('DROP SCHEMA rag_reconcile_index_test CASCADE');
        }
    }

    private function assertScopedPlan(array $plan, int $scopeSize): void
    {
        $nodes = [];
        $visit = static function (array $node) use (&$visit, &$nodes): void {
            $nodes[] = $node;
            foreach ($node['Plans'] ?? [] as $child) { $visit($child); }
        };
        $visit($plan);
        self::assertContains('ai_rag_sources_reconcile_page_idx', array_column($nodes, 'Index Name'));
        self::assertNotContains('Seq Scan', array_column($nodes, 'Node Type'));
        foreach ($nodes as $node) {
            if (($node['Relation Name'] ?? null) === 'ai_rag_sources') {
                self::assertLessThanOrEqual($scopeSize, $node['Actual Rows'] + ($node['Rows Removed by Filter'] ?? 0));
            }
        }
    }
}
