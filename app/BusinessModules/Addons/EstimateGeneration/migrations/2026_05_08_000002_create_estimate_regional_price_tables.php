<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estimate_regions', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->unsignedBigInteger('fgiscs_subject_id')->unique();
            $table->boolean('is_supported')->default(false);
            $table->timestamps();
        });

        Schema::create('estimate_price_zones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('estimate_region_id')->constrained('estimate_regions')->cascadeOnDelete();
            $table->string('name');
            $table->unsignedBigInteger('fgiscs_price_zone_id')->unique();
            $table->timestamps();
            $table->unique(['estimate_region_id', 'name']);
        });

        Schema::create('estimate_price_periods', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('fgiscs_period_id')->unique();
            $table->string('name');
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('quarter');
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->timestamps();
            $table->unique(['year', 'quarter']);
        });

        Schema::create('estimate_regional_price_versions', function (Blueprint $table): void {
            $table->id();
            $table->string('source');
            $table->foreignId('region_id')->constrained('estimate_regions')->restrictOnDelete();
            $table->foreignId('price_zone_id')->constrained('estimate_price_zones')->restrictOnDelete();
            $table->foreignId('period_id')->constrained('estimate_price_periods')->restrictOnDelete();
            $table->string('version_key');
            $table->string('status');
            $table->unsignedInteger('files_count')->default(0);
            $table->unsignedInteger('rows_read')->default(0);
            $table->unsignedInteger('rows_imported')->default(0);
            $table->unsignedInteger('errors_count')->default(0);
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('superseded_at')->nullable();
            $table->timestampTz('rolled_back_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->unique(['source', 'region_id', 'price_zone_id', 'period_id', 'version_key'], 'estimate_regional_versions_unique');
            $table->index(['region_id', 'price_zone_id', 'status']);
        });

        Schema::create('estimate_regional_price_activations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('region_id')->constrained('estimate_regions')->restrictOnDelete();
            $table->foreignId('price_zone_id')->constrained('estimate_price_zones')->restrictOnDelete();
            $table->foreignId('active_version_id')->constrained('estimate_regional_price_versions')->restrictOnDelete();
            $table->foreignId('previous_version_id')->nullable()->constrained('estimate_regional_price_versions')->nullOnDelete();
            $table->timestampTz('activated_at');
            $table->string('activation_reason');
            $table->timestamps();
            $table->unique(['region_id', 'price_zone_id']);
        });

        Schema::table('estimate_resource_prices', function (Blueprint $table): void {
            $table->foreignId('regional_price_version_id')->nullable()->after('dataset_version_id')->constrained('estimate_regional_price_versions')->nullOnDelete();
            $table->foreignId('region_id')->nullable()->after('regional_price_version_id')->constrained('estimate_regions')->nullOnDelete();
            $table->foreignId('price_zone_id')->nullable()->after('region_id')->constrained('estimate_price_zones')->nullOnDelete();
            $table->foreignId('period_id')->nullable()->after('price_zone_id')->constrained('estimate_price_periods')->nullOnDelete();
            $table->index(['resource_code', 'regional_price_version_id'], 'estimate_resource_prices_regional_code_idx');
        });

        Schema::table('estimates', function (Blueprint $table): void {
            $table->foreignId('estimate_region_id')->nullable()->after('calculation_method')->constrained('estimate_regions')->nullOnDelete();
            $table->foreignId('estimate_price_zone_id')->nullable()->after('estimate_region_id')->constrained('estimate_price_zones')->nullOnDelete();
            $table->foreignId('estimate_price_period_id')->nullable()->after('estimate_price_zone_id')->constrained('estimate_price_periods')->nullOnDelete();
            $table->foreignId('estimate_regional_price_version_id')->nullable()->after('estimate_price_period_id')->constrained('estimate_regional_price_versions')->nullOnDelete();
            $table->jsonb('regional_price_snapshot')->nullable()->after('estimate_regional_price_version_id');
        });
    }

    public function down(): void
    {
        Schema::table('estimates', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('estimate_regional_price_version_id');
            $table->dropConstrainedForeignId('estimate_price_period_id');
            $table->dropConstrainedForeignId('estimate_price_zone_id');
            $table->dropConstrainedForeignId('estimate_region_id');
            $table->dropColumn('regional_price_snapshot');
        });

        Schema::table('estimate_resource_prices', function (Blueprint $table): void {
            $table->dropIndex('estimate_resource_prices_regional_code_idx');
            $table->dropConstrainedForeignId('period_id');
            $table->dropConstrainedForeignId('price_zone_id');
            $table->dropConstrainedForeignId('region_id');
            $table->dropConstrainedForeignId('regional_price_version_id');
        });

        Schema::dropIfExists('estimate_regional_price_activations');
        Schema::dropIfExists('estimate_regional_price_versions');
        Schema::dropIfExists('estimate_price_periods');
        Schema::dropIfExists('estimate_price_zones');
        Schema::dropIfExists('estimate_regions');
    }
};
