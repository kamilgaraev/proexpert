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
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        Schema::create('safety_site_workforce_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('safety_site_id')->constrained('safety_sites')->restrictOnDelete();
            $table->foreignId('workforce_assignment_id')->constrained('workforce_employee_assignments')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('workforce_employees')->restrictOnDelete();
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->string('mapping_source', 80);
            $table->char('source_hash', 64);
            $table->timestampsTz();

            $table->unique(
                ['organization_id', 'safety_site_id', 'workforce_assignment_id', 'valid_from'],
                'safety_site_workforce_assignment_unique'
            );
            $table->index(
                ['organization_id', 'safety_site_id', 'employee_id', 'valid_from', 'valid_to'],
                'safety_site_workforce_assignment_dates_idx'
            );
        });

        Schema::create('safety_admission_policy_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('safety_site_id')->nullable()->constrained('safety_sites')->restrictOnDelete();
            $table->string('version', 80);
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->jsonb('mandatory_requirements');
            $table->unsignedSmallInteger('expiring_soon_days')->default(30);
            $table->boolean('waiver_evidence_required')->default(true);
            $table->char('source_hash', 64);
            $table->timestampsTz();

            $table->unique(['organization_id', 'project_id', 'safety_site_id', 'version'], 'safety_admission_policy_version_unique');
            $table->index(
                ['organization_id', 'project_id', 'safety_site_id', 'effective_from', 'effective_until'],
                'safety_admission_policy_effective_idx'
            );
        });

        Schema::create('safety_admission_snapshots', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('safety_site_id')->nullable()->constrained('safety_sites')->restrictOnDelete();
            $table->foreignId('policy_version_id')->constrained('safety_admission_policy_versions')->restrictOnDelete();
            $table->char('scope_hash', 64);
            $table->char('definition_hash', 64);
            $table->string('formula_version', 80);
            $table->char('source_hash', 64);
            $table->date('snapshot_date');
            $table->timestampTz('source_watermark');
            $table->unsignedBigInteger('row_count')->default(0);
            $table->unsignedBigInteger('evaluated_people')->default(0);
            $table->unsignedBigInteger('admitted_people')->default(0);
            $table->unsignedBigInteger('partial_people')->default(0);
            $table->unsignedBigInteger('not_admitted_people')->default(0);
            $table->unsignedBigInteger('blocker_count')->default(0);
            $table->unsignedBigInteger('expiring_count')->default(0);
            $table->unsignedBigInteger('unverified_count')->default(0);
            $table->unsignedBigInteger('eligible_count')->default(0);
            $table->unsignedBigInteger('projected_count')->default(0);
            $table->unsignedBigInteger('gap_count')->default(0);
            $table->unsignedBigInteger('unknown_count')->default(0);
            $table->timestampTz('generated_at');
            $table->timestampTz('stale_at');

            $table->unique(
                ['organization_id', 'scope_hash', 'snapshot_date', 'formula_version', 'source_hash'],
                'safety_admission_snapshot_unique'
            );
            $table->index(['organization_id', 'project_id', 'safety_site_id', 'snapshot_date'], 'safety_admission_snapshot_scope_idx');
        });

        Schema::create('safety_admission_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->ulid('snapshot_id');
            $table->foreign('snapshot_id')->references('id')->on('safety_admission_snapshots')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('safety_site_id')->constrained('safety_sites')->restrictOnDelete();
            $table->foreignId('workforce_assignment_id')->constrained('workforce_employee_assignments')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('workforce_employees')->restrictOnDelete();
            $table->date('snapshot_date');
            $table->string('row_type', 24);
            $table->string('row_key', 190);
            $table->string('requirement_code', 80);
            $table->string('requirement_type', 40);
            $table->string('status', 40);
            $table->boolean('mandatory');
            $table->boolean('blocked');
            $table->boolean('verified');
            $table->date('valid_until')->nullable();
            $table->string('evidence_type', 40)->nullable();
            $table->unsignedBigInteger('evidence_id')->nullable();
            $table->jsonb('medical_details')->nullable();
            $table->jsonb('blocker_codes');

            $table->unique(['organization_id', 'snapshot_id', 'row_key'], 'safety_admission_row_unique');
            $table->index(
                ['organization_id', 'snapshot_id', 'project_id', 'safety_site_id', 'employee_id', 'requirement_code', 'status', 'valid_until', 'row_key'],
                'safety_admission_rows_filter_idx'
            );
            $table->index(['organization_id', 'snapshot_id', 'snapshot_date', 'row_key'], 'safety_admission_rows_sort_idx');
        });

        DB::statement('ALTER TABLE safety_site_workforce_assignments ADD CONSTRAINT safety_site_workforce_assignment_dates_check CHECK (valid_to IS NULL OR valid_to >= valid_from)');
        DB::statement("ALTER TABLE safety_site_workforce_assignments ADD CONSTRAINT safety_site_workforce_assignment_hash_check CHECK (source_hash ~ '^[a-f0-9]{64}$')");
        DB::statement("ALTER TABLE safety_site_workforce_assignments ADD CONSTRAINT safety_site_workforce_assignment_no_overlap EXCLUDE USING gist (organization_id WITH =, workforce_assignment_id WITH =, daterange(valid_from, COALESCE(valid_to, 'infinity'::date), '[]') WITH &&)");
        DB::statement('ALTER TABLE safety_admission_policy_versions ADD CONSTRAINT safety_admission_policy_dates_check CHECK (effective_until IS NULL OR effective_until >= effective_from)');
        DB::statement("ALTER TABLE safety_admission_policy_versions ADD CONSTRAINT safety_admission_policy_hash_check CHECK (source_hash ~ '^[a-f0-9]{64}$')");
        DB::statement("ALTER TABLE safety_admission_snapshots ADD CONSTRAINT safety_admission_snapshot_hashes_check CHECK (scope_hash ~ '^[a-f0-9]{64}$' AND definition_hash ~ '^[a-f0-9]{64}$' AND source_hash ~ '^[a-f0-9]{64}$')");
        DB::statement('ALTER TABLE safety_admission_snapshots ADD CONSTRAINT safety_admission_snapshot_counts_check CHECK (evaluated_people = admitted_people + partial_people + not_admitted_people AND projected_count <= eligible_count AND row_count = projected_count)');
        DB::statement("ALTER TABLE safety_admission_rows ADD CONSTRAINT safety_admission_row_type_check CHECK (row_type IN ('requirement', 'person_summary'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('safety_admission_rows');
        Schema::dropIfExists('safety_admission_snapshots');
        Schema::dropIfExists('safety_admission_policy_versions');
        Schema::dropIfExists('safety_site_workforce_assignments');
    }
};
