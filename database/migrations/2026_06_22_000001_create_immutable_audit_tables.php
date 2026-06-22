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
        Schema::create('immutable_audit_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('sequence_id')->unique();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->restrictOnDelete();
            $table->string('domain', 64);
            $table->string('event_type', 160);
            $table->string('action', 64);
            $table->string('result', 64)->default('success');
            $table->string('severity', 64)->default('info');
            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');
            $table->string('actor_type', 64)->default('system');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->jsonb('actor_snapshot')->nullable()->default('{}');
            $table->foreignId('impersonator_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('source', 120);
            $table->string('source_route', 255)->nullable();
            $table->string('source_model', 160)->nullable();
            $table->string('source_table', 120)->nullable();
            $table->string('source_event_id', 191)->nullable();
            $table->string('correlation_id', 120)->nullable();
            $table->string('idempotency_key', 191)->nullable();
            $table->string('subject_type', 160)->nullable();
            $table->string('subject_id', 120)->nullable();
            $table->string('subject_label', 255)->nullable();
            $table->jsonb('related_subjects')->nullable()->default('[]');
            $table->text('reason')->nullable();
            $table->jsonb('before_state')->nullable()->default('{}');
            $table->jsonb('after_state')->nullable()->default('{}');
            $table->jsonb('diff')->nullable()->default('{}');
            $table->jsonb('domain_context')->nullable()->default('{}');
            $table->jsonb('sensitive_fields')->nullable()->default('[]');
            $table->string('redaction_policy_version', 40);
            $table->char('payload_hash', 64);
            $table->char('previous_hash', 64)->nullable();
            $table->char('record_hash', 64);
            $table->string('chain_scope', 120);
            $table->unsignedSmallInteger('chain_version')->default(1);
            $table->timestampTz('sealed_at')->nullable();
            $table->uuid('seal_id')->nullable();
            $table->string('integrity_status', 40)->default('pending');
            $table->timestampTz('retention_until');
            $table->timestampTz('created_at');

            $table->index(['organization_id', 'sequence_id'], 'immutable_audit_org_sequence_idx');
            $table->index(['organization_id', 'occurred_at'], 'immutable_audit_org_occurred_idx');
            $table->index(['organization_id', 'domain', 'occurred_at'], 'immutable_audit_domain_time_idx');
            $table->index(['organization_id', 'actor_user_id', 'occurred_at'], 'immutable_audit_actor_idx');
            $table->index(['organization_id', 'project_id', 'occurred_at'], 'immutable_audit_project_idx');
            $table->index(['organization_id', 'subject_type', 'subject_id'], 'immutable_audit_subject_idx');
            $table->index(['organization_id', 'result', 'occurred_at'], 'immutable_audit_result_idx');
            $table->index(['organization_id', 'severity', 'occurred_at'], 'immutable_audit_severity_idx');
            $table->index(['organization_id', 'integrity_status', 'occurred_at'], 'immutable_audit_integrity_idx');
            $table->index(['organization_id', 'correlation_id'], 'immutable_audit_correlation_idx');
            $table->index(['chain_scope', 'sequence_id'], 'immutable_audit_chain_idx');
            $table->index(['retention_until'], 'immutable_audit_retention_idx');
        });

        Schema::create('immutable_audit_seals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('chain_scope', 120);
            $table->unsignedBigInteger('from_sequence_id');
            $table->unsignedBigInteger('to_sequence_id');
            $table->unsignedInteger('events_count');
            $table->char('root_hash', 64);
            $table->char('previous_seal_hash', 64)->nullable();
            $table->char('seal_hash', 64);
            $table->timestampTz('sealed_at');
            $table->foreignId('sealed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->jsonb('storage_anchor')->nullable()->default('{}');
            $table->string('integrity_status', 40)->default('sealed');
            $table->timestampTz('created_at');

            $table->index(['organization_id', 'chain_scope', 'to_sequence_id'], 'immutable_audit_seals_chain_idx');
            $table->index(['organization_id', 'sealed_at'], 'immutable_audit_seals_time_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE immutable_audit_events ADD CONSTRAINT immutable_audit_events_domain_check CHECK (domain IN ('payments', 'budgeting', 'mdm', 'rbac', 'one_c_exchange', 'warehouse', 'crm', 'period_close', 'procurement', 'sod'))");
            DB::statement("ALTER TABLE immutable_audit_events ADD CONSTRAINT immutable_audit_events_integrity_status_check CHECK (integrity_status IN ('pending', 'sealed', 'verified', 'broken', 'archived'))");
            DB::statement("ALTER TABLE immutable_audit_seals ADD CONSTRAINT immutable_audit_seals_integrity_status_check CHECK (integrity_status IN ('sealed', 'verified', 'broken', 'archived'))");
            DB::statement('CREATE UNIQUE INDEX immutable_audit_source_event_unique ON immutable_audit_events (organization_id, domain, source, source_event_id) WHERE source_event_id IS NOT NULL');
            DB::statement('CREATE INDEX immutable_audit_domain_context_gin_idx ON immutable_audit_events USING GIN (domain_context)');
            DB::statement('CREATE INDEX immutable_audit_diff_gin_idx ON immutable_audit_events USING GIN (diff)');
            DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION immutable_audit_prevent_mutation()
RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'immutable audit records are append-only';
END;
$$ LANGUAGE plpgsql;
SQL);
            DB::statement('CREATE TRIGGER immutable_audit_events_append_only BEFORE UPDATE OR DELETE ON immutable_audit_events FOR EACH ROW EXECUTE FUNCTION immutable_audit_prevent_mutation()');
            DB::statement('CREATE TRIGGER immutable_audit_seals_append_only BEFORE UPDATE OR DELETE ON immutable_audit_seals FOR EACH ROW EXECUTE FUNCTION immutable_audit_prevent_mutation()');
        } else {
            Schema::table('immutable_audit_events', function (Blueprint $table): void {
                $table->unique(['organization_id', 'domain', 'source', 'source_event_id'], 'immutable_audit_source_event_unique');
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS immutable_audit_events_append_only ON immutable_audit_events');
            DB::statement('DROP TRIGGER IF EXISTS immutable_audit_seals_append_only ON immutable_audit_seals');
            DB::statement('DROP FUNCTION IF EXISTS immutable_audit_prevent_mutation()');
            DB::statement('DROP INDEX IF EXISTS immutable_audit_source_event_unique');
            DB::statement('DROP INDEX IF EXISTS immutable_audit_domain_context_gin_idx');
            DB::statement('DROP INDEX IF EXISTS immutable_audit_diff_gin_idx');
        }

        Schema::dropIfExists('immutable_audit_seals');
        Schema::dropIfExists('immutable_audit_events');
    }
};
