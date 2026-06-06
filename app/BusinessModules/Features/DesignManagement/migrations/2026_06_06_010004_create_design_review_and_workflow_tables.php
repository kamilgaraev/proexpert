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
        Schema::create('design_review_rounds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->constrained('design_packages')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('round_number');
            $table->text('review_type')->default('norm_control');
            $table->text('status')->default('open');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();

            $table->unique(['package_id', 'round_number', 'review_type'], 'design_review_rounds_package_number_type_unique');
            $table->index(['organization_id', 'project_id']);
            $table->index(['package_id', 'status']);
        });

        Schema::create('design_review_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->constrained('design_packages')->cascadeOnDelete();
            $table->foreignId('round_id')->nullable()->constrained('design_review_rounds')->nullOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('design_package_sections')->nullOnDelete();
            $table->foreignId('artifact_id')->nullable()->constrained('design_artifacts')->nullOnDelete();
            $table->foreignId('version_id')->nullable()->constrained('design_artifact_versions')->nullOnDelete();
            $table->foreignId('sheet_id')->nullable()->constrained('design_document_sheets')->nullOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('severity')->default('warning');
            $table->text('status')->default('open');
            $table->text('body');
            $table->text('response')->nullable();
            $table->text('bim_element_id')->nullable();
            $table->date('due_date')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();

            $table->index(['organization_id', 'project_id']);
            $table->index(['package_id', 'status']);
            $table->index(['package_id', 'severity']);
            $table->index(['section_id', 'status']);
            $table->index(['artifact_id', 'version_id']);
        });

        Schema::create('design_workflow_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->constrained('design_packages')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('action');
            $table->text('from_status')->nullable();
            $table->text('to_status')->nullable();
            $table->text('comment')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();

            $table->index(['organization_id', 'project_id']);
            $table->index(['package_id', 'created_at']);
            $table->index(['action', 'to_status']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX design_review_rounds_metadata_gin_idx ON design_review_rounds USING GIN (metadata)');
            DB::statement('CREATE INDEX design_review_comments_metadata_gin_idx ON design_review_comments USING GIN (metadata)');
            DB::statement('CREATE INDEX design_workflow_events_metadata_gin_idx ON design_workflow_events USING GIN (metadata)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('design_workflow_events');
        Schema::dropIfExists('design_review_comments');
        Schema::dropIfExists('design_review_rounds');
    }
};
