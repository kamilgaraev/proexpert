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
        Schema::table('quality_defect_photos', function (Blueprint $table): void {
            $table->string('storage_version_id', 255)->nullable();
            $table->string('storage_etag', 255)->nullable();
            $table->char('storage_sha256', 64)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('mime_type', 255)->nullable();
            $table->boolean('storage_identity_verified')->default(false);
        });
        DB::statement("ALTER TABLE quality_defect_photos ADD CONSTRAINT quality_defect_photo_storage_identity_check CHECK ((NOT storage_identity_verified AND storage_version_id IS NULL AND storage_etag IS NULL AND storage_sha256 IS NULL AND size_bytes IS NULL AND mime_type IS NULL) OR (storage_identity_verified AND url LIKE 'org-%/%' AND url NOT LIKE '%://%' AND storage_version_id IS NOT NULL AND storage_etag IS NOT NULL AND storage_sha256 ~ '^[a-f0-9]{64}$' AND size_bytes > 0 AND mime_type IS NOT NULL))");
        foreach ([
            'quality_defect_flow_snapshots',
            'safety_incident_snapshots',
            'safety_admission_snapshots',
        ] as $table) {
            Schema::table($table, static function (Blueprint $table): void {
                $table->timestampTz('sealed_at')->nullable();
            });
        }
        foreach ([
            'quality_defect_flow_snapshots' => 'quality_defect_flow_rows',
            'safety_incident_snapshots' => 'safety_incident_rows',
            'safety_admission_snapshots' => 'safety_admission_rows',
        ] as $snapshot => $rows) {
            DB::statement("DO $$ BEGIN IF EXISTS (SELECT 1 FROM {$snapshot} snapshot WHERE snapshot.row_count <> (SELECT count(*) FROM {$rows} row_record WHERE row_record.snapshot_id = snapshot.id) OR snapshot.output_hash !~ '^[a-f0-9]{64}$') THEN RAISE EXCEPTION 'sealed_reporting_existing_output_invalid' USING ERRCODE = '23514'; END IF; UPDATE {$snapshot} SET sealed_at = clock_timestamp() WHERE sealed_at IS NULL; END $$");
        }
        DB::unprepared(<<<'SQL'
CREATE FUNCTION sealed_reporting_snapshot_guard() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    persisted_rows bigint;
BEGIN
    IF TG_OP = 'UPDATE'
       AND OLD.sealed_at IS NULL
       AND NEW.sealed_at IS NOT NULL
       AND (to_jsonb(NEW) - 'sealed_at') = (to_jsonb(OLD) - 'sealed_at')
       AND NEW.output_hash ~ '^[a-f0-9]{64}$' THEN
        EXECUTE format('SELECT count(*) FROM %I WHERE snapshot_id = $1', TG_ARGV[0])
        INTO persisted_rows USING NEW.id;
        IF persisted_rows = NEW.row_count THEN
            RETURN NEW;
        END IF;
        RAISE EXCEPTION 'sealed_reporting_row_count_mismatch' USING ERRCODE = '23514';
    END IF;
    RAISE EXCEPTION 'sealed_reporting_record_immutable' USING ERRCODE = '55000';
END;
$$;
CREATE FUNCTION sealed_reporting_row_guard() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    parent_sealed_at timestamptz;
BEGIN
    IF TG_OP = 'INSERT' THEN
        EXECUTE format('SELECT sealed_at FROM %I WHERE id = $1', TG_ARGV[0])
        INTO parent_sealed_at USING NEW.snapshot_id;
        IF parent_sealed_at IS NULL THEN
            RETURN NEW;
        END IF;
    END IF;
    RAISE EXCEPTION 'sealed_reporting_record_immutable' USING ERRCODE = '55000';
END;
$$;
SQL);
        foreach ([
            'quality_defect_flow_snapshots' => 'quality_defect_flow_rows',
            'safety_incident_snapshots' => 'safety_incident_rows',
            'safety_admission_snapshots' => 'safety_admission_rows',
        ] as $snapshot => $rows) {
            DB::statement("CREATE TRIGGER {$snapshot}_sealed BEFORE UPDATE OR DELETE ON {$snapshot} FOR EACH ROW EXECUTE FUNCTION sealed_reporting_snapshot_guard('{$rows}')");
            DB::statement("CREATE TRIGGER {$rows}_sealed BEFORE INSERT OR UPDATE OR DELETE ON {$rows} FOR EACH ROW EXECUTE FUNCTION sealed_reporting_row_guard('{$snapshot}')");
        }
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS sealed_reporting_snapshot_guard() CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS sealed_reporting_row_guard() CASCADE');
        foreach ([
            'quality_defect_flow_snapshots',
            'safety_incident_snapshots',
            'safety_admission_snapshots',
        ] as $table) {
            Schema::table($table, static function (Blueprint $table): void {
                $table->dropColumn('sealed_at');
            });
        }
        DB::statement('ALTER TABLE quality_defect_photos DROP CONSTRAINT IF EXISTS quality_defect_photo_storage_identity_check');
        Schema::table('quality_defect_photos', function (Blueprint $table): void {
            $table->dropColumn([
                'storage_version_id',
                'storage_etag',
                'storage_sha256',
                'size_bytes',
                'mime_type',
                'storage_identity_verified',
            ]);
        });
    }
};
