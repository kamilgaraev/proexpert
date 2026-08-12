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
        Schema::create('estimate_change_proposals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('actor_id');
            $table->string('idempotency_key', 128);
            $table->char('payload_fingerprint', 64);
            $table->string('intent', 64);
            $table->string('interpretation_version', 80);
            $table->string('command_excerpt', 1000);
            $table->jsonb('before_payload');
            $table->jsonb('after_payload');
            $table->jsonb('affected_payload');
            $table->jsonb('dependency_keys');
            $table->jsonb('assumptions');
            $table->jsonb('questions');
            $table->jsonb('evidence');
            $table->jsonb('version_fence');
            $table->boolean('cost_delta_known');
            $table->decimal('cost_delta', 20, 4)->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['organization_id', 'session_id', 'idempotency_key'], 'estimate_change_proposals_idempotency_unique');
            $table->index(['organization_id', 'project_id', 'session_id', 'created_at'], 'estimate_change_proposals_scope_created_idx');
        });

        Schema::create('estimate_change_proposal_states', function (Blueprint $table): void {
            $table->uuid('proposal_id')->primary();
            $table->string('status', 24)->default('proposed');
            $table->unsignedInteger('version')->default(1);
            $table->jsonb('result')->nullable();
            $table->string('failure_code', 80)->nullable();
            $table->unsignedBigInteger('terminal_actor_id')->nullable();
            $table->timestampTz('applied_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('updated_at')->useCurrent();
            $table->foreign('proposal_id')->references('id')->on('estimate_change_proposals')->restrictOnDelete();
            $table->index(['status', 'updated_at'], 'estimate_change_proposal_states_status_idx');
        });

        Schema::create('estimate_change_proposal_transitions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('proposal_id');
            $table->unsignedBigInteger('actor_id');
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->jsonb('metadata');
            $table->timestampTz('created_at')->useCurrent();
            $table->foreign('proposal_id')->references('id')->on('estimate_change_proposals')->restrictOnDelete();
            $table->index(['proposal_id', 'id'], 'estimate_change_proposal_transitions_history_idx');
        });

        Schema::create('estimate_change_proposal_items', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('proposal_id');
            $table->string('stable_key', 255);
            $table->string('kind', 64);
            $table->jsonb('before_payload')->nullable();
            $table->jsonb('after_payload')->nullable();
            $table->jsonb('locator')->nullable();
            $table->foreign('proposal_id')->references('id')->on('estimate_change_proposals')->restrictOnDelete();
            $table->unique(['proposal_id', 'stable_key'], 'estimate_change_proposal_items_stable_unique');
            $table->index(['proposal_id', 'id'], 'estimate_change_proposal_items_page_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE estimate_change_proposal_states ADD CONSTRAINT estimate_change_proposal_status_check CHECK (status IN ('proposed','applying','applied','cancelled','expired','stale','failed'))");
            DB::statement('ALTER TABLE estimate_change_proposals ADD CONSTRAINT estimate_change_proposals_cost_check CHECK ((cost_delta_known AND cost_delta IS NOT NULL) OR (NOT cost_delta_known AND cost_delta IS NULL))');
            DB::unprepared(<<<'SQL'
                CREATE FUNCTION prevent_estimate_change_proposal_mutation() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'immutable estimate change proposal history';
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER estimate_change_proposals_immutable BEFORE UPDATE OR DELETE ON estimate_change_proposals FOR EACH ROW EXECUTE FUNCTION prevent_estimate_change_proposal_mutation();
                CREATE TRIGGER estimate_change_proposal_transitions_immutable BEFORE UPDATE OR DELETE ON estimate_change_proposal_transitions FOR EACH ROW EXECUTE FUNCTION prevent_estimate_change_proposal_mutation();
                CREATE TRIGGER estimate_change_proposal_items_immutable BEFORE UPDATE OR DELETE ON estimate_change_proposal_items FOR EACH ROW EXECUTE FUNCTION prevent_estimate_change_proposal_mutation();
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS prevent_estimate_change_proposal_mutation() CASCADE');
        }
        Schema::dropIfExists('estimate_change_proposal_items');
        Schema::dropIfExists('estimate_change_proposal_transitions');
        Schema::dropIfExists('estimate_change_proposal_states');
        Schema::dropIfExists('estimate_change_proposals');
    }
};
