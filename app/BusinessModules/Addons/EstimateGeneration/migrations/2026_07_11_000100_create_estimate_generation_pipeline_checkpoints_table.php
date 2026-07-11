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
        Schema::table('estimate_generation_sessions', fn (Blueprint $table) => $table->unique(['id', 'organization_id', 'project_id'], 'eg_sessions_scope_uq'));
        Schema::create('estimate_generation_pipeline_checkpoints', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('session_id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->uuid('generation_attempt_id');
            $table->char('base_input_version', 71);
            $table->string('stage', 80);
            $table->char('input_version', 71);
            $table->jsonb('dependency_versions')->default('{}');
            $table->char('output_version', 71)->nullable();
            $table->jsonb('output_payload')->nullable();
            $table->unsignedInteger('artifact_bytes')->nullable();
            $table->string('status', 30);
            $table->jsonb('metrics')->default('{}');
            $table->jsonb('warnings')->default('[]');
            $table->unsignedInteger('attempt_count')->default(1);
            $table->uuid('claim_token')->nullable();
            $table->timestampTz('lease_expires_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('invalidated_at')->nullable();
            $table->string('invalidation_reason', 80)->nullable();
            $table->string('last_error_code', 160)->nullable();
            $table->text('last_error_message')->nullable();
            $table->char('last_error_fingerprint', 64)->nullable();
            $table->timestampsTz();

            $table->unique(
                ['session_id', 'generation_attempt_id', 'stage', 'input_version'],
                'estimate_generation_checkpoint_unique',
            );
            $table->foreign(['session_id', 'organization_id', 'project_id'], 'eg_checkpoint_session_scope_fk')
                ->references(['id', 'organization_id', 'project_id'])->on('estimate_generation_sessions')->cascadeOnDelete();
            $table->index(['session_id', 'generation_attempt_id', 'status'], 'estimate_generation_checkpoint_session_status');
            $table->index(['status', 'lease_expires_at'], 'estimate_generation_checkpoint_status_lease');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE estimate_generation_pipeline_checkpoints
            ADD CONSTRAINT ai_ckpt_status_ck
            CHECK (status IN ('running', 'completed', 'failed', 'invalidated'))
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE estimate_generation_pipeline_checkpoints
            ADD CONSTRAINT ai_ckpt_attempt_ck CHECK (attempt_count >= 1)
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE estimate_generation_pipeline_checkpoints
            ADD CONSTRAINT ai_ckpt_stage_ck CHECK (stage IN (
                'understand_documents', 'understand_object', 'extract_quantities',
                'plan_work_items', 'match_normatives', 'assemble_resources',
                'resolve_prices', 'build_draft', 'validate_draft'
            ))
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE estimate_generation_pipeline_checkpoints
            ADD CONSTRAINT ai_ckpt_json_ck CHECK (
                jsonb_typeof(metrics) = 'object'
                AND jsonb_typeof(warnings) = 'array'
                AND jsonb_typeof(dependency_versions) = 'object'
                AND (output_payload IS NULL OR (
                    jsonb_typeof(output_payload) = 'object'
                    AND pg_column_size(output_payload) <= 4096
                ))
            )
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE estimate_generation_pipeline_checkpoints
            ADD CONSTRAINT ai_ckpt_state_ck CHECK (
                (status = 'running'
                    AND claim_token IS NOT NULL AND lease_expires_at IS NOT NULL AND started_at IS NOT NULL
                    AND output_version IS NULL AND completed_at IS NULL AND failed_at IS NULL
                    AND output_payload IS NULL AND artifact_bytes IS NULL
                    AND invalidated_at IS NULL AND invalidation_reason IS NULL
                    AND last_error_code IS NULL AND last_error_message IS NULL AND last_error_fingerprint IS NULL)
                OR (status = 'completed'
                    AND started_at IS NOT NULL AND output_version IS NOT NULL AND completed_at IS NOT NULL
                    AND output_payload IS NOT NULL AND artifact_bytes BETWEEN 1 AND 8388608
                    AND invalidated_at IS NULL AND invalidation_reason IS NULL
                    AND claim_token IS NULL AND lease_expires_at IS NULL AND failed_at IS NULL
                    AND last_error_code IS NULL AND last_error_message IS NULL AND last_error_fingerprint IS NULL)
                OR (status = 'failed'
                    AND started_at IS NOT NULL AND failed_at IS NOT NULL
                    AND last_error_code = 'pipeline_stage_failed'
                    AND last_error_message IS NULL
                    AND last_error_fingerprint ~ '^[0-9a-f]{64}$'
                    AND claim_token IS NULL AND lease_expires_at IS NULL
                    AND output_version IS NULL AND completed_at IS NULL
                    AND output_payload IS NULL AND artifact_bytes IS NULL
                    AND invalidated_at IS NULL AND invalidation_reason IS NULL)
                OR (status = 'invalidated'
                    AND started_at IS NOT NULL AND output_version IS NOT NULL AND completed_at IS NOT NULL
                    AND output_payload IS NOT NULL AND artifact_bytes BETWEEN 1 AND 8388608
                    AND claim_token IS NULL AND lease_expires_at IS NULL AND failed_at IS NULL
                    AND invalidated_at IS NOT NULL AND invalidation_reason = 'dependency_changed'
                    AND last_error_code IS NULL AND last_error_message IS NULL AND last_error_fingerprint IS NULL)
            )
            SQL);
        DB::statement("ALTER TABLE estimate_generation_pipeline_checkpoints ADD CONSTRAINT ai_ckpt_version_ck CHECK (base_input_version ~ '^sha256:[0-9a-f]{64}$' AND input_version ~ '^sha256:[0-9a-f]{64}$' AND (output_version IS NULL OR output_version ~ '^sha256:[0-9a-f]{64}$'))");
        DB::statement(<<<'SQL'
            CREATE FUNCTION eg_checkpoint_immutable_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.status IN ('completed','invalidated') THEN
                    IF OLD.status = 'completed' AND NEW.status = 'invalidated'
                        AND (to_jsonb(OLD) - ARRAY['status','invalidated_at','invalidation_reason','updated_at'])
                          IS NOT DISTINCT FROM
                            (to_jsonb(NEW) - ARRAY['status','invalidated_at','invalidation_reason','updated_at'])
                        AND NEW.invalidated_at IS NOT NULL AND NEW.invalidation_reason = 'dependency_changed' THEN
                        RETURN NEW;
                    END IF;
                    RAISE EXCEPTION 'estimate_generation.checkpoint_is_immutable';
                END IF;
                RETURN NEW;
            END; $$
            SQL);
        DB::statement('CREATE TRIGGER eg_checkpoint_immutable_update BEFORE UPDATE ON estimate_generation_pipeline_checkpoints FOR EACH ROW EXECUTE FUNCTION eg_checkpoint_immutable_guard()');
        DB::statement(<<<'SQL'
            CREATE FUNCTION eg_checkpoint_delete_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF pg_trigger_depth() <= 1 THEN RAISE EXCEPTION 'estimate_generation.checkpoint_delete_forbidden'; END IF;
                RETURN OLD;
            END; $$
            SQL);
        DB::statement('CREATE TRIGGER eg_checkpoint_delete BEFORE DELETE ON estimate_generation_pipeline_checkpoints FOR EACH ROW EXECUTE FUNCTION eg_checkpoint_delete_guard()');
        DB::statement(<<<'SQL'
            CREATE FUNCTION eg_checkpoint_aggregate_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE total_bytes bigint;
            BEGIN
                IF NEW.status = 'completed' THEN
                    PERFORM 1 FROM estimate_generation_sessions
                    WHERE id = NEW.session_id
                      AND organization_id = NEW.organization_id
                      AND project_id = NEW.project_id
                    FOR UPDATE;
                    SELECT COALESCE(SUM(artifact_bytes),0) INTO total_bytes
                    FROM estimate_generation_pipeline_checkpoints
                    WHERE session_id = NEW.session_id
                      AND organization_id = NEW.organization_id
                      AND project_id = NEW.project_id
                      AND generation_attempt_id = NEW.generation_attempt_id
                      AND status = 'completed' AND id <> NEW.id;
                    IF total_bytes + NEW.artifact_bytes > 8388608 THEN
                        RAISE EXCEPTION 'estimate_generation.pipeline_artifact_budget_exceeded';
                    END IF;
                END IF;
                RETURN NEW;
            END; $$
            SQL);
        DB::statement('CREATE TRIGGER eg_checkpoint_aggregate BEFORE INSERT OR UPDATE ON estimate_generation_pipeline_checkpoints FOR EACH ROW EXECUTE FUNCTION eg_checkpoint_aggregate_guard()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS eg_checkpoint_aggregate ON estimate_generation_pipeline_checkpoints');
        DB::statement('DROP FUNCTION IF EXISTS eg_checkpoint_aggregate_guard()');
        DB::statement('DROP TRIGGER IF EXISTS eg_checkpoint_delete ON estimate_generation_pipeline_checkpoints');
        DB::statement('DROP FUNCTION IF EXISTS eg_checkpoint_delete_guard()');
        DB::statement('DROP TRIGGER IF EXISTS eg_checkpoint_immutable_update ON estimate_generation_pipeline_checkpoints');
        DB::statement('DROP FUNCTION IF EXISTS eg_checkpoint_immutable_guard()');
        Schema::dropIfExists('estimate_generation_pipeline_checkpoints');
        Schema::table('estimate_generation_sessions', fn (Blueprint $table) => $table->dropUnique('eg_sessions_scope_uq'));
    }
};
