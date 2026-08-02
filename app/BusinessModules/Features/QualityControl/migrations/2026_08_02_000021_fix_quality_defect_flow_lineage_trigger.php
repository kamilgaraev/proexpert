<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION quality_defect_flow_reject_lineage_retarget()
RETURNS trigger AS $$
DECLARE
    new_row jsonb := to_jsonb(NEW);
    old_row jsonb := to_jsonb(OLD);
BEGIN
    IF TG_TABLE_NAME = 'quality_defects' THEN
        IF new_row->>'organization_id' <> old_row->>'organization_id'
           AND (
                EXISTS (SELECT 1 FROM quality_defect_flow_events WHERE quality_defect_id = OLD.id)
                OR EXISTS (SELECT 1 FROM quality_defect_flow_gaps WHERE quality_defect_id = OLD.id)
           ) THEN
            RAISE EXCEPTION 'quality defect flow defect organization cannot be retargeted' USING ERRCODE = '55000';
        END IF;

        IF new_row->>'project_id' <> old_row->>'project_id'
           AND EXISTS (SELECT 1 FROM quality_defect_flow_events WHERE quality_defect_id = OLD.id) THEN
            RAISE EXCEPTION 'quality defect flow defect project cannot be retargeted' USING ERRCODE = '55000';
        END IF;
    ELSIF TG_TABLE_NAME = 'projects'
       AND new_row->>'organization_id' <> old_row->>'organization_id'
       AND EXISTS (SELECT 1 FROM quality_defect_flow_events WHERE project_id = OLD.id) THEN
        RAISE EXCEPTION 'quality defect flow project organization cannot be retargeted' USING ERRCODE = '55000';
    ELSIF TG_TABLE_NAME = 'contractors'
       AND new_row->>'organization_id' <> old_row->>'organization_id'
       AND EXISTS (SELECT 1 FROM quality_defect_flow_events WHERE contractor_id = OLD.id) THEN
        RAISE EXCEPTION 'quality defect flow contractor organization cannot be retargeted' USING ERRCODE = '55000';
    ELSIF TG_TABLE_NAME = 'schedule_tasks'
       AND (
            new_row->>'organization_id' <> old_row->>'organization_id'
            OR new_row->>'schedule_id' <> old_row->>'schedule_id'
       )
       AND EXISTS (SELECT 1 FROM quality_defect_flow_events WHERE schedule_task_id = OLD.id) THEN
        RAISE EXCEPTION 'quality defect flow schedule task cannot be retargeted' USING ERRCODE = '55000';
    ELSIF TG_TABLE_NAME = 'project_schedules'
       AND (
            new_row->>'organization_id' <> old_row->>'organization_id'
            OR new_row->>'project_id' <> old_row->>'project_id'
       )
       AND EXISTS (
            SELECT 1
            FROM quality_defect_flow_events
            INNER JOIN schedule_tasks ON schedule_tasks.id = quality_defect_flow_events.schedule_task_id
            WHERE schedule_tasks.schedule_id = OLD.id
       ) THEN
        RAISE EXCEPTION 'quality defect flow project schedule cannot be retargeted' USING ERRCODE = '55000';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql
SQL);
    }

    public function down(): void
    {
    }
};
