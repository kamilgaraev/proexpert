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
            $table->char('snapshot_materialized_source_hash', 64)->nullable();
        });
        Schema::table('report_exports', function (Blueprint $table): void {
            $table->char('snapshot_materialized_source_hash', 64)->nullable();
        });

        DB::statement('UPDATE report_runs SET snapshot_materialized_source_hash = source_hash WHERE snapshot_id IS NOT NULL');
        DB::statement(<<<'SQL'
UPDATE report_runs AS runs
SET snapshot_materialized_source_hash = snapshots.source_hash
FROM workforce_report_snapshots AS snapshots
WHERE runs.snapshot_id = snapshots.id
  AND runs.organization_id = snapshots.organization_id
  AND runs.report_code = snapshots.report_code
SQL);
        DB::statement(<<<'SQL'
UPDATE report_exports AS exports
SET snapshot_materialized_source_hash = runs.snapshot_materialized_source_hash
FROM report_runs AS runs
WHERE exports.run_id = runs.id
  AND exports.organization_id = runs.organization_id
SQL);

        DB::statement("ALTER TABLE report_runs ADD CONSTRAINT report_runs_snapshot_materialized_source_hash_check CHECK (snapshot_materialized_source_hash IS NULL OR snapshot_materialized_source_hash ~ '^[a-f0-9]{64}$')");
        DB::statement("ALTER TABLE report_exports ADD CONSTRAINT report_exports_snapshot_materialized_source_hash_check CHECK (snapshot_materialized_source_hash IS NULL OR snapshot_materialized_source_hash ~ '^[a-f0-9]{64}$')");
        DB::statement("ALTER TABLE report_runs ADD CONSTRAINT report_runs_ready_materialized_source_hash_check CHECK (status NOT IN ('ready', 'expired') OR snapshot_materialized_source_hash IS NOT NULL)");
        DB::statement("ALTER TABLE report_exports ADD CONSTRAINT report_exports_materialized_source_hash_required_check CHECK (snapshot_materialized_source_hash IS NOT NULL)");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE report_exports DROP CONSTRAINT IF EXISTS report_exports_materialized_source_hash_required_check');
        DB::statement('ALTER TABLE report_runs DROP CONSTRAINT IF EXISTS report_runs_ready_materialized_source_hash_check');
        DB::statement('ALTER TABLE report_exports DROP CONSTRAINT IF EXISTS report_exports_snapshot_materialized_source_hash_check');
        DB::statement('ALTER TABLE report_runs DROP CONSTRAINT IF EXISTS report_runs_snapshot_materialized_source_hash_check');

        Schema::table('report_exports', function (Blueprint $table): void {
            $table->dropColumn('snapshot_materialized_source_hash');
        });
        Schema::table('report_runs', function (Blueprint $table): void {
            $table->dropColumn('snapshot_materialized_source_hash');
        });
    }
};
