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
        Schema::create('estimate_generation_targeted_rebuild_operations', function (Blueprint $table): void {
            $table->uuid('operation_id')->primary();
            $table->char('idempotency_key', 64);
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('session_id');
            $table->unsignedInteger('expected_state_version');
            $table->char('source_input_version', 71);
            $table->char('root_input_hash', 71);
            $table->char('source_draft_fingerprint', 71);
            $table->string('package_key', 120);
            $table->string('status', 20);
            $table->uuid('lease_token')->nullable();
            $table->timestampTz('lease_expires_at')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->jsonb('result_delta')->default('{}');
            $table->jsonb('safe_arbiter_review')->default('{}');
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->unique(['session_id', 'expected_state_version', 'source_input_version', 'root_input_hash', 'package_key'], 'eg_targeted_rebuild_idempotency_uq');
            $table->index(['status', 'lease_expires_at', 'updated_at'], 'eg_targeted_rebuild_claim_recovery_idx');
            $table->index(['organization_id', 'project_id', 'session_id', 'created_at'], 'eg_targeted_rebuild_tenant_session_idx');
            $table->foreign(['session_id', 'organization_id', 'project_id'], 'eg_targeted_rebuild_session_fk')
                ->references(['id', 'organization_id', 'project_id'])->on('estimate_generation_sessions')->cascadeOnDelete();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE public.estimate_generation_targeted_rebuild_operations ADD CONSTRAINT eg_targeted_rebuild_status_ck CHECK (status IN ('queued','running','reviewed','committed','human_review','stale','cancelled'))");
        DB::statement("ALTER TABLE public.estimate_generation_targeted_rebuild_operations ADD CONSTRAINT eg_targeted_rebuild_identifier_ck CHECK (operation_id::text ~ '^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$' AND operation_id <> '00000000-0000-0000-0000-000000000000'::uuid AND idempotency_key ~ '^[a-f0-9]{64}$' AND source_input_version ~ '^sha256:[a-f0-9]{64}$' AND root_input_hash ~ '^sha256:[a-f0-9]{64}$' AND source_draft_fingerprint ~ '^sha256:[a-f0-9]{64}$' AND package_key ~ '^[A-Za-z0-9:._-]{1,120}$')");
        DB::statement('ALTER TABLE public.estimate_generation_targeted_rebuild_operations ADD CONSTRAINT eg_targeted_rebuild_lease_ck CHECK ((lease_token IS NULL AND lease_expires_at IS NULL) OR (lease_token IS NOT NULL AND lease_expires_at IS NOT NULL))');
        DB::statement("ALTER TABLE public.estimate_generation_targeted_rebuild_operations ADD CONSTRAINT eg_targeted_rebuild_result_ck CHECK (jsonb_typeof(result_delta) = 'object' AND jsonb_typeof(safe_arbiter_review) = 'object' AND (result_delta - ARRAY['target_package','target_before_fingerprint','target_after_fingerprint','non_target_fingerprints']) = '{}'::jsonb AND (safe_arbiter_review - ARRAY['mode','status','outcome','input_hash','schema_version','prompt_version','model','findings','cycle','remediation','input_tokens','output_tokens']) = '{}'::jsonb AND octet_length(result_delta::text) <= 524288 AND octet_length(safe_arbiter_review::text) <= 131072)");
        DB::statement("ALTER TABLE public.estimate_generation_targeted_rebuild_operations ADD CONSTRAINT eg_targeted_rebuild_state_ck CHECK (attempt_count BETWEEN 0 AND 1000 AND ((status IN ('queued','running') AND finished_at IS NULL) OR (status IN ('reviewed','committed','human_review','stale','cancelled') AND finished_at IS NOT NULL)))");
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_generation_targeted_rebuild_operations');
    }
};
