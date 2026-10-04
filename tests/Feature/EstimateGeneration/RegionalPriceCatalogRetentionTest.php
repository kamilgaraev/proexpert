<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimatePricePeriod;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimatePriceZone;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateRegion;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Conjuncture\ResidentialConjuncturePriceImporter;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs\FgiscsBuildingResourcePricePriority;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs\FgiscsBuildingResourcePriceUpdateService;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs\FgiscsClient;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs\FgiscsRegionalCatalogService;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs\RegionalPriceImportLifecycleService;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs\RegionalPriceVersionResolver;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Import\FgiscsBuildingResourcePriceSpreadsheetParser;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Retention\RegionalPriceCatalogRetentionService;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

final class RegionalPriceCatalogRetentionTest extends TestCase
{
    public function refreshDatabase(): void {}

    protected function tearDown(): void
    {
        try {
            (require base_path('app/BusinessModules/Addons/EstimateGeneration/migrations/2026_10_04_000200_add_regional_price_retention_guards.php'))->down();
        } finally {
            parent::tearDown();
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        $tables = ['estimate_generation_ai_usage', 'estimate_generation_package_item_project_price_inputs', 'estimate_generation_package_item_price_inputs',
            'estimate_generation_package_items', 'estimate_items', 'estimates', 'estimate_regional_price_activations', 'estimate_resource_prices',
            'estimate_regional_price_versions', 'estimate_price_periods'];
        foreach ($tables as $table) {
            DB::statement('DROP TABLE IF EXISTS public.'.$table.' CASCADE');
        }
        DB::unprepared(<<<'SQL'
CREATE TABLE public.estimate_price_periods(id bigint PRIMARY KEY, year int NOT NULL, quarter int NOT NULL);
CREATE TABLE public.estimate_regional_price_versions(id bigint PRIMARY KEY, source text NOT NULL DEFAULT 'fgis_labor_prices', region_id bigint NOT NULL DEFAULT 1,
 price_zone_id bigint NOT NULL DEFAULT 1, period_id bigint NOT NULL REFERENCES public.estimate_price_periods(id), status text NOT NULL,
 activated_at timestamptz, updated_at timestamptz NOT NULL DEFAULT '2020-01-01', metadata jsonb, version_key text);
CREATE TABLE public.estimate_resource_prices(id bigint PRIMARY KEY, regional_price_version_id bigint REFERENCES public.estimate_regional_price_versions(id) ON DELETE SET NULL, dataset_version_id bigint);
CREATE TABLE public.estimate_regional_price_activations(id bigint PRIMARY KEY, region_id bigint, price_zone_id bigint,
 active_version_id bigint REFERENCES public.estimate_regional_price_versions(id) ON DELETE RESTRICT,
 previous_version_id bigint REFERENCES public.estimate_regional_price_versions(id) ON DELETE SET NULL);
CREATE TABLE public.estimates(id bigint PRIMARY KEY, estimate_regional_price_version_id bigint REFERENCES public.estimate_regional_price_versions(id) ON DELETE SET NULL,
 regional_price_snapshot jsonb, metadata jsonb);
CREATE TABLE public.estimate_items(id bigint PRIMARY KEY, resources jsonb, resource_calculation jsonb, custom_resources jsonb, metadata jsonb);
CREATE TABLE public.estimate_generation_package_items(id bigint PRIMARY KEY, regional_price_version_id bigint, price_snapshot jsonb, metadata jsonb, pricing_finalized_at timestamptz);
CREATE TABLE public.estimate_generation_package_item_price_inputs(id bigint PRIMARY KEY, package_item_id bigint,
 resource_price_id bigint REFERENCES public.estimate_resource_prices(id) ON DELETE RESTRICT);
CREATE TABLE public.estimate_generation_package_item_project_price_inputs(id bigint PRIMARY KEY, package_item_id bigint,
 resource_price_id bigint REFERENCES public.estimate_resource_prices(id) ON DELETE RESTRICT);
CREATE TABLE public.estimate_generation_ai_usage(id bigint PRIMARY KEY, price_snapshot jsonb);
CREATE OR REPLACE FUNCTION public.eg_pricing_catalog_immutable_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP IN ('DELETE','UPDATE') AND EXISTS(SELECT 1 FROM public.estimate_regional_price_versions WHERE id=OLD.regional_price_version_id AND status IN ('active','superseded','rolled_back')) THEN RAISE EXCEPTION 'published_price_immutable'; END IF;
 IF TG_OP='INSERT' AND EXISTS(SELECT 1 FROM public.estimate_regional_price_versions WHERE id=NEW.regional_price_version_id AND status IN ('active','superseded','rolled_back')) THEN RAISE EXCEPTION 'published_price_immutable'; END IF;
 RETURN CASE WHEN TG_OP='DELETE' THEN OLD ELSE NEW END;
END; $$;
CREATE OR REPLACE FUNCTION public.eg_used_pricing_source_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_TABLE_NAME='estimate_regional_price_versions' THEN
   IF TG_OP IN ('DELETE','UPDATE') AND OLD.status IN ('active','superseded','rolled_back') THEN RAISE EXCEPTION 'published_version_immutable'; END IF;
 END IF;
 RETURN CASE WHEN TG_OP='DELETE' THEN OLD ELSE NEW END;
END; $$;
CREATE OR REPLACE FUNCTION public.eg_project_material_price_reference_immutable_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 RETURN CASE WHEN TG_OP='DELETE' THEN OLD ELSE NEW END;
END; $$;
CREATE TRIGGER eg_active_resource_price_immutable BEFORE INSERT OR UPDATE OR DELETE ON public.estimate_resource_prices FOR EACH ROW EXECUTE FUNCTION public.eg_pricing_catalog_immutable_guard();
CREATE TRIGGER eg_used_resource_price_immutable BEFORE UPDATE OR DELETE ON public.estimate_resource_prices FOR EACH ROW EXECUTE FUNCTION public.eg_used_pricing_source_guard();
CREATE TRIGGER eg_finalized_project_material_price_immutable BEFORE UPDATE OR DELETE ON public.estimate_resource_prices FOR EACH ROW EXECUTE FUNCTION public.eg_project_material_price_reference_immutable_guard();
CREATE TRIGGER eg_used_regional_version_immutable BEFORE UPDATE OR DELETE ON public.estimate_regional_price_versions FOR EACH ROW EXECUTE FUNCTION public.eg_used_pricing_source_guard();
SQL);
        for ($id = 1; $id <= 6; $id++) {
            DB::table('estimate_price_periods')->insert(['id' => $id, 'year' => 2024 + intdiv($id - 1, 4), 'quarter' => (($id - 1) % 4) + 1]);
        }
        for ($id = 1; $id <= 6; $id++) {
            $this->version($id, $id, $id === 6 ? 'active' : 'superseded');
        }
        (require base_path('app/BusinessModules/Addons/EstimateGeneration/migrations/2026_10_04_000200_add_regional_price_retention_guards.php'))->up();
    }

    public function test_dry_run_then_bounded_batches_delete_old_quarters_and_are_idempotent(): void
    {
        $service = app(RegionalPriceCatalogRetentionService::class);
        $preview = $service->prune();
        self::assertSame([1, 2], $preview['eligible_versions']);
        self::assertSame(0, $preview['deleted_rows']);
        self::assertSame(7, DB::table('estimate_resource_prices')->count());
        $first = $service->prune(true, limit: 1, batch: 1);
        self::assertSame(1, $first['deleted_rows']);
        self::assertTrue(DB::table('estimate_regional_price_versions')->where('id', 1)->exists());
        $second = $service->prune(true, batch: 1);
        self::assertSame(2, $second['deleted_rows']);
        self::assertSame(2, $second['deleted_versions']);
        self::assertSame([3, 4, 5, 6], DB::table('estimate_regional_price_versions')->orderBy('id')->pluck('id')->all());
        self::assertSame(0, $service->prune(true)['deleted_rows']);
    }

    public function test_active_previous_estimates_and_unfinalized_package_price_references_survive(): void
    {
        DB::table('estimate_regional_price_activations')->insert(['id' => 1, 'active_version_id' => 6, 'previous_version_id' => 1]);
        DB::table('estimate_generation_package_items')->insert(['id' => 1]);
        DB::table('estimate_generation_package_item_price_inputs')->insert(['id' => 1, 'package_item_id' => 1, 'resource_price_id' => 20]);
        $result = app(RegionalPriceCatalogRetentionService::class)->prune(true);
        self::assertSame(0, $result['deleted_rows']);
        self::assertEqualsCanonicalizing([1, 2], $result['protected_versions']);
    }

    public function test_snapshot_only_version_and_resource_source_reference_survive(): void
    {
        DB::table('estimates')->insert(['id' => 1, 'regional_price_snapshot' => json_encode(['version_id' => 1])]);
        DB::table('estimate_generation_ai_usage')->insert(['id' => 1, 'price_snapshot' => json_encode(['resources' => [['source_reference' => 'estimate_resource_prices:20']]])]);
        self::assertSame(0, app(RegionalPriceCatalogRetentionService::class)->prune(true)['deleted_rows']);
    }

    public function test_latest_published_revision_is_retained_for_each_of_four_distinct_quarters(): void
    {
        $this->version(7, 5, 'superseded');
        $result = app(RegionalPriceCatalogRetentionService::class)->prune(true, batch: 1);
        self::assertSame(3, $result['deleted_versions']);
        self::assertSame([3, 4, 6, 7], DB::table('estimate_regional_price_versions')->orderBy('id')->pluck('id')->all());
    }

    public function test_project_material_inputs_and_direct_estimate_contexts_are_protected(): void
    {
        DB::table('estimate_generation_package_item_project_price_inputs')->insert(['id' => 1, 'resource_price_id' => 10]);
        DB::table('estimates')->insert(['id' => 1, 'estimate_regional_price_version_id' => 2]);
        self::assertSame(0, app(RegionalPriceCatalogRetentionService::class)->prune(true)['deleted_rows']);
    }

    public function test_failed_grace_and_in_progress_imports_are_preserved(): void
    {
        $this->version(7, 1, 'failed');
        $this->version(8, 1, 'failed', now()->subDay()->toDateTimeString());
        $this->version(9, 1, 'parsing');
        $result = app(RegionalPriceCatalogRetentionService::class)->prune(true);
        self::assertTrue(DB::table('estimate_regional_price_versions')->where('id', 8)->exists());
        self::assertTrue(DB::table('estimate_regional_price_versions')->where('id', 9)->exists());
        self::assertFalse(DB::table('estimate_regional_price_versions')->where('id', 7)->exists());
        self::assertSame(3, $result['deleted_versions']);
    }

    public function test_database_guards_reject_direct_deletes_of_used_and_active_sources(): void
    {
        DB::table('estimates')->insert(['id' => 1, 'estimate_regional_price_version_id' => 1]);
        foreach ([10, 60] as $priceId) {
            try {
                DB::transaction(static fn () => DB::table('estimate_resource_prices')->where('id', $priceId)->delete());
                self::fail('Guard accepted a protected source deletion.');
            } catch (QueryException $exception) {
                self::assertSame('P0001', $exception->errorInfo[0]);
            }
        }
        self::assertTrue(DB::table('estimate_resource_prices')->where('id', 10)->exists());
        self::assertTrue(DB::table('estimate_resource_prices')->where('id', 60)->exists());
    }

    public function test_mutex_skips_cleanup_while_sync_owns_lock(): void
    {
        $lock = Cache::lock(RegionalPriceCatalogRetentionService::MUTEX, 60);
        self::assertTrue($lock->get());
        try {
            self::assertTrue(app(RegionalPriceCatalogRetentionService::class)->prune(true)['busy']);
        } finally {
            $lock->release();
        }
    }

    public function test_retention_query_settings_are_local_to_successful_transactions(): void
    {
        $settings = static fn (): array => DB::table('pg_settings')
            ->whereIn('name', ['statement_timeout', 'lock_timeout', 'work_mem'])->orderBy('name')->pluck('setting', 'name')->all();
        $original = $settings();
        $observed = [];
        DB::listen(static function (QueryExecuted $event) use (&$observed, $settings): void {
            if (str_starts_with($event->sql, 'SELECT public.eg_regional_price_retention_eligible')) {
                $observed[] = $settings();
            }
        });
        $service = app(RegionalPriceCatalogRetentionService::class);
        $service->prune();
        self::assertSame($original, $settings());
        $service->prune(true);
        self::assertSame($original, $settings());
        self::assertCount(4, $observed);
        foreach ($observed as $local) {
            self::assertSame('60000', $local['statement_timeout']);
            self::assertSame('1000', $local['lock_timeout']);
            self::assertSame('65536', $local['work_mem']);
        }
    }

    public function test_failed_transaction_restores_query_settings_and_releases_mutex(): void
    {
        $settings = static fn (): array => DB::table('pg_settings')
            ->whereIn('name', ['statement_timeout', 'lock_timeout', 'work_mem'])->orderBy('name')->pluck('setting', 'name')->all();
        $original = $settings();
        $injected = false;
        DB::listen(static function (QueryExecuted $event) use (&$injected): void {
            if (! $injected && str_starts_with($event->sql, 'SELECT public.eg_regional_price_retention_eligible')) {
                $injected = true;
                throw new RuntimeException('retention_test_forced_rollback');
            }
        });
        try {
            app(RegionalPriceCatalogRetentionService::class)->prune(true);
            self::fail('Injected failure was not raised.');
        } catch (RuntimeException $exception) {
            self::assertSame('retention_test_forced_rollback', $exception->getMessage());
        }
        self::assertTrue($injected);
        self::assertSame($original, $settings());
        self::assertSame(7, DB::table('estimate_resource_prices')->count());
        $lock = Cache::lock(RegionalPriceCatalogRetentionService::MUTEX, 60);
        self::assertTrue($lock->get());
        $lock->release();
    }

    public function test_mutex_protects_the_final_transaction_after_the_time_budget(): void
    {
        $checked = false;
        DB::listen(function (QueryExecuted $event) use (&$checked): void {
            if ($checked || ! str_contains($event->sql, 'eg_regional_price_retention_lock_evidence')) {
                return;
            }
            $checked = true;
            $this->travel(600)->seconds();
            $competing = Cache::lock(RegionalPriceCatalogRetentionService::MUTEX, 60);
            try {
                self::assertFalse($competing->get());
            } finally {
                $competing->release();
                $this->travelBack();
            }
        });
        app(RegionalPriceCatalogRetentionService::class)->prune(true, maxSeconds: 240);
        self::assertTrue($checked);
    }

    public function test_statement_timeout_respects_a_shorter_cleanup_budget(): void
    {
        $observed = [];
        DB::listen(static function (QueryExecuted $event) use (&$observed): void {
            if (str_starts_with($event->sql, 'SELECT public.eg_regional_price_retention_eligible')) {
                $observed[] = (int) DB::table('pg_settings')->where('name', 'statement_timeout')->value('setting');
            }
        });
        app(RegionalPriceCatalogRetentionService::class)->prune(maxSeconds: 5);
        self::assertCount(2, $observed);
        foreach ($observed as $timeout) {
            self::assertGreaterThan(0, $timeout);
            self::assertLessThanOrEqual(5000, $timeout);
        }
    }

    public function test_database_policy_cannot_be_reduced_by_command_arguments(): void
    {
        self::assertFalse(DB::scalar('SELECT public.eg_regional_price_retention_eligible(3, 1, 1)'));
        DB::transaction(static function (): void {
            DB::table('estimate_resource_prices')->where('regional_price_version_id', 1)->delete();
            DB::table('estimate_regional_price_versions')->where('id', 1)->delete();
        });
        self::assertFalse(DB::table('estimate_regional_price_versions')->where('id', 1)->exists());
    }

    public function test_new_snapshot_evidence_cannot_reference_pruned_sources(): void
    {
        app(RegionalPriceCatalogRetentionService::class)->prune(true);
        try {
            DB::transaction(static fn () => DB::table('estimate_generation_package_items')->insert([
                'id' => 1, 'price_snapshot' => json_encode(['version_id' => 1, 'source_reference' => 'estimate_resource_prices:10']),
            ]));
            self::fail('A new dangling snapshot was accepted.');
        } catch (QueryException $exception) {
            self::assertSame('P0001', $exception->errorInfo[0]);
        }
    }

    public function test_failed_download_marks_partial_import_failed_without_removing_recent_prices(): void
    {
        $this->version(9, 1, 'parsing');
        DB::unprepared(<<<'SQL'
ALTER TABLE public.estimate_regional_price_versions ADD COLUMN files_count int NOT NULL DEFAULT 0;
ALTER TABLE public.estimate_regional_price_versions ADD COLUMN rows_read int NOT NULL DEFAULT 0;
ALTER TABLE public.estimate_regional_price_versions ADD COLUMN rows_imported int NOT NULL DEFAULT 0;
ALTER TABLE public.estimate_regional_price_versions ADD COLUMN errors_count int NOT NULL DEFAULT 0;
DROP TABLE IF EXISTS public.estimate_dataset_versions CASCADE;
CREATE TABLE public.estimate_dataset_versions(id bigserial PRIMARY KEY, source_type text, version_key text, bucket text, prefix text, status text,
 files_count int NOT NULL DEFAULT 0, rows_read int NOT NULL DEFAULT 0, rows_imported int NOT NULL DEFAULT 0, errors_count int NOT NULL DEFAULT 0,
 started_at timestamptz, finished_at timestamptz, meta jsonb, created_at timestamptz, updated_at timestamptz);
SQL);
        config(['filesystems.disks.s3.bucket' => 'test-estimate-import']);
        $region = new EstimateRegion(['code' => 'RU-TA', 'fgiscs_subject_id' => 296]);
        $region->setAttribute('id', 1);
        $relation = Mockery::mock(BelongsTo::class);
        $relation->shouldReceive('firstOrFail')->once()->andReturn($region);
        $zone = Mockery::mock(EstimatePriceZone::class)->makePartial();
        $zone->setRawAttributes(['id' => 1, 'fgiscs_price_zone_id' => 202]);
        $zone->shouldReceive('region')->once()->andReturn($relation);
        $period = new EstimatePricePeriod(['year' => 2024, 'quarter' => 1, 'fgiscs_period_id' => 101]);
        $period->setAttribute('id', 1);
        $client = Mockery::mock(FgiscsClient::class);
        $client->shouldReceive('downloadBuildingResources')->once()->with(202, 101)->andThrow(new RuntimeException('download_failed'));
        $resolver = Mockery::mock(RegionalPriceVersionResolver::class);
        $resolver->shouldReceive('resolveVersionKey')->once()->andReturn('test-9');
        $resolver->shouldReceive('assertWritable')->once();
        $service = new FgiscsBuildingResourcePriceUpdateService(
            $client,
            Mockery::mock(FgiscsRegionalCatalogService::class),
            new FgiscsBuildingResourcePriceSpreadsheetParser,
            Mockery::mock(RegionalPriceImportLifecycleService::class),
            $resolver,
            new FgiscsBuildingResourcePricePriority,
            new ResidentialConjuncturePriceImporter
        );
        try {
            (new ReflectionMethod($service, 'syncPeriod'))->invoke($service, $zone, $period, false, false, null);
            self::fail('Download failure was swallowed.');
        } catch (RuntimeException $exception) {
            self::assertSame('download_failed', $exception->getMessage());
        }
        self::assertSame('failed', DB::table('estimate_regional_price_versions')->where('id', 9)->value('status'));
        self::assertSame('failed', DB::table('estimate_dataset_versions')->where('version_key', 'test-9')->value('status'));
        app(RegionalPriceCatalogRetentionService::class)->prune(true);
        self::assertTrue(DB::table('estimate_resource_prices')->where('id', 90)->exists());
    }

    public function test_raw_catalog_deletes_fail_closed_at_snapshot_isolation(): void
    {
        $this->version(7, 1, 'failed');
        DB::transaction(static fn () => DB::table('estimate_resource_prices')->where('id', 70)->delete());
        foreach (['REPEATABLE READ', 'SERIALIZABLE'] as $isolation) {
            foreach ([['estimate_resource_prices', 10], ['estimate_regional_price_versions', 7]] as [$table, $id]) {
                try {
                    DB::transaction(static function () use ($isolation, $table, $id): void {
                        DB::statement('SET TRANSACTION ISOLATION LEVEL '.$isolation);
                        DB::table($table)->where('id', $id)->delete();
                    });
                    self::fail('A catalog delete accepted snapshot isolation.');
                } catch (QueryException $exception) {
                    self::assertSame('P0001', $exception->errorInfo[0]);
                    self::assertStringContainsString('estimate_generation.regional_price_retention_requires_read_committed', $exception->getMessage());
                }
                self::assertTrue(DB::table($table)->where('id', $id)->exists());
            }
        }
    }

    public function test_json_absence_prefilter_preserves_escaped_keys_and_rejects_text_false_positives(): void
    {
        $references = DB::select('SELECT reference_kind, reference_id FROM public.eg_regional_price_retention_json_references(?::jsonb, false) ORDER BY reference_kind, reference_id', [
            '{"price\u005fid":10,"regional_price_version\u005fid":1,"candidate_resource_price_ids":[20],"source_reference":"estimate_resource_prices\u003a30"}',
        ]);
        self::assertSame([
            ['reference_kind' => 'price', 'reference_id' => '10'],
            ['reference_kind' => 'price', 'reference_id' => '20'],
            ['reference_kind' => 'price', 'reference_id' => '30'],
            ['reference_kind' => 'version', 'reference_id' => '1'],
        ], array_map(static fn (object $reference): array => (array) $reference, $references));
        foreach ([
            ['note' => 'estimate_resource_prices:20 and "price_id":10', 'version_id' => 1],
            ['totals' => ['labor' => '123.00'], 'input_per_million' => '1.00'],
        ] as $payload) {
            self::assertSame([], DB::select('SELECT * FROM public.eg_regional_price_retention_json_references(?::jsonb, false)', [
                json_encode($payload, JSON_THROW_ON_ERROR),
            ]));
        }
    }

    public function test_actual_published_pricing_guards_preserve_insert_update_and_finalized_reference_contracts(): void
    {
        $migration = require base_path('app/BusinessModules/Addons/EstimateGeneration/migrations/2026_10_04_000200_add_regional_price_retention_guards.php');
        $migration->down();
        DB::unprepared(<<<'SQL'
ALTER TABLE public.estimate_regional_price_versions ADD COLUMN superseded_at timestamptz;
ALTER TABLE public.estimate_regional_price_versions ADD COLUMN rolled_back_at timestamptz;
ALTER TABLE public.estimate_generation_package_item_project_price_inputs ADD COLUMN selection jsonb;
SQL);
        $guardSources = [
            'eg_regional_price_lifecycle_transition_allowed' => '2026_07_20_000500_allow_published_regional_price_lifecycle_transitions.php',
            'eg_pricing_catalog_immutable_guard' => '2026_07_20_000500_allow_published_regional_price_lifecycle_transitions.php',
            'eg_used_pricing_source_guard' => '2026_07_21_000100_fix_regional_price_version_insert_guard.php',
            'eg_project_material_price_reference_immutable_guard' => '2026_07_20_000100_finalize_supplementary_project_material_prices.php',
        ];
        foreach ($guardSources as $function => $source) {
            $contents = file_get_contents(base_path('app/BusinessModules/Addons/EstimateGeneration/migrations/'.$source));
            self::assertIsString($contents);
            self::assertSame(1, preg_match('/CREATE OR REPLACE FUNCTION public\.'.preg_quote($function, '/').'\([\s\S]*?\nEND; \$\$;/', $contents, $matches));
            DB::unprepared($matches[0]);
        }
        $migration->up();

        $mutations = [
            static fn () => DB::table('estimate_resource_prices')->insert(['id' => 999, 'regional_price_version_id' => 6]),
            static fn () => DB::table('estimate_resource_prices')->where('id', 60)->update(['dataset_version_id' => 999]),
            static fn () => DB::table('estimate_regional_price_versions')->where('id', 6)->update(['version_key' => 'forged']),
        ];
        foreach ($mutations as $mutation) {
            try {
                DB::transaction($mutation);
                self::fail('A real published pricing guard accepted INSERT/UPDATE mutation.');
            } catch (QueryException $exception) {
                self::assertSame('P0001', $exception->errorInfo[0]);
            }
        }
        self::assertSame(1, DB::table('estimate_regional_price_versions')->where('id', 6)->update([
            'status' => 'superseded', 'superseded_at' => now(), 'updated_at' => now(),
        ]));
        $this->version(7, 1, 'parsing');
        self::assertSame(1, DB::table('estimate_regional_price_versions')->where('id', 7)->update(['status' => 'active', 'activated_at' => now()]));
        DB::table('estimate_resource_prices')->insert([
            ['id' => 997, 'regional_price_version_id' => null],
            ['id' => 998, 'regional_price_version_id' => null],
            ['id' => 999, 'regional_price_version_id' => null],
        ]);
        DB::table('estimate_generation_package_items')->insert(['id' => 99, 'pricing_finalized_at' => now()]);
        DB::table('estimate_generation_package_item_price_inputs')->insert(['id' => 99, 'package_item_id' => 99, 'resource_price_id' => 997]);
        DB::table('estimate_generation_package_item_project_price_inputs')->insert([
            'id' => 99, 'package_item_id' => 99, 'resource_price_id' => 998,
            'selection' => json_encode(['candidate_resource_price_ids' => [999]], JSON_THROW_ON_ERROR),
        ]);
        foreach ([997, 998, 999] as $priceId) {
            try {
                DB::transaction(static fn () => DB::table('estimate_resource_prices')->where('id', $priceId)->update(['dataset_version_id' => 999]));
                self::fail('A real finalized reference guard accepted source mutation.');
            } catch (QueryException $exception) {
                self::assertSame('P0001', $exception->errorInfo[0]);
                self::assertStringContainsString('is_immutable', $exception->getMessage());
            }
        }
    }

    private function version(int $id, int $period, string $status, string $updatedAt = '2020-01-01 00:00:00'): void
    {
        $published = in_array($status, ['active', 'superseded', 'rolled_back'], true);
        DB::table('estimate_regional_price_versions')->insert(['id' => $id, 'period_id' => $period, 'status' => $published ? 'parsing' : $status,
            'activated_at' => in_array($status, ['active', 'superseded', 'rolled_back'], true) ? '2020-01-01 00:00:00' : null,
            'updated_at' => $updatedAt, 'version_key' => 'test-'.$id]);
        DB::table('estimate_resource_prices')->insert(['id' => $id * 10, 'regional_price_version_id' => $id]);
        if ($id === 1) {
            DB::table('estimate_resource_prices')->insert(['id' => 100, 'regional_price_version_id' => $id]);
        }
        DB::table('estimate_regional_price_versions')->where('id', $id)->update(['status' => $status]);
    }
}
