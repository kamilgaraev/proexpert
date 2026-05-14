<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Models;

use App\BusinessModules\Features\QualityControl\Enums\QualityDefectSeverityEnum;
use App\BusinessModules\Features\QualityControl\Enums\QualityDefectStatusEnum;
use App\Models\Contractor;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $project_id
 * @property int|null $contractor_id
 * @property int|null $created_by
 * @property int|null $assigned_to
 * @property string $defect_number
 * @property string $title
 * @property string|null $description
 * @property QualityDefectSeverityEnum $severity
 * @property QualityDefectStatusEnum $status
 * @property string|null $location_name
 * @property int|null $schedule_task_id
 * @property int|null $construction_journal_entry_id
 * @property int|null $completed_work_id
 * @property Carbon|null $due_date
 * @property bool $inspection_required
 * @property Carbon|null $resolved_at
 * @property Carbon|null $verified_at
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class QualityDefect extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'project_id',
        'contractor_id',
        'created_by',
        'assigned_to',
        'defect_number',
        'title',
        'description',
        'severity',
        'status',
        'location_name',
        'schedule_task_id',
        'construction_journal_entry_id',
        'completed_work_id',
        'due_date',
        'inspection_required',
        'resolved_at',
        'verified_at',
        'metadata',
    ];

    protected $casts = [
        'severity' => QualityDefectSeverityEnum::class,
        'status' => QualityDefectStatusEnum::class,
        'due_date' => 'date',
        'inspection_required' => 'boolean',
        'resolved_at' => 'datetime',
        'verified_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected $attributes = [
        'severity' => 'major',
        'status' => 'draft',
        'inspection_required' => true,
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(QualityDefectPhoto::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(QualityDefectStatusHistory::class);
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeWithStatus(Builder $query, string|QualityDefectStatusEnum $status): Builder
    {
        $value = $status instanceof QualityDefectStatusEnum ? $status->value : $status;

        return $query->where('status', $value);
    }

    public function canBeAssigned(): bool
    {
        return in_array($this->status, [
            QualityDefectStatusEnum::OPEN,
            QualityDefectStatusEnum::REJECTED,
        ], true);
    }

    public function canBeStarted(): bool
    {
        return in_array($this->status, [
            QualityDefectStatusEnum::OPEN,
            QualityDefectStatusEnum::ASSIGNED,
            QualityDefectStatusEnum::REJECTED,
        ], true);
    }

    public function canBeResolved(): bool
    {
        return in_array($this->status, [
            QualityDefectStatusEnum::OPEN,
            QualityDefectStatusEnum::ASSIGNED,
            QualityDefectStatusEnum::IN_PROGRESS,
            QualityDefectStatusEnum::REJECTED,
        ], true);
    }

    public function canBeVerified(): bool
    {
        return $this->status === QualityDefectStatusEnum::READY_FOR_REVIEW;
    }
}
