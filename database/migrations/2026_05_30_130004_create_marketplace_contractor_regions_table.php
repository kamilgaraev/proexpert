<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_contractor_regions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('profile_id')->constrained('marketplace_contractor_profiles')->cascadeOnDelete();
            $table->string('country')->default('Россия');
            $table->string('region')->nullable();
            $table->string('city')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestampsTz();

            $table->index(['profile_id', 'is_primary']);
            $table->index(['country', 'region', 'city']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_contractor_regions');
    }
};
