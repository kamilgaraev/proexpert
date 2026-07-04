<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('safety_requirement_matrices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('work_type_id')->nullable()->constrained('work_types')->nullOnDelete();
            $table->string('position_name')->nullable();
            $table->string('work_category', 80);
            $table->string('risk_level', 30)->default('medium');
            $table->jsonb('requirements')->nullable();
            $table->boolean('is_active')->default(true);
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['organization_id', 'work_category', 'is_active']);
            $table->index(['organization_id', 'project_id', 'work_category']);
            $table->index(['effective_from', 'effective_until']);
        });

        Schema::create('safety_employee_requirements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('workforce_employees')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('work_type_id')->nullable()->constrained('work_types')->nullOnDelete();
            $table->string('work_category', 80);
            $table->string('requirement_code', 120);
            $table->string('requirement_type', 80);
            $table->string('source_type', 80)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->string('status', 40)->default('missing');
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['organization_id', 'employee_id', 'status']);
            $table->index(['organization_id', 'employee_id', 'valid_until']);
            $table->index(['organization_id', 'project_id', 'work_category']);
        });

        Schema::create('safety_training_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('workforce_employees')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('program_code', 120);
            $table->string('program_name');
            $table->string('training_type', 80);
            $table->date('completed_at')->nullable();
            $table->date('valid_until')->nullable();
            $table->string('result', 40)->default('passed');
            $table->string('document_number')->nullable();
            $table->string('protocol_number')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['organization_id', 'employee_id', 'program_code']);
            $table->index(['organization_id', 'employee_id', 'valid_until']);
        });

        Schema::create('safety_medical_exams', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('workforce_employees')->cascadeOnDelete();
            $table->string('exam_type', 80);
            $table->date('completed_at')->nullable();
            $table->date('valid_until')->nullable();
            $table->string('result', 40)->default('fit');
            $table->text('restrictions')->nullable();
            $table->unsignedBigInteger('file_id')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['organization_id', 'employee_id', 'result']);
            $table->index(['organization_id', 'employee_id', 'valid_until']);
        });

        Schema::create('safety_ppe_norms', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('position_name')->nullable();
            $table->string('work_category', 80)->nullable();
            $table->string('ppe_code', 120);
            $table->string('ppe_name');
            $table->integer('wear_period_days')->nullable();
            $table->boolean('is_required')->default(true);
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['organization_id', 'position_name']);
            $table->index(['organization_id', 'work_category']);
        });

        Schema::create('safety_ppe_issues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('workforce_employees')->cascadeOnDelete();
            $table->string('ppe_code', 120);
            $table->string('ppe_name');
            $table->date('issued_at')->nullable();
            $table->date('valid_until')->nullable();
            $table->decimal('quantity', 12, 3)->default(1);
            $table->string('status', 40)->default('issued');
            $table->unsignedBigInteger('warehouse_operation_id')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['organization_id', 'employee_id', 'status']);
            $table->index(['organization_id', 'employee_id', 'valid_until']);
            $table->index(['organization_id', 'ppe_code', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('safety_ppe_issues');
        Schema::dropIfExists('safety_ppe_norms');
        Schema::dropIfExists('safety_medical_exams');
        Schema::dropIfExists('safety_training_records');
        Schema::dropIfExists('safety_employee_requirements');
        Schema::dropIfExists('safety_requirement_matrices');
    }
};
