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
        Schema::create('design_packages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('title');
            $table->text('stage')->nullable();
            $table->text('discipline')->nullable();
            $table->text('status')->default('draft');
            $table->date('planned_issue_date')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();

            $table->index(['organization_id', 'project_id']);
            $table->index(['organization_id', 'status']);
            $table->index(['project_id', 'planned_issue_date']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX design_packages_metadata_gin_idx ON design_packages USING GIN (metadata)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('design_packages');
    }
};
