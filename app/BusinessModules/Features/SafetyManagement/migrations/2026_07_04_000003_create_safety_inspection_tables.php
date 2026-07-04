<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('safety_inspection_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('inspection_type', 80);
            $table->jsonb('checklist_items')->nullable();
            $table->boolean('is_active')->default(true);
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['organization_id', 'inspection_type', 'is_active']);
        });

        Schema::create('safety_inspections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('safety_inspection_templates')->nullOnDelete();
            $table->foreignId('permit_id')->nullable()->constrained('safety_work_permits')->nullOnDelete();
            $table->foreignId('conducted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('inspection_number', 80)->unique();
            $table->string('title');
            $table->string('inspection_type', 80);
            $table->string('location_name')->nullable();
            $table->string('risk_level', 30)->default('medium');
            $table->string('status', 40)->default('planned');
            $table->timestampTz('planned_at')->nullable();
            $table->timestampTz('conducted_at')->nullable();
            $table->string('result', 40)->nullable();
            $table->text('summary')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['organization_id', 'project_id', 'status']);
            $table->index(['organization_id', 'inspection_type', 'status']);
            $table->index(['conducted_at', 'status']);
        });

        Schema::create('safety_inspection_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inspection_id')->constrained('safety_inspections')->cascadeOnDelete();
            $table->string('item_code', 120);
            $table->string('title');
            $table->text('requirement_text')->nullable();
            $table->string('severity', 30)->default('major');
            $table->string('status', 40)->default('not_checked');
            $table->text('comment')->nullable();
            $table->jsonb('evidence_files')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['inspection_id', 'status']);
            $table->index(['inspection_id', 'severity']);
        });

        Schema::create('safety_inspection_findings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inspection_id')->nullable()->constrained('safety_inspections')->nullOnDelete();
            $table->foreignId('inspection_item_id')->nullable()->constrained('safety_inspection_items')->nullOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('corrective_action_id')->nullable()->constrained('safety_corrective_actions')->nullOnDelete();
            $table->string('finding_number', 80)->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('severity', 30)->default('major');
            $table->string('status', 40)->default('open');
            $table->date('due_date')->nullable();
            $table->text('resolution_comment')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->jsonb('evidence_files')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['organization_id', 'project_id', 'status']);
            $table->index(['organization_id', 'assigned_to_user_id', 'status']);
            $table->index(['due_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('safety_inspection_findings');
        Schema::dropIfExists('safety_inspection_items');
        Schema::dropIfExists('safety_inspections');
        Schema::dropIfExists('safety_inspection_templates');
    }
};
