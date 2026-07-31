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
            $table->timestampTz('retained_until')->nullable();
            $table->string('status', 16)->default('approved');
            $table->char('restates_close_id', 26)->nullable();
            $table->foreignId('restated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('restated_at')->nullable();
            $table->char('restated_by_close_id', 26)->nullable();
            $table->timestampsTz();

            $table->index(['organization_id', 'period_start', 'period_end'], 'budgeting_report_close_period_idx');
            $table->index(['organization_id', 'scenario_identity', 'plan_identity'], 'budgeting_report_close_identity_idx');
        });

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
            DROP FUNCTION IF EXISTS budgeting_report_source_close_immutability_guard();
            SQL);
    }
};
