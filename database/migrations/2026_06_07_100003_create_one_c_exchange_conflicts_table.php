<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('one_c_exchange_conflicts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('operation_id')->nullable()->constrained('one_c_exchange_operations')->cascadeOnDelete();
            $table->foreignId('mapping_id')->nullable()->constrained('one_c_exchange_mappings')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('conflict_key', 191);
            $table->string('conflict_type', 64);
            $table->string('status', 32)->default('open');
            $table->string('severity', 16)->default('warning');
            $table->string('scope', 64);
            $table->string('entity_type', 80)->nullable();
            $table->string('entity_id', 120)->nullable();
            $table->string('external_id', 191)->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('source_hash', 128)->nullable();
            $table->string('payload_hash', 128)->nullable();
            $table->jsonb('prohelper_values')->nullable();
            $table->jsonb('one_c_values')->nullable();
            $table->jsonb('safe_payload_preview')->nullable();
            $table->jsonb('resolution')->nullable();
            $table->jsonb('summary')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampTz('detected_at');
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('postponed_until')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'conflict_key'], 'one_c_conflict_key_unique');
            $table->index(['organization_id', 'status', 'severity'], 'one_c_conflict_status_idx');
            $table->index(['organization_id', 'scope', 'entity_type'], 'one_c_conflict_scope_idx');
            $table->index(['organization_id', 'detected_at'], 'one_c_conflict_detected_idx');
            $table->index(['organization_id', 'due_at'], 'one_c_conflict_due_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('one_c_exchange_conflicts');
    }
};
