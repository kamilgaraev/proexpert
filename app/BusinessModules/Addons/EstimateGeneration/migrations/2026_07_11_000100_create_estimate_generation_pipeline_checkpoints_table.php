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
        Schema::create('estimate_generation_pipeline_checkpoints', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('session_id')->constrained('estimate_generation_sessions')->cascadeOnDelete();
            $table->string('stage', 80);
            $table->string('input_version', 80);
            $table->string('output_version', 80)->nullable();
            $table->jsonb('output_payload')->nullable();
            $table->string('status', 30);
            $table->jsonb('metrics')->default('{}');
            $table->jsonb('warnings')->default('[]');
            $table->unsignedInteger('attempt_count')->default(1);
            $table->uuid('claim_token')->nullable();
            $table->timestampTz('lease_expires_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->string('last_error_code', 160)->nullable();
            $table->text('last_error_message')->nullable();
            $table->char('last_error_fingerprint', 64)->nullable();
            $table->timestampsTz();

            $table->unique(
                ['session_id', 'stage', 'input_version'],
                'estimate_generation_checkpoint_unique',
            );
            $table->index(['session_id', 'status'], 'estimate_generation_checkpoint_session_status');
            $table->index(['status', 'lease_expires_at'], 'estimate_generation_checkpoint_status_lease');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE estimate_generation_pipeline_checkpoints
            ADD CONSTRAINT ai_ckpt_status_ck
            CHECK (status IN ('running', 'completed', 'failed'))
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
                    AND output_payload IS NULL
                    AND last_error_code IS NULL AND last_error_message IS NULL AND last_error_fingerprint IS NULL)
                OR (status = 'completed'
                    AND started_at IS NOT NULL AND output_version IS NOT NULL AND completed_at IS NOT NULL
                    AND output_payload IS NOT NULL
                    AND claim_token IS NULL AND lease_expires_at IS NULL AND failed_at IS NULL
                    AND last_error_code IS NULL AND last_error_message IS NULL AND last_error_fingerprint IS NULL)
                OR (status = 'failed'
                    AND started_at IS NOT NULL AND failed_at IS NOT NULL
                    AND last_error_code = 'pipeline_stage_failed'
                    AND last_error_message IS NULL
                    AND last_error_fingerprint ~ '^[0-9a-f]{64}$'
                    AND claim_token IS NULL AND lease_expires_at IS NULL
                    AND output_version IS NULL AND completed_at IS NULL
                    AND output_payload IS NULL)
            )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_generation_pipeline_checkpoints');
    }
};
