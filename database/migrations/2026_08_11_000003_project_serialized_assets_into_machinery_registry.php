<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const UNIQUE_INDEX = 'machinery_assets_organization_asset_unique';

    public function up(): void
    {
        DB::table('organization_assets')
            ->where('accounting_mode', 'serialized')
            ->whereNotNull('material_id')
            ->whereNull('deleted_at')
            ->whereNotExists(static fn ($query) => $query
                ->selectRaw('1')
                ->from('machinery_assets')
                ->whereColumn('machinery_assets.organization_asset_id', 'organization_assets.id'))
            ->orderBy('id')
            ->eachById(static function (object $asset): void {
                DB::table('machinery_assets')->insert([
                    'organization_id' => $asset->organization_id,
                    'organization_asset_id' => $asset->id,
                    'machinery_id' => $asset->machinery_id,
                    'current_project_id' => $asset->current_project_id,
                    'current_schedule_task_id' => null,
                    'asset_code' => 'OA-'.$asset->id,
                    'name' => $asset->name,
                    'inventory_number' => $asset->inventory_number,
                    'ownership_type' => $asset->ownership_type,
                    'status' => 'available',
                    'operating_cost_per_hour' => 0,
                    'fuel_type' => null,
                    'fuel_consumption_rate' => null,
                    'meter_hours' => 0,
                    'metadata' => json_encode([
                        'registry_projection' => true,
                        'canonical_source' => 'warehouse_receipt',
                    ], JSON_THROW_ON_ERROR),
                    'archived_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                    'deleted_at' => null,
                ]);
            });

        Schema::table('machinery_assets', static function (Blueprint $table): void {
            $table->unique('organization_asset_id', self::UNIQUE_INDEX);
        });
    }

    public function down(): void
    {
        Schema::table('machinery_assets', static function (Blueprint $table): void {
            $table->dropUnique(self::UNIQUE_INDEX);
        });
    }
};
