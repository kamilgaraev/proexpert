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
        Schema::create('design_artifact_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('artifact_id')->constrained('design_artifacts')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('title');
            $table->text('version_number');
            $table->text('revision')->nullable();
            $table->text('source_format')->default('ifc');
            $table->text('source_file_path');
            $table->text('source_original_name');
            $table->text('source_mime_type');
            $table->unsignedBigInteger('source_size_bytes');
            $table->date('model_date')->nullable();
            $table->text('status')->default('uploaded');
            $table->boolean('is_current')->default(false);
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();

            $table->unique(['artifact_id', 'version_number']);
            $table->index(['organization_id', 'project_id']);
            $table->index(['artifact_id', 'is_current']);
            $table->index(['organization_id', 'status']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX design_artifact_versions_metadata_gin_idx ON design_artifact_versions USING GIN (metadata)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('design_artifact_versions');
    }
};
