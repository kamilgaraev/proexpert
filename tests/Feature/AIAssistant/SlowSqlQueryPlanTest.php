<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Models\RagIndexRun;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexingCoordinator;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\CommercialProposalBusinessRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\OrganizationReportingRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\ProcurementBusinessRagSource;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

final class SlowSqlQueryPlanTest extends TestCase
{
    public function test_norm_first_and_following_pages_do_not_trigger_jit_compilation(): void
    {
        $dataset = DB::table('estimate_dataset_versions')->insertGetId(['source_type' => 'fsnb_2022', 'version_key' => 'slow-sql-test', 'bucket' => 'testing', 'prefix' => 'norms', 'status' => 'parsed', 'rows_imported' => 56000, 'finished_at' => now()]);
        $collection = DB::table('estimate_norm_collections')->insertGetId(['dataset_version_id' => $dataset, 'code' => 'test', 'name' => 'Нормы', 'norm_type' => 'gesn', 'source_file' => 'testing.xml']);
        DB::statement("INSERT INTO estimate_norms (collection_id, code, name, unit) SELECT ?, 'norm-' || n, 'Тестовая норма ' || n, 'м2' FROM generate_series(1, 56000) n", [$collection]);
        DB::statement('ANALYZE estimate_norms');
        $query = (new OrganizationReportingRagSource)->scopedQuery('approved_estimate_norm', 1);
        foreach ([0, 10000] as $cursor) {
            $page = (clone $query)->where('estimate_norms.id', '>', $cursor)->orderBy('estimate_norms.id')->limit(50);
            $this->assertCheapPlan($page);
        }
    }

    public function test_empty_receipt_returns_and_proposal_pages_do_not_compile_thousands_of_functions(): void
    {
        foreach ([
            'purchase_receipt_return' => new ProcurementBusinessRagSource,
            'commercial_proposal_line_item' => new CommercialProposalBusinessRagSource,
            'commercial_proposal_approval' => new CommercialProposalBusinessRagSource,
        ] as $type => $source) {
            $query = (new ReflectionMethod($source, 'collectionQuery'))->invoke($source, $type, 1);
            $this->assertCheapPlan($query->orderBy('id')->limit(50));
        }
    }

    public function test_organization_delete_reports_actual_cascade_cost_in_the_isolated_database(): void
    {
        $organization = Organization::withoutEvents(fn (): Organization => Organization::factory()->create());
        $plan = json_decode(DB::select('EXPLAIN (ANALYZE, FORMAT JSON) DELETE FROM organizations WHERE id = ?', [$organization->id])[0]->{'QUERY PLAN'}, true, 512, JSON_THROW_ON_ERROR)[0];
        $slowTriggers = array_values(array_filter($plan['Triggers'] ?? [], static fn (array $trigger): bool => $trigger['Time'] >= 20));
        fwrite(STDERR, json_encode(['organization_delete_ms' => $plan['Execution Time'], 'slow_triggers' => $slowTriggers], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL);
        self::assertNull(DB::table('organizations')->where('id', $organization->id)->first());
    }

    public function test_online_search_and_lookup_migrations_are_idempotent_and_indexes_are_valid(): void
    {
        self::assertSame(1, DB::connection()->transactionLevel());
        DB::rollBack();
        try {
            foreach (['2026_10_01_120010_add_estimate_item_russian_search_index.php', '2026_10_02_120000_add_slow_query_lookup_indexes.php'] as $file) {
                $migration = require database_path('migrations/'.$file);
                self::assertFalse($migration->withinTransaction);
                $migration->up();
                $migration->up();
            }
            $indexes = DB::select("SELECT c.relname, i.indisvalid FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname IN ('estimate_items_russian_name_fts_idx','estimate_items_normative_rate_code_idx','ai_usage_records_period_idx','ai_rag_runs_latest_attempt_idx','ai_rag_runs_bulk_scope_idx')");
            self::assertCount(5, $indexes);
            foreach ($indexes as $index) {
                self::assertTrue((bool) $index->indisvalid, $index->relname);
            }
        } finally {
            DB::beginTransaction();
        }
    }

    public function test_usage_period_uses_its_index_without_changing_id_order(): void
    {
        DB::statement("INSERT INTO ai_usage_records (provider, model, operation, occurred_at) SELECT 'testing', 'testing', 'rag_index', CASE WHEN n <= 100 THEN '2026-10-01'::timestamptz ELSE '2026-09-01'::timestamptz END FROM generate_series(1, 20000) n");
        DB::statement('ANALYZE ai_usage_records');
        $query = DB::table('ai_usage_records')->select(['id', 'organization_id', 'provider', 'model', 'operation', 'total_cost_rub', 'currency', 'metadata'])
            ->where('occurred_at', '>=', '2026-10-01')->where('occurred_at', '<', '2026-10-02')->orderBy('id');
        $plan = DB::select('EXPLAIN (FORMAT JSON) '.$query->toSql(), $query->getBindings())[0]->{'QUERY PLAN'};
        self::assertStringContainsString('ai_usage_records_period_idx', $plan);
        $ids = $query->pluck('id')->all();
        self::assertCount(100, $ids);
        $ordered = $ids;
        sort($ordered);
        self::assertSame($ordered, $ids);
    }

    public function test_latest_attempt_order_keeps_unindexed_organizations_first_and_uses_the_latest_entity_attempt(): void
    {
        $organizations = Organization::withoutEvents(fn () => Organization::factory()->count(3)->create());
        foreach ([[$organizations[0], '2026-09-01'], [$organizations[0], '2026-10-01'], [$organizations[1], '2026-09-15']] as [$organization, $createdAt]) {
            RagIndexRun::query()->forceCreate(['organization_id' => $organization->id, 'entity_type' => 'project', 'entity_id' => '1', 'status' => RagIndexRun::STATUS_SUCCEEDED, 'mode' => RagIndexRun::MODE_ASYNC, 'created_at' => $createdAt]);
        }
        $query = Organization::query()->whereIn('id', $organizations->modelKeys());
        (new ReflectionMethod(RagIndexingCoordinator::class, 'orderByOldestRagAttempt'))->invoke(app(RagIndexingCoordinator::class), $query);
        self::assertSame([$organizations[2]->id, $organizations[1]->id, $organizations[0]->id], $query->pluck('id')->all());
    }

    public function test_dataset_lifecycle_update_reports_guard_cost_without_disabling_immutability(): void
    {
        $dataset = DB::table('estimate_dataset_versions')->insertGetId(['source_type' => 'fsnb_2022', 'version_key' => 'update-cost', 'bucket' => 'testing', 'prefix' => 'norms', 'status' => 'created']);
        $plan = json_decode(DB::select("EXPLAIN (ANALYZE, FORMAT JSON) UPDATE estimate_dataset_versions SET status = ?, started_at = CURRENT_TIMESTAMP, finished_at = NULL, meta = '{}'::json, updated_at = CURRENT_TIMESTAMP WHERE id = ?", ['importing', $dataset])[0]->{'QUERY PLAN'}, true, 512, JSON_THROW_ON_ERROR)[0];
        fwrite(STDERR, json_encode(['dataset_update_ms' => $plan['Execution Time'], 'triggers' => $plan['Triggers'] ?? []], JSON_THROW_ON_ERROR).PHP_EOL);
        self::assertSame('importing', DB::table('estimate_dataset_versions')->where('id', $dataset)->value('status'));
    }

    private function assertCheapPlan(Builder $query): void
    {
        $plan = json_decode(DB::select('EXPLAIN (FORMAT JSON) '.$query->toSql(), $query->getBindings())[0]->{'QUERY PLAN'}, true, 512, JSON_THROW_ON_ERROR)[0];
        self::assertLessThan(100000, $plan['Plan']['Total Cost'], $query->getModel()->getTable());
        self::assertArrayNotHasKey('JIT', $plan, $query->getModel()->getTable());
    }
}
