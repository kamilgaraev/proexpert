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
        Schema::table('report_runs', function (Blueprint $table): void {
            $table->text('correlation_lineage_id')->nullable();
        });

        DB::statement(
            "ALTER TABLE report_runs ADD CONSTRAINT report_runs_correlation_lineage_check ".
            "CHECK (correlation_lineage_id IS NULL OR correlation_lineage_id ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$')",
        );
        DB::statement(
            'CREATE UNIQUE INDEX report_runs_execution_lease_token_unique '.
            'ON report_runs (execution_lease_token) WHERE execution_lease_token IS NOT NULL',
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS report_runs_execution_lease_token_unique');
        DB::statement(
            'ALTER TABLE report_runs DROP CONSTRAINT IF EXISTS report_runs_correlation_lineage_check',
        );

        Schema::table('report_runs', function (Blueprint $table): void {
            $table->dropColumn('correlation_lineage_id');
        });
    }
};
