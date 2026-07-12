<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Pricing;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackageItem;
use App\BusinessModules\Addons\EstimateGeneration\Pricing\ResolveRegionalPrice;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimatePricingService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('postgres-contract')]
final class EstimateGenerationPriceSnapshotPostgresTest extends TestCase
{
    public function refreshDatabase(): void {}

    public function test_snapshot_is_historical_and_database_rejects_forged_or_missing_evidence(): void
    {
        $database = (string) DB::connection()->getDatabaseName();
        if (getenv('RUN_POSTGRES_PRICE_SNAPSHOT_CONTRACT') !== '1' || DB::getDriverName() !== 'pgsql' || ! str_ends_with($database, '_contract')) {
            self::markTestSkipped('Requires explicit disposable PostgreSQL price snapshot contract database.');
        }

        DB::beginTransaction();
        try {
            $organization = Organization::factory()->create();
            $user = User::factory()->create(['current_organization_id' => $organization->id]);
            $project = Project::factory()->create(['organization_id' => $organization->id]);
            $sessionId = DB::table('estimate_generation_sessions')->insertGetId([
                'organization_id' => $organization->id, 'project_id' => $project->id, 'user_id' => $user->id,
                'status' => 'generating', 'processing_stage' => 'resolve_prices', 'input_payload' => '{}',
            ]);
            $packageId = DB::table('estimate_generation_packages')->insertGetId([
                'session_id' => $sessionId, 'key' => 'price-contract', 'title' => 'Contract', 'scope_type' => 'custom',
            ]);
            $suffix = strtolower((string) str()->ulid());
            $regionId = DB::table('estimate_regions')->insertGetId(['code' => 'PC-'.$suffix, 'name' => 'Contract', 'fgiscs_subject_id' => random_int(100000, 999999)]);
            $zoneId = DB::table('estimate_price_zones')->insertGetId(['estimate_region_id' => $regionId, 'name' => 'Contract', 'fgiscs_price_zone_id' => random_int(1000000, 1999999)]);
            $periodId = DB::table('estimate_price_periods')->insertGetId(['fgiscs_period_id' => random_int(2000000, 2999999), 'name' => $suffix, 'year' => 2099, 'quarter' => 4]);
            $versionId = DB::table('estimate_regional_price_versions')->insertGetId([
                'source' => 'fgiscs', 'region_id' => $regionId, 'price_zone_id' => $zoneId, 'period_id' => $periodId,
                'version_key' => $suffix, 'status' => 'active',
            ]);
            $datasetId = DB::table('estimate_dataset_versions')->insertGetId([
                'source_type' => 'fgis_labor_prices', 'version_key' => $suffix, 'bucket' => 'contract', 'prefix' => $suffix, 'status' => 'parsed',
            ]);
            $priceId = DB::table('estimate_resource_prices')->insertGetId([
                'dataset_version_id' => $datasetId, 'regional_price_version_id' => $versionId, 'region_id' => $regionId,
                'price_zone_id' => $zoneId, 'period_id' => $periodId, 'resource_code' => $suffix, 'resource_name' => 'Resource',
                'unit' => 'h', 'base_price' => '100.0000', 'price_type' => 'labor', 'raw_payload' => '{}',
            ]);
            $context = ['region_id' => $regionId, 'price_zone_id' => $zoneId, 'period_id' => $periodId, 'estimate_regional_price_version_id' => $versionId];
            $priced = (new EstimatePricingService(new ResolveRegionalPrice))->price([[
                'item_type' => 'priced_work', 'materials' => [],
                'labor' => [['price_id' => $priceId, 'quantity' => '2.500000', 'unit_price' => '999', 'total_price' => '1']],
                'machinery' => [], 'other_resources' => [], 'validation_flags' => [], 'price_source' => 'regional',
            ]], $context)[0];
            $item = EstimateGenerationPackageItem::query()->create([
                'package_id' => $packageId, 'key' => 'item-1', 'name' => 'Item', 'item_type' => 'priced_work',
                'total_cost' => $priced['total_cost'], 'direct_cost' => '250.00', 'price_source' => 'regional',
                'price_snapshot' => $priced['price_snapshot'],
            ]);

            DB::table('estimate_resource_prices')->where('id', $priceId)->update(['base_price' => '900.0000']);
            $item->refresh();
            self::assertSame('500.00', $item->total_cost);
            self::assertSame('100.0000', $item->price_snapshot['coefficients']['resource_evidence'][0]['base_amount']);

            $this->assertRejected(fn () => DB::table('estimate_generation_package_items')->insert([
                'package_id' => $packageId, 'key' => 'missing', 'name' => 'Missing', 'item_type' => 'priced_work', 'total_cost' => '1.00',
            ]));
            $forged = $priced['price_snapshot'];
            $forged['final_amount'] = '1.00';
            $this->assertRejected(fn () => DB::table('estimate_generation_package_items')->insert([
                'package_id' => $packageId, 'key' => 'forged', 'name' => 'Forged', 'item_type' => 'priced_work',
                'total_cost' => '2.00', 'price_snapshot' => json_encode($forged, JSON_THROW_ON_ERROR),
            ]));
        } finally {
            DB::rollBack();
        }
    }

    private function assertRejected(callable $write): void
    {
        DB::statement('SAVEPOINT price_snapshot_contract');
        try {
            $write();
            self::fail('PostgreSQL accepted invalid price evidence.');
        } catch (QueryException) {
            self::addToAssertionCount(1);
        } finally {
            DB::statement('ROLLBACK TO SAVEPOINT price_snapshot_contract');
            DB::statement('RELEASE SAVEPOINT price_snapshot_contract');
        }
    }
}
