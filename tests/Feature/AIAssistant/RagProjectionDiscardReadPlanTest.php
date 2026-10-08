<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\Rag\RagExpectedSourceProjection;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer;
use App\Models\Organization;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

final class RagProjectionDiscardReadPlanTest extends TestCase
{
    public function test_remaining_generation_check_uses_the_identity_index_with_stale_dense_statistics(): void
    {
        Cache::flush();
        $owner = Organization::factory()->create();
        $foreign = Organization::factory()->create();
        $obsolete = '00000000-0000-4000-8000-000000000001';
        $neighbor = '00000000-0000-4000-8000-000000000002';
        $table = 'ai_rag_expected_sources';
        try {
            DB::statement('ALTER TABLE '.$table.' SET (autovacuum_enabled = false)');
            DB::statement('INSERT INTO '.$table.' (organization_id,generation,identity_project_id,source_type,entity_type,entity_id,identity_part_key,checksum,pending_since,created_at,updated_at) '
                ."SELECT ?,CAST(? AS uuid),0,'project','project',n::text,'',md5(n::text),NOW(),NOW(),NOW() FROM generate_series(1,20000) n", [$owner->id, $obsolete]);
            DB::statement('INSERT INTO '.$table.' (organization_id,generation,identity_project_id,source_type,entity_type,entity_id,identity_part_key,checksum,pending_since,created_at,updated_at) '
                ."SELECT ?,CAST(? AS uuid),0,'project','project',n::text,'',md5(n::text),NOW(),NOW(),NOW() FROM generate_series(1,1000) n", [$owner->id, $neighbor]);
            DB::statement('INSERT INTO '.$table.' (organization_id,generation,identity_project_id,source_type,entity_type,entity_id,identity_part_key,checksum,pending_since,created_at,updated_at) '
                ."VALUES (?,CAST(? AS uuid),0,'project','project','foreign','','test',NOW(),NOW(),NOW())", [$foreign->id, $obsolete]);
            DB::statement('ANALYZE '.$table);
            DB::table($table)->where('organization_id', $owner->id)->where('generation', $obsolete)->delete();
            DB::enableQueryLog();
            DB::flushQueryLog();
            $projection = new RagExpectedSourceProjection(Mockery::mock(RagIndexer::class));
            self::assertSame(0, $projection->discard((int) $owner->id, $obsolete, 0));
            $checks = array_values(array_filter(DB::getQueryLog(), static fn (array $query): bool => str_starts_with($query['query'], 'select ') && str_contains($query['query'], 'ai_rag_expected_sources')));
            self::assertCount(1, $checks);
            $result = DB::select('EXPLAIN (FORMAT JSON) '.$checks[0]['query'], $checks[0]['bindings']);
            $plan = json_decode($result[0]->{'QUERY PLAN'}, true, 512, JSON_THROW_ON_ERROR)[0]['Plan'];
            $nodes = $this->nodes($plan);
            self::assertNotEmpty(array_intersect(['ai_rag_expected_scope_idx', 'ai_rag_expected_identity_unique'], array_column($nodes, 'Index Name')));
            self::assertNotContains('Sort', array_column($nodes, 'Node Type'));
            self::assertNotContains('Incremental Sort', array_column($nodes, 'Node Type'));
            self::assertNotContains('Seq Scan', array_column($nodes, 'Node Type'));
            self::assertFalse(Cache::has('ai-rag-coverage-discard-generations:'.$owner->id));
            self::assertSame(1000, DB::table($table)->where('organization_id', $owner->id)->where('generation', $neighbor)->count());
            self::assertSame(1, DB::table($table)->where('organization_id', $foreign->id)->where('generation', $obsolete)->count());
        } finally {
            DB::disableQueryLog();
            DB::statement('ALTER TABLE '.$table.' RESET (autovacuum_enabled)');
        }
    }

    private function nodes(array $plan): array
    {
        $nodes = [$plan];
        foreach ($plan['Plans'] ?? [] as $child) {
            $nodes = [...$nodes, ...$this->nodes($child)];
        }

        return $nodes;
    }
}
