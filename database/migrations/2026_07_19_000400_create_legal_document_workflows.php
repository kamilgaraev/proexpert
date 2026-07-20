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
        if (! Schema::hasTable('legal_workflow_templates')) {
            Schema::create('legal_workflow_templates', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')->constrained()->restrictOnDelete();
                $table->string('code', 120);
                $table->unsignedInteger('version');
                $table->string('name', 255);
                $table->char('definition_hash', 64);
                $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
                $table->timestampsTz();
                $table->unique(['organization_id', 'code', 'version'], 'legal_workflow_templates_version_unique');
            });
        }
        if (! Schema::hasTable('legal_workflow_template_heads')) {
            Schema::create('legal_workflow_template_heads', function (Blueprint $table): void {
                $table->foreignId('organization_id')->constrained()->restrictOnDelete();
                $table->string('code', 120);
                $table->unsignedBigInteger('template_id');
                $table->timestampsTz();
                $table->primary(['organization_id', 'code'], 'legal_workflow_template_heads_pk');
            });
        }
        if (! Schema::hasTable('legal_workflow_template_steps')) {
            Schema::create('legal_workflow_template_steps', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('template_id');
                $table->foreignId('organization_id')->constrained()->restrictOnDelete();
                $table->string('step_key', 120);
                $table->string('label', 255);
                $table->unsignedInteger('sequence');
                $table->string('parallel_group', 120);
                $table->boolean('required')->default(false);
                $table->string('policy_key', 120)->nullable();
                $table->string('actor_type', 32);
                $table->string('actor_reference', 191);
                $table->unsignedInteger('due_in_hours')->nullable();
                $table->jsonb('settings')->nullable();
                $table->timestampsTz();
                $table->unique(['template_id', 'step_key'], 'legal_workflow_template_steps_key_unique');
            });
        }
        if (! Schema::hasTable('legal_workflow_instances')) {
            Schema::create('legal_workflow_instances', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')->constrained()->restrictOnDelete();
                $table->unsignedBigInteger('document_id');
                $table->unsignedBigInteger('document_version_id');
                $table->char('document_content_hash', 64);
                $table->unsignedBigInteger('template_id');
                $table->unsignedInteger('template_version');
                $table->jsonb('template_snapshot');
                $table->char('snapshot_hash', 64);
                $table->char('request_hash', 64);
                $table->string('idempotency_key', 191);
                $table->string('status', 32);
                $table->unsignedInteger('lock_version')->default(0);
                $table->foreignId('submitted_by_user_id')->constrained('users')->restrictOnDelete();
                $table->timestampTz('submitted_at');
                $table->timestampTz('due_at')->nullable();
                $table->timestampTz('completed_at')->nullable();
                $table->timestampTz('cancelled_at')->nullable();
                $table->timestampTz('expired_at')->nullable();
                $table->timestampTz('reconciliation_required_at')->nullable();
                $table->string('reconciliation_reason', 191)->nullable();
                $table->unsignedInteger('reconciliation_attempts')->default(0);
                $table->text('reconciliation_last_error')->nullable();
                $table->timestampsTz();
                $table->unique(
                    ['organization_id', 'document_id', 'idempotency_key'],
                    'legal_workflow_instances_idempotency_unique',
                );
            });
        }
        if (! Schema::hasTable('legal_workflow_steps')) {
            Schema::create('legal_workflow_steps', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('instance_id');
                $table->foreignId('organization_id')->constrained()->restrictOnDelete();
                $table->string('step_key', 120);
                $table->string('label', 255);
                $table->unsignedInteger('sequence');
                $table->string('parallel_group', 120);
                $table->boolean('required')->default(false);
                $table->string('policy_key', 120)->nullable();
                $table->string('actor_type', 32);
                $table->string('actor_reference', 191);
                $table->string('status', 32);
                $table->unsignedInteger('lock_version')->default(0);
                $table->unsignedInteger('due_in_hours')->nullable();
                $table->timestampTz('deadline_at')->nullable();
                $table->timestampTz('activated_at')->nullable();
                $table->timestampTz('due_at')->nullable();
                $table->timestampTz('completed_at')->nullable();
                $table->timestampsTz();
                $table->unique(['instance_id', 'step_key'], 'legal_workflow_steps_key_unique');
            });
        }
        if (! Schema::hasTable('legal_workflow_decisions')) {
            Schema::create('legal_workflow_decisions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')->constrained()->restrictOnDelete();
                $table->unsignedBigInteger('instance_id');
                $table->unsignedBigInteger('step_id')->nullable();
                $table->unsignedBigInteger('document_id');
                $table->unsignedBigInteger('document_version_id');
                $table->char('document_content_hash', 64);
                $table->string('actor_type', 32);
                $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
                $table->string('action', 32);
                $table->text('comment')->nullable();
                $table->text('reason')->nullable();
                $table->string('from_status', 32);
                $table->string('to_status', 32);
                $table->jsonb('context')->nullable();
                $table->char('request_hash', 64);
                $table->string('idempotency_key', 191);
                $table->timestampTz('decided_at');
                $table->timestampsTz();
                $table->unique(
                    ['organization_id', 'instance_id', 'idempotency_key'],
                    'legal_workflow_decisions_idempotency_unique',
                );
            });
        }
    }

    public function down(): void
    {
        foreach (['legal_workflow_decisions', 'legal_workflow_instances', 'legal_workflow_templates'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('legal_workflow_rollback_blocked_by_data');
            }
        }
        Schema::dropIfExists('legal_workflow_decisions');
        Schema::dropIfExists('legal_workflow_steps');
        Schema::dropIfExists('legal_workflow_instances');
        Schema::dropIfExists('legal_workflow_template_steps');
        Schema::dropIfExists('legal_workflow_template_heads');
        Schema::dropIfExists('legal_workflow_templates');
    }
};
