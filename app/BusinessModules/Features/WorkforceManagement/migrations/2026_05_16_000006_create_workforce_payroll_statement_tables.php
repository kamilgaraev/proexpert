<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('workforce_payroll_statements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_period_id')->constrained('workforce_payroll_periods')->cascadeOnDelete();
            $table->string('statement_number', 120);
            $table->string('status', 40)->default('prepared');
            $table->decimal('total_hours', 18, 2)->default(0);
            $table->decimal('gross_amount', 18, 2)->default(0);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['organization_id', 'payroll_period_id'], 'workforce_statement_period_unique');
            $table->unique(['organization_id', 'statement_number'], 'workforce_statement_number_unique');
            $table->index(['organization_id', 'status']);
        });

        Schema::create('workforce_payroll_statement_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_statement_id')->constrained('workforce_payroll_statements')->cascadeOnDelete();
            $table->foreignId('payroll_period_id')->constrained('workforce_payroll_periods')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('workforce_employees')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('hours', 18, 2)->default(0);
            $table->decimal('gross_amount', 18, 2)->default(0);
            $table->jsonb('source_row_ids')->nullable();
            $table->timestampsTz();

            $table->unique(['payroll_statement_id', 'employee_id', 'project_id'], 'workforce_statement_row_unique');
            $table->index(['organization_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_payroll_statement_rows');
        Schema::dropIfExists('workforce_payroll_statements');
    }
};
