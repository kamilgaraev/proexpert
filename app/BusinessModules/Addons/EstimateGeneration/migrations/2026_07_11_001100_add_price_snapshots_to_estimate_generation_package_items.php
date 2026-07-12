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
        AND (price_snapshot->>'region_id')::bigint > 0
        AND (price_snapshot->>'zone_id')::bigint > 0
        AND (price_snapshot->>'period_id')::bigint > 0
        AND (price_snapshot->>'version_id')::bigint > 0
        AND price_snapshot->>'source_reference' <> ''
        AND price_snapshot->>'currency' ~ '^[A-Z]{3}$'
        AND price_snapshot->>'base_amount' ~ '^[0-9]+(\.[0-9]{1,4})?$'
        AND price_snapshot->>'final_amount' ~ '^[0-9]+(\.[0-9]{1,2})?$'
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
        }

        Schema::table('estimate_generation_package_items', function (Blueprint $table): void {
            $table->dropColumn('price_snapshot');
        });
    }
};
