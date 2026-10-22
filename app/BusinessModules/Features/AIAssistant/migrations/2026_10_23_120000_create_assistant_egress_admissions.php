<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_assistant_egress_cohorts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20);
            $table->string('policy_version', 80);
            $table->string('provider', 20);
            $table->string('model', 100);
            $table->string('endpoint', 512);
            $table->jsonb('allowed_tools');
            $table->timestampTz('expires_at');
            $table->timestampsTz();
            $table->unique(['organization_id', 'actor_id']);
        });
        Schema::create('ai_assistant_egress_enrollments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('cohort_id')->constrained('ai_assistant_egress_cohorts')->cascadeOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('input_hash', 64);
            $table->string('manifest_hash', 64);
            $table->string('status', 20);
            $table->jsonb('sources');
            $table->string('declaration_version', 80)->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('approved_at')->nullable();
            $table->timestampsTz();
            $table->index(['cohort_id', 'input_hash', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_assistant_egress_enrollments');
        Schema::dropIfExists('ai_assistant_egress_cohorts');
    }
};
