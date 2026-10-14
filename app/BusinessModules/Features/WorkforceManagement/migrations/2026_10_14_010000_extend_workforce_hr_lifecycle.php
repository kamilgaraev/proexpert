<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Existing personnel cards retain their unknown legal relation until explicit reconciliation.
        Schema::table('workforce_employees', function (Blueprint $table): void {
            $table->string('hr_reconciliation_status', 40)->default('needs_reconciliation');
        });
        Schema::table('workforce_employment_contracts', function (Blueprint $table): void {
            $table->string('relation_type', 24)->nullable();
            $table->string('employment_mode', 32)->nullable();
            $table->decimal('job_share', 5, 3)->nullable();
            $table->string('timezone', 80)->nullable();
            $table->decimal('weekly_norm_hours', 6, 2)->nullable();
            $table->string('summed_accounting_period', 32)->nullable();
            $table->string('calendar_profile', 80)->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('supersedes_id')->nullable()->constrained('workforce_employment_contracts')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dropUnique(['organization_id', 'contract_number']);
            $table->unique(['organization_id', 'contract_number', 'revision'], 'hr_contract_revision_unique');
            $table->unique('supersedes_id');
            $table->index(['organization_id', 'employee_id', 'start_date'], 'hr_relation_effective_idx');
        });
        Schema::create('workforce_hr_documents', function (Blueprint $table): void {
            $this->employeeColumns($table);
            $table->string('document_type', 60);
            $table->string('title');
            $table->foreignId('file_id')->constrained('files')->restrictOnDelete();
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('supersedes_id')->nullable()->constrained('workforce_hr_documents')->restrictOnDelete();
            $table->string('retention_class', 80);
            $table->date('document_date');
            $table->date('case_closed_on')->nullable();
            $table->string('closure_basis_reference')->nullable();
            $table->date('retain_until')->nullable();
            $table->jsonb('retention_policy_metadata');
            $table->string('evidence_kind', 40)->default('unsigned');
            $table->foreignId('evidence_file_id')->nullable()->constrained('files')->restrictOnDelete();
            $table->jsonb('paper_evidence_metadata')->nullable();
            $table->timestampTz('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['organization_id', 'employee_id']);
            $table->unique('supersedes_id');
        });
        Schema::create('workforce_hr_events', function (Blueprint $table): void {
            $this->employeeColumns($table);
            $table->foreignId('relation_id')->nullable()->constrained('workforce_employment_contracts')->restrictOnDelete();
            $table->string('event_type', 40);
            $table->date('effective_date');
            $table->string('basis_reference', 255);
            $table->foreignId('basis_document_id')->nullable()->constrained('workforce_hr_documents')->restrictOnDelete();
            $table->jsonb('before_state')->nullable();
            $table->jsonb('after_state')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('recorded_at');
            $table->index(['organization_id', 'employee_id', 'effective_date'], 'hr_events_employee_date_idx');
        });
        Schema::create('workforce_hr_vacancies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('status', 24)->default('open');
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->date('target_date')->nullable();
            $table->jsonb('stage_pipeline');
            $table->timestampsTz();
            $table->index(['organization_id', 'status']);
        });
        Schema::create('workforce_hr_candidates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vacancy_id')->constrained('workforce_hr_vacancies')->restrictOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 80)->nullable();
            $table->string('stage', 80);
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['organization_id', 'vacancy_id']);
        });
        Schema::create('workforce_hr_candidate_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('candidate_id')->constrained('workforce_hr_candidates')->restrictOnDelete();
            $table->string('from_stage', 80);
            $table->string('to_stage', 80);
            $table->string('reason', 500);
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('recorded_at');
        });
        Schema::create('workforce_hr_checklist_items', function (Blueprint $table): void {
            $this->employeeColumns($table);
            $table->string('lifecycle', 24);
            $table->string('title');
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->date('due_date');
            $table->timestampTz('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('completion_reference', 500)->nullable();
            $table->timestampsTz();
            $table->index(['organization_id', 'employee_id']);
        });
        Schema::create('workforce_hr_development_records', function (Blueprint $table): void {
            $this->employeeColumns($table);
            $table->string('record_type', 32);
            $table->string('title');
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->date('effective_date');
            $table->date('valid_until')->nullable();
            $table->string('result', 1000)->nullable();
            $table->foreignId('source_file_id')->nullable()->constrained('files')->restrictOnDelete();
            $table->foreignId('supersedes_id')->nullable()->constrained('workforce_hr_development_records')->restrictOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique('supersedes_id');
            $table->index(['organization_id', 'employee_id']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE workforce_employment_contracts ADD CONSTRAINT hr_relation_dates CHECK (end_date IS NULL OR end_date >= start_date), ADD CONSTRAINT hr_relation_type CHECK (relation_type IS NULL OR relation_type IN ('employment', 'civil')), ADD CONSTRAINT hr_relation_terms CHECK (relation_type IS NULL OR (relation_type = 'civil' AND employment_mode IS NULL AND job_share IS NULL AND weekly_norm_hours IS NULL AND summed_accounting_period IS NULL AND calendar_profile IS NULL) OR (relation_type = 'employment' AND employment_mode IS NOT NULL AND job_share IS NOT NULL AND weekly_norm_hours IS NOT NULL AND employment_mode IN ('main', 'internal_secondary', 'external_secondary', 'part_time') AND job_share > 0 AND job_share <= 1 AND weekly_norm_hours > 0 AND weekly_norm_hours <= 40 AND timezone IS NOT NULL AND calendar_profile IS NOT NULL))");
        }
    }

    private function employeeColumns(Blueprint $table): void
    {
        $table->id();
        $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
        $table->foreignId('employee_id')->constrained('workforce_employees')->restrictOnDelete();
    }

    public function down(): void
    {
        foreach (['workforce_hr_development_records', 'workforce_hr_checklist_items', 'workforce_hr_candidate_events', 'workforce_hr_candidates', 'workforce_hr_vacancies', 'workforce_hr_events', 'workforce_hr_documents'] as $table) {
            Schema::dropIfExists($table);
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE workforce_employment_contracts DROP CONSTRAINT IF EXISTS hr_relation_dates, DROP CONSTRAINT IF EXISTS hr_relation_type, DROP CONSTRAINT IF EXISTS hr_relation_terms');
        }
        Schema::table('workforce_employment_contracts', function (Blueprint $table): void {
            $table->dropUnique('hr_contract_revision_unique');
            $table->dropUnique(['supersedes_id']);
            $table->dropIndex('hr_relation_effective_idx');
            $table->dropConstrainedForeignId('supersedes_id');
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['relation_type', 'employment_mode', 'job_share', 'timezone', 'weekly_norm_hours', 'summed_accounting_period', 'calendar_profile', 'revision']);
            // Revision history must be exported before rollback; duplicate contract numbers intentionally prevent unsafe rollback.
            $table->unique(['organization_id', 'contract_number']);
        });
        Schema::table('workforce_employees', fn (Blueprint $table) => $table->dropColumn('hr_reconciliation_status'));
    }
};
