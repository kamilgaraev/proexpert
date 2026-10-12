<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Models\RagIndexRun;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexingCoordinator;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

final class RagRecoveryLookupIndexTest extends TestCase
{
    public function test_backlogged_live_queue_recovery_skips_ineligible_history_without_changing_candidates(): void
    {
        $organization = Organization::withoutEvents(fn () => Organization::factory()->create());
        DB::table('ai_rag_index_runs')->delete();
        DB::statement(<<<'SQL'
            INSERT INTO ai_rag_index_runs
                (organization_id, status, mode, source_type, entity_type, entity_id, queued_at, created_at, updated_at)
            SELECT ?, CASE WHEN row_number <= 60000 THEN 'queued' ELSE 'succeeded' END,
                'async', 'estimate', 'estimate_item', row_number,
                NOW() - INTERVAL '1 hour', NOW() - INTERVAL '1 hour', NOW() - INTERVAL '1 hour'
            FROM generate_series(1, 200000) AS row_number
            SQL, [$organization->id]);
        $pending = RagIndexRun::query()->create([
            'organization_id' => $organization->id,
            'status' => 'queued',
            'mode' => 'async',
            'source_type' => 'estimate',
            'entity_type' => 'estimate_item',
            'entity_id' => 200001,
            'last_error' => 'dispatch_pending:'.str_repeat('a', 32),
            'queued_at' => now()->subHour(),
        ]);
        $bulk = RagIndexRun::query()->create([
            'organization_id' => $organization->id,
            'status' => 'queued',
            'mode' => 'async',
            'source_type' => 'estimate',
            'queued_at' => now()->subHour(),
        ]);
        $running = RagIndexRun::query()->create([
            'organization_id' => $organization->id,
            'status' => 'running',
            'mode' => 'async',
            'source_type' => 'estimate',
            'entity_type' => 'estimate_item',
            'entity_id' => 200002,
            'lease_expires_at' => now()->subMinute(),
            'queued_at' => now()->subHour(),
        ]);
        DB::statement('ANALYZE ai_rag_index_runs');

        $query = $this->recoveryQuery();
        $this->assertSame([$pending->id, $bulk->id, $running->id], $query->pluck('id')->all());
        $plan = DB::selectOne('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$query->toSql(), $query->getBindings());
        $decoded = json_decode((string) $plan->{'QUERY PLAN'}, true, 512, JSON_THROW_ON_ERROR);
        $nodes = $this->nodes($decoded[0]['Plan']);
        $names = array_column($nodes, 'Index Name');
        $this->assertContains('ai_rag_runs_recovery_candidates_idx', $names);
        $filtered = array_sum(array_column($nodes, 'Rows Removed by Filter'));
        $this->assertLessThan(100, $filtered);
        $this->assertSame(3, $decoded[0]['Plan']['Actual Rows']);
    }

    private function recoveryQuery(): Builder
    {
        $coordinator = (new ReflectionClass(RagIndexingCoordinator::class))->newInstanceWithoutConstructor();
        $eligibility = new ReflectionMethod(RagIndexingCoordinator::class, 'applyRecoveryEligibility');

        return RagIndexRun::query()
            ->whereIn('status', ['queued', 'running'])
            ->where(function (Builder $query) use ($coordinator, $eligibility): void {
                $eligibility->invoke($coordinator, $query, now(), true, false);
            })
            ->orderBy('id')
            ->limit(25);
    }

    private function nodes(array $node): array
    {
        $nodes = [$node];
        foreach ($node['Plans'] ?? [] as $child) {
            $nodes = [...$nodes, ...$this->nodes($child)];
        }

        return $nodes;
    }
}
