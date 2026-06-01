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
        Schema::create('design_artifacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->constrained('design_packages')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('artifact_type');
            $table->text('title');
            $table->text('discipline')->nullable();
            $table->text('stage')->nullable();
            $table->text('status')->default('active');
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();

            $table->index(['organization_id', 'project_id']);
            $table->index(['package_id', 'artifact_type']);
            $table->index(['organization_id', 'status']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX design_artifacts_metadata_gin_idx ON design_artifacts USING GIN (metadata)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('design_artifacts');
    }
};
