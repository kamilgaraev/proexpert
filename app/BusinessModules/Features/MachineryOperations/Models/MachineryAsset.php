<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\MachineryOperations\Models;

use App\Models\Machinery;
use App\Models\Project;
use App\Models\ScheduleTask;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property int|null $machinery_id
 * @property int|null $current_project_id
 * @property int|null $current_schedule_task_id
 * @property string $asset_code
 * @property string $name
 * @property string|null $inventory_number
 * @property string $ownership_type
 * @property string $status
 * @property string $operating_cost_per_hour
 * @property string|null $fuel_type
 * @property string|null $fuel_consumption_rate
 * @property string $meter_hours
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $archived_at
 */
final class MachineryAsset extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'machinery_id',
        'current_project_id',
        'current_schedule_task_id',
        'asset_code',
        'name',
        'inventory_number',
        'ownership_type',
        'status',
        'operating_cost_per_hour',
        'fuel_type',
        'fuel_consumption_rate',
        'meter_hours',
        'metadata',
        'archived_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'archived_at' => 'datetime',
    ];

    public function machinery(): BelongsTo
    {
        return $this->belongsTo(Machinery::class);
    }

    public function currentProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'current_project_id');
    }

    public function currentScheduleTask(): BelongsTo
    {
        return $this->belongsTo(ScheduleTask::class, 'current_schedule_task_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(MachineryAssignment::class, 'asset_id');
    }

    public function shiftReports(): HasMany
    {
        return $this->hasMany(MachineryShiftReport::class, 'asset_id');
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
