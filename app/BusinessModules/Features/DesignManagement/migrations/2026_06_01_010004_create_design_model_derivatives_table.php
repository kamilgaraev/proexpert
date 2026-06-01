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
        Schema::create('design_model_derivatives', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('version_id')->constrained('design_artifact_versions')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('viewer_provider');
            $table->text('derivative_format');
            $table->text('derivative_file_path')->nullable();
            $table->text('status')->default('missing');
            $table->timestampTz('prepared_at')->nullable();
            $table->text('failed_reason')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();

            $table->unique(['version_id', 'viewer_provider', 'derivative_format'], 'design_derivatives_version_provider_format_unique');
            $table->index(['organization_id', 'project_id']);
            $table->index(['version_id', 'viewer_provider', 'derivative_format'], 'design_derivatives_version_provider_format_idx');
            $table->index(['organization_id', 'status']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX design_model_derivatives_metadata_gin_idx ON design_model_derivatives USING GIN (metadata)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('design_model_derivatives');
    }
};
