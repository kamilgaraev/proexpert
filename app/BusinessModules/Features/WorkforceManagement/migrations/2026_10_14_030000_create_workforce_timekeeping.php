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
        Schema::table('workforce_employee_assignments', function (Blueprint $table): void {
            $table->foreignId('employment_contract_id')->nullable()->constrained('workforce_employment_contracts')->restrictOnDelete();
        });
        Schema::create('workforce_timekeeping_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('month');
            $table->string('status', 24)->default('draft');
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('supersedes_id')->nullable()->constrained('workforce_timekeeping_periods')->restrictOnDelete();
            $table->jsonb('rule_snapshot');
            $table->jsonb('snapshot');
            $table->jsonb('approval')->nullable();
            $table->jsonb('archive_metadata')->nullable();
            $table->char('snapshot_sha256', 64);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();
            $table->unique('supersedes_id');
            $table->index(['organization_id', 'month', 'project_id']);
        });
        DB::statement('CREATE UNIQUE INDEX workforce_timekeeping_initial_unique ON workforce_timekeeping_periods (organization_id, month, COALESCE(project_id, 0)) WHERE supersedes_id IS NULL');
        DB::statement("ALTER TABLE workforce_timekeeping_periods ADD CONSTRAINT workforce_timekeeping_status_check CHECK (status IN ('draft','submitted','verified','approved','closed'))");
        Schema::create('workforce_timekeeping_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('period_id')->constrained('workforce_timekeeping_periods')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->jsonb('snapshot');
            $table->char('sha256', 64);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');
            $table->unique(['period_id', 'revision']);
        });
        Schema::create('workforce_timekeeping_cells', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('period_id')->constrained('workforce_timekeeping_periods')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('workforce_employees')->restrictOnDelete();
            $table->foreignId('employment_contract_id')->nullable()->constrained('workforce_employment_contracts')->restrictOnDelete();
            $table->date('work_date');
            $table->unsignedInteger('revision');
            $table->jsonb('payload');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');
            $table->unique(['period_id', 'employee_id', 'employment_contract_id', 'work_date', 'revision'], 'timekeeping_cell_revision_unique');
        });
        Schema::create('workforce_timekeeping_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('period_id')->constrained('workforce_timekeeping_periods')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->foreignId('file_id')->constrained('files')->restrictOnDelete();
            $table->string('format', 8);
            $table->char('sha256', 64);
            $table->char('snapshot_sha256', 64);
            $table->string('form_version', 80);
            $table->unsignedSmallInteger('retention_years');
            $table->date('retain_until');
            $table->string('filename');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');
        });
        Schema::create('workforce_timekeeping_transitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('period_id')->constrained('workforce_timekeeping_periods')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->string('action', 24);
            $table->string('from_status', 24);
            $table->string('to_status', 24);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->text('comment')->nullable();
            $table->string('basis_reference', 255)->nullable();
            $table->jsonb('evidence')->nullable();
            $table->timestampTz('created_at');
        });
        DB::unprepared(<<<'SQL'
CREATE FUNCTION protect_workforce_timekeeping_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'timekeeping_immutable'; END; $$;
CREATE FUNCTION protect_workforce_timekeeping_period() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'DELETE' OR OLD.status = 'closed' THEN RAISE EXCEPTION 'timekeeping_closed_immutable'; END IF;
 IF OLD.status = 'approved' AND (NEW.snapshot IS DISTINCT FROM OLD.snapshot OR NEW.rule_snapshot IS DISTINCT FROM OLD.rule_snapshot OR NEW.revision IS DISTINCT FROM OLD.revision OR NEW.snapshot_sha256 IS DISTINCT FROM OLD.snapshot_sha256 OR NEW.approval IS DISTINCT FROM OLD.approval) THEN RAISE EXCEPTION 'timekeeping_approved_immutable'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER timekeeping_period_immutable BEFORE UPDATE OR DELETE ON workforce_timekeeping_periods FOR EACH ROW EXECUTE FUNCTION protect_workforce_timekeeping_period();
SQL);
        foreach (['revisions', 'cells', 'documents', 'transitions'] as $suffix) {
            DB::statement("CREATE TRIGGER timekeeping_{$suffix}_immutable BEFORE UPDATE OR DELETE ON workforce_timekeeping_{$suffix} FOR EACH ROW EXECUTE FUNCTION protect_workforce_timekeeping_append_only()");
        }
    }

    public function down(): void
    {
        foreach (['transitions', 'documents', 'cells', 'revisions', 'periods'] as $suffix) {
            Schema::dropIfExists('workforce_timekeeping_'.$suffix);
        }
        DB::statement('DROP FUNCTION IF EXISTS protect_workforce_timekeeping_append_only()');
        DB::statement('DROP FUNCTION IF EXISTS protect_workforce_timekeeping_period()');
        Schema::table('workforce_employee_assignments', fn (Blueprint $table) => $table->dropConstrainedForeignId('employment_contract_id'));
    }
};
