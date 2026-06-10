<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('epm_data_mart_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->index();
            $table->string('report_scope', 64)->index();
            $table->string('scope_hash', 128)->index();
            $table->string('status', 32)->index();
            $table->string('formula_version', 64)->index();
            $table->string('source_hash', 128)->index();
            $table->date('period_start')->nullable()->index();
            $table->date('period_end')->nullable()->index();
            $table->date('as_of_date')->nullable()->index();
            $table->foreignId('project_id')->nullable()->index();
            $table->string('currency', 3)->nullable()->index();
            $table->jsonb('filters');
            $table->jsonb('payload');
            $table->jsonb('freshness');
            $table->jsonb('source_refs');
            $table->timestampTz('generated_at')->index();
            $table->timestampTz('stale_at')->nullable()->index();
            $table->timestampTz('superseded_at')->nullable()->index();
            $table->timestampsTz();

            $table->index(['organization_id', 'report_scope', 'scope_hash', 'generated_at']);
            $table->index(['organization_id', 'report_scope', 'status']);
        });

        Schema::create('epm_data_mart_aggregates', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('snapshot_id')->constrained('epm_data_mart_snapshots')->cascadeOnDelete();
            $table->foreignId('organization_id')->index();
            $table->string('report_scope', 64)->index();
            $table->string('scope_hash', 128)->index();
            $table->string('aggregate_key', 128)->index();
            $table->string('formula_version', 64)->index();
            $table->string('source_hash', 128)->index();
            $table->date('period_start')->nullable()->index();
            $table->date('period_end')->nullable()->index();
            $table->date('as_of_date')->nullable()->index();
            $table->foreignId('project_id')->nullable()->index();
            $table->string('currency', 3)->nullable()->index();
            $table->jsonb('dimensions')->nullable();
            $table->jsonb('metrics');
            $table->jsonb('source_refs');
            $table->timestampTz('generated_at')->index();
            $table->timestampsTz();

            $table->index(['organization_id', 'report_scope', 'aggregate_key']);
            $table->index(['snapshot_id', 'aggregate_key']);
        });

        Schema::create('epm_data_mart_recalculation_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->index();
            $table->string('report_scope', 64)->index();
            $table->string('scope_hash', 128)->index();
            $table->string('active_lock', 128)->nullable();
            $table->string('status', 32)->index();
            $table->string('formula_version', 64)->index();
            $table->string('source_hash', 128)->nullable()->index();
            $table->foreignId('snapshot_id')->nullable()->constrained('epm_data_mart_snapshots')->nullOnDelete();
            $table->jsonb('filters');
            $table->jsonb('source_refs')->nullable();
            $table->jsonb('error_summary')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('queued_at')->nullable()->index();
            $table->timestampTz('started_at')->nullable()->index();
            $table->timestampTz('finished_at')->nullable()->index();
            $table->timestampTz('generated_at')->nullable()->index();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedSmallInteger('attempts_count')->default(0);
            $table->timestampsTz();

            $table->unique(['organization_id', 'report_scope', 'active_lock']);
            $table->index(['organization_id', 'report_scope', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('epm_data_mart_recalculation_runs');
        Schema::dropIfExists('epm_data_mart_aggregates');
        Schema::dropIfExists('epm_data_mart_snapshots');
    }
};
