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
        Schema::create('estimate_generation_factory_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('session_id')->unique();
            $table->string('policy_version', 64);
            $table->unsignedInteger('step_count')->default(0);
            $table->timestampsTz();
            $table->unique(['id', 'organization_id', 'project_id', 'session_id'], 'eg_factory_run_scope_uq');
            $table->foreign(['session_id', 'organization_id', 'project_id'], 'eg_factory_run_session_fk')
                ->references(['id', 'organization_id', 'project_id'])->on('estimate_generation_sessions')->cascadeOnDelete();
        });
        Schema::create('estimate_generation_factory_checkpoints', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('run_id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('session_id');
            $table->uuid('request_id');
            $table->unsignedBigInteger('actor_id');
            $table->unsignedInteger('step_number');
            $table->unsignedInteger('expected_state_version');
            $table->char('request_fingerprint', 64);
            $table->char('input_fingerprint', 64);
            $table->string('status', 32);
            $table->uuid('owner_token')->nullable();
            $table->timestampTz('lease_expires_at')->nullable();
            $table->unsignedInteger('attempt_count');
            $table->jsonb('result')->default('{}');
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
            $table->unique(['session_id', 'request_id'], 'eg_factory_request_uq');
            $table->unique(['run_id', 'step_number'], 'eg_factory_step_uq');
            $table->index(['run_id', 'status'], 'eg_factory_status_idx');
            $table->foreign(['run_id', 'organization_id', 'project_id', 'session_id'], 'eg_factory_checkpoint_scope_fk')
                ->references(['id', 'organization_id', 'project_id', 'session_id'])->on('estimate_generation_factory_runs')->cascadeOnDelete();
        });
        DB::statement("ALTER TABLE estimate_generation_factory_runs ADD CONSTRAINT eg_factory_run_bounds_ck CHECK (step_count BETWEEN 0 AND 64 AND policy_version = 'factory-input-inspection:v1')");
        DB::statement("CREATE UNIQUE INDEX eg_factory_one_running_uq ON estimate_generation_factory_checkpoints (run_id) WHERE status = 'running'");
        DB::statement("ALTER TABLE estimate_generation_factory_runs ADD CONSTRAINT eg_factory_run_identifier_ck CHECK (id::text ~ '^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$')");
        DB::statement("ALTER TABLE estimate_generation_factory_checkpoints ADD CONSTRAINT eg_factory_checkpoint_identifier_ck CHECK (id::text ~ '^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$' AND request_id::text ~ '^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$')");
        DB::statement("ALTER TABLE estimate_generation_factory_checkpoints ADD CONSTRAINT eg_factory_bounds_ck CHECK (step_number BETWEEN 1 AND 64 AND attempt_count BETWEEN 1 AND 3 AND expected_state_version >= 0 AND actor_id > 0 AND request_fingerprint ~ '^[a-f0-9]{64}$' AND input_fingerprint ~ '^[a-f0-9]{64}$')");
        DB::statement(<<<'SQL'
            ALTER TABLE estimate_generation_factory_checkpoints ADD CONSTRAINT eg_factory_lifecycle_ck CHECK (
                jsonb_typeof(result) = 'object' AND octet_length(result::text) <= 131072 AND (
                    (status = 'running' AND owner_token IS NOT NULL AND lease_expires_at IS NOT NULL AND finished_at IS NULL AND result = '{}'::jsonb)
                    OR (status IN ('awaiting_input','source_review_required','planning_required','unavailable','stale','exhausted')
                        AND owner_token IS NULL AND lease_expires_at IS NULL AND finished_at IS NOT NULL
                        AND result->>'outcome' = status AND result->>'cost_units' = '0'
                        AND jsonb_exists_all(result, ARRAY['step','outcome','question_refs','total_questions','truncated','limitations','estimate_complete','paid_actions_enabled','cost_units'])
                        AND result - ARRAY['step','outcome','question_refs','total_questions','truncated','limitations','estimate_complete','paid_actions_enabled','cost_units']::text[] = '{}'::jsonb
                        AND result->>'step' = 'inspect_input'
                        AND jsonb_typeof(result->'question_refs') = 'array' AND jsonb_array_length(result->'question_refs') <= 50
                        AND jsonb_typeof(result->'limitations') = 'array' AND jsonb_array_length(result->'limitations') <= 64
                        AND result->'estimate_complete' = 'false'::jsonb AND result->'paid_actions_enabled' = 'false'::jsonb)
                )
            )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_generation_factory_checkpoints');
        Schema::dropIfExists('estimate_generation_factory_runs');
    }
};
