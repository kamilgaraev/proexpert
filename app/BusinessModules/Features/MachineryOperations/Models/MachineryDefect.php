<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\MachineryOperations\Models;

use App\BusinessModules\Core\AssetManagement\Models\OrganizationAsset;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MachineryDefect extends Model
{
    protected $fillable = [
        'organization_id', 'organization_asset_id', 'asset_id', 'project_id',
        'reported_by_user_id', 'defect_code', 'severity', 'status', 'description',
        'reported_at', 'resolved_at',
    ];

    protected $casts = ['reported_at' => 'datetime', 'resolved_at' => 'datetime'];

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

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by_user_id');
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
