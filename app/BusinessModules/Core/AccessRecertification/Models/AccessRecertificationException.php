<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\AccessRecertification\Models;

use App\BusinessModules\Core\ImmutableAudit\Models\ImmutableAuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AccessRecertificationException extends Model
{
    use HasUuids;

    protected $table = 'access_recertification_exceptions';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'campaign_id',
        'item_id',
        'decision_id',
        'organization_id',
        'status',
        'requested_by_user_id',
        'approved_by_user_id',
        'rejected_by_user_id',
        'reason',
        'valid_until',
        'approved_at',
        'rejected_at',
        'compensating_controls',
        'linked_sod_rule_ids',
        'evidence_snapshot',
        'audit_event_id',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'requested_by_user_id' => 'integer',
        'approved_by_user_id' => 'integer',
        'rejected_by_user_id' => 'integer',
        'valid_until' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'compensating_controls' => 'array',
        'linked_sod_rule_ids' => 'array',
        'evidence_snapshot' => 'array',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AccessRecertificationCampaign::class, 'campaign_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(AccessRecertificationItem::class, 'item_id');
    }

    public function decision(): BelongsTo
    {
        return $this->belongsTo(AccessRecertificationDecision::class, 'decision_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by_user_id');
    }

    public function auditEvent(): BelongsTo
    {
        return $this->belongsTo(ImmutableAuditEvent::class, 'audit_event_id');
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
