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
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('DROP MATERIALIZED VIEW IF EXISTS mv_normative_rates_usage CASCADE');

        DB::table('estimate_items')->whereNull('quantity')->update(['quantity' => 0]);
        DB::table('estimate_items')->whereNull('unit_price')->update(['unit_price' => 0]);
        DB::table('estimate_items')->whereNull('current_unit_price')->update(['current_unit_price' => 0]);

        Schema::table('estimate_items', function (Blueprint $table): void {
            $table->decimal('quantity', 20, 8)->default(0)->change();
            $table->decimal('unit_price', 20, 4)->default(0)->change();
            $table->decimal('current_unit_price', 20, 4)->default(0)->change();
        });

        DB::statement(<<<'SQL'
            CREATE MATERIALIZED VIEW mv_normative_rates_usage AS
            SELECT
                nr.id AS rate_id,
                nr.collection_id,
                nr.code,
                nr.name,
                COUNT(DISTINCT ei.estimate_id) AS used_in_estimates,
                COUNT(ei.id) AS usage_count,
                SUM(ei.quantity) AS total_quantity,
                MAX(ei.updated_at) AS last_used_at
            FROM normative_rates nr
            LEFT JOIN estimate_items ei ON ei.normative_rate_id = nr.id AND ei.deleted_at IS NULL
            GROUP BY nr.id, nr.collection_id, nr.code, nr.name
            SQL);

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS mv_normative_rates_usage_rate_idx ON mv_normative_rates_usage(rate_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS mv_normative_rates_usage_collection_idx ON mv_normative_rates_usage(collection_id, usage_count DESC)');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('DROP MATERIALIZED VIEW IF EXISTS mv_normative_rates_usage CASCADE');

        Schema::table('estimate_items', function (Blueprint $table): void {
            $table->decimal('quantity', 18, 4)->change();
            $table->decimal('unit_price', 15, 2)->change();
            $table->decimal('current_unit_price', 15, 2)->change();
        });

        DB::statement(<<<'SQL'
            CREATE MATERIALIZED VIEW mv_normative_rates_usage AS
            SELECT
                nr.id AS rate_id,
                nr.collection_id,
                nr.code,
                nr.name,
                COUNT(DISTINCT ei.estimate_id) AS used_in_estimates,
                COUNT(ei.id) AS usage_count,
                SUM(ei.quantity) AS total_quantity,
                MAX(ei.updated_at) AS last_used_at
            FROM normative_rates nr
            LEFT JOIN estimate_items ei ON ei.normative_rate_id = nr.id AND ei.deleted_at IS NULL
            GROUP BY nr.id, nr.collection_id, nr.code, nr.name
            SQL);

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS mv_normative_rates_usage_rate_idx ON mv_normative_rates_usage(rate_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS mv_normative_rates_usage_collection_idx ON mv_normative_rates_usage(collection_id, usage_count DESC)');
    }
};
