<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\AccessRecertification\Models;

use App\BusinessModules\Core\ImmutableAudit\Models\ImmutableAuditEvent;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AccessRecertificationRevocation extends Model
{
    use HasUuids;

    protected $table = 'access_recertification_revocations';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'campaign_id',
        'item_id',
        'organization_id',
        'assignment_id',
        'subject_user_id',
        'role_slug',
        'role_type',
        'role_context_id',
        'status',
        'reason',
        'executor_user_id',
        'due_at',
        'completed_at',
        'failure_reason',
        'audit_event_id',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'assignment_id' => 'integer',
        'subject_user_id' => 'integer',
        'role_context_id' => 'integer',
        'executor_user_id' => 'integer',
        'due_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AccessRecertificationCampaign::class, 'campaign_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(AccessRecertificationItem::class, 'item_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(UserRoleAssignment::class, 'assignment_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    public function roleContext(): BelongsTo
    {
        return $this->belongsTo(AuthorizationContext::class, 'role_context_id');
    }

    public function executor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executor_user_id');
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
