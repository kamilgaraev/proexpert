<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_contractor_ratings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('profile_id')->constrained('marketplace_contractor_profiles')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('marketplace_work_categories')->cascadeOnDelete();
            $table->decimal('score', 3, 2)->nullable();
            $table->decimal('quality_score', 3, 2)->nullable();
            $table->decimal('deadline_score', 3, 2)->nullable();
            $table->decimal('communication_score', 3, 2)->nullable();
            $table->decimal('safety_score', 3, 2)->nullable();
            $table->decimal('financial_discipline_score', 3, 2)->nullable();
            $table->unsignedInteger('reviews_count')->default(0);
            $table->unsignedInteger('completed_offers_count')->default(0);
            $table->unsignedInteger('repeat_hires_count')->default(0);
            $table->timestampTz('last_recalculated_at')->nullable();
            $table->jsonb('source_snapshot')->nullable();
            $table->timestampsTz();

            $table->unique(['profile_id', 'category_id'], 'marketplace_rating_profile_category_unique');
            $table->index(['category_id', 'score']);
            $table->index('last_recalculated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_contractor_ratings');
    }
};
