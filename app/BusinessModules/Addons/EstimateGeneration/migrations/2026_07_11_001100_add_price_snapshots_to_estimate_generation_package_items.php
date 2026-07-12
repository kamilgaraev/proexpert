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
DECLARE evidence_count integer := 0;
DECLARE resource_total numeric := 0;
DECLARE manifest text;
BEGIN
    IF NOT ((snapshot ?& ARRAY['region_id','zone_id','period_id','version_id','source_type','source_reference','base_amount','coefficients','final_amount','currency','captured_at']) IS TRUE)
       OR NOT ((snapshot->'coefficients' ?& ARRAY['work_cost','resource_evidence']) IS TRUE)
       OR NOT (jsonb_typeof(snapshot->'coefficients'->'resource_evidence') = 'array') IS TRUE
       OR NOT (jsonb_array_length(snapshot->'coefficients'->'resource_evidence') > 0) IS TRUE THEN
        RETURN false;
    END IF;
    FOR evidence IN SELECT value FROM jsonb_array_elements(snapshot->'coefficients'->'resource_evidence') LOOP
        evidence_count := evidence_count + 1;
        IF NOT (jsonb_typeof(evidence) = 'object') IS TRUE
           OR NOT ((evidence ?& ARRAY['region_id','zone_id','period_id','version_id','source_type','source_reference','base_amount','coefficients','final_amount','currency','captured_at']) IS TRUE)
           OR NOT (evidence - ARRAY['region_id','zone_id','period_id','version_id','source_type','source_reference','base_amount','coefficients','final_amount','currency','captured_at'] = '{}'::jsonb) IS TRUE
           OR NOT (jsonb_typeof(evidence->'region_id') = 'number' AND jsonb_typeof(evidence->'zone_id') = 'number' AND jsonb_typeof(evidence->'period_id') = 'number' AND jsonb_typeof(evidence->'version_id') = 'number') IS TRUE
           OR NOT ((evidence->>'region_id')::numeric = trunc((evidence->>'region_id')::numeric) AND (evidence->>'region_id')::numeric > 0) IS TRUE
           OR NOT ((evidence->>'zone_id')::numeric = trunc((evidence->>'zone_id')::numeric) AND (evidence->>'zone_id')::numeric > 0) IS TRUE
           OR NOT ((evidence->>'period_id')::numeric = trunc((evidence->>'period_id')::numeric) AND (evidence->>'period_id')::numeric > 0) IS TRUE
           OR NOT ((evidence->>'version_id')::numeric = trunc((evidence->>'version_id')::numeric) AND (evidence->>'version_id')::numeric > 0) IS TRUE
           OR NOT (jsonb_typeof(evidence->'source_type') = 'string' AND evidence->>'source_type' IN ('fgiscs','regional_catalog','fsbc','fgis_labor_prices')) IS TRUE
           OR NOT (jsonb_typeof(evidence->'source_reference') = 'string' AND evidence->>'source_reference' ~ '^estimate_resource_prices:[1-9][0-9]*$') IS TRUE
           OR NOT (jsonb_typeof(evidence->'base_amount') = 'string' AND evidence->>'base_amount' ~ '^[0-9]+(\.[0-9]{4})$') IS TRUE
           OR NOT (jsonb_typeof(evidence->'final_amount') = 'string' AND evidence->>'final_amount' ~ '^[0-9]+(\.[0-9]{2})$') IS TRUE
           OR NOT (jsonb_typeof(evidence->'currency') = 'string' AND evidence->>'currency' = snapshot->>'currency') IS TRUE
           OR NOT (evidence->>'region_id' = snapshot->>'region_id' AND evidence->>'zone_id' = snapshot->>'zone_id' AND evidence->>'period_id' = snapshot->>'period_id' AND evidence->>'version_id' = snapshot->>'version_id') IS TRUE
           OR NOT (jsonb_typeof(evidence->'captured_at') = 'string') IS TRUE
           OR NOT (jsonb_typeof(evidence->'coefficients') = 'object' AND (evidence->'coefficients' ?& ARRAY['quantity']) AND (evidence->'coefficients') - ARRAY['quantity'] = '{}'::jsonb) IS TRUE
           OR NOT (jsonb_typeof(evidence->'coefficients'->'quantity') = 'string' AND evidence->'coefficients'->>'quantity' ~ '^[0-9]+(\.[0-9]{6})$') IS TRUE
           OR NOT ((evidence->>'final_amount')::numeric = round((evidence->>'base_amount')::numeric * (evidence->'coefficients'->>'quantity')::numeric, 2)) IS TRUE THEN
            RETURN false;
        END IF;
        resource_total := resource_total + (evidence->>'final_amount')::numeric;
    END LOOP;
    SELECT string_agg(value->>'source_reference', '|' ORDER BY value->>'source_reference') INTO manifest
    FROM jsonb_array_elements(snapshot->'coefficients'->'resource_evidence');
    RETURN (evidence_count > 0
        AND (snapshot->>'base_amount')::numeric = round(resource_total, 2)
        AND (snapshot->>'final_amount')::numeric = round(resource_total + (snapshot->'coefficients'->>'work_cost')::numeric, 2)
        AND snapshot->>'source_reference' = 'sha256:' || encode(sha256(convert_to(manifest, 'UTF8')), 'hex')) IS TRUE;
EXCEPTION WHEN others THEN
    RETURN false;
END;
$$;
SQL);
        DB::unprepared(<<<'SQL'
ALTER TABLE estimate_generation_package_items
ADD CONSTRAINT eg_package_items_price_snapshot_required_ck
CHECK (total_cost <= 0 OR price_snapshot IS NOT NULL) NOT VALID,
ADD CONSTRAINT eg_package_items_price_snapshot_shape_ck
CHECK (
    price_snapshot IS NULL OR (
        (jsonb_typeof(price_snapshot) = 'object') IS TRUE
        AND (price_snapshot ?& ARRAY['region_id','zone_id','period_id','version_id','source_type','source_reference','base_amount','coefficients','final_amount','currency','captured_at']) IS TRUE
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
        AND (price_snapshot->'coefficients' ?& ARRAY['work_cost','resource_evidence']) IS TRUE
        AND ((price_snapshot->'coefficients') - ARRAY['work_cost','resource_evidence'] = '{}'::jsonb) IS TRUE
        AND jsonb_typeof(price_snapshot->'coefficients'->'work_cost') = 'string'
        AND price_snapshot->'coefficients'->>'work_cost' ~ '^[0-9]+(\.[0-9]{1,2})?$'
        AND jsonb_typeof(price_snapshot->'coefficients'->'resource_evidence') = 'array'
        AND jsonb_array_length(price_snapshot->'coefficients'->'resource_evidence') > 0
        AND eg_price_resource_evidence_valid(price_snapshot) IS TRUE
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
