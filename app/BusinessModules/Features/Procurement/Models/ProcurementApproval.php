<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Models;

use App\BusinessModules\Features\Procurement\Enums\ProcurementApprovalStatusEnum;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ProcurementApproval extends Model
{
    protected $table = 'procurement_approvals';

    protected $fillable = [
        'organization_id',
        'approval_policy_id',
        'approvable_type',
        'approvable_id',
        'reason_code',
        'status',
        'requested_by',
        'approved_by',
        'rejected_by',
        'requested_at',
        'resolved_at',
        'comment',
        'context',
    ];

    protected $casts = [
        'status' => ProcurementApprovalStatusEnum::class,
        'requested_at' => 'datetime',
        'resolved_at' => 'datetime',
        'context' => 'array',
    ];

    protected $attributes = [
        'status' => 'pending',
        'context' => '{}',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function approvalPolicy(): BelongsTo
    {
        return $this->belongsTo(ProcurementApprovalPolicy::class, 'approval_policy_id');
    }

    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }
}
