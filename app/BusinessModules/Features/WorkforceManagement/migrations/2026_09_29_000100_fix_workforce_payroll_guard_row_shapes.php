<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION workforce_payroll_guard_immutable() RETURNS trigger AS $$
DECLARE parent_status text;
BEGIN
    IF TG_TABLE_NAME IN (
        'workforce_payroll_calculation_transitions',
        'payroll_readiness_snapshot_rows'
    ) OR (
        TG_TABLE_NAME = 'workforce_payroll_calculation_source_rows'
        AND TG_OP <> 'INSERT'
    ) THEN
        RAISE EXCEPTION 'immutable payroll record';
    END IF;

    IF TG_TABLE_NAME = 'workforce_payroll_calculation_issues' THEN
        SELECT status INTO parent_status
          FROM workforce_payroll_calculation_versions
         WHERE id = CASE WHEN TG_OP = 'INSERT' THEN NEW.calculation_version_id ELSE OLD.calculation_version_id END
           AND organization_id = CASE WHEN TG_OP = 'INSERT' THEN NEW.organization_id ELSE OLD.organization_id END
         FOR UPDATE;
        IF parent_status IN ('validated', 'locked') THEN
            RAISE EXCEPTION 'immutable payroll validation';
        END IF;
    END IF;

    IF TG_TABLE_NAME = 'workforce_payroll_calculation_source_rows' THEN
        IF TG_OP = 'INSERT' THEN
            SELECT status INTO parent_status
              FROM workforce_payroll_calculation_versions
             WHERE id = NEW.calculation_version_id
               AND organization_id = NEW.organization_id
             FOR UPDATE;
            IF parent_status IN ('validated', 'locked') THEN
                RAISE EXCEPTION 'immutable payroll calculation source';
            END IF;
        END IF;
    END IF;

    IF TG_TABLE_NAME = 'workforce_payroll_calculation_versions' THEN
        IF OLD.status = 'locked' THEN
            RAISE EXCEPTION 'immutable locked payroll calculation';
        END IF;
    END IF;

    IF TG_TABLE_NAME = 'workforce_payroll_source_rows' THEN
        IF EXISTS (
            SELECT 1
              FROM workforce_payroll_periods
             WHERE id = OLD.payroll_period_id
               AND organization_id = OLD.organization_id
               AND status = 'locked'
        ) THEN
            RAISE EXCEPTION 'immutable locked payroll source';
        END IF;
    END IF;

    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);
    }

    public function down(): void
    {
    }
};
