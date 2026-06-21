<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class MdmChangeRequest extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_UNDER_REVIEW = 'under_review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'uuid',
        'organization_id',
        'mdm_record_id',
        'entity_type',
        'entity_id',
        'action',
        'status',
        'priority',
        'title',
        'reason',
        'business_justification',
        'current_values',
        'proposed_values',
        'diff',
        'field_policy_version',
        'impact_snapshot',
        'validation_snapshot',
        'one_c_lock_summary',
        'rollback_snapshot',
        'apply_result',
        'failure_reason',
        'requested_by_user_id',
        'owner_user_id',
        'approver_user_id',
        'executor_user_id',
        'cancelled_by_user_id',
        'reviewed_by_user_id',
        'submitted_at',
        'under_review_at',
        'approved_at',
        'rejected_at',
        'applied_at',
        'failed_at',
        'cancelled_at',
        'reviewed_at',
        'review_note',
        'apply_note',
        'cancel_reason',
        'expected_record_version',
        'idempotency_key',
        'payload_hash',
    ];

    protected $casts = [
        'current_values' => 'array',
        'proposed_values' => 'array',
        'diff' => 'array',
        'impact_snapshot' => 'array',
        'validation_snapshot' => 'array',
        'one_c_lock_summary' => 'array',
        'rollback_snapshot' => 'array',
        'apply_result' => 'array',
        'expected_record_version' => 'integer',
        'submitted_at' => 'datetime',
        'under_review_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'applied_at' => 'datetime',
        'failed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(static function (MdmChangeRequest $changeRequest): void {
            if (! $changeRequest->uuid) {
                $changeRequest->uuid = (string) Str::uuid();
            }
        });
    }

    public function mdmRecord(): BelongsTo
    {
        return $this->belongsTo(MdmRecord::class, 'mdm_record_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }

    public function executor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executor_user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(MdmChangeRequestEvent::class, 'change_request_id')->orderBy('created_at');
    }
}
