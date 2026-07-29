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
        Schema::create('contractor_scorecard_policy_versions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('version', 64);
            $table->jsonb('components');
            $table->jsonb('cohort_rules');
            $table->decimal('minimum_coverage', 12, 8);
            $table->unsignedInteger('minimum_sample_size');
            $table->char('source_hash', 64);
            $table->timestampTz('effective_from', 6);
            $table->timestampTz('effective_to', 6)->nullable();
            $table->timestampsTz(6);

            $table->unique(['organization_id', 'version'], 'contractor_scorecard_policy_org_version_unique');
            $table->index(['organization_id', 'effective_from', 'id'], 'contractor_scorecard_policy_effective_idx');
        });

        Schema::create('contractor_scorecard_snapshots', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('policy_version_id');
            $table->char('definition_hash', 64);
            $table->char('source_hash', 64);
            $table->char('source_tuple_hash', 64);
            $table->string('formula_version', 64);
            $table->jsonb('scope_identity');
            $table->jsonb('filters');
            $table->timestampTz('as_of', 6);
            $table->timestampTz('generated_at', 6);
            $table->timestampTz('stale_at', 6)->nullable();
            $table->jsonb('watermarks');
            $table->unsignedBigInteger('row_count')->default(0);

            $table->unique(['organization_id', 'source_hash', 'definition_hash'], 'contractor_scorecard_snapshot_identity_unique');
            $table->index(['organization_id', 'generated_at', 'id'], 'contractor_scorecard_snapshot_generated_idx');
        });

        Schema::create('contractor_scorecard_snapshot_sources', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->char('snapshot_id', 26);
            $table->string('source_report_code', 64);
            $table->string('source_snapshot_id', 128);
            $table->char('source_hash', 64);
            $table->string('formula_version', 64);
            $table->string('source_schema_version', 64);
            $table->string('watermark', 128);

            $table->unique(
                ['organization_id', 'snapshot_id', 'source_report_code'],
                'contractor_scorecard_source_code_unique',
            );
            $table->index(
                ['organization_id', 'snapshot_id', 'source_report_code', 'source_snapshot_id'],
                'contractor_scorecard_source_lookup_idx',
            );
        });

        Schema::create('contractor_scorecard_rows', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->char('snapshot_id', 26);
            $table->unsignedBigInteger('profile_id');
            $table->unsignedBigInteger('category_id');
            $table->unsignedBigInteger('project_id');
            $table->string('cohort_key', 64);
            $table->string('component_code', 64);
            $table->string('unit_code', 32);
            $table->decimal('component_mean', 20, 8)->nullable();
            $table->unsignedInteger('sample_size');
            $table->unsignedInteger('eligible_count');
            $table->decimal('coverage', 12, 8)->nullable();
            $table->jsonb('evidence_refs');
            $table->string('row_key', 256);

            $table->unique(
                ['organization_id', 'snapshot_id', 'profile_id', 'category_id', 'project_id', 'cohort_key', 'component_code'],
                'contractor_scorecard_row_dimension_unique',
            );
            $table->unique(['organization_id', 'snapshot_id', 'row_key'], 'contractor_scorecard_row_key_unique');
            $table->index(
                ['organization_id', 'snapshot_id', 'profile_id', 'category_id', 'cohort_key', 'component_code', 'row_key'],
                'contractor_scorecard_row_keyset_idx',
            );
        });

        foreach ([
            'ALTER TABLE contractor_scorecard_policy_versions ADD CONSTRAINT contractor_scorecard_policy_coverage_check CHECK (minimum_coverage >= 0 AND minimum_coverage <= 1)',
            'ALTER TABLE contractor_scorecard_policy_versions ADD CONSTRAINT contractor_scorecard_policy_sample_check CHECK (minimum_sample_size > 0)',
            "ALTER TABLE contractor_scorecard_policy_versions ADD CONSTRAINT contractor_scorecard_policy_hash_check CHECK (source_hash ~ '^[a-f0-9]{64}$')",
            'ALTER TABLE contractor_scorecard_policy_versions ADD CONSTRAINT contractor_scorecard_policy_interval_check CHECK (effective_to IS NULL OR effective_to > effective_from)',
            "ALTER TABLE contractor_scorecard_snapshots ADD CONSTRAINT contractor_scorecard_snapshot_hash_check CHECK (definition_hash ~ '^[a-f0-9]{64}$' AND source_hash ~ '^[a-f0-9]{64}$' AND source_tuple_hash ~ '^[a-f0-9]{64}$')",
            'ALTER TABLE contractor_scorecard_snapshots ADD CONSTRAINT contractor_scorecard_snapshot_time_check CHECK (stale_at IS NULL OR stale_at >= generated_at)',
            "ALTER TABLE contractor_scorecard_snapshot_sources ADD CONSTRAINT contractor_scorecard_source_hash_check CHECK (source_hash ~ '^[a-f0-9]{64}$')",
            'ALTER TABLE contractor_scorecard_rows ADD CONSTRAINT contractor_scorecard_row_counts_check CHECK (sample_size <= eligible_count)',
            'ALTER TABLE contractor_scorecard_rows ADD CONSTRAINT contractor_scorecard_row_coverage_check CHECK ((eligible_count = 0 AND coverage IS NULL) OR (eligible_count > 0 AND coverage >= 0 AND coverage <= 1))',
        ] as $statement) {
            DB::statement($statement);
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION most_prevent_reporting_mutation_v1()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION 'reporting_fact_is_immutable';
            END;
            $$
            SQL);

        foreach ([
            'contractor_scorecard_policy_versions',
            'contractor_scorecard_snapshots',
            'contractor_scorecard_snapshot_sources',
            'contractor_scorecard_rows',
        ] as $table) {
            DB::statement(
                "CREATE TRIGGER {$table}_append_only BEFORE UPDATE OR DELETE ON {$table} "
                .'FOR EACH ROW EXECUTE FUNCTION most_prevent_reporting_mutation_v1()',
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('contractor_scorecard_rows');
        Schema::dropIfExists('contractor_scorecard_snapshot_sources');
        Schema::dropIfExists('contractor_scorecard_snapshots');
        Schema::dropIfExists('contractor_scorecard_policy_versions');
    }
};
