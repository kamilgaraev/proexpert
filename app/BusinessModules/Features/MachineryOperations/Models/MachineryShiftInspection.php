<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\MachineryOperations\Models;

use App\BusinessModules\Core\AssetManagement\Models\OrganizationAsset;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MachineryShiftInspection extends Model
{
    protected $fillable = [
        'organization_id',
        'organization_asset_id',
        'asset_id',
        'project_id',
        'shift_report_id',
        'inspected_by_user_id',
        'inspection_type',
        'result',
        'notes',
        'evidence',
        'defects',
        'inspected_at',
    ];

    protected $casts = [
        'evidence' => 'array',
        'defects' => 'array',
        'inspected_at' => 'datetime',
    ];

    public function shiftReport(): BelongsTo
    {
        return $this->belongsTo(MachineryShiftReport::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(MachineryAsset::class);
    }

    public function organizationAsset(): BelongsTo
    {
        return $this->belongsTo(OrganizationAsset::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function inspectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by_user_id');
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
