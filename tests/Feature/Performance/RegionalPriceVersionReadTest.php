<?php

declare(strict_types=1);

namespace Tests\Feature\Performance;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs\RegionalPriceVersionResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class RegionalPriceVersionReadTest extends TestCase
{
    public function refreshDatabase(): void {}

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('estimate_resource_prices');
        Schema::dropIfExists('estimate_regional_price_versions');
        Schema::create('estimate_regional_price_versions', static function (Blueprint $table): void {
            $table->id();
            $table->string('source');
            $table->unsignedBigInteger('region_id');
            $table->unsignedBigInteger('price_zone_id');
            $table->unsignedBigInteger('period_id');
            $table->string('version_key');
            $table->string('status');
            $table->jsonb('metadata');
        });
        Schema::create('estimate_resource_prices', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('regional_price_version_id');
            $table->string('source_price_kind');
        });
    }

    public function test_active_and_historical_versions_do_not_read_resource_prices(): void
    {
        foreach (['rolled_back', 'superseded', 'active'] as $id => $status) {
            $this->version($id + 1, $status, $id === 0 ? 'base' : 'base-r'.$id);
        }

        DB::enableQueryLog();
        self::assertSame('base-r2', $this->resolve());
        self::assertCount(0, $this->priceQueries());
    }

    public function test_force_creates_revision_without_scanning_immutable_prices(): void
    {
        $this->version(1, 'active', 'base');
        DB::enableQueryLog();

        self::assertSame('base-r1', $this->resolve(true));
        self::assertCount(0, $this->priceQueries());
    }

    public function test_partial_component_is_not_overwritten_and_count_is_not_used(): void
    {
        $this->version(1, 'downloaded', 'base');
        DB::table('estimate_resource_prices')->insert([
            'regional_price_version_id' => 1,
            'source_price_kind' => 'regional_worker_salary',
        ]);
        DB::enableQueryLog();

        self::assertSame('base-r1', $this->resolve());
        self::assertCount(1, $this->priceQueries());
        self::assertStringContainsString('exists', $this->priceQueries()[0]['query']);
        self::assertStringNotContainsString('count(*)', $this->priceQueries()[0]['query']);
    }

    public function test_other_component_does_not_prevent_empty_revision_from_resuming(): void
    {
        $this->version(1, 'active', 'base');
        $this->version(2, 'downloaded', 'base-r1');
        DB::table('estimate_resource_prices')->insert([
            'regional_price_version_id' => 2,
            'source_price_kind' => 'regional_building_resource_direct',
        ]);
        DB::enableQueryLog();

        self::assertSame('base-r1', $this->resolve());
        self::assertCount(1, $this->priceQueries());
    }

    public function test_failed_revision_is_not_resumed_or_scanned(): void
    {
        $this->version(1, 'failed', 'base');
        DB::enableQueryLog();

        self::assertSame('base-r1', $this->resolve());
        self::assertCount(0, $this->priceQueries());
    }

    public function test_newest_empty_revision_stops_checks_of_older_revisions(): void
    {
        $this->version(1, 'downloaded', 'base');
        $this->version(2, 'downloaded', 'base-r1');
        DB::enableQueryLog();

        self::assertSame('base-r1', $this->resolve());
        self::assertCount(1, $this->priceQueries());
    }

    public function test_component_lookup_index_is_valid_and_can_be_retried(): void
    {
        $migration = require base_path('app/BusinessModules/Addons/EstimateGeneration/migrations/2026_09_28_000100_add_regional_price_component_lookup_index.php');
        self::assertFalse($migration->withinTransaction);
        $migration->up();
        $migration->up();
        $index = DB::selectOne("SELECT i.indisvalid, pg_get_indexdef(i.indexrelid) AS definition FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = 'eg_prices_version_kind_idx'");

        self::assertTrue($index->indisvalid);
        self::assertStringContainsString('(regional_price_version_id, source_price_kind)', $index->definition);
        $migration->down();
    }

    private function version(int $id, string $status, string $key): void
    {
        DB::table('estimate_regional_price_versions')->insert([
            'id' => $id,
            'source' => 'fgis_labor_prices',
            'region_id' => 16,
            'price_zone_id' => 1,
            'period_id' => 1,
            'version_key' => $key,
            'status' => $status,
            'metadata' => json_encode(['worker_salary_imported' => $status === 'active']),
        ]);
    }

    private function resolve(bool $force = false): string
    {
        return (new RegionalPriceVersionResolver)->resolveVersionKey(
            'fgis_labor_prices', 16, 1, 1, 'base', 'worker_salary_imported', $force,
        );
    }

    private function priceQueries(): array
    {
        return array_values(array_filter(
            DB::getQueryLog(),
            static fn (array $query): bool => str_contains($query['query'], 'estimate_resource_prices'),
        ));
    }
}
