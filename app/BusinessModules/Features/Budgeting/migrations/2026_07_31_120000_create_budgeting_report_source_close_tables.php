<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('budgeting_report_source_closes', function (Blueprint $table): void {
            $table->id();
            $table->char('close_id', 26)->unique();
            $table->foreignId('organization_id')->index();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('scenario_identity', 128);
            $table->string('plan_identity', 128);
            $table->string('formula_version', 64);
            $table->jsonb('source_manifest');
            $table->char('content_hash', 64);
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at');
            $table->timestampTz('retained_until');
            $table->string('status', 16)->default('approved');
            $table->char('restates_close_id', 26)->nullable();
            $table->foreignId('restated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('restated_at')->nullable();
            $table->char('restated_by_close_id', 26)->nullable();
            $table->timestampsTz();

            $table->index(['organization_id', 'period_start', 'period_end'], 'budgeting_report_close_period_idx');
            $table->index(['organization_id', 'scenario_identity', 'plan_identity'], 'budgeting_report_close_identity_idx');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE budgeting_report_source_closes
                ADD CONSTRAINT budgeting_report_source_close_status_check
                CHECK (status IN ('approved', 'restated', 'expired')),
                ADD CONSTRAINT budgeting_report_source_close_retention_check
                CHECK (retained_until > approved_at),
                ADD CONSTRAINT budgeting_report_source_close_restatement_state_check
                CHECK (
                    (status = 'approved' AND restated_by IS NULL AND restated_at IS NULL AND restated_by_close_id IS NULL)
                    OR (status = 'restated' AND restated_by IS NOT NULL AND restated_at IS NOT NULL AND restated_by_close_id IS NOT NULL)
                    OR (status = 'expired' AND restated_by IS NULL AND restated_at IS NULL AND restated_by_close_id IS NULL)
                ),
                ADD CONSTRAINT budgeting_report_source_close_restates_fk
                FOREIGN KEY (restates_close_id) REFERENCES budgeting_report_source_closes(close_id)
                DEFERRABLE INITIALLY DEFERRED,
                ADD CONSTRAINT budgeting_report_source_close_restated_by_fk
                FOREIGN KEY (restated_by_close_id) REFERENCES budgeting_report_source_closes(close_id)
                DEFERRABLE INITIALLY DEFERRED;
            SQL);

        Schema::create('budgeting_report_source_watermarks', function (Blueprint $table): void {
            $table->id();
            $table->char('close_id', 26);
            $table->string('source', 128);
            $table->timestampTz('cutoff_at');
            $table->text('watermark');
            $table->string('source_schema_version', 64);
            $table->timestampsTz();

            $table->foreign('close_id')->references('close_id')->on('budgeting_report_source_closes')->restrictOnDelete();
            $table->unique(['close_id', 'source'], 'budgeting_report_close_watermark_unique');
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

            CREATE FUNCTION budgeting_report_source_watermark_immutability_guard()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'budgeting_report_source_watermark_immutable';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER budgeting_report_source_watermark_immutability_trigger
            BEFORE UPDATE OR DELETE ON budgeting_report_source_watermarks
            FOR EACH ROW EXECUTE FUNCTION budgeting_report_source_watermark_immutability_guard();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('budgeting_report_source_watermarks');
        Schema::dropIfExists('budgeting_report_source_closes');

        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS budgeting_report_source_watermark_immutability_guard();
            DROP FUNCTION IF EXISTS budgeting_report_source_close_restatement_guard();
            DROP FUNCTION IF EXISTS budgeting_report_source_close_immutability_guard();
            SQL);
    }
};
