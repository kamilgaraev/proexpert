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

final class RagProjectionRetentionReadPlanTest extends TestCase
{
    public function test_cleanup_reads_uuid_ranges_without_scanning_the_dense_active_generation(): void
    {
        Cache::flush();
        $owner = Organization::factory()->create();
        $foreign = Organization::factory()->create();
        $active = '80000000-0000-4000-8000-000000000001';
        $obsolete = '10000000-0000-4000-8000-000000000001';
        $table = 'ai_rag_expected_sources';
        DB::statement('INSERT INTO '.$table.' (organization_id,generation,identity_project_id,source_type,entity_type,entity_id,identity_part_key,checksum,pending_since,created_at,updated_at) '
            ."SELECT ?,CAST(? AS uuid),0,'project','project',n::text,'',md5(n::text),NOW()-INTERVAL '4 hours',NOW()-INTERVAL '4 hours',NOW() FROM generate_series(1,20000) n", [$owner->id, $active]);
        DB::statement('INSERT INTO '.$table.' (organization_id,generation,identity_project_id,source_type,entity_type,entity_id,identity_part_key,checksum,pending_since,created_at,updated_at) '
            ."SELECT ?,CAST(? AS uuid),0,'project','project',n::text,'',md5(n::text),NOW()-INTERVAL '4 hours',NOW()-INTERVAL '4 hours',NOW() FROM generate_series(1,1000) n", [$foreign->id, $obsolete]);
        DB::statement('ANALYZE '.$table);
        $revision = (int) Cache::get('ai-rag-coverage-revision:'.$owner->id, 0);
        Cache::put('ai-rag-coverage:'.$owner->id.':0:*:'.$revision, ['projection_generation' => $active], 300);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $projection = new RagExpectedSourceProjection(Mockery::mock(RagIndexer::class));
            self::assertSame(['deleted' => 0, 'locked' => false], $projection->pruneOrganization((int) $owner->id));
            $reads = array_values(array_filter(DB::getQueryLog(), static fn (array $query): bool => str_starts_with($query['query'], 'select ') && str_contains($query['query'], 'ai_rag_expected_sources')));
            self::assertCount(1, $reads);
            $result = DB::select('EXPLAIN (FORMAT JSON) '.$reads[0]['query'], $reads[0]['bindings']);
            $plan = json_decode($result[0]->{'QUERY PLAN'}, true, 512, JSON_THROW_ON_ERROR)[0]['Plan'];
            $nodes = $this->nodes($plan);
            self::assertNotContains('Seq Scan', array_column($nodes, 'Node Type'));
            self::assertNotEmpty(array_intersect(['ai_rag_expected_scope_idx', 'ai_rag_expected_identity_unique', 'ai_rag_expected_type_entity_idx', 'ai_rag_expected_coverage_cover_idx'], array_column($nodes, 'Index Name')));
            self::assertSame(20000, DB::table($table)->where('organization_id', $owner->id)->count());
            self::assertSame(1000, DB::table($table)->where('organization_id', $foreign->id)->count());
        } finally {
            DB::disableQueryLog();
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
