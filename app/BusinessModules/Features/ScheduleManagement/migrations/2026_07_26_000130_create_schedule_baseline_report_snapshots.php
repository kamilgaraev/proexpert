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
        Schema::create('schedule_baseline_versions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('schedule_id');
            $table->unsignedInteger('version');
            $table->timestampTz('captured_at');
            $table->unsignedBigInteger('captured_by');
            $table->string('critical_path_watermark', 128);
            $table->char('source_hash', 64);
            $table->jsonb('source_payload');
            $table->timestampsTz();
            $table->unique(['organization_id', 'schedule_id', 'version'], 'schedule_baseline_version_unique');
        });

        Schema::create('schedule_baseline_task_rows', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('baseline_version_id');
            $table->unsignedBigInteger('schedule_id');
            $table->unsignedBigInteger('task_id');
            $table->string('wbs_code')->nullable();
            $table->string('task_name');
            $table->date('baseline_start')->nullable();
            $table->date('baseline_end')->nullable();
            $table->integer('baseline_duration_days')->nullable();
            $table->integer('total_float_days');
            $table->integer('free_float_days');
            $table->boolean('is_critical');
            $table->jsonb('dependency_refs');
            $table->unique(['organization_id', 'baseline_version_id', 'task_id'], 'schedule_baseline_task_unique');
        });

        Schema::create('schedule_task_state_versions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('schedule_id');
            $table->unsignedBigInteger('task_id');
            $table->unsignedInteger('version');
            $table->timestampTz('effective_at');
            $table->string('source_kind', 32);
            $table->boolean('is_active');
            $table->string('task_name');
            $table->string('wbs_code')->nullable();
            $table->string('task_type', 64);
            $table->string('status', 64);
            $table->date('planned_start');
            $table->date('planned_end');
            $table->integer('planned_duration_days');
            $table->integer('total_float_days');
            $table->integer('free_float_days');
            $table->boolean('is_critical');
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->unsignedBigInteger('contractor_id')->nullable();
            $table->unsignedBigInteger('zone_id')->nullable();
            $table->char('source_hash', 64);
            $table->timestampsTz();
            $table->unique(['organization_id', 'task_id', 'version'], 'schedule_task_state_version_unique');
            $table->index(
                ['organization_id', 'project_id', 'effective_at', 'task_id', 'version'],
                'schedule_task_state_as_of'
            );
        });
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION schedule_reporting_history_append_only_guard()
RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'schedule_reporting_history_is_append_only' USING ERRCODE = '55000';
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER schedule_task_state_versions_append_only
BEFORE UPDATE OR DELETE ON schedule_task_state_versions
FOR EACH ROW EXECUTE FUNCTION schedule_reporting_history_append_only_guard();

CREATE TRIGGER schedule_baseline_versions_append_only
BEFORE UPDATE OR DELETE ON schedule_baseline_versions
FOR EACH ROW EXECUTE FUNCTION schedule_reporting_history_append_only_guard();

CREATE TRIGGER schedule_baseline_task_rows_append_only
BEFORE UPDATE OR DELETE ON schedule_baseline_task_rows
FOR EACH ROW EXECUTE FUNCTION schedule_reporting_history_append_only_guard();
SQL);

        Schema::create('baseline_schedule_variance_snapshots', function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->unsignedBigInteger('organization_id');
            $table->date('as_of');
            $table->string('formula_version', 64);
            $table->char('definition_hash', 64);
            $table->char('query_hash', 64);
            $table->char('source_hash', 64);
            $table->timestampTz('generated_at');
            $table->timestampTz('stale_at')->nullable();
            $table->jsonb('watermarks');
            $table->jsonb('totals');
            $table->jsonb('source_refs');
            $table->jsonb('row_schema');
            $table->unsignedInteger('row_count');
            $table->timestampsTz();
            $table->unique(['organization_id', 'query_hash', 'source_hash'], 'baseline_variance_snapshot_unique');
        });

        Schema::create('baseline_schedule_variance_rows', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->string('snapshot_id', 26);
            $table->string('row_key', 160);
            $table->unsignedBigInteger('schedule_id');
            $table->unsignedBigInteger('task_id');
            $table->unsignedBigInteger('baseline_version_id')->nullable();
            $table->string('wbs_code')->nullable();
            $table->string('task_name');
            $table->date('planned_start');
            $table->date('planned_end');
            $table->integer('variance_days')->nullable();
            $table->integer('total_float_days');
            $table->boolean('is_critical');
            $table->string('status', 64);
            $table->jsonb('payload');
            $table->jsonb('source_refs');
            $table->unique(['organization_id', 'snapshot_id', 'row_key'], 'baseline_variance_row_unique');
            $table->index(['organization_id', 'snapshot_id', 'variance_days', 'schedule_id', 'task_id'], 'baseline_variance_keyset');
            $table->index(['organization_id', 'snapshot_id', 'planned_start', 'task_id'], 'baseline_variance_start');
            $table->index(['organization_id', 'snapshot_id', 'wbs_code', 'task_id'], 'baseline_variance_wbs');
            $table->index(['organization_id', 'snapshot_id', 'is_critical', 'status', 'task_id'], 'baseline_variance_flags');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('baseline_schedule_variance_rows');
        Schema::dropIfExists('baseline_schedule_variance_snapshots');
        Schema::dropIfExists('schedule_task_state_versions');
        Schema::dropIfExists('schedule_baseline_task_rows');
        Schema::dropIfExists('schedule_baseline_versions');
        DB::statement('DROP FUNCTION IF EXISTS schedule_reporting_history_append_only_guard()');
    }
};
