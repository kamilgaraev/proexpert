<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\MachineryOperations\Models;

use App\BusinessModules\Core\AssetManagement\Models\OrganizationAsset;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MaintenanceInspection extends Model
{
    protected $fillable = [
        'organization_id', 'maintenance_order_id', 'organization_asset_id',
        'asset_id', 'inspected_by_user_id', 'result', 'notes', 'evidence', 'inspected_at',
    ];

    protected $casts = ['evidence' => 'array', 'inspected_at' => 'datetime'];

    public function maintenanceOrder(): BelongsTo
    {
        return $this->belongsTo(MachineryMaintenanceOrder::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(MachineryAsset::class, 'asset_id');
    }

    public function organizationAsset(): BelongsTo
    {
        return $this->belongsTo(OrganizationAsset::class);
    }

    public function inspectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by_user_id');
    }
}
