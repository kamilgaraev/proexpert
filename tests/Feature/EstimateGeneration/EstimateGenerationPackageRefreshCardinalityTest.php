<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackage;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackageItem;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationPackagePersistenceService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationPackagePresenter;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class EstimateGenerationPackageRefreshCardinalityTest extends TestCase
{
    protected function setUpTraits(): array
    {
        return [];
    }

    #[Test]
    public function refresh_aggregates_thousands_of_current_items_without_hydration_or_unbounded_queries(): void
    {
        config()->set('database.connections.package_refresh_contract', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('package_refresh_contract');
        DB::setDefaultConnection('package_refresh_contract');
        Schema::create('estimate_generation_packages', function ($table): void {
            $table->id();
            $table->string('status');
            $table->unsignedInteger('generation_progress');
            $table->unsignedInteger('actual_items_count');
            $table->text('totals')->nullable();
            $table->text('quality_summary')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
        Schema::create('estimate_generation_package_items', function ($table): void {
            $table->id();
            $table->foreignId('package_id');
            $table->string('key');
            $table->string('logical_key')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->string('item_type');
            $table->decimal('total_cost', 30, 6)->default(0);
            $table->timestamp('pricing_finalized_at')->nullable();
            $table->timestamps();
            $table->index(['package_id', 'logical_key', 'revision', 'id'], 'eg_refresh_latest_idx');
        });
        $packageId = DB::table('estimate_generation_packages')->insertGetId([
            'status' => 'processing', 'generation_progress' => 50, 'actual_items_count' => 0,
            'totals' => '{}', 'quality_summary' => json_encode(['level' => 'passed', 'critical_flags' => [], 'warning_flags' => []], JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $rows = [];
        $expected = \Brick\Math\BigDecimal::zero();
        for ($item = 1; $item <= 3000; $item++) {
            for ($revision = 1; $revision <= 3; $revision++) {
                $finalized = $revision === 3 && $item % 10 !== 0;
                $amount = $revision === 3 ? '123456789.123456' : '999999999.999999';
                $rows[] = [
                    'package_id' => $packageId, 'key' => "item-{$item}#r{$revision}", 'logical_key' => "item-{$item}",
                    'revision' => $revision, 'item_type' => 'priced_work', 'total_cost' => $amount,
                    'pricing_finalized_at' => $finalized ? now() : null, 'created_at' => now(), 'updated_at' => now(),
                ];
                if ($revision === 3 && $finalized) {
                    $expected = $expected->plus($amount);
                }
                if (count($rows) === 500) {
                    DB::table('estimate_generation_package_items')->insert($rows);
                    $rows = [];
                }
            }
        }
        if ($rows !== []) {
            DB::table('estimate_generation_package_items')->insert($rows);
        }

        $selects = [];
        DB::listen(static function (QueryExecuted $query) use (&$selects): void {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                $selects[] = $query->sql;
            }
        });
        $package = (new EstimateGenerationPackage)->setConnection('package_refresh_contract')->newQuery()->findOrFail($packageId);
        $selects = [];
        $method = new \ReflectionMethod(EstimateGenerationPackagePersistenceService::class, 'refreshPackagePricingState');
        $method->invoke(new EstimateGenerationPackagePersistenceService, $package);

        self::assertCount(1, $selects);
        self::assertStringNotContainsString('select *', strtolower($selects[0]));
        self::assertStringContainsString('row_number()', strtolower($selects[0]));
        $fresh = $package->fresh();
        self::assertSame(3000, $fresh->actual_items_count);
        self::assertSame(2700, $fresh->totals['priced_items_count']);
        self::assertSame((string) $expected->toScale(2, \Brick\Math\RoundingMode::HalfUp), $fresh->totals['total_cost']);
        self::assertSame('blocked', $fresh->status);
        self::assertSame(99, $fresh->generation_progress);
        self::assertContains('missing_price_snapshot', $fresh->quality_summary['critical_flags']);

        $item = new EstimateGenerationPackageItem;
        $item->forceFill(['id' => 1, 'key' => 'precision', 'logical_key' => 'precision', 'revision' => 1,
            'item_type' => 'priced_work', 'name' => 'Точная величина', 'quantity' => '123456789.123456',
            'total_cost' => '1.00', 'unit_price' => '1.00', 'direct_cost' => '1.00', 'overhead_cost' => '0.00',
            'profit_cost' => '0.00', 'metadata' => [], 'flags' => []]);
        self::assertSame('123456789.123456000000000000', (new EstimateGenerationPackagePresenter)->item($item)['quantity']);
    }
}
