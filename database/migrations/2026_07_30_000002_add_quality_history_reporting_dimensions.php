<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
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
    IF OLD.reporting_dimensions IS DISTINCT FROM NEW.reporting_dimensions
        OR OLD.reporting_evidence_refs IS DISTINCT FROM NEW.reporting_evidence_refs THEN
        RAISE EXCEPTION 'quality_defect_history_reporting_immutable' USING ERRCODE = '55000';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER quality_defect_history_reporting_immutable
BEFORE UPDATE ON quality_defect_status_history
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
