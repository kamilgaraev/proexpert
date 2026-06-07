<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('one_c_exchange_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('operation_id')->constrained('one_c_exchange_operations')->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt_number');
            $table->string('status', 32);
            $table->string('failure_type', 64)->nullable();
            $table->unsignedSmallInteger('transport_status')->nullable();
            $table->boolean('retryable')->default(false);
            $table->timestampTz('next_retry_at')->nullable();
            $table->string('safe_error_code', 80)->nullable();
            $table->text('safe_error_message')->nullable();
            $table->string('request_hash', 128)->nullable();
            $table->string('response_hash', 128)->nullable();
            $table->jsonb('safe_request_preview')->nullable();
            $table->jsonb('safe_response_preview')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('received_at')->nullable();
            $table->timestampsTz();

            $table->unique(['operation_id', 'attempt_number'], 'one_c_message_attempt_unique');
            $table->index(['organization_id', 'status', 'next_retry_at'], 'one_c_message_retry_index');
            $table->index(['organization_id', 'created_at'], 'one_c_message_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('one_c_exchange_messages');
    }
};
