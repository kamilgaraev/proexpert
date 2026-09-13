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
        Schema::create('bim_construction_progress_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('version_id')->constrained('design_artifact_versions')->cascadeOnDelete();
            $table->foreignId('schedule_task_id')->nullable()->constrained('schedule_tasks')->nullOnDelete();
            $table->string('title', 255)->nullable();
            $table->string('floor', 120)->nullable();
            $table->string('zone', 120)->nullable();
            $table->string('work_kind', 120)->nullable();
            $table->string('status', 20)->default('active');
            $table->unsignedInteger('revision')->default(1);
            $table->string('idempotency_key', 120)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('ended_at')->nullable();
            $table->text('end_reason')->nullable();
            $table->timestampsTz();
            $table->index(['organization_id', 'project_id', 'version_id', 'status'], 'bim_progress_group_scope_idx');
        });
        Schema::table('bim_construction_progress_groups', function (Blueprint $table): void {
            $table->unique(['version_id', 'idempotency_key'], 'bim_progress_group_idempotency_unique');
        });

        Schema::create('bim_construction_progress_group_elements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('group_id')->constrained('bim_construction_progress_groups')->cascadeOnDelete();
            $table->foreignId('version_id')->constrained('design_artifact_versions')->cascadeOnDelete();
            $table->foreignId('schedule_task_id')->nullable()->constrained('schedule_tasks')->nullOnDelete();
            $table->unsignedInteger('element_id');
            $table->string('status', 20)->default('active');
            $table->timestampTz('ended_at')->nullable();
            $table->timestampsTz();
            $table->index(['group_id', 'status'], 'bim_progress_group_element_group_idx');
        });
        DB::statement("CREATE UNIQUE INDEX bim_progress_group_element_active_unique ON bim_construction_progress_group_elements (version_id, schedule_task_id, element_id) WHERE status = 'active'");
        Schema::create('bim_construction_progress_group_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('group_id')->constrained('bim_construction_progress_groups')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('version_id')->constrained('design_artifact_versions')->cascadeOnDelete();
            $table->string('action', 20);
            $table->unsignedInteger('revision');
            $table->jsonb('snapshot');
            $table->text('reason')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['group_id', 'id'], 'bim_progress_group_history_group_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bim_construction_progress_group_history');
        Schema::dropIfExists('bim_construction_progress_group_elements');
        Schema::dropIfExists('bim_construction_progress_groups');
    }
};
