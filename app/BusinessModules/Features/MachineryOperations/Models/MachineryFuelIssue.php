<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\MachineryOperations\Models;

use App\BusinessModules\Core\AssetManagement\Models\OrganizationAsset;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MachineryFuelIssue extends Model
{
    protected $fillable = [
        'organization_id',
        'organization_asset_id',
        'asset_id',
        'project_id',
        'shift_report_id',
        'operator_user_id',
        'warehouse_id',
        'material_id',
        'warehouse_movement_id',
        'reversal_movement_id',
        'issued_by_user_id',
        'cancelled_by_user_id',
        'issued_at',
        'cancelled_at',
        'fuel_type',
        'fuel_type_code',
        'fuel_type_original',
        'quantity',
        'unit',
        'unit_code',
        'unit_original',
        'cost',
        'comment',
        'cancellation_reason',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(MachineryAsset::class, 'asset_id');
    }

    public function organizationAsset(): BelongsTo
    {
        return $this->belongsTo(OrganizationAsset::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    public function shiftReport(): BelongsTo
    {
        return $this->belongsTo(MachineryShiftReport::class, 'shift_report_id');
    }

    public function warehouseMovement(): BelongsTo
    {
        return $this->belongsTo(WarehouseMovement::class, 'warehouse_movement_id');
    }

    public function reversalMovement(): BelongsTo
    {
        return $this->belongsTo(WarehouseMovement::class, 'reversal_movement_id');
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId)
            ->when(
                (bool) config('asset_registry.strict_canonical_reads'),
                fn (Builder $query): Builder => $query->whereHas('organizationAsset'),
            );
    }
}
