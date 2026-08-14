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
        Schema::create('estimate_generation_ai_role_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('document_id')->nullable();
            $table->unsignedBigInteger('page_id')->nullable();
            $table->string('subject_type', 48);
            $table->string('subject_id', 160);
            $table->string('subject_version', 160);
            $table->string('role', 40);
            $table->string('status', 24);
            $table->string('model', 160);
            $table->string('prompt_contract_version', 160);
            $table->char('input_fingerprint', 64);
            $table->char('identity_fingerprint', 64)->unique('estimate_ai_role_runs_identity_unique');
            $table->uuid('physical_attempt_id')->nullable();
            $table->jsonb('result_payload')->nullable();
            $table->string('failure_code', 120)->nullable();
            $table->uuid('owner_uuid')->nullable();
            $table->timestampTz('lease_expires_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampsTz();
            $table->index(
                ['organization_id', 'project_id', 'session_id', 'subject_type', 'subject_id', 'role'],
                'estimate_ai_role_runs_subject_idx',
            );
            $table->index(['status', 'lease_expires_at'], 'estimate_ai_role_runs_status_lease_idx');
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('session_id')->references('id')->on('estimate_generation_sessions')->cascadeOnDelete();
            $table->foreign('document_id')->references('id')->on('estimate_generation_documents')->nullOnDelete();
            $table->foreign('page_id')->references('id')->on('estimate_generation_document_pages')->nullOnDelete();
            $table->foreign('physical_attempt_id')->references('attempt_id')
                ->on('estimate_generation_vision_physical_attempts')->restrictOnDelete();
        });
        DB::statement(<<<'SQL'
            ALTER TABLE estimate_generation_ai_role_runs
            ADD CONSTRAINT estimate_generation_ai_role_runs_status_check
            CHECK (status IN ('running', 'completed', 'failed', 'ambiguous')),
            ADD CONSTRAINT estimate_generation_ai_role_runs_result_size_check
            CHECK (result_payload IS NULL OR octet_length(result_payload::text) <= 262144),
            ADD CONSTRAINT estimate_generation_ai_role_runs_terminal_shape_check
            CHECK (
                (status = 'running' AND owner_uuid IS NOT NULL AND lease_expires_at IS NOT NULL
                    AND completed_at IS NULL AND failed_at IS NULL)
                OR (status = 'completed' AND owner_uuid IS NULL AND lease_expires_at IS NULL
                    AND result_payload IS NOT NULL AND failure_code IS NULL AND completed_at IS NOT NULL)
                OR (status IN ('failed', 'ambiguous') AND owner_uuid IS NULL AND lease_expires_at IS NULL
                    AND failure_code IS NOT NULL AND failed_at IS NOT NULL)
            )
            SQL);
    }

    public function down(): void {}
};
