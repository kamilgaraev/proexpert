<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_contractor_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('profile_id')->constrained('marketplace_contractor_profiles')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('marketplace_work_categories')->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->unsignedInteger('experience_years')->nullable();
            $table->unsignedInteger('team_capacity')->nullable();
            $table->decimal('min_project_budget', 15, 2)->nullable();
            $table->decimal('max_project_budget', 15, 2)->nullable();
            $table->decimal('rating_score', 3, 2)->nullable();
            $table->unsignedInteger('ratings_count')->default(0);
            $table->unsignedInteger('completed_projects_count')->default(0);
            $table->timestampTz('last_completed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['profile_id', 'category_id'], 'marketplace_profile_category_unique');
            $table->index(['category_id', 'is_primary']);
            $table->index(['rating_score', 'ratings_count']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_contractor_categories');
    }
};
