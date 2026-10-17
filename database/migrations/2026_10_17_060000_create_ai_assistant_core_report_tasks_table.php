<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_assistant_core_report_tasks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('assistant_request_id')->constrained('ai_assistant_requests')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('surface', 16);
            $table->char('input_sha256', 64);
            $table->jsonb('parameters');
            $table->string('status', 24)->default('queued');
            $table->ulid('run_id')->nullable();
            $table->ulid('export_id')->nullable();
            $table->unsignedSmallInteger('failure_count')->default(0);
            $table->string('error_code', 48)->nullable();
            $table->uuid('lease_token')->nullable();
            $table->timestampTz('lease_expires_at')->nullable();
            $table->timestampTz('retry_at');
            $table->timestampTz('execution_expires_at');
            $table->timestampTz('expires_at');
            $table->timestampsTz();
            $table->unique(['assistant_request_id', 'input_sha256'], 'assistant_core_report_request_input_unique');
            $table->index(['organization_id', 'user_id', 'surface', 'id'], 'assistant_core_report_owner_index');
            $table->index(['status', 'retry_at', 'lease_expires_at'], 'assistant_core_report_recovery_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_assistant_core_report_tasks');
    }
};
