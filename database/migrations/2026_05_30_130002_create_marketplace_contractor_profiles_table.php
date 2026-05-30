<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_contractor_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained('organizations')->cascadeOnDelete();
            $table->string('status', 30)->default('draft');
            $table->string('display_name')->nullable();
            $table->string('short_description', 500)->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('team_size_min')->nullable();
            $table->unsignedInteger('team_size_max')->nullable();
            $table->unsignedInteger('years_on_market')->nullable();
            $table->string('base_city')->nullable();
            $table->unsignedInteger('service_radius_km')->nullable();
            $table->string('availability_status', 40)->default('hidden');
            $table->timestampTz('available_from')->nullable();
            $table->string('verification_level', 40)->default('none');
            $table->boolean('is_visible_in_marketplace')->default(false);
            $table->timestampTz('published_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'is_visible_in_marketplace']);
            $table->index(['base_city', 'availability_status']);
            $table->index('verification_level');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_contractor_profiles');
    }
};
