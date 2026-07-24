<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS production_labor_output_validation_idx '
                .'ON production_labor_output_entries (organization_id, status, work_date, work_order_line_id) '
                .'WHERE deleted_at IS NULL'
            );

            return;
        }

        Schema::table('production_labor_output_entries', function (Blueprint $table): void {
            $table->index(
                ['organization_id', 'status', 'work_date', 'work_order_line_id'],
                'production_labor_output_validation_idx'
            );
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS production_labor_output_validation_idx');

            return;
        }

        Schema::table('production_labor_output_entries', function (Blueprint $table): void {
            $table->dropIndex('production_labor_output_validation_idx');
        });
    }
};
