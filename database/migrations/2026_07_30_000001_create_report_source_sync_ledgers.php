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
        Schema::create('report_source_generations', function (Blueprint $table): void {
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('source_code', 96);
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestampTz('watermark');
            $table->primary(['organization_id', 'source_code']);
        });
        Schema::create('report_source_sync_ledgers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('source_code', 96);
            $table->jsonb('cursor')->default('{}');
            $table->jsonb('target_cursor')->default('{}');
            $table->string('owner_checksum', 64);
            $table->string('completed_owner_checksum', 64)->nullable();
            $table->string('status', 24)->default('pending');
            $table->unsignedBigInteger('source_count')->default(0);
            $table->unsignedBigInteger('projected_count')->default(0);
            $table->unsignedBigInteger('gap_count')->default(0);
            $table->unsignedBigInteger('unknown_count')->default(0);
            $table->jsonb('unknown_owner_keys')->default('[]');
            $table->timestampTz('source_watermark')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'source_code']);
        });
        DB::statement("ALTER TABLE report_source_sync_ledgers ADD CONSTRAINT report_source_sync_status_check CHECK (status IN ('pending','running','ready','partial','failed'))");
        DB::statement("ALTER TABLE report_source_sync_ledgers ADD CONSTRAINT report_source_sync_owner_hash_check CHECK (owner_checksum ~ '^[a-f0-9]{64}$' AND (completed_owner_checksum IS NULL OR completed_owner_checksum ~ '^[a-f0-9]{64}$'))");
        DB::unprepared(<<<'SQL'
CREATE FUNCTION bump_report_source_generation() RETURNS trigger AS $$
DECLARE
    owner_organization_id bigint;
    code text;
BEGIN
    owner_organization_id := COALESCE(NEW.organization_id, OLD.organization_id);
    FOREACH code IN ARRAY TG_ARGV LOOP
        INSERT INTO report_source_generations (organization_id, source_code, revision, watermark)
        VALUES (owner_organization_id, code, 1, clock_timestamp())
        ON CONFLICT (organization_id, source_code) DO UPDATE
        SET revision = report_source_generations.revision + 1,
            watermark = EXCLUDED.watermark;
    END LOOP;
    RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql;
SQL);
        foreach ($this->generationSources() as $table => $sourceCodes) {
            $arguments = implode(', ', array_map(
                static fn (string $code): string => DB::getPdo()->quote($code),
                $sourceCodes,
            ));
            DB::statement(sprintf(
                'CREATE TRIGGER %s_report_source_generation AFTER INSERT OR UPDATE OR DELETE ON %s FOR EACH ROW EXECUTE FUNCTION bump_report_source_generation(%s)',
                $table,
                $table,
                $arguments,
            ));
        }
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS bump_report_source_generation() CASCADE');
        Schema::dropIfExists('report_source_sync_ledgers');
        Schema::dropIfExists('report_source_generations');
    }

    private function generationSources(): array
    {
        return [
            'quality_defect_status_history' => ['quality_defect_status_history'],
            'quality_defect_photos' => ['quality_defect_status_history'],
            'workforce_attendance_corrections' => ['approved_workforce_attendance'],
            'workforce_employee_assignments' => ['safety_site_workforce_assignments'],
            'workforce_employees' => ['safety_site_workforce_assignments'],
            'safety_site_workforce_assignments' => ['approved_workforce_attendance', 'safety_site_workforce_assignments'],
            'safety_sites' => ['approved_workforce_attendance', 'safety_site_workforce_assignments'],
            'safety_admission_policy_versions' => ['safety_site_workforce_assignments'],
            'safety_training_records' => ['safety_site_workforce_assignments'],
            'safety_medical_exams' => ['safety_site_workforce_assignments'],
            'safety_ppe_issues' => ['safety_site_workforce_assignments'],
            'safety_employee_requirements' => ['safety_site_workforce_assignments'],
            'safety_incidents' => ['safety_subject_lifecycle'],
            'safety_violations' => ['safety_subject_lifecycle'],
            'safety_corrective_actions' => ['safety_subject_lifecycle'],
            'safety_incident_policy_versions' => ['safety_subject_lifecycle'],
        ];
    }
};
