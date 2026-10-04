<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AssistantResourcePriceAccessIndexTest extends TestCase
{
    public function test_identity_access_reads_publication_fields_from_the_covering_index(): void
    {
        $dataset = DB::table('estimate_dataset_versions')->insertGetId([
            'source_type' => 'fsbc', 'version_key' => 'access-index', 'bucket' => 'testing', 'prefix' => 'prices',
            'status' => 'parsed', 'finished_at' => now(), 'rows_imported' => 1000, 'errors_count' => 0,
        ]);
        $rows = [];
        for ($number = 0; $number < 1000; $number++) {
            $rows[] = ['dataset_version_id' => $dataset, 'resource_code' => 'access-'.$number,
                'price_type' => 'material', 'base_price' => $number % 2 === 0 ? 10 : 0];
        }
        DB::table('estimate_resource_prices')->insert($rows);
        $ids = DB::table('estimate_resource_prices')->where('dataset_version_id', $dataset)->orderBy('id')->limit(2)->pluck('id')->all();
        DB::statement('ANALYZE estimate_resource_prices');

        $query = DB::table('estimate_resource_prices')->select([
            'id', 'regional_price_version_id', 'region_id', 'price_zone_id', 'period_id',
            'dataset_version_id', 'construction_resource_id', 'base_price',
        ])->whereRaw('id = ANY(CAST(? AS bigint[]))', ['{'.implode(',', $ids).'}'])->where('base_price', '>', 0);
        self::assertSame([$ids[0]], $query->pluck('id')->all());

        $result = DB::select('EXPLAIN (FORMAT JSON) '.$query->toSql(), $query->getBindings());
        $plan = json_decode($result[0]->{'QUERY PLAN'}, true, 512, JSON_THROW_ON_ERROR)[0]['Plan'];
        self::assertSame('Index Only Scan', $plan['Node Type']);
        self::assertSame('rag_prices_access_identity_cover_idx', $plan['Index Name']);
    }

    public function test_invalid_index_retry_and_rollback_use_the_tables_schema(): void
    {
        while (DB::connection()->transactionLevel() > 0) { DB::rollBack(); }
        $migration = require base_path('database/migrations/2026_10_04_160000_add_rag_price_access_covering_index.php');
        $originalPath = DB::selectOne('SHOW search_path')->search_path;
        $migration->down();
        $dataset = DB::table('estimate_dataset_versions')->insertGetId([
            'source_type' => 'fsbc', 'version_key' => 'index-retry', 'bucket' => 'testing', 'prefix' => 'prices',
            'status' => 'parsed', 'finished_at' => now(), 'rows_imported' => 2, 'errors_count' => 0,
        ]);
        DB::table('estimate_resource_prices')->insert([
            ['dataset_version_id' => $dataset, 'resource_code' => 'retry-1', 'price_type' => 'material', 'base_price' => 10],
            ['dataset_version_id' => $dataset, 'resource_code' => 'retry-2', 'price_type' => 'material', 'base_price' => 10],
        ]);
        try {
            DB::statement('CREATE UNIQUE INDEX CONCURRENTLY rag_prices_access_identity_cover_idx ON public.estimate_resource_prices (price_type) WHERE base_price > 0');
            self::fail('The duplicate fixture must leave an invalid concurrent index.');
        } catch (QueryException $exception) {
            self::assertSame('23505', $exception->errorInfo[0]);
        }
        $state = DB::selectOne("SELECT indisvalid::integer AS valid FROM pg_index WHERE indexrelid = to_regclass('public.rag_prices_access_identity_cover_idx')");
        self::assertSame(0, (int) $state->valid);

        DB::statement('CREATE SCHEMA assistant_price_access_retry');
        try {
            DB::statement('CREATE TABLE assistant_price_access_retry.retry_guard (id bigint)');
            DB::statement('CREATE INDEX rag_prices_access_identity_cover_idx ON assistant_price_access_retry.retry_guard (id)');
            DB::statement('SET search_path TO assistant_price_access_retry, public');
            self::assertSame('assistant_price_access_retry', DB::selectOne('SELECT current_schema() AS name')->name);

            $migration->up();
            $state = DB::selectOne("SELECT indisvalid::integer AS valid FROM pg_index WHERE indexrelid = to_regclass('public.rag_prices_access_identity_cover_idx')");
            self::assertSame(1, (int) $state->valid);
            $migration->down();
            self::assertNull(DB::selectOne("SELECT to_regclass('public.rag_prices_access_identity_cover_idx') AS name")->name);
            self::assertNotNull(DB::selectOne("SELECT to_regclass('assistant_price_access_retry.rag_prices_access_identity_cover_idx') AS name")->name);
            $migration->up();
        } finally {
            DB::select('SELECT set_config(?, ?, false)', ['search_path', $originalPath]);
            DB::statement('DROP SCHEMA assistant_price_access_retry CASCADE');
        }
    }
}
