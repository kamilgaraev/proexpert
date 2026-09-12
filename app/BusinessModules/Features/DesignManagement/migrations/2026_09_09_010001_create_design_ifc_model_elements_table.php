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
        Schema::create('design_ifc_model_elements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('version_id')->constrained('design_artifact_versions')->cascadeOnDelete();
            $table->foreignId('derivative_id')->nullable()->constrained('design_model_derivatives')->nullOnDelete();
            $table->unsignedInteger('express_id');
            $table->string('global_id', 64)->nullable();
            $table->string('category', 120)->nullable();
            $table->text('name')->nullable();
            $table->jsonb('properties')->default('{}');
            $table->jsonb('classifications')->default('{}');
            $table->timestampsTz();
            $table->unique(['version_id', 'express_id'], 'design_ifc_model_elements_version_express_unique');
            $table->index(['organization_id', 'project_id', 'version_id'], 'design_ifc_model_elements_scope_idx');
            $table->index(['version_id', 'global_id'], 'design_ifc_model_elements_global_idx');
        });
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX design_ifc_model_elements_properties_gin_idx ON design_ifc_model_elements USING GIN (properties)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('design_ifc_model_elements');
    }
};
