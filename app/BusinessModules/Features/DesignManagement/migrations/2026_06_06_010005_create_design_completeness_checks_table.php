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
        Schema::create('design_completeness_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->constrained('design_packages')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('status')->default('blocked');
            $table->text('profile_code');
            $table->text('project_stage');
            $table->text('object_type')->nullable();
            $table->timestampTz('checked_at');
            $table->unsignedInteger('blocking_count')->default(0);
            $table->unsignedInteger('warning_count')->default(0);
            $table->jsonb('summary')->default('{}');
            $table->jsonb('results')->default('[]');
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();

            $table->index(['organization_id', 'project_id']);
            $table->index(['package_id', 'checked_at']);
            $table->index(['package_id', 'status']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX design_completeness_checks_summary_gin_idx ON design_completeness_checks USING GIN (summary)');
            DB::statement('CREATE INDEX design_completeness_checks_results_gin_idx ON design_completeness_checks USING GIN (results)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('design_completeness_checks');
    }
};
