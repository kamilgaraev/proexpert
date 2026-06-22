<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\AccessRecertification\Models;

use App\BusinessModules\Core\ImmutableAudit\Models\ImmutableAuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AccessRecertificationDecision extends Model
{
    use HasUuids;

    protected $table = 'access_recertification_decisions';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'campaign_id',
        'item_id',
        'organization_id',
        'reviewer_user_id',
        'decision',
        'reason',
        'valid_until',
        'next_review_at',
        'revoke_reason',
        'revoke_executor_user_id',
        'evidence_notes',
        'compensating_controls',
        'linked_sod_rule_ids',
        'evidence_snapshot',
        'audit_event_id',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'reviewer_user_id' => 'integer',
        'revoke_executor_user_id' => 'integer',
        'valid_until' => 'datetime',
        'next_review_at' => 'datetime',
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

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_user_id');
    }

    public function revokeExecutor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoke_executor_user_id');
    }

    public function auditEvent(): BelongsTo
    {
        return $this->belongsTo(ImmutableAuditEvent::class, 'audit_event_id');
    }
}
