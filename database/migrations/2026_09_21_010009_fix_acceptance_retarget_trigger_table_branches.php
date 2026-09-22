<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION quality_defect_flow_reject_acceptance_retarget()
RETURNS trigger AS $$
BEGIN
    IF TG_TABLE_NAME = 'acceptance_scopes' THEN
        IF (NEW.organization_id IS DISTINCT FROM OLD.organization_id OR NEW.project_id IS DISTINCT FROM OLD.project_id)
           AND EXISTS (SELECT 1 FROM quality_defect_flow_events WHERE acceptance_scope_id = OLD.id) THEN
            RAISE EXCEPTION 'quality defect flow acceptance scope cannot be retargeted' USING ERRCODE = '55000';
        END IF;
    ELSIF TG_TABLE_NAME = 'acceptance_sessions' THEN
        IF (NEW.organization_id IS DISTINCT FROM OLD.organization_id
            OR NEW.project_id IS DISTINCT FROM OLD.project_id
            OR NEW.acceptance_scope_id IS DISTINCT FROM OLD.acceptance_scope_id)
           AND EXISTS (SELECT 1 FROM quality_defect_flow_events WHERE acceptance_session_id = OLD.id) THEN
            RAISE EXCEPTION 'quality defect flow acceptance session cannot be retargeted' USING ERRCODE = '55000';
        END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql
SQL);
    }

    public function down(): void {}
};
