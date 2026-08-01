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
        Schema::table('report_source_snapshots', function (Blueprint $table): void {
            $table->jsonb('report_query_identity')->nullable();
            $table->char('report_query_hash', 64)->nullable();
            $table->char('materialized_source_hash', 64)->nullable();
        });

        DB::statement('UPDATE report_source_snapshots SET materialized_source_hash = source_hash WHERE materialized_source_hash IS NULL');
        DB::statement(
            'ALTER TABLE report_source_snapshots ADD CONSTRAINT report_source_snapshots_report_query_identity_check '
            ."CHECK ((report_query_identity IS NULL AND report_query_hash IS NULL) OR (report_query_identity IS NOT NULL AND report_query_hash ~ '^[a-f0-9]{64}$'))",
        );
        DB::statement(
            'ALTER TABLE report_source_snapshots ADD CONSTRAINT report_source_snapshots_materialized_source_hash_check '
            ."CHECK (materialized_source_hash IS NULL OR materialized_source_hash ~ '^[a-f0-9]{64}$')",
        );
        DB::statement(<<<'SQL'
CREATE FUNCTION report_source_snapshot_require_report_identity() RETURNS trigger AS $$
BEGIN
    IF NEW.status = 'ready' AND (NEW.report_query_identity IS NULL OR NEW.report_query_hash IS NULL OR NEW.materialized_source_hash IS NULL) THEN
        RAISE EXCEPTION 'report_source_snapshot_report_identity_required';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql
SQL);
        DB::statement('CREATE TRIGGER report_source_snapshots_require_report_identity BEFORE INSERT OR UPDATE ON report_source_snapshots FOR EACH ROW EXECUTE FUNCTION report_source_snapshot_require_report_identity()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS report_source_snapshots_require_report_identity ON report_source_snapshots');
        DB::statement('DROP FUNCTION IF EXISTS report_source_snapshot_require_report_identity()');
        DB::statement('ALTER TABLE report_source_snapshots DROP CONSTRAINT IF EXISTS report_source_snapshots_materialized_source_hash_check');
        DB::statement('ALTER TABLE report_source_snapshots DROP CONSTRAINT IF EXISTS report_source_snapshots_report_query_identity_check');

        Schema::table('report_source_snapshots', function (Blueprint $table): void {
            $table->dropColumn(['report_query_identity', 'report_query_hash', 'materialized_source_hash']);
        });
    }
};
