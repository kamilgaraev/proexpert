<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ChangeManagement\Models;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

final class ChangeRequest extends Model
{
    use SoftDeletes;

    protected $table = 'change_management_change_requests';

    protected $fillable = [
        'organization_id',
        'project_id',
        'created_by_user_id',
        'related_rfi_id',
        'change_number',
        'title',
        'reason',
        'description',
        'initiator_type',
        'status',
        'affected_schedule_task_ids',
        'affected_estimate_item_ids',
        'linked_entities',
        'implementation_comment',
        'submitted_at',
        'approved_at',
        'implemented_at',
        'closed_at',
        'rejected_at',
        'cancelled_at',
    ];

    protected $casts = [
        'affected_schedule_task_ids' => 'array',
        'affected_estimate_item_ids' => 'array',
        'linked_entities' => 'array',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'implemented_at' => 'datetime',
        'closed_at' => 'datetime',
        'rejected_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function relatedRfi(): BelongsTo
    {
        return $this->belongsTo(ChangeManagementRfi::class, 'related_rfi_id');
    }

    public function impact(): HasOne
    {
        return $this->hasOne(ChangeImpact::class, 'change_request_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(ChangeApproval::class, 'change_request_id');
    }

    public function variationOrders(): HasMany
    {
        return $this->hasMany(VariationOrder::class, 'change_request_id');
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
