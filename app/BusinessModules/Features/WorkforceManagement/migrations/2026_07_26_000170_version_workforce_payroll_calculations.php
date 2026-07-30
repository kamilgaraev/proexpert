<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('workforce_payroll_calculation_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_period_id')->constrained('workforce_payroll_periods')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 32);
            $table->char('source_hash', 64);
            $table->string('formula_version', 80);
            $table->unsignedInteger('source_row_count');
            $table->unsignedInteger('blocking_count')->default(0);
            $table->unsignedInteger('warning_count')->default(0);
            $table->foreignId('built_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('validated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('locked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('validated_at')->nullable();
            $table->timestampTz('locked_at')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['organization_id', 'payroll_period_id', 'version'],
                'workforce_payroll_calculation_version_unique',
            );
            $table->unique(
                ['organization_id', 'id'],
                'workforce_payroll_calculation_version_org_id_unique',
            );
            $table->index(
                ['organization_id', 'payroll_period_id', 'status', 'version'],
                'workforce_payroll_calculation_current_idx',
            );
        });

        DB::statement(
            "ALTER TABLE workforce_payroll_calculation_versions
             ADD CONSTRAINT workforce_payroll_calculation_status_check
             CHECK (status IN ('built', 'validated', 'locked'))",
        );

        Schema::create('workforce_payroll_calculation_transitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('calculation_version_id');
            $table->string('status', 32);
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('transitioned_at');
            $table->char('transition_hash', 64);

            $table->foreign(['organization_id', 'calculation_version_id'])
                ->references(['organization_id', 'id'])
                ->on('workforce_payroll_calculation_versions')
                ->cascadeOnDelete();
            $table->unique(
                ['organization_id', 'calculation_version_id', 'status'],
                'workforce_payroll_transition_state_unique',
            );
            $table->index(
                ['organization_id', 'calculation_version_id', 'transitioned_at', 'id'],
                'workforce_payroll_transition_as_of_idx',
            );
        });
        DB::statement(
            "ALTER TABLE workforce_payroll_calculation_transitions
             ADD CONSTRAINT workforce_payroll_transition_status_check
             CHECK (status IN ('built', 'validated', 'locked'))",
        );

        Schema::create('workforce_payroll_calculation_source_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('calculation_version_id');
            $table->foreignId('source_row_id')->constrained('workforce_payroll_source_rows')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('workforce_employees')->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->date('work_date');
            $table->string('source_type', 80);
            $table->decimal('hours', 18, 4);
            $table->foreignId('rate_version_id')->nullable()
                ->constrained('time_tracking_labor_rate_versions')
                ->restrictOnDelete();
            $table->string('rate_type', 40)->nullable();
            $table->decimal('rate', 18, 4)->nullable();
            $table->decimal('amount', 24, 4)->nullable();
            $table->char('currency', 3)->nullable();
            $table->jsonb('source_refs');
            $table->char('row_hash', 64);

            $table->foreign(['organization_id', 'calculation_version_id'])
                ->references(['organization_id', 'id'])
                ->on('workforce_payroll_calculation_versions')
                ->cascadeOnDelete();
            $table->unique(
                ['organization_id', 'calculation_version_id', 'source_row_id'],
                'workforce_payroll_calculation_source_unique',
            );
            $table->index(
                ['organization_id', 'calculation_version_id', 'employee_id', 'project_id'],
                'workforce_payroll_calculation_source_dimensions_idx',
            );
        });

        Schema::create('workforce_payroll_calculation_issues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('calculation_version_id');
            $table->foreignId('source_issue_id')->nullable()
                ->constrained('workforce_payroll_validation_issues')
                ->restrictOnDelete();
            $table->foreignId('source_row_id')->nullable()
                ->constrained('workforce_payroll_source_rows')
                ->restrictOnDelete();
            $table->string('severity', 40);
            $table->string('issue_code', 120);
            $table->foreignId('employee_id')->nullable()->constrained('workforce_employees')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->jsonb('audit_ref');
            $table->char('row_hash', 64);

            $table->foreign(['organization_id', 'calculation_version_id'])
                ->references(['organization_id', 'id'])
                ->on('workforce_payroll_calculation_versions')
                ->cascadeOnDelete();
            $table->unique(
                ['organization_id', 'calculation_version_id', 'source_issue_id'],
                'workforce_payroll_calculation_issue_unique',
            );
            $table->index(
                ['organization_id', 'calculation_version_id', 'severity', 'issue_code'],
                'workforce_payroll_calculation_issue_severity_idx',
            );
        });
        DB::statement(
            "ALTER TABLE workforce_payroll_calculation_source_rows
             ADD CONSTRAINT workforce_payroll_calculation_money_check
             CHECK (
                 (rate_version_id IS NULL AND rate_type IS NULL AND rate IS NULL AND amount IS NULL AND currency IS NULL)
                 OR
                 (rate_version_id IS NOT NULL AND rate_type = 'hourly' AND rate IS NOT NULL
                     AND amount IS NOT NULL AND currency ~ '^[A-Z]{3}$')
             )",
        );

        Schema::create('payroll_readiness_snapshot_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->ulid('snapshot_id');
            $table->string('row_key', 128);
            $table->foreignId('payroll_period_id')->constrained('workforce_payroll_periods')->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedBigInteger('calculation_version_id');
            $table->unsignedInteger('calculation_version');
            $table->string('row_type', 32);
            $table->foreignId('employee_id')->nullable()->constrained('workforce_employees')->restrictOnDelete();
            $table->string('employee_name')->nullable();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('project_name')->nullable();
            $table->string('source_type', 80)->nullable();
            $table->foreignId('source_row_id')->nullable()
                ->constrained('workforce_payroll_source_rows')
                ->restrictOnDelete();
            $table->decimal('hours', 18, 4)->nullable();
            $table->decimal('rate', 18, 4)->nullable();
            $table->string('rate_type', 40)->nullable();
            $table->decimal('amount', 24, 4)->nullable();
            $table->char('currency', 3)->nullable();
            $table->unsignedBigInteger('issue_id')->nullable();
            $table->string('issue_code', 120)->nullable();
            $table->string('severity', 40)->nullable();
            $table->string('status', 32);
            $table->jsonb('source_refs');
            $table->jsonb('audit_refs')->default('[]');
            $table->jsonb('row_payload');

            $table->foreign(['organization_id', 'snapshot_id'])
                ->references(['organization_id', 'id'])
                ->on('workforce_report_snapshots')
                ->cascadeOnDelete();
            $table->foreign(['organization_id', 'calculation_version_id'])
                ->references(['organization_id', 'id'])
                ->on('workforce_payroll_calculation_versions')
                ->restrictOnDelete();
            $table->unique(['organization_id', 'snapshot_id', 'row_key'], 'payroll_readiness_snapshot_row_unique');
            $table->index(
                ['organization_id', 'snapshot_id', 'status', 'row_key'],
                'payroll_readiness_snapshot_status_idx',
            );
            $table->index(
                ['organization_id', 'snapshot_id', 'employee_id', 'row_key'],
                'payroll_readiness_snapshot_employee_idx',
            );
        });

        DB::statement(
            "ALTER TABLE payroll_readiness_snapshot_rows
             ADD CONSTRAINT payroll_readiness_snapshot_row_type_check
             CHECK (
                 (row_type = 'source' AND employee_id IS NOT NULL AND source_type IS NOT NULL
                     AND source_row_id IS NOT NULL AND hours IS NOT NULL)
                 OR
                 (row_type = 'issue' AND source_type IS NULL AND source_row_id IS NULL
                     AND hours IS NULL AND rate IS NULL AND rate_type IS NULL
                     AND amount IS NULL AND currency IS NULL AND issue_code IS NOT NULL
                     AND severity IS NOT NULL)
             )",
        );
        DB::unprepared(
            <<<'SQL'
CREATE FUNCTION workforce_payroll_guard_immutable() RETURNS trigger AS $$
DECLARE parent_status text;
BEGIN
    IF TG_TABLE_NAME IN (
        'workforce_payroll_calculation_source_rows',
        'workforce_payroll_calculation_transitions',
        'payroll_readiness_snapshot_rows'
    ) THEN
        RAISE EXCEPTION 'immutable payroll record';
    END IF;

    IF TG_TABLE_NAME = 'workforce_payroll_calculation_issues' THEN
        SELECT status INTO parent_status
          FROM workforce_payroll_calculation_versions
         WHERE id = OLD.calculation_version_id
           AND organization_id = OLD.organization_id;
        IF parent_status IN ('validated', 'locked') THEN
            RAISE EXCEPTION 'immutable payroll validation';
        END IF;
    END IF;

    IF TG_TABLE_NAME = 'workforce_payroll_calculation_versions'
       AND OLD.status = 'locked' THEN
        RAISE EXCEPTION 'immutable locked payroll calculation';
    END IF;

    IF TG_TABLE_NAME = 'workforce_payroll_source_rows'
       AND EXISTS (
           SELECT 1
             FROM workforce_payroll_periods
            WHERE id = OLD.payroll_period_id
              AND organization_id = OLD.organization_id
              AND status = 'locked'
       ) THEN
        RAISE EXCEPTION 'immutable locked payroll source';
    END IF;

    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER workforce_payroll_source_rows_immutable
BEFORE UPDATE OR DELETE ON workforce_payroll_calculation_source_rows
FOR EACH ROW EXECUTE FUNCTION workforce_payroll_guard_immutable();

CREATE TRIGGER workforce_payroll_transitions_immutable
BEFORE UPDATE OR DELETE ON workforce_payroll_calculation_transitions
FOR EACH ROW EXECUTE FUNCTION workforce_payroll_guard_immutable();

CREATE TRIGGER workforce_payroll_calculation_issues_immutable
BEFORE UPDATE OR DELETE ON workforce_payroll_calculation_issues
FOR EACH ROW EXECUTE FUNCTION workforce_payroll_guard_immutable();

CREATE TRIGGER workforce_payroll_calculation_versions_immutable
BEFORE UPDATE OR DELETE ON workforce_payroll_calculation_versions
FOR EACH ROW EXECUTE FUNCTION workforce_payroll_guard_immutable();

CREATE TRIGGER workforce_payroll_period_sources_immutable
BEFORE UPDATE OR DELETE ON workforce_payroll_source_rows
FOR EACH ROW EXECUTE FUNCTION workforce_payroll_guard_immutable();

CREATE TRIGGER payroll_readiness_snapshot_rows_immutable
BEFORE UPDATE OR DELETE ON payroll_readiness_snapshot_rows
FOR EACH ROW EXECUTE FUNCTION workforce_payroll_guard_immutable();
SQL,
        );
    }

    public function down(): void
    {
        DB::unprepared(
            <<<'SQL'
DROP TRIGGER IF EXISTS payroll_readiness_snapshot_rows_immutable ON payroll_readiness_snapshot_rows;
DROP TRIGGER IF EXISTS workforce_payroll_period_sources_immutable ON workforce_payroll_source_rows;
DROP TRIGGER IF EXISTS workforce_payroll_calculation_versions_immutable ON workforce_payroll_calculation_versions;
DROP TRIGGER IF EXISTS workforce_payroll_calculation_issues_immutable ON workforce_payroll_calculation_issues;
DROP TRIGGER IF EXISTS workforce_payroll_transitions_immutable ON workforce_payroll_calculation_transitions;
DROP TRIGGER IF EXISTS workforce_payroll_source_rows_immutable ON workforce_payroll_calculation_source_rows;
DROP FUNCTION IF EXISTS workforce_payroll_guard_immutable();
SQL,
        );
        Schema::dropIfExists('payroll_readiness_snapshot_rows');
        Schema::dropIfExists('workforce_payroll_calculation_issues');
        Schema::dropIfExists('workforce_payroll_calculation_source_rows');
        Schema::dropIfExists('workforce_payroll_calculation_transitions');
        Schema::dropIfExists('workforce_payroll_calculation_versions');
    }
};
