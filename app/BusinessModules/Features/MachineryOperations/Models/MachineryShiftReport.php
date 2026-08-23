<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\MachineryOperations\Models;

use App\BusinessModules\Core\AssetManagement\Models\OrganizationAsset;
use App\Models\ConstructionJournalEntry;
use App\Models\Project;
use App\Models\ScheduleTask;
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
        'organization_asset_id',
        'asset_id',
        'project_id',
        'assignment_id',
        'schedule_task_id',
        'construction_journal_entry_id',
        'reported_by_user_id',
        'finished_by_user_id',
        'cancelled_by_user_id',
        'approved_by_user_id',
        'report_date',
        'status',
        'planned_hours',
        'actual_hours',
        'hourly_rate_snapshot',
        'cost_evidence',
        'fuel_consumed',
        'meter_start',
        'meter_end',
        'work_description',
        'finish_evidence',
        'rejection_reason',
        'cancellation_reason',
        'submitted_at',
        'approved_at',
        'rejected_at',
        'started_at',
        'finished_at',
        'cancelled_at',
    ];

    protected $casts = [
        'report_date' => 'date',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'finish_evidence' => 'array',
        'cost_evidence' => 'array',
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

    public function inspections(): HasMany
    {
        return $this->hasMany(MachineryShiftInspection::class, 'shift_report_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(MachineryAssignment::class, 'assignment_id');
    }

    public function scheduleTask(): BelongsTo
    {
        return $this->belongsTo(ScheduleTask::class);
    }

    public function constructionJournalEntry(): BelongsTo
    {
        return $this->belongsTo(ConstructionJournalEntry::class);
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
        return $query->where('organization_id', $organizationId)
            ->when(
                (bool) config('asset_registry.strict_canonical_reads'),
                fn (Builder $query): Builder => $query->whereHas('organizationAsset'),
            );
    }
}
