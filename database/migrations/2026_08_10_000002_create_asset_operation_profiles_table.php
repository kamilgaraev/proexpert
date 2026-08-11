<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_operation_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_asset_id')->unique()->constrained('organization_assets')->cascadeOnDelete();
            $table->string('operational_mode', 32)->default('custody');
            $table->boolean('tracks_meter')->default(false);
            $table->boolean('tracks_fuel')->default(false);
            $table->boolean('tracks_production')->default(false);
            $table->boolean('maintenance_enabled')->default(false);
            $table->string('meter_unit', 32)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_operation_profiles');
    }
};
