<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimate_generation_package_items', function (Blueprint $table): void {
            $table->jsonb('price_snapshot')->nullable();
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION eg_price_resource_evidence_valid(snapshot jsonb) RETURNS boolean
LANGUAGE plpgsql IMMUTABLE STRICT AS $$
DECLARE evidence jsonb;
BEGIN
    FOR evidence IN SELECT value FROM jsonb_array_elements(snapshot->'coefficients'->'resource_evidence') LOOP
        IF jsonb_typeof(evidence) <> 'object'
           OR evidence - ARRAY['region_id','zone_id','period_id','version_id','source_type','source_reference','base_amount','coefficients','final_amount','currency','captured_at'] <> '{}'::jsonb
           OR evidence->>'source_type' NOT IN ('fgiscs','regional_catalog','fsbc','fgis_labor_prices')
           OR evidence->>'source_reference' !~ '^estimate_resource_prices:[1-9][0-9]*$'
           OR evidence->>'base_amount' !~ '^[0-9]+(\.[0-9]{4})$'
           OR evidence->>'final_amount' !~ '^[0-9]+(\.[0-9]{2})$'
           OR evidence->>'currency' <> snapshot->>'currency'
           OR evidence->>'region_id' <> snapshot->>'region_id'
           OR evidence->>'zone_id' <> snapshot->>'zone_id'
           OR evidence->>'period_id' <> snapshot->>'period_id'
           OR evidence->>'version_id' <> snapshot->>'version_id'
           OR jsonb_typeof(evidence->'coefficients') <> 'object'
           OR (evidence->'coefficients') - ARRAY['quantity'] <> '{}'::jsonb
           OR evidence->'coefficients'->>'quantity' !~ '^[0-9]+(\.[0-9]{6})$' THEN
            RETURN false;
        END IF;
    END LOOP;
    RETURN true;
EXCEPTION WHEN others THEN
    RETURN false;
END;
$$;
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE estimate_generation_package_items
ADD CONSTRAINT eg_package_items_price_snapshot_required_ck
CHECK (total_cost <= 0 OR price_snapshot IS NOT NULL) NOT VALID,
ADD CONSTRAINT eg_package_items_price_snapshot_shape_ck
CHECK (
    price_snapshot IS NULL OR (
        jsonb_typeof(price_snapshot) = 'object'
        AND price_snapshot - ARRAY['region_id','zone_id','period_id','version_id','source_type','source_reference','base_amount','coefficients','final_amount','currency','captured_at'] = '{}'::jsonb
        AND jsonb_typeof(price_snapshot->'region_id') = 'number'
        AND jsonb_typeof(price_snapshot->'zone_id') = 'number'
        AND jsonb_typeof(price_snapshot->'period_id') = 'number'
        AND jsonb_typeof(price_snapshot->'version_id') = 'number'
        AND jsonb_typeof(price_snapshot->'source_type') = 'string'
        AND jsonb_typeof(price_snapshot->'source_reference') = 'string'
        AND jsonb_typeof(price_snapshot->'base_amount') = 'string'
        AND jsonb_typeof(price_snapshot->'coefficients') = 'object'
        AND jsonb_typeof(price_snapshot->'final_amount') = 'string'
        AND jsonb_typeof(price_snapshot->'currency') = 'string'
        AND jsonb_typeof(price_snapshot->'captured_at') = 'string'
        AND (price_snapshot->>'region_id')::numeric = trunc((price_snapshot->>'region_id')::numeric) AND (price_snapshot->>'region_id')::numeric > 0
        AND (price_snapshot->>'zone_id')::numeric = trunc((price_snapshot->>'zone_id')::numeric) AND (price_snapshot->>'zone_id')::numeric > 0
        AND (price_snapshot->>'period_id')::numeric = trunc((price_snapshot->>'period_id')::numeric) AND (price_snapshot->>'period_id')::numeric > 0
        AND (price_snapshot->>'version_id')::numeric = trunc((price_snapshot->>'version_id')::numeric) AND (price_snapshot->>'version_id')::numeric > 0
        AND price_snapshot->>'source_type' = 'regional_resource_aggregate'
        AND price_snapshot->>'source_reference' ~ '^sha256:[a-f0-9]{64}$'
        AND price_snapshot->>'currency' ~ '^[A-Z]{3}$'
        AND price_snapshot->>'base_amount' ~ '^[0-9]+(\.[0-9]{1,4})?$'
        AND price_snapshot->>'final_amount' ~ '^[0-9]+(\.[0-9]{1,2})?$'
        AND (price_snapshot->>'final_amount')::numeric = total_cost
        AND price_snapshot->>'captured_at' ~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(\.[0-9]+)?[+-][0-9]{2}:[0-9]{2}$'
        AND (price_snapshot->'coefficients') - ARRAY['work_cost','resource_evidence'] = '{}'::jsonb
        AND jsonb_typeof(price_snapshot->'coefficients'->'work_cost') = 'string'
        AND price_snapshot->'coefficients'->>'work_cost' ~ '^[0-9]+(\.[0-9]{1,2})?$'
        AND jsonb_typeof(price_snapshot->'coefficients'->'resource_evidence') = 'array'
        AND jsonb_array_length(price_snapshot->'coefficients'->'resource_evidence') > 0
        AND eg_price_resource_evidence_valid(price_snapshot)
        AND octet_length(price_snapshot::text) <= 262144
    )
) NOT VALID
SQL);
        DB::statement("CREATE INDEX eg_package_items_price_context_idx ON estimate_generation_package_items (((price_snapshot->>'region_id')::bigint), ((price_snapshot->>'zone_id')::bigint), ((price_snapshot->>'period_id')::bigint), ((price_snapshot->>'version_id')::bigint)) WHERE price_snapshot IS NOT NULL");
        DB::statement("CREATE INDEX eg_package_items_price_reference_idx ON estimate_generation_package_items ((price_snapshot->>'source_reference')) WHERE price_snapshot IS NOT NULL");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS eg_package_items_price_reference_idx');
            DB::statement('DROP INDEX IF EXISTS eg_package_items_price_context_idx');
            DB::statement('ALTER TABLE estimate_generation_package_items DROP CONSTRAINT IF EXISTS eg_package_items_price_snapshot_shape_ck');
            DB::statement('ALTER TABLE estimate_generation_package_items DROP CONSTRAINT IF EXISTS eg_package_items_price_snapshot_required_ck');
            DB::statement('DROP FUNCTION IF EXISTS eg_price_resource_evidence_valid(jsonb)');
        }

        Schema::table('estimate_generation_package_items', function (Blueprint $table): void {
            $table->dropColumn('price_snapshot');
        });
    }
};
