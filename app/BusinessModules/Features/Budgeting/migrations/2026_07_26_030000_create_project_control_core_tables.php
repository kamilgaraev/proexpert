<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_control_baseline_versions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('schedule_id');
            $table->unsignedInteger('version_number');
            $table->timestampTz('approved_at');
            $table->unsignedBigInteger('approved_by');
            $table->char('source_hash', 64);
            $table->jsonb('source_payload');
            $table->timestampsTz();
            $table->unique(['organization_id', 'project_id', 'schedule_id', 'version_number'], 'pc_baseline_version_unique');
        });

        Schema::create('project_control_snapshots', function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('baseline_version_id');
            $table->date('status_date');
            $table->string('wip_version', 128);
            $table->string('progress_watermark', 128);
            $table->string('actual_cost_watermark', 128);
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
            $table->unique(['organization_id', 'query_hash', 'source_hash'], 'pc_snapshot_identity_unique');
        });

        Schema::create('project_control_rows', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->string('snapshot_id', 26);
            $table->string('row_key', 160);
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('task_id');
            $table->string('wbs_code')->nullable();
            $table->unsignedBigInteger('contractor_id')->nullable();
            $table->unsignedBigInteger('cost_center_id')->nullable();
            $table->char('currency', 3);
            $table->bigInteger('bac_minor');
            $table->bigInteger('pv_minor');
            $table->bigInteger('ev_minor');
            $table->bigInteger('ac_minor');
            $table->bigInteger('approved_etc_minor')->nullable();
            $table->bigInteger('sv_minor');
            $table->bigInteger('cv_minor');
            $table->decimal('spi', 20, 8)->nullable();
            $table->decimal('cpi', 20, 8)->nullable();
            $table->bigInteger('eac_minor')->nullable();
            $table->jsonb('payload');
            $table->jsonb('source_refs');
            $table->unique(['organization_id', 'snapshot_id', 'row_key'], 'pc_row_unique');
            $table->index(['organization_id', 'snapshot_id', 'wbs_code', 'task_id', 'currency', 'row_key'], 'pc_row_keyset');
            $table->index(['organization_id', 'snapshot_id', 'contractor_id', 'cost_center_id', 'currency', 'row_key'], 'pc_row_filters');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_control_rows');
        Schema::dropIfExists('project_control_snapshots');
        Schema::dropIfExists('project_control_baseline_versions');
    }
};
