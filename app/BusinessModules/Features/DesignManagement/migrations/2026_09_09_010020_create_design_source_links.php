<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('design_source_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_version_id')->constrained('design_artifact_versions')->cascadeOnDelete();
            $table->foreignId('source_sheet_id')->nullable()->constrained('design_document_sheets')->nullOnDelete();
            $table->string('target_type', 80);
            $table->unsignedBigInteger('target_id');
            $table->jsonb('target_snapshot');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['source_version_id', 'source_sheet_id', 'target_type', 'target_id'], 'design_source_links_unique_target');
            $table->index(['organization_id', 'project_id', 'target_type', 'target_id'], 'design_source_links_target_scope');
        });
        Schema::create('design_impact_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('link_id')->constrained('design_source_links')->cascadeOnDelete();
            $table->foreignId('previous_version_id')->constrained('design_artifact_versions')->cascadeOnDelete();
            $table->foreignId('new_version_id')->constrained('design_artifact_versions')->cascadeOnDelete();
            $table->string('status', 20)->default('pending');
            $table->string('decision', 20)->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampsTz();
            $table->unique(['link_id', 'previous_version_id', 'new_version_id'], 'design_impact_reviews_unique_revision');
            $table->index(['organization_id', 'project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('design_impact_reviews');
        Schema::dropIfExists('design_source_links');
    }
};
