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
        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1
                    FROM budgeting_report_source_closes
                    WHERE formula_version NOT IN ('margin-v1', '1.0.0')
                ) THEN
                    RAISE EXCEPTION 'budgeting_report_source_close_report_backfill_unknown';
                END IF;
            END;
            $$
            SQL);
        Schema::table('budgeting_report_source_closes', function (Blueprint $table): void {
            $table->string('report_code', 64)->nullable()->after('close_id');
        });
        DB::statement('DROP TRIGGER budgeting_report_source_close_immutability_trigger ON budgeting_report_source_closes');
        DB::statement(<<<'SQL'
            UPDATE budgeting_report_source_closes
            SET report_code = CASE
                WHEN formula_version = 'margin-v1' THEN 'project_margin'
                WHEN formula_version = '1.0.0' THEN 'budget_plan_fact'
            END
            WHERE report_code IS NULL
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER budgeting_report_source_close_immutability_trigger
            BEFORE UPDATE OR DELETE ON budgeting_report_source_closes
            FOR EACH ROW EXECUTE FUNCTION budgeting_report_source_close_immutability_guard()
            SQL);
        DB::statement('ALTER TABLE budgeting_report_source_closes ALTER COLUMN report_code SET NOT NULL');
        DB::statement("ALTER TABLE budgeting_report_source_closes
            ADD CONSTRAINT budgeting_report_source_close_report_code_check
            CHECK (report_code ~ '^[a-z][a-z0-9_]{2,63}$')");
        DB::statement('DROP INDEX IF EXISTS budgeting_report_close_active_identity_unique');
        DB::statement("CREATE UNIQUE INDEX budgeting_report_close_active_identity_unique
            ON budgeting_report_source_closes (report_code, organization_id, period_start, period_end, scenario_identity, plan_identity)
            WHERE status = 'approved'");
        Schema::table('budgeting_report_source_closes', function (Blueprint $table): void {
            $table->index(
                ['organization_id', 'report_code', 'formula_version', 'status'],
                'budgeting_report_close_options_idx',
            );
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION budgeting_report_source_close_immutability_guard()
            RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'budgeting_report_source_close_immutable';
                END IF;

                IF OLD.close_id IS DISTINCT FROM NEW.close_id
                    OR OLD.report_code IS DISTINCT FROM NEW.report_code
                    OR OLD.organization_id IS DISTINCT FROM NEW.organization_id
                    OR OLD.period_start IS DISTINCT FROM NEW.period_start
                    OR OLD.period_end IS DISTINCT FROM NEW.period_end
                    OR OLD.scenario_identity IS DISTINCT FROM NEW.scenario_identity
                    OR OLD.plan_identity IS DISTINCT FROM NEW.plan_identity
                    OR OLD.formula_version IS DISTINCT FROM NEW.formula_version
                    OR OLD.source_manifest IS DISTINCT FROM NEW.source_manifest
                    OR OLD.content_hash IS DISTINCT FROM NEW.content_hash
                    OR OLD.approved_by IS DISTINCT FROM NEW.approved_by
                    OR OLD.approved_at IS DISTINCT FROM NEW.approved_at
                    OR OLD.retained_until IS DISTINCT FROM NEW.retained_until
                    OR OLD.restates_close_id IS DISTINCT FROM NEW.restates_close_id THEN
                    RAISE EXCEPTION 'budgeting_report_source_close_immutable';
                END IF;

                IF OLD.status <> 'approved'
                    OR NEW.status NOT IN ('restated', 'expired')
                    OR (NEW.status = 'restated' AND (NEW.restated_by IS NULL OR NEW.restated_at IS NULL OR NEW.restated_by_close_id IS NULL))
                    OR (NEW.status = 'expired' AND (NEW.restated_by IS NOT NULL OR NEW.restated_at IS NOT NULL OR NEW.restated_by_close_id IS NOT NULL)) THEN
                    RAISE EXCEPTION 'budgeting_report_source_close_invalid_transition';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION budgeting_report_source_close_restatement_guard()
            RETURNS trigger AS $$
            BEGIN
                IF NEW.status = 'restated' AND NOT EXISTS (
                    SELECT 1
                    FROM budgeting_report_source_closes replacement
                    WHERE replacement.close_id = NEW.restated_by_close_id
                        AND replacement.status = 'approved'
                        AND replacement.restates_close_id = NEW.close_id
                        AND replacement.report_code = NEW.report_code
                        AND replacement.organization_id = NEW.organization_id
                        AND replacement.period_start = NEW.period_start
                        AND replacement.period_end = NEW.period_end
                        AND replacement.scenario_identity = NEW.scenario_identity
                        AND replacement.plan_identity = NEW.plan_identity
                ) THEN
                    RAISE EXCEPTION 'budgeting_report_source_close_restatement_link_invalid';
                END IF;

                IF NEW.restates_close_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1
                    FROM budgeting_report_source_closes prior_close
                    WHERE prior_close.close_id = NEW.restates_close_id
                        AND prior_close.status = 'restated'
                        AND prior_close.restated_by_close_id = NEW.close_id
                        AND prior_close.report_code = NEW.report_code
                        AND prior_close.organization_id = NEW.organization_id
                        AND prior_close.period_start = NEW.period_start
                        AND prior_close.period_end = NEW.period_end
                        AND prior_close.scenario_identity = NEW.scenario_identity
                        AND prior_close.plan_identity = NEW.plan_identity
                ) THEN
                    RAISE EXCEPTION 'budgeting_report_source_close_restatement_link_invalid';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1
                    FROM budgeting_report_source_closes
                    WHERE status = 'approved'
                    GROUP BY organization_id, period_start, period_end, scenario_identity, plan_identity
                    HAVING COUNT(*) > 1
                ) THEN
                    RAISE EXCEPTION 'budgeting_report_source_close_report_rollback_conflict';
                END IF;
            END;
            $$
            SQL);
        DB::statement('DROP TRIGGER budgeting_report_source_close_immutability_trigger ON budgeting_report_source_closes');
        DB::statement('DROP TRIGGER budgeting_report_source_close_restatement_trigger ON budgeting_report_source_closes');
        DB::statement('DROP FUNCTION budgeting_report_source_close_immutability_guard()');
        DB::statement('DROP FUNCTION budgeting_report_source_close_restatement_guard()');
        DB::statement('DROP INDEX IF EXISTS budgeting_report_close_active_identity_unique');
        Schema::table('budgeting_report_source_closes', function (Blueprint $table): void {
            $table->dropIndex('budgeting_report_close_options_idx');
        });
        Schema::table('budgeting_report_source_closes', function (Blueprint $table): void {
            $table->dropColumn('report_code');
        });
        DB::statement("CREATE UNIQUE INDEX budgeting_report_close_active_identity_unique
            ON budgeting_report_source_closes (organization_id, period_start, period_end, scenario_identity, plan_identity)
            WHERE status = 'approved'");
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION budgeting_report_source_close_immutability_guard()
            RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'budgeting_report_source_close_immutable';
                END IF;

                IF OLD.close_id IS DISTINCT FROM NEW.close_id
                    OR OLD.organization_id IS DISTINCT FROM NEW.organization_id
                    OR OLD.period_start IS DISTINCT FROM NEW.period_start
                    OR OLD.period_end IS DISTINCT FROM NEW.period_end
                    OR OLD.scenario_identity IS DISTINCT FROM NEW.scenario_identity
                    OR OLD.plan_identity IS DISTINCT FROM NEW.plan_identity
                    OR OLD.formula_version IS DISTINCT FROM NEW.formula_version
                    OR OLD.source_manifest IS DISTINCT FROM NEW.source_manifest
                    OR OLD.content_hash IS DISTINCT FROM NEW.content_hash
                    OR OLD.approved_by IS DISTINCT FROM NEW.approved_by
                    OR OLD.approved_at IS DISTINCT FROM NEW.approved_at
                    OR OLD.retained_until IS DISTINCT FROM NEW.retained_until
                    OR OLD.restates_close_id IS DISTINCT FROM NEW.restates_close_id THEN
                    RAISE EXCEPTION 'budgeting_report_source_close_immutable';
                END IF;

                IF OLD.status <> 'approved'
                    OR NEW.status NOT IN ('restated', 'expired')
                    OR (NEW.status = 'restated' AND (NEW.restated_by IS NULL OR NEW.restated_at IS NULL OR NEW.restated_by_close_id IS NULL))
                    OR (NEW.status = 'expired' AND (NEW.restated_by IS NOT NULL OR NEW.restated_at IS NOT NULL OR NEW.restated_by_close_id IS NOT NULL)) THEN
                    RAISE EXCEPTION 'budgeting_report_source_close_invalid_transition';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER budgeting_report_source_close_immutability_trigger
            BEFORE UPDATE OR DELETE ON budgeting_report_source_closes
            FOR EACH ROW EXECUTE FUNCTION budgeting_report_source_close_immutability_guard();

            CREATE FUNCTION budgeting_report_source_close_restatement_guard()
            RETURNS trigger AS $$
            BEGIN
                IF NEW.status = 'restated' AND NOT EXISTS (
                    SELECT 1
                    FROM budgeting_report_source_closes replacement
                    WHERE replacement.close_id = NEW.restated_by_close_id
                        AND replacement.status = 'approved'
                        AND replacement.restates_close_id = NEW.close_id
                        AND replacement.organization_id = NEW.organization_id
                        AND replacement.period_start = NEW.period_start
                        AND replacement.period_end = NEW.period_end
                        AND replacement.scenario_identity = NEW.scenario_identity
                        AND replacement.plan_identity = NEW.plan_identity
                ) THEN
                    RAISE EXCEPTION 'budgeting_report_source_close_restatement_link_invalid';
                END IF;

                IF NEW.restates_close_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1
                    FROM budgeting_report_source_closes prior_close
                    WHERE prior_close.close_id = NEW.restates_close_id
                        AND prior_close.status = 'restated'
                        AND prior_close.restated_by_close_id = NEW.close_id
                        AND prior_close.organization_id = NEW.organization_id
                        AND prior_close.period_start = NEW.period_start
                        AND prior_close.period_end = NEW.period_end
                        AND prior_close.scenario_identity = NEW.scenario_identity
                        AND prior_close.plan_identity = NEW.plan_identity
                ) THEN
                    RAISE EXCEPTION 'budgeting_report_source_close_restatement_link_invalid';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER budgeting_report_source_close_restatement_trigger
            AFTER INSERT OR UPDATE ON budgeting_report_source_closes
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION budgeting_report_source_close_restatement_guard();
            SQL);
    }
};
