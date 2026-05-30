<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_hiring_offer_work_packages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('offer_id')->constrained('marketplace_hiring_offers')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('marketplace_work_categories')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->decimal('quantity', 15, 3)->nullable();
            $table->string('unit', 32)->nullable();
            $table->decimal('budget_min', 15, 2)->nullable();
            $table->decimal('budget_max', 15, 2)->nullable();
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['offer_id', 'category_id']);
            $table->index('category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_hiring_offer_work_packages');
    }
};
