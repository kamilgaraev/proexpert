<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\AssetManagement\Models;

use App\BusinessModules\Core\AssetManagement\Enums\AssetOperationalMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AssetOperationProfile extends Model
{
    protected $fillable = [
        'organization_asset_id',
        'operational_mode',
        'tracks_meter',
        'tracks_fuel',
        'tracks_production',
        'maintenance_enabled',
        'meter_unit',
        'operating_cost_per_hour',
        'fuel_type',
        'fuel_consumption_rate',
        'meter_value',
    ];

    protected $casts = [
        'operational_mode' => AssetOperationalMode::class,
        'tracks_meter' => 'boolean',
        'tracks_fuel' => 'boolean',
        'tracks_production' => 'boolean',
        'maintenance_enabled' => 'boolean',
        'operating_cost_per_hour' => 'decimal:2',
        'fuel_consumption_rate' => 'decimal:3',
        'meter_value' => 'decimal:2',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(OrganizationAsset::class, 'organization_asset_id');
    }
}
