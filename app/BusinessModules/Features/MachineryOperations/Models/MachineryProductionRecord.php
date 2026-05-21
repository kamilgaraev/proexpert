<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\MachineryOperations\Models;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MachineryProductionRecord extends Model
{
    protected $fillable = [
        'organization_id',
        'asset_id',
        'project_id',
        'shift_report_id',
        'recorded_by_user_id',
        'recorded_at',
        'quantity',
        'unit',
        'comment',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(MachineryAsset::class, 'asset_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function shiftReport(): BelongsTo
    {
        return $this->belongsTo(MachineryShiftReport::class, 'shift_report_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
