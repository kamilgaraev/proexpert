<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class RagEntityLookupIndexTest extends TestCase
{
    public function test_cross_organization_lookup_uses_entity_index_and_recovers_invalid_build(): void
    {
        while (DB::connection()->transactionLevel() > 0) {
            DB::rollBack();
        }

        $migration = require base_path('database/migrations/2026_10_10_190000_add_rag_entity_lookup_index.php');
        $originalPath = DB::selectOne('SHOW search_path')->search_path;
        $originalIndex = DB::selectOne("SELECT to_regclass('public.ai_rag_sources_entity_lookup_idx') AS name")->name;
        $needsPublicIndex = $originalIndex === null;
        $createdPublicIndex = false;
        $createdSchema = false;

        try {
            if ($needsPublicIndex) {
                DB::statement('CREATE INDEX CONCURRENTLY ai_rag_sources_entity_lookup_idx ON public.ai_rag_sources (id)');
                $createdPublicIndex = true;
                $originalIndex = DB::selectOne("SELECT to_regclass('public.ai_rag_sources_entity_lookup_idx') AS name")->name;
            }
            DB::statement('CREATE SCHEMA rag_entity_lookup_test');
            $createdSchema = true;
            DB::statement('SET search_path TO rag_entity_lookup_test, public');
            DB::statement('CREATE TABLE rag_entity_lookup_test.ai_rag_sources (LIKE public.ai_rag_sources INCLUDING DEFAULTS)');
            DB::statement("INSERT INTO ai_rag_sources (id, organization_id, project_id, source_type, entity_type, entity_id, title, checksum, metadata) "
                ."SELECT n, 38 + n % 3, NULL, 'contract', 'contract', n::text, 'Contract', md5(n::text), '{}'::jsonb FROM generate_series(1, 20000) n");
            DB::statement("INSERT INTO ai_rag_sources (id, organization_id, project_id, source_type, entity_type, entity_id, title, checksum, metadata) VALUES "
                ."(20001, 38, NULL, 'contract', 'contract', '424242', 'Contract', md5('20001'), '{}'::jsonb), "
                ."(20002, 84, 10, 'contract', 'contract', '424242', 'Contract', md5('20002'), '{}'::jsonb), "
                ."(20003, 85, 11, 'contract', 'contract', '424242', 'Contract', md5('20003'), '{}'::jsonb)");
            DB::statement('CREATE INDEX ai_rag_sources_type_entity_idx ON ai_rag_sources (organization_id, source_type, entity_type, entity_id)');
            DB::statement('CREATE INDEX ai_rag_sources_identity_scope_idx ON ai_rag_sources (organization_id, identity_project_id, source_type)');
            DB::statement('ANALYZE ai_rag_sources');

            $base = DB::table('ai_rag_sources')->select(['organization_id', 'project_id'])->distinct()
                ->where('source_type', 'contract')->where('entity_type', 'contract')->where('entity_id', '424242');
            $read = (clone $base)->where('organization_id', '!=', 38);
            $before = $this->plan($read->toSql(), $read->getBindings());

            try {
                DB::statement('CREATE UNIQUE INDEX CONCURRENTLY ai_rag_sources_entity_lookup_idx ON ai_rag_sources (source_type)');
                self::fail('Duplicate source types must leave an invalid index.');
            } catch (QueryException $exception) {
                self::assertSame('23505', $exception->errorInfo[0]);
            }

            self::assertSame(0, (int) DB::selectOne("SELECT indisvalid::integer AS valid FROM pg_index WHERE indexrelid = to_regclass('rag_entity_lookup_test.ai_rag_sources_entity_lookup_idx')")->valid);
            $migration->up();
            $migration->up();
            DB::statement('ANALYZE ai_rag_sources');

            self::assertSame([84, 85], (clone $read)->orderBy('organization_id')->get()->map(static fn (object $row): int => (int) $row->organization_id)->all());
            self::assertSame([38, 84, 85], (clone $base)->orderBy('organization_id')->get()->map(static fn (object $row): int => (int) $row->organization_id)->all());
            self::assertSame([], (clone $read)->where('entity_type', 'project')->get()->all());

            $after = $this->plan($read->toSql(), $read->getBindings());
            self::assertLessThan($before['Total Cost'], $after['Total Cost']);

            foreach ([$after, $this->plan($base->toSql(), $base->getBindings())] as $plan) {
                $nodes = $this->nodes($plan);
                self::assertContains('ai_rag_sources_entity_lookup_idx', array_column($nodes, 'Index Name'));
                self::assertNotContains('Seq Scan', array_column($nodes, 'Node Type'));
                foreach ($nodes as $node) {
                    if (($node['Relation Name'] ?? null) === 'ai_rag_sources') {
                        self::assertLessThanOrEqual(3, $node['Actual Rows'] + ($node['Rows Removed by Filter'] ?? 0));
                        self::assertLessThanOrEqual(16, ($node['Shared Hit Blocks'] ?? 0) + ($node['Shared Read Blocks'] ?? 0));
                    }
                }
            }

            $definition = DB::selectOne("SELECT pg_get_indexdef(to_regclass('rag_entity_lookup_test.ai_rag_sources_entity_lookup_idx')) AS definition")->definition;
            self::assertStringContainsString('(source_type, entity_type, entity_id) INCLUDE (organization_id, project_id)', $definition);
            $migration->down();
            $migration->down();
            self::assertNull(DB::selectOne("SELECT to_regclass('rag_entity_lookup_test.ai_rag_sources_entity_lookup_idx') AS name")->name);
            self::assertSame($originalIndex, DB::selectOne("SELECT to_regclass('public.ai_rag_sources_entity_lookup_idx') AS name")->name);
        } finally {
            DB::select('SELECT set_config(?, ?, false)', ['search_path', $originalPath]);
            if ($createdSchema) {
                DB::statement('DROP SCHEMA rag_entity_lookup_test CASCADE');
            }
            if ($createdPublicIndex) {
                DB::statement('DROP INDEX CONCURRENTLY IF EXISTS public.ai_rag_sources_entity_lookup_idx');
            }
        }
    }

    private function plan(string $sql, array $bindings): array
    {
        $result = DB::select('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$sql, $bindings);

        return json_decode($result[0]->{'QUERY PLAN'}, true, 512, JSON_THROW_ON_ERROR)[0]['Plan'];
    }

    private function nodes(array $plan): array
    {
        $nodes = [$plan];
        foreach ($plan['Plans'] ?? [] as $child) {
            array_push($nodes, ...$this->nodes($child));
        }

        return $nodes;
    }
}
