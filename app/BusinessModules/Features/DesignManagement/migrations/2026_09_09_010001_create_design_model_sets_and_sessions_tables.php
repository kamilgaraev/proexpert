<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('design_model_sets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('title');
            $table->unsignedInteger('revision')->default(1);
            $table->timestampsTz();
            $table->index(['organization_id', 'project_id']);
        });

        Schema::create('design_model_set_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('model_set_id')->constrained('design_model_sets')->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->jsonb('version_ids');
            $table->jsonb('transforms')->default('{}');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['model_set_id', 'revision']);
        });

        Schema::create('design_model_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('model_set_id')->constrained('design_model_sets')->restrictOnDelete();
            $table->foreignId('model_set_revision_id')->constrained('design_model_set_revisions')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('title');
            $table->timestampsTz();
            $table->index(['organization_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('design_model_sessions');
        Schema::dropIfExists('design_model_set_revisions');
        Schema::dropIfExists('design_model_sets');
    }
};
