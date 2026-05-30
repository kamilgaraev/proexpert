<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_hiring_offer_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('offer_id')->constrained('marketplace_hiring_offers')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('reviewer_organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('contractor_organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('contractor_profile_id')->constrained('marketplace_contractor_profiles')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('marketplace_work_categories')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('quality_score', 3, 2);
            $table->decimal('deadline_score', 3, 2);
            $table->decimal('communication_score', 3, 2);
            $table->decimal('safety_score', 3, 2)->nullable();
            $table->decimal('financial_discipline_score', 3, 2)->nullable();
            $table->text('comment')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['offer_id', 'category_id', 'reviewer_organization_id'],
                'marketplace_offer_review_unique'
            );
            $table->index(['contractor_profile_id', 'category_id']);
            $table->index(['reviewer_organization_id', 'contractor_organization_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_hiring_offer_reviews');
    }
};
