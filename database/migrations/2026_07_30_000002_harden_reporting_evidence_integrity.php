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
        DB::unprepared(<<<'SQL'
CREATE FUNCTION sealed_reporting_record_guard() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'sealed_reporting_record_immutable' USING ERRCODE = '55000';
END;
$$;
SQL);
        foreach ([
            'quality_defect_flow_snapshots',
            'quality_defect_flow_rows',
            'safety_incident_snapshots',
            'safety_incident_rows',
            'safety_admission_snapshots',
            'safety_admission_rows',
        ] as $table) {
            DB::statement("CREATE TRIGGER {$table}_sealed BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION sealed_reporting_record_guard()");
        }
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS sealed_reporting_record_guard() CASCADE');
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
