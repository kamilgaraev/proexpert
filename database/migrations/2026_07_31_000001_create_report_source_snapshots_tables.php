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
        Schema::create('report_source_snapshots', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->text('source_kind');
            $table->text('report_code');
            $table->text('schema_version');
            $table->unsignedBigInteger('organization_id');
            $table->jsonb('scope_identity');
            $table->char('query_hash', 64);
            $table->timestampTz('as_of', 6);
            $table->char('source_hash', 64);
            $table->jsonb('watermarks');
            $table->timestampTz('generated_at', 6);
            $table->timestampTz('stale_at', 6)->nullable();
            $table->text('status');
            $table->unsignedBigInteger('row_count');
            $table->unsignedBigInteger('drill_row_count');
            $table->char('snapshot_hash', 64);
            $table->timestampTz('ready_at', 6)->nullable();
            $table->timestampTz('expired_at', 6)->nullable();
            $table->timestampTz('created_at', 6);
            $table->timestampTz('updated_at', 6);
        });

        Schema::create('report_source_snapshot_rows', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->char('snapshot_id', 26);
            $table->unsignedBigInteger('ordinal');
            $table->text('row_key');
            $table->jsonb('payload');
            $table->char('payload_hash', 64);
            $table->timestampTz('created_at', 6);
            $table->unique(['snapshot_id', 'ordinal']);
            $table->unique(['snapshot_id', 'row_key']);
        });

        Schema::create('report_source_snapshot_drill_rows', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->char('snapshot_id', 26);
            $table->text('row_key');
            $table->text('column_id');
            $table->unsignedBigInteger('ordinal');
            $table->jsonb('payload');
            $table->char('payload_hash', 64);
            $table->timestampTz('created_at', 6);
            $table->unique(['snapshot_id', 'row_key', 'column_id', 'ordinal'], 'report_source_snapshot_drill_rows_order_unique');
        });

        foreach ([
            "ALTER TABLE report_source_snapshots ADD CONSTRAINT report_source_snapshots_status_check CHECK (status IN ('writing','ready','expired'))",
            "ALTER TABLE report_source_snapshots ADD CONSTRAINT report_source_snapshots_hashes_check CHECK (query_hash ~ '^[a-f0-9]{64}$' AND source_hash ~ '^[a-f0-9]{64}$' AND snapshot_hash ~ '^[a-f0-9]{64}$')",
            "ALTER TABLE report_source_snapshots ADD CONSTRAINT report_source_snapshots_lifecycle_check CHECK ((status = 'writing' AND ready_at IS NULL AND expired_at IS NULL) OR (status = 'ready' AND ready_at IS NOT NULL AND expired_at IS NULL) OR (status = 'expired' AND ready_at IS NOT NULL AND expired_at IS NOT NULL))",
            "ALTER TABLE report_source_snapshots ADD CONSTRAINT report_source_snapshots_time_check CHECK ((stale_at IS NULL OR stale_at >= generated_at) AND (ready_at IS NULL OR ready_at >= generated_at) AND (expired_at IS NULL OR expired_at >= ready_at))",
            "ALTER TABLE report_source_snapshot_rows ADD CONSTRAINT report_source_snapshot_rows_hash_check CHECK (payload_hash ~ '^[a-f0-9]{64}$')",
            "ALTER TABLE report_source_snapshot_drill_rows ADD CONSTRAINT report_source_snapshot_drill_rows_hash_check CHECK (payload_hash ~ '^[a-f0-9]{64}$')",
            'ALTER TABLE report_source_snapshot_rows ADD CONSTRAINT report_source_snapshot_rows_header_fk FOREIGN KEY (snapshot_id) REFERENCES report_source_snapshots(id) ON DELETE RESTRICT',
            'ALTER TABLE report_source_snapshot_drill_rows ADD CONSTRAINT report_source_snapshot_drill_rows_header_fk FOREIGN KEY (snapshot_id) REFERENCES report_source_snapshots(id) ON DELETE RESTRICT',
            'CREATE INDEX report_source_snapshots_read_idx ON report_source_snapshots (organization_id, report_code, query_hash, id) WHERE status = \'ready\'',
            'CREATE INDEX report_source_snapshot_rows_page_idx ON report_source_snapshot_rows (snapshot_id, ordinal)',
            'CREATE INDEX report_source_snapshot_drill_rows_page_idx ON report_source_snapshot_drill_rows (snapshot_id, row_key, column_id, ordinal)',
            <<<'SQL'
CREATE FUNCTION report_source_snapshot_prevent_ready_mutation() RETURNS trigger AS $$
BEGIN
    IF TG_TABLE_NAME = 'report_source_snapshots' AND TG_OP IN ('UPDATE', 'DELETE') AND OLD.status IN ('ready', 'expired') THEN
        RAISE EXCEPTION 'report_source_snapshot_immutable';
    END IF;
    IF TG_TABLE_NAME <> 'report_source_snapshots' THEN
        IF TG_OP = 'DELETE' AND EXISTS (
            SELECT 1 FROM report_source_snapshots WHERE id = OLD.snapshot_id AND status IN ('ready', 'expired')
        ) THEN
            RAISE EXCEPTION 'report_source_snapshot_immutable';
        END IF;
        IF TG_OP IN ('INSERT', 'UPDATE') AND EXISTS (
            SELECT 1 FROM report_source_snapshots WHERE id = NEW.snapshot_id AND status IN ('ready', 'expired')
        ) THEN
            RAISE EXCEPTION 'report_source_snapshot_immutable';
        END IF;
    END IF;
    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql
SQL,
            'CREATE TRIGGER report_source_snapshots_immutable BEFORE UPDATE OR DELETE ON report_source_snapshots FOR EACH ROW EXECUTE FUNCTION report_source_snapshot_prevent_ready_mutation()',
            'CREATE TRIGGER report_source_snapshot_rows_immutable BEFORE UPDATE OR DELETE ON report_source_snapshot_rows FOR EACH ROW EXECUTE FUNCTION report_source_snapshot_prevent_ready_mutation()',
            'CREATE TRIGGER report_source_snapshot_drill_rows_immutable BEFORE UPDATE OR DELETE ON report_source_snapshot_drill_rows FOR EACH ROW EXECUTE FUNCTION report_source_snapshot_prevent_ready_mutation()',
            'CREATE TRIGGER report_source_snapshot_rows_no_ready_insert BEFORE INSERT ON report_source_snapshot_rows FOR EACH ROW EXECUTE FUNCTION report_source_snapshot_prevent_ready_mutation()',
            'CREATE TRIGGER report_source_snapshot_drill_rows_no_ready_insert BEFORE INSERT ON report_source_snapshot_drill_rows FOR EACH ROW EXECUTE FUNCTION report_source_snapshot_prevent_ready_mutation()',
        ] as $statement) {
            DB::statement($statement);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('report_source_snapshot_drill_rows');
        Schema::dropIfExists('report_source_snapshot_rows');
        Schema::dropIfExists('report_source_snapshots');
        DB::statement('DROP FUNCTION IF EXISTS report_source_snapshot_prevent_ready_mutation()');
    }
};
