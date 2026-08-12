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
        Schema::create('estimate_interpretation_attempts', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('session_id');
            $table->string('idempotency_key', 128);
            $table->char('request_fingerprint', 64);
            $table->string('state', 24);
            $table->uuid('owner_uuid')->nullable();
            $table->timestampTz('lease_expires_at')->nullable();
            $table->timestampTz('wire_started_at')->nullable();
            $table->timestampTz('response_received_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->jsonb('interpretation_payload')->nullable();
            $table->jsonb('result_payload')->nullable();
            $table->string('failure_code', 80)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->unique(
                ['organization_id', 'project_id', 'session_id', 'idempotency_key'],
                'estimate_interpretation_attempt_scope_key_unique',
            );
            $table->index(['state', 'lease_expires_at'], 'estimate_interpretation_attempt_lease_idx');
        });

        Schema::table('estimate_change_proposals', function (Blueprint $table): void {
            $table->string('cost_state', 16)->default('unknown');
            $table->jsonb('cost_blockers')->default('[]');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE estimate_interpretation_attempts ADD CONSTRAINT estimate_interpretation_attempt_state_check CHECK (state IN ('pre_wire','wire_started','response_received','completed','failed','ambiguous'))");
            DB::statement("ALTER TABLE estimate_change_proposals ADD CONSTRAINT estimate_change_proposals_cost_state_check CHECK (cost_state IN ('known','unknown','partial'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE estimate_change_proposals DROP CONSTRAINT IF EXISTS estimate_change_proposals_cost_state_check');
        }
        Schema::table('estimate_change_proposals', function (Blueprint $table): void {
            $table->dropColumn(['cost_state', 'cost_blockers']);
        });
        Schema::dropIfExists('estimate_interpretation_attempts');
    }
};
