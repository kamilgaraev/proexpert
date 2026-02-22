<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. В PostgreSQL нельзя менять тип колонки, если от нее зависит Materialized View.
        // Поэтому сначала удаляем вьюху.
        DB::statement('DROP MATERIALIZED VIEW IF EXISTS mv_normative_rates_usage CASCADE');

        Schema::table('estimate_items', function (Blueprint $table) {
            // Расширяем до 8 знаков после запятой, чтобы ловить доли копеек из Гранд-Сметы
            $table->decimal('quantity', 20, 8)->change();
            // Также на всякий случай расширим цену, иногда там тоже много знаков
            $table->decimal('unit_price', 20, 4)->change();
            $table->decimal('current_unit_price', 20, 4)->change();
        });

        // 2. Воссоздаем Materialized View mv_normative_rates_usage
        DB::statement("
            CREATE MATERIALIZED VIEW mv_normative_rates_usage AS
            SELECT 
                nr.id as rate_id,
                nr.collection_id,
                nr.code,
                nr.name,
                COUNT(DISTINCT ei.estimate_id) as used_in_estimates,
                COUNT(ei.id) as usage_count,
                SUM(ei.quantity) as total_quantity,
                MAX(ei.updated_at) as last_used_at
            FROM normative_rates nr
            LEFT JOIN estimate_items ei ON ei.normative_rate_id = nr.id AND ei.deleted_at IS NULL
            GROUP BY nr.id, nr.collection_id, nr.code, nr.name;
        ");

        // 3. Воссоздаем индексы для вьюхи
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS mv_normative_rates_usage_rate_idx ON mv_normative_rates_usage(rate_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS mv_normative_rates_usage_collection_idx ON mv_normative_rates_usage(collection_id, usage_count DESC)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP MATERIALIZED VIEW IF EXISTS mv_normative_rates_usage CASCADE');

        Schema::table('estimate_items', function (Blueprint $table) {
            $table->decimal('quantity', 18, 4)->change();
            $table->decimal('unit_price', 15, 2)->change();
            $table->decimal('current_unit_price', 15, 2)->change();
        });

        // Воссоздаем оригинальную версию из миграции 2025_11_01_000010
        DB::statement("
            CREATE MATERIALIZED VIEW mv_normative_rates_usage AS
            SELECT 
                nr.id as rate_id,
                nr.collection_id,
                nr.code,
                nr.name,
                COUNT(DISTINCT ei.estimate_id) as used_in_estimates,
                COUNT(ei.id) as usage_count,
                SUM(ei.quantity) as total_quantity,
                MAX(ei.updated_at) as last_used_at
            FROM normative_rates nr
            LEFT JOIN estimate_items ei ON ei.normative_rate_id = nr.id AND ei.deleted_at IS NULL
            GROUP BY nr.id, nr.collection_id, nr.code, nr.name;
        ");

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS mv_normative_rates_usage_rate_idx ON mv_normative_rates_usage(rate_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS mv_normative_rates_usage_collection_idx ON mv_normative_rates_usage(collection_id, usage_count DESC)');
    }
};
