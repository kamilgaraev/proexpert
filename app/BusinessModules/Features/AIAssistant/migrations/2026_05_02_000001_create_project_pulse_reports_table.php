<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_pulse_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('scope_type', 32)->default('organization');
            $table->date('report_date');
            $table->string('period_preset', 32)->default('today');
            $table->timestampTz('period_from')->nullable();
            $table->timestampTz('period_to')->nullable();
            $table->string('status', 32)->default('good');
            $table->string('ai_status', 32)->default('rules_only');
            $table->string('ai_provider', 64)->nullable();
            $table->jsonb('summary');
            $table->jsonb('metrics');
            $table->jsonb('urgent_actions');
            $table->jsonb('risk_groups');
            $table->jsonb('finance');
            $table->jsonb('activity');
            $table->jsonb('recommendations');
            $table->jsonb('raw_facts')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('generated_at');
            $table->timestamps();

            $table->index(['organization_id', 'report_date']);
            $table->index(['organization_id', 'project_id', 'report_date']);
            $table->index(['organization_id', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_pulse_reports');
    }
};
