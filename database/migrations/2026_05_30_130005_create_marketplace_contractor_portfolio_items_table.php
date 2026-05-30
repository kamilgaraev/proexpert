<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_contractor_portfolio_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('profile_id')->constrained('marketplace_contractor_profiles')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('marketplace_work_categories')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('city')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->jsonb('media')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['profile_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_contractor_portfolio_items');
    }
};
