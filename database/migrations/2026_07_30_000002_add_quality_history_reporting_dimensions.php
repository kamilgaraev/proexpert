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
        Schema::table('quality_defect_status_history', function (Blueprint $table): void {
            $table->jsonb('reporting_dimensions')->nullable();
            $table->jsonb('reporting_evidence_refs')->nullable();
        });
        DB::unprepared(<<<'SQL'
CREATE FUNCTION quality_defect_history_reporting_immutable() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'quality_defect_history_immutable' USING ERRCODE = '55000';
    END IF;
    IF OLD.quality_defect_id IS DISTINCT FROM NEW.quality_defect_id
        OR OLD.organization_id IS DISTINCT FROM NEW.organization_id
        OR OLD.from_status IS DISTINCT FROM NEW.from_status
        OR OLD.to_status IS DISTINCT FROM NEW.to_status
        OR OLD.comment IS DISTINCT FROM NEW.comment
        OR OLD.changed_by IS DISTINCT FROM NEW.changed_by
        OR OLD.changed_at IS DISTINCT FROM NEW.changed_at
        OR OLD.reporting_dimensions IS DISTINCT FROM NEW.reporting_dimensions
        OR OLD.reporting_evidence_refs IS DISTINCT FROM NEW.reporting_evidence_refs THEN
        RAISE EXCEPTION 'quality_defect_history_immutable' USING ERRCODE = '55000';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER quality_defect_history_reporting_immutable
BEFORE UPDATE OR DELETE ON quality_defect_status_history
FOR EACH ROW EXECUTE FUNCTION quality_defect_history_reporting_immutable();
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS quality_defect_history_reporting_immutable ON quality_defect_status_history');
        DB::statement('DROP FUNCTION IF EXISTS quality_defect_history_reporting_immutable()');
        Schema::table('quality_defect_status_history', function (Blueprint $table): void {
            $table->dropColumn(['reporting_dimensions', 'reporting_evidence_refs']);
        });
    }
};
