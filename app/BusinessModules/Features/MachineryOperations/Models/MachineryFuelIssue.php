<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\MachineryOperations\Models;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MachineryFuelIssue extends Model
{
    protected $fillable = [
        'organization_id',
        'asset_id',
        'project_id',
        'issued_by_user_id',
        'issued_at',
        'fuel_type',
        'quantity',
        'unit',
        'cost',
        'comment',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(MachineryAsset::class, 'asset_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
