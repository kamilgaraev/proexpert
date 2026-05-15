<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\MachineryOperations\Models;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class MachineryShiftReport extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'asset_id',
        'project_id',
        'assignment_id',
        'reported_by_user_id',
        'approved_by_user_id',
        'report_date',
        'status',
        'planned_hours',
        'actual_hours',
        'fuel_consumed',
        'meter_start',
        'meter_end',
        'work_description',
        'rejection_reason',
        'submitted_at',
        'approved_at',
        'rejected_at',
    ];

    protected $casts = [
        'report_date' => 'date',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(MachineryAsset::class, 'asset_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(MachineryAssignment::class, 'assignment_id');
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by_user_id');
    }

    public function downtimes(): HasMany
    {
        return $this->hasMany(MachineryDowntime::class, 'shift_report_id');
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
