<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Pricing;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationPackagePersistenceService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('postgres-contract')]
final class EstimateGenerationPriceSnapshotPostgresTest extends TestCase
{
    public function refreshDatabase(): void {}

    public function test_database_builds_deterministic_price_snapshot_and_protects_every_trust_input(): void
    {
        $this->requireDisposablePostgres();

        DB::beginTransaction();
        try {
            $fixture = $this->fixture();
            DB::select('SELECT eg_finalize_package_item_price(?)', [$fixture['item_id']]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

            $item = DB::table('estimate_generation_package_items')->find($fixture['item_id']);
            $snapshot = json_decode((string) $item->price_snapshot, true, 512, JSON_THROW_ON_ERROR);
            $canonical = $fixture['norm_resource_id'].':'.$fixture['norm_id'].':'.$fixture['resource_code'].':labor:min:3.000000:'
                .$fixture['price_id'].':'.$fixture['version_id'].':h:600.0000:'.$fixture['conversion_id'].':0.016666666667|'
                .$fixture['evidence_id'].':'.$fixture['fingerprint'];

            self::assertSame('75.00', $item->direct_cost);
            self::assertSame('75.00', $item->total_cost);
            self::assertSame('30.000000', $item->unit_price);
            self::assertSame('2.500000', $item->quantity);
            self::assertSame('sha256:'.hash('sha256', $canonical), $snapshot['source_reference']);
            self::assertSame('75.00', $snapshot['base_amount']);
            self::assertSame('75.00', $snapshot['final_amount']);
            self::assertSame('0.00', $snapshot['coefficients']['work_cost']);
            self::assertSame($fixture['region_id'], $snapshot['region_id']);
            self::assertSame($fixture['version_id'], $snapshot['version_id']);
            self::assertSame('3.000000', $snapshot['coefficients']['resource_evidence'][0]['norm_quantity']);
            self::assertSame('600.0000', $snapshot['coefficients']['resource_evidence'][0]['base_price']);

            DB::statement('SET CONSTRAINTS ALL DEFERRED');
            $session = EstimateGenerationSession::query()->findOrFail($fixture['session_id']);
            app(EstimateGenerationPackagePersistenceService::class)->syncFromDraft($session, $this->serviceDraft($fixture, '2.5'));
            $servicePackageId = DB::table('estimate_generation_packages')->where('session_id', $fixture['session_id'])->where('key', 'service-package')->value('id');
            $serviceItem = DB::table('estimate_generation_package_items')->where('package_id', $servicePackageId)->where('logical_key', 'item-1')->first();
            self::assertNotNull($serviceItem->pricing_finalized_at);
            self::assertSame('75.00', $serviceItem->total_cost);

            app(EstimateGenerationPackagePersistenceService::class)->syncFromDraft($session, $this->serviceDraft($fixture, '9'));
            $tampered = DB::table('estimate_generation_package_items')->where('package_id', $servicePackageId)->where('logical_key', 'item-1')->orderByDesc('revision')->first();
            self::assertNull($tampered->pricing_finalized_at);
            self::assertNull($tampered->price_snapshot);
            self::assertSame('0.00', $tampered->total_cost);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
            $this->assertRejected(fn () => DB::table('estimate_generation_package_item_price_inputs')->insert([
                'package_item_id' => $serviceItem->id, 'norm_resource_id' => $fixture['norm_resource_id'],
                'resource_price_id' => $fixture['price_id'], 'unit_conversion_id' => $fixture['conversion_id'],
                'ordinal' => 2, 'created_at' => now(), 'updated_at' => now(),
            ]));
            $this->assertRejected(fn () => DB::table('estimate_generation_package_items')->insert(array_replace(
                $this->itemPayload($fixture, 'forged-priced', 1, null),
                ['pricing_finalized_at' => now(), 'price_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR)],
            )));
            $this->assertRejected(fn () => DB::table('estimate_regional_price_versions')->where('id', $fixture['version_id'])->update(['status' => 'superseded']));
            $this->assertRejected(fn () => DB::table('estimate_norm_resources')->where('id', $fixture['norm_resource_id'])->update(['quantity' => 99]));
            $this->assertRejected(fn () => DB::table('estimate_dataset_versions')->where('id', $fixture['dataset_id'])->update(['version_key' => strtolower((string) str()->ulid())]));

            $function = DB::selectOne("SELECT p.prosecdef, p.proconfig, NOT EXISTS (SELECT 1 FROM aclexplode(COALESCE(p.proacl, acldefault('f', p.proowner))) a WHERE a.grantee=0 AND a.privilege_type='EXECUTE') AS public_revoked FROM pg_proc p WHERE p.oid='public.eg_finalize_package_item_price(bigint)'::regprocedure");
            self::assertTrue($function->prosecdef);
            self::assertStringContainsString('search_path=pg_catalog, public', (string) $function->proconfig);
            self::assertTrue($function->public_revoked);

            foreach (['null' => null, 'zero' => '0.0000', 'negative' => '-1.0000'] as $case => $basePrice) {
                $invalidCatalog = $this->catalogPrice($fixture, $fixture['resource_code'], $basePrice, 'invalid-'.$case);
                $this->assertFinalizeRejected(
                    $fixture,
                    'invalid-base-'.$case,
                    ['regional_price_version_id' => $invalidCatalog['version_id']],
                    true,
                    ['resource_price_id' => $invalidCatalog['price_id']],
                );
            }

            DB::statement('SET CONSTRAINTS ALL DEFERRED');
            foreach (['price_snapshot', 'total_cost', 'quantity', 'region_id', 'quantity_evidence_id'] as $column) {
                $this->assertRejected(fn () => DB::table('estimate_generation_package_items')
                    ->where('id', $fixture['item_id'])->update([$column => $this->mutatedValue($column, $snapshot)]));
            }
            $this->assertRejected(fn () => DB::table('estimate_generation_package_items')->where('id', $fixture['item_id'])->delete());
            $this->assertRejected(fn () => DB::table('estimate_generation_package_item_price_inputs')
                ->where('package_item_id', $fixture['item_id'])->update(['ordinal' => 2]));
            $this->assertRejected(fn () => DB::table('estimate_generation_package_item_price_inputs')
                ->where('package_item_id', $fixture['item_id'])->delete());
            $this->assertRejected(fn () => DB::table('estimate_resource_prices')->where('id', $fixture['price_id'])->update(['base_price' => 999]));
            $this->assertRejected(fn () => DB::table('estimate_resource_prices')->where('id', $fixture['price_id'])->delete());
            $this->assertRejected(fn () => DB::table('estimate_resource_prices')->insert([
                'dataset_version_id' => $fixture['dataset_id'], 'regional_price_version_id' => $fixture['version_id'],
                'region_id' => $fixture['region_id'], 'price_zone_id' => $fixture['zone_id'], 'period_id' => $fixture['period_id'],
                'resource_code' => $fixture['resource_code'].'-late', 'resource_name' => 'Late mutation', 'unit' => 'h',
                'base_price' => '1.0000', 'price_type' => 'labor', 'raw_payload' => '{}', 'created_at' => now(), 'updated_at' => now(),
            ]));
            $this->assertRejected(fn () => DB::table('estimate_generation_unit_conversions')->where('id', $fixture['conversion_id'])->update(['factor' => 2]));
            $this->assertRejected(fn () => DB::table('estimate_generation_unit_conversions')->where('id', $fixture['conversion_id'])->delete());
            $this->assertRejected(fn () => DB::table('estimate_generation_evidence')->where('id', $fixture['evidence_id'])
                ->update(['value' => json_encode(['work_code' => 'work_type:1', 'quantity' => 9, 'unit' => 'h'], JSON_THROW_ON_ERROR)]));

            $this->assertFinalizeRejected($fixture, 'missing-input', [], false);
            $this->assertFinalizeRejected($fixture, 'missing-conversion', [], true, ['unit_conversion_id' => null]);
            $this->assertFinalizeRejected($fixture, 'cross-context', ['region_id' => $fixture['region_id'] + 1000000]);
            $this->assertFinalizeRejected($fixture, 'wrong-fingerprint', ['quantity_evidence_fingerprint' => str_repeat('f', 64)]);
            $this->assertFinalizeRejected($fixture, 'foreign-evidence', ['quantity_evidence_id' => 999999999]);

            $this->assertNormResourceMatrixRejected($fixture);

            $historical = DB::table('estimate_generation_package_items')->find($fixture['item_id']);
            self::assertSame('75.00', $historical->total_cost);
            self::assertSame($item->price_snapshot, $historical->price_snapshot);

            $revisionId = $this->unpricedItem($fixture, 'item-1', 2, $fixture['item_id']);
            self::assertSame($fixture['item_id'], (int) DB::table('estimate_generation_package_items')->find($revisionId)->supersedes_item_id);
            $this->assertRejected(fn () => DB::table('estimate_generation_package_items')->insert($this->itemPayload($fixture, 'item-1', 1, null)));
            $this->assertExactLargeDecimalPackageTotal($fixture);

            $newVersion = DB::table('estimate_regional_price_versions')->insertGetId([
                'source' => 'fgiscs', 'region_id' => $fixture['region_id'], 'price_zone_id' => $fixture['zone_id'],
                'period_id' => $fixture['period_id'], 'version_key' => 'contract-new', 'status' => 'draft',
            ]);
            self::assertNotSame($fixture['version_id'], $newVersion);
            self::assertSame('75.00', DB::table('estimate_generation_package_items')->find($fixture['item_id'])->total_cost);
        } finally {
            DB::rollBack();
        }
    }

    private function serviceDraft(array $f, string $quantity): array
    {
        return ['local_estimates' => [[
            'key' => 'service-package', 'title' => 'Service contract', 'target_items_min' => 1,
            'sections' => [['work_items' => [[
                'key' => 'item-1', 'item_type' => 'priced_work', 'name' => 'Item', 'unit' => 'h', 'quantity' => $quantity,
                'quantity_evidence_id' => $f['evidence_id'], 'quantity_evidence_fingerprint' => $f['fingerprint'],
                'normative_match' => ['status' => 'matched', 'norm_id' => $f['norm_id']],
                'labor' => [[
                    'normative_ref' => ['norm_resource_id' => $f['norm_resource_id'], 'price_id' => $f['price_id'], 'unit_conversion_id' => $f['conversion_id']],
                ]],
                'price_snapshot' => ['region_id' => $f['region_id'], 'zone_id' => $f['zone_id'], 'period_id' => $f['period_id'], 'version_id' => $f['version_id'], 'final_amount' => '999.00'],
                'pricing_status' => 'calculated', 'total_cost' => '999.00', 'labor_cost' => '999.00', 'validation_flags' => [],
            ]]]],
        ]]];
    }

    private function fixture(): array
    {
        $now = now();
        $organizationId = DB::table('organizations')->insertGetId(['name' => 'Contract', 'created_at' => $now, 'updated_at' => $now]);
        $userId = DB::table('users')->insertGetId(['name' => 'Contract', 'email' => uniqid('contract-', true).'@example.test', 'password' => 'x', 'created_at' => $now, 'updated_at' => $now]);
        $projectId = DB::table('projects')->insertGetId(['organization_id' => $organizationId, 'name' => 'Contract', 'created_at' => $now, 'updated_at' => $now]);
        $sessionId = DB::table('estimate_generation_sessions')->insertGetId([
            'organization_id' => $organizationId, 'project_id' => $projectId, 'user_id' => $userId,
            'status' => 'generating', 'processing_stage' => 'resolve_prices', 'input_payload' => '{}', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $packageId = DB::table('estimate_generation_packages')->insertGetId([
            'session_id' => $sessionId, 'key' => uniqid('contract-', true), 'title' => 'Contract', 'scope_type' => 'custom', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $suffix = strtolower((string) str()->ulid());
        $datasetId = DB::table('estimate_dataset_versions')->insertGetId(['source_type' => 'fgis_labor_prices', 'version_key' => $suffix, 'bucket' => 'contract', 'prefix' => $suffix, 'status' => 'parsed', 'created_at' => $now, 'updated_at' => $now]);
        $collectionId = DB::table('estimate_norm_collections')->insertGetId(['dataset_version_id' => $datasetId, 'code' => $suffix, 'name' => 'Contract', 'norm_type' => 'gesn', 'source_file' => 'contract', 'created_at' => $now, 'updated_at' => $now]);
        $normId = DB::table('estimate_norms')->insertGetId(['collection_id' => $collectionId, 'code' => $suffix, 'name' => 'Contract', 'unit' => 'h', 'created_at' => $now, 'updated_at' => $now]);
        $normResourceId = DB::table('estimate_norm_resources')->insertGetId(['estimate_norm_id' => $normId, 'resource_code' => $suffix, 'resource_name' => 'Resource', 'unit' => 'min', 'quantity' => '3.000000', 'resource_type' => 'labor', 'created_at' => $now, 'updated_at' => $now]);
        $regionId = DB::table('estimate_regions')->insertGetId(['code' => 'PC-'.$suffix, 'name' => 'Contract', 'fgiscs_subject_id' => random_int(100000, 999999), 'created_at' => $now, 'updated_at' => $now]);
        $zoneId = DB::table('estimate_price_zones')->insertGetId(['estimate_region_id' => $regionId, 'name' => 'Contract', 'fgiscs_price_zone_id' => random_int(1000000, 1999999), 'created_at' => $now, 'updated_at' => $now]);
        $periodId = DB::table('estimate_price_periods')->insertGetId(['fgiscs_period_id' => random_int(2000000, 2999999), 'name' => $suffix, 'year' => 2099, 'quarter' => 4, 'created_at' => $now, 'updated_at' => $now]);
        $versionId = DB::table('estimate_regional_price_versions')->insertGetId(['source' => 'fgiscs', 'region_id' => $regionId, 'price_zone_id' => $zoneId, 'period_id' => $periodId, 'version_key' => $suffix, 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now]);
        $priceId = DB::table('estimate_resource_prices')->insertGetId(['dataset_version_id' => $datasetId, 'regional_price_version_id' => $versionId, 'region_id' => $regionId, 'price_zone_id' => $zoneId, 'period_id' => $periodId, 'resource_code' => $suffix, 'resource_name' => 'Resource', 'unit' => 'h', 'base_price' => '600.0000', 'price_type' => 'labor', 'raw_payload' => '{}', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('estimate_regional_price_versions')->where('id', $versionId)->update(['status' => 'active']);
        $conversionId = DB::table('estimate_generation_unit_conversions')->insertGetId(['from_unit' => 'min', 'to_unit' => 'h', 'factor' => '0.016666666667', 'version' => 1, 'fingerprint' => str_repeat('c', 64), 'created_at' => $now, 'updated_at' => $now]);
        $fingerprint = hash('sha256', $suffix);
        $evidenceId = DB::table('estimate_generation_evidence')->insertGetId(['organization_id' => $organizationId, 'project_id' => $projectId, 'session_id' => $sessionId, 'type' => 'work_item', 'source_type' => 'user_input', 'source_ref' => 'input:1', 'source_version' => 'contract:abcdef', 'locator' => json_encode(['item_key' => 'item:'.hash('sha256', 'item-1')], JSON_THROW_ON_ERROR), 'value' => json_encode(['work_code' => 'work_type:1', 'quantity' => 2.5, 'unit' => 'h'], JSON_THROW_ON_ERROR), 'confidence' => 1, 'producer_name' => 'contract', 'producer_version' => 'contract:abcdef', 'fingerprint' => $fingerprint, 'created_at' => $now, 'updated_at' => $now]);
        $resourceCode = $suffix;
        $fixture = compact('sessionId', 'packageId', 'datasetId', 'collectionId', 'resourceCode', 'normId', 'normResourceId', 'regionId', 'zoneId', 'periodId', 'versionId', 'priceId', 'conversionId', 'evidenceId', 'fingerprint');
        $fixture = array_combine(array_map(fn (string $key): string => strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $key)), array_keys($fixture)), array_values($fixture));
        $fixture['item_id'] = $this->unpricedItem($fixture, 'item-1', 1, null);
        DB::table('estimate_generation_package_item_price_inputs')->insert(['package_item_id' => $fixture['item_id'], 'norm_resource_id' => $normResourceId, 'resource_price_id' => $priceId, 'unit_conversion_id' => $conversionId, 'ordinal' => 1, 'created_at' => $now, 'updated_at' => $now]);

        return $fixture;
    }

    private function unpricedItem(array $fixture, string $logicalKey, int $revision, ?int $supersedes): int
    {
        return DB::table('estimate_generation_package_items')->insertGetId($this->itemPayload($fixture, $logicalKey, $revision, $supersedes));
    }

    private function itemPayload(array $f, string $key, int $revision, ?int $supersedes): array
    {
        return ['package_id' => $f['package_id'], 'key' => $key.'#r'.$revision, 'logical_key' => $key, 'revision' => $revision, 'supersedes_item_id' => $supersedes, 'name' => 'Item', 'item_type' => 'priced_work', 'quantity_evidence_id' => $f['evidence_id'], 'quantity_evidence_fingerprint' => $f['fingerprint'], 'estimate_norm_id' => $f['norm_id'], 'region_id' => $f['region_id'], 'price_zone_id' => $f['zone_id'], 'period_id' => $f['period_id'], 'regional_price_version_id' => $f['version_id'], 'unit_price' => 999, 'direct_cost' => 999, 'overhead_cost' => 999, 'profit_cost' => 999, 'total_cost' => 999, 'created_at' => now(), 'updated_at' => now()];
    }

    private function mutatedValue(string $column, array $snapshot): mixed
    {
        return match ($column) {
            'price_snapshot' => json_encode(array_replace($snapshot, ['final_amount' => '1.00']), JSON_THROW_ON_ERROR), 'total_cost' => 1, 'quantity' => 9, 'region_id', 'quantity_evidence_id' => 999999999
        };
    }

    private function assertFinalizeRejected(
        array $fixture,
        string $logicalKey,
        array $itemOverrides = [],
        bool $withInput = true,
        array $inputOverrides = [],
    ): void {
        $this->assertRejected(function () use ($fixture, $logicalKey, $itemOverrides, $withInput, $inputOverrides): void {
            $itemId = DB::table('estimate_generation_package_items')->insertGetId(array_replace(
                $this->itemPayload($fixture, $logicalKey, 1, null),
                $itemOverrides,
            ));
            if ($withInput) {
                DB::table('estimate_generation_package_item_price_inputs')->insert(array_replace([
                    'package_item_id' => $itemId,
                    'norm_resource_id' => $fixture['norm_resource_id'],
                    'resource_price_id' => $fixture['price_id'],
                    'unit_conversion_id' => $fixture['conversion_id'],
                    'ordinal' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ], $inputOverrides));
            }
            DB::select('SELECT eg_finalize_package_item_price(?)', [$itemId]);
        });
    }

    private function catalogPrice(array $fixture, string $resourceCode, ?string $basePrice, string $key): array
    {
        $now = now();
        $suffix = strtolower((string) str()->ulid()).'-'.$key;
        $datasetId = DB::table('estimate_dataset_versions')->insertGetId([
            'source_type' => 'fgis_labor_prices', 'version_key' => $suffix, 'bucket' => 'contract',
            'prefix' => $suffix, 'status' => 'parsed', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $versionId = DB::table('estimate_regional_price_versions')->insertGetId([
            'source' => 'fgiscs', 'region_id' => $fixture['region_id'], 'price_zone_id' => $fixture['zone_id'],
            'period_id' => $fixture['period_id'], 'version_key' => $suffix, 'status' => 'draft',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $priceId = DB::table('estimate_resource_prices')->insertGetId([
            'dataset_version_id' => $datasetId, 'regional_price_version_id' => $versionId,
            'region_id' => $fixture['region_id'], 'price_zone_id' => $fixture['zone_id'], 'period_id' => $fixture['period_id'],
            'resource_code' => $resourceCode, 'resource_name' => 'Resource', 'unit' => 'h',
            'base_price' => $basePrice, 'price_type' => 'labor', 'raw_payload' => '{}',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('estimate_regional_price_versions')->where('id', $versionId)->update(['status' => 'active']);

        return ['version_id' => $versionId, 'price_id' => $priceId];
    }

    private function assertNormResourceMatrixRejected(array $fixture): void
    {
        $now = now();
        $secondCode = $fixture['resource_code'].'-second';
        $secondNormResourceId = DB::table('estimate_norm_resources')->insertGetId([
            'estimate_norm_id' => $fixture['norm_id'], 'resource_code' => $secondCode, 'resource_name' => 'Second',
            'unit' => 'h', 'quantity' => '1.000000', 'resource_type' => 'labor', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $firstCatalog = $this->catalogPrice($fixture, $fixture['resource_code'], '10.0000', 'matrix-first');
        $secondCatalog = $this->catalogPrice($fixture, $secondCode, '20.0000', 'matrix-second');

        $this->assertFinalizeRejected(
            $fixture,
            'missing-norm-resource',
            ['regional_price_version_id' => $firstCatalog['version_id']],
            true,
            ['resource_price_id' => $firstCatalog['price_id']],
        );
        $this->assertFinalizeRejected(
            $fixture,
            'wrong-norm-price',
            ['regional_price_version_id' => $secondCatalog['version_id']],
            true,
            ['resource_price_id' => $secondCatalog['price_id']],
        );

        $foreignNormId = DB::table('estimate_norms')->insertGetId([
            'collection_id' => $fixture['collection_id'], 'code' => $secondCode, 'name' => 'Foreign norm',
            'unit' => 'h', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $foreignResourceId = DB::table('estimate_norm_resources')->insertGetId([
            'estimate_norm_id' => $foreignNormId, 'resource_code' => $secondCode, 'resource_name' => 'Foreign',
            'unit' => 'h', 'quantity' => '1.000000', 'resource_type' => 'labor', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->assertFinalizeRejectedWithInputs($fixture, 'extra-unmatched-input', [
            ['norm_resource_id' => $fixture['norm_resource_id'], 'resource_price_id' => $firstCatalog['price_id'], 'unit_conversion_id' => $fixture['conversion_id']],
            ['norm_resource_id' => $secondNormResourceId, 'resource_price_id' => $secondCatalog['price_id'], 'unit_conversion_id' => null],
            ['norm_resource_id' => $foreignResourceId, 'resource_price_id' => $secondCatalog['price_id'], 'unit_conversion_id' => null],
        ], ['regional_price_version_id' => $firstCatalog['version_id']]);
    }

    private function assertFinalizeRejectedWithInputs(array $fixture, string $logicalKey, array $inputs, array $itemOverrides): void
    {
        $this->assertRejected(function () use ($fixture, $logicalKey, $inputs, $itemOverrides): void {
            $itemId = DB::table('estimate_generation_package_items')->insertGetId(array_replace(
                $this->itemPayload($fixture, $logicalKey, 1, null),
                $itemOverrides,
            ));
            foreach ($inputs as $ordinal => $input) {
                DB::table('estimate_generation_package_item_price_inputs')->insert(array_replace($input, [
                    'package_item_id' => $itemId, 'ordinal' => $ordinal + 1, 'created_at' => now(), 'updated_at' => now(),
                ]));
            }
            DB::select('SELECT eg_finalize_package_item_price(?)', [$itemId]);
        });
    }

    private function assertExactLargeDecimalPackageTotal(array $fixture): void
    {
        $now = now();
        $quantity = '12345.678901';
        $basePrice = '123456789.1234';
        $code = strtolower((string) str()->ulid());
        $normId = DB::table('estimate_norms')->insertGetId([
            'collection_id' => $fixture['collection_id'], 'code' => $code, 'name' => 'Decimal norm',
            'unit' => 'h', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $normResourceId = DB::table('estimate_norm_resources')->insertGetId([
            'estimate_norm_id' => $normId, 'resource_code' => $code, 'resource_name' => 'Decimal resource',
            'unit' => 'h', 'quantity' => '1.000000', 'resource_type' => 'labor', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $catalog = $this->catalogPrice($fixture, $code, $basePrice, 'large-decimal');
        $fingerprint = hash('sha256', 'decimal-'.$code);
        $evidenceId = DB::table('estimate_generation_evidence')->insertGetId([
            'organization_id' => DB::table('estimate_generation_evidence')->where('id', $fixture['evidence_id'])->value('organization_id'),
            'project_id' => DB::table('estimate_generation_evidence')->where('id', $fixture['evidence_id'])->value('project_id'),
            'session_id' => DB::table('estimate_generation_evidence')->where('id', $fixture['evidence_id'])->value('session_id'),
            'type' => 'work_item', 'source_type' => 'user_input', 'source_ref' => 'input:2',
            'source_version' => 'contract:abcdef', 'locator' => json_encode(['item_key' => 'item:'.hash('sha256', 'large-decimal')], JSON_THROW_ON_ERROR),
            'value' => json_encode(['work_code' => 'work_type:2', 'quantity' => 12345.678901, 'unit' => 'h'], JSON_THROW_ON_ERROR),
            'confidence' => 1, 'producer_name' => 'contract', 'producer_version' => 'contract:abcdef',
            'fingerprint' => $fingerprint, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $firstId = 0;
        foreach ([1, 2] as $revision) {
            $itemId = DB::table('estimate_generation_package_items')->insertGetId(array_replace(
                $this->itemPayload($fixture, 'large-decimal', $revision, $revision === 1 ? null : $firstId),
                ['estimate_norm_id' => $normId, 'quantity_evidence_id' => $evidenceId,
                    'quantity_evidence_fingerprint' => $fingerprint, 'regional_price_version_id' => $catalog['version_id']],
            ));
            DB::table('estimate_generation_package_item_price_inputs')->insert([
                'package_item_id' => $itemId, 'norm_resource_id' => $normResourceId,
                'resource_price_id' => $catalog['price_id'], 'unit_conversion_id' => null, 'ordinal' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::select('SELECT eg_finalize_package_item_price(?)', [$itemId]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
            $firstId = $revision === 1 ? $itemId : $firstId;
        }
        $expected = (string) BigDecimal::of($quantity)->multipliedBy($basePrice)->toScale(2, RoundingMode::HalfUp);
        $latest = DB::table('estimate_generation_package_items')->where('logical_key', 'large-decimal')
            ->orderByDesc('revision')->first();
        self::assertSame($expected, $latest->total_cost);
        $packageTotal = DB::selectOne(<<<'SQL'
SELECT to_char(sum(total_cost), 'FM999999999999999990.00') AS total
FROM (
  SELECT DISTINCT ON (logical_key) logical_key, total_cost
  FROM estimate_generation_package_items
  WHERE package_id = ? AND price_snapshot IS NOT NULL
  ORDER BY logical_key, revision DESC
) latest
SQL, [$fixture['package_id']]);
        self::assertSame((string) BigDecimal::of($expected)->plus('75.00')->toScale(2), $packageTotal->total);
    }

    private function assertRejected(callable $write): void
    {
        DB::statement('SAVEPOINT price_snapshot_contract');
        try {
            $write();
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            self::fail('PostgreSQL accepted an invalid pricing mutation.');
        } catch (QueryException) {
            self::addToAssertionCount(1);
        } finally {
            DB::statement('ROLLBACK TO SAVEPOINT price_snapshot_contract');
            DB::statement('RELEASE SAVEPOINT price_snapshot_contract');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
        }
    }

    private function requireDisposablePostgres(): void
    {
        $database = (string) DB::connection()->getDatabaseName();
        if (getenv('RUN_POSTGRES_PRICE_SNAPSHOT_CONTRACT') !== '1' || DB::getDriverName() !== 'pgsql' || ! str_ends_with($database, '_contract')) {
            self::markTestSkipped('Requires explicit disposable PostgreSQL price snapshot contract database.');
        }
    }
}
