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
        Schema::create('estimate_generation_pipeline_checkpoints', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('session_id')->constrained('estimate_generation_sessions')->cascadeOnDelete();
            $table->string('stage', 80);
            $table->string('input_version', 80);
            $table->string('output_version', 80)->nullable();
            $table->string('status', 30);
            $table->jsonb('metrics')->default('{}');
            $table->jsonb('warnings')->default('[]');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->uuid('claim_token')->nullable();
            $table->timestampTz('lease_expires_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->string('last_error_code', 160)->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['session_id', 'stage', 'input_version'],
                'estimate_generation_checkpoint_unique',
            );
            $table->index(['session_id', 'status'], 'estimate_generation_checkpoint_session_status');
            $table->index(['status', 'lease_expires_at'], 'estimate_generation_checkpoint_status_lease');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE estimate_generation_pipeline_checkpoints
            ADD CONSTRAINT estimate_generation_checkpoint_status_check
            CHECK (status IN ('running', 'completed', 'failed'))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_generation_pipeline_checkpoints');
    }
};
