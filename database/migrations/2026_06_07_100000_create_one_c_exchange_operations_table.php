<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('one_c_exchange_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('run_id')->nullable()->constrained('one_c_exchange_runs')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('mapping_id')->nullable()->constrained('one_c_exchange_mappings')->nullOnDelete();
            $table->string('operation_key', 191);
            $table->string('correlation_id', 120);
            $table->string('idempotency_key', 191)->nullable();
            $table->string('direction', 32);
            $table->string('scope', 64);
            $table->string('entity_type', 80)->nullable();
            $table->string('entity_id', 120)->nullable();
            $table->string('external_id', 191)->nullable();
            $table->string('status', 32);
            $table->string('accounting_status', 32)->nullable();
            $table->string('failure_type', 64)->nullable();
            $table->string('safe_error_code', 80)->nullable();
            $table->text('safe_error_message')->nullable();
            $table->unsignedSmallInteger('retry_count')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(5);
            $table->boolean('retryable')->default(false);
            $table->timestampTz('next_retry_at')->nullable();
            $table->timestampTz('last_attempt_at')->nullable();
            $table->timestampTz('dead_lettered_at')->nullable();
            $table->string('source_hash', 128)->nullable();
            $table->string('payload_hash', 128)->nullable();
            $table->jsonb('safe_payload_preview')->nullable();
            $table->jsonb('summary')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'operation_key'], 'one_c_operation_key_unique');
            $table->unique(['organization_id', 'idempotency_key'], 'one_c_operation_idempotency_unique');
            $table->index(['organization_id', 'status', 'next_retry_at'], 'one_c_operation_retry_index');
            $table->index(['organization_id', 'scope', 'direction', 'status'], 'one_c_operation_scope_index');
            $table->index(['organization_id', 'entity_type', 'entity_id'], 'one_c_operation_entity_index');
            $table->index(['organization_id', 'correlation_id'], 'one_c_operation_correlation_index');
            $table->index(['organization_id', 'created_at'], 'one_c_operation_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('one_c_exchange_operations');
    }
};
