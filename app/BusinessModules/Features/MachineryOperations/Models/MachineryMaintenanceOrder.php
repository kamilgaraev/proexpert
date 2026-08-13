<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\MachineryOperations\Models;

use App\BusinessModules\Core\AssetManagement\Models\OrganizationAsset;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

final class MachineryMaintenanceOrder extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'organization_asset_id',
        'asset_id',
        'project_id',
        'requested_by_user_id',
        'completed_by_user_id',
        'order_number',
        'title',
        'maintenance_type',
        'priority',
        'status',
        'description',
        'planned_at',
        'completed_at',
        'cost',
        'completion_comment',
    ];

    protected $casts = [
        'planned_at' => 'datetime',
        'completed_at' => 'datetime',
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

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function inspection(): HasOne
    {
        return $this->hasOne(MaintenanceInspection::class, 'maintenance_order_id');
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
