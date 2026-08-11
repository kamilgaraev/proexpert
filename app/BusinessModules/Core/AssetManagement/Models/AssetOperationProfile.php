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
    ];

    protected $casts = [
        'operational_mode' => AssetOperationalMode::class,
        'tracks_meter' => 'boolean',
        'tracks_fuel' => 'boolean',
        'tracks_production' => 'boolean',
        'maintenance_enabled' => 'boolean',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(OrganizationAsset::class, 'organization_asset_id');
    }
}
