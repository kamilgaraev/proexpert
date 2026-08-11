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
        Schema::table('asset_operation_profiles', function (Blueprint $table): void {
            $table->decimal('operating_cost_per_hour', 15, 2)->default(0);
            $table->string('fuel_type', 80)->nullable();
            $table->decimal('fuel_consumption_rate', 12, 3)->nullable();
            $table->decimal('meter_value', 12, 2)->default(0);
        });

        DB::statement(<<<'SQL'
            UPDATE asset_operation_profiles AS profile
            SET operating_cost_per_hour = legacy.operating_cost_per_hour,
                fuel_type = legacy.fuel_type,
                fuel_consumption_rate = legacy.fuel_consumption_rate,
                meter_value = legacy.meter_hours,
                updated_at = CURRENT_TIMESTAMP
            FROM machinery_assets AS legacy
            WHERE legacy.organization_asset_id = profile.organization_asset_id
            SQL);

        DB::statement(<<<'SQL'
            UPDATE organization_assets AS canonical
            SET metadata = COALESCE(legacy.metadata::jsonb, '{}'::jsonb)
                    || COALESCE(canonical.metadata, '{}'::jsonb),
                updated_at = CURRENT_TIMESTAMP
            FROM machinery_assets AS legacy
            WHERE legacy.organization_asset_id = canonical.id
              AND legacy.metadata IS NOT NULL
            SQL);
    }

    public function down(): void
    {
        Schema::table('asset_operation_profiles', function (Blueprint $table): void {
            $table->dropColumn([
                'operating_cost_per_hour',
                'fuel_type',
                'fuel_consumption_rate',
                'meter_value',
            ]);
        });
    }
};
