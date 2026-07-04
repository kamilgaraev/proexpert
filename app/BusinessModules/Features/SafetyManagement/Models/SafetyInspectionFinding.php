<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Models;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class SafetyInspectionFinding extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'project_id',
        'inspection_id',
        'inspection_item_id',
        'assigned_to_user_id',
        'created_by_user_id',
        'resolved_by_user_id',
        'corrective_action_id',
        'finding_number',
        'title',
        'description',
        'severity',
        'status',
        'due_date',
        'resolution_comment',
        'resolved_at',
        'evidence_files',
        'metadata',
    ];

    protected $casts = [
        'due_date' => 'date',
        'resolved_at' => 'datetime',
        'evidence_files' => 'array',
        'metadata' => 'array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(SafetyInspection::class, 'inspection_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(SafetyInspectionItem::class, 'inspection_item_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function correctiveAction(): BelongsTo
    {
        return $this->belongsTo(SafetyCorrectiveAction::class, 'corrective_action_id');
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
