<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\AccessRecertification\Models;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class AccessRecertificationItem extends Model
{
    use HasUuids;

    protected $table = 'access_recertification_items';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'campaign_id',
        'organization_id',
        'reviewer_user_id',
        'subject_user_id',
        'assignment_id',
        'role_slug',
        'role_type',
        'role_context_id',
        'role_context_type',
        'role_context_resource_id',
        'role_context_label',
        'role_label',
        'permission_snapshot',
        'risk_snapshot',
        'evidence_snapshot',
        'assignment_snapshot_hash',
        'risk_level',
        'status',
        'due_at',
        'decided_at',
        'next_review_at',
        'last_reminder_at',
        'correlation_id',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'reviewer_user_id' => 'integer',
        'subject_user_id' => 'integer',
        'assignment_id' => 'integer',
        'role_context_id' => 'integer',
        'role_context_resource_id' => 'integer',
        'permission_snapshot' => 'array',
        'risk_snapshot' => 'array',
        'evidence_snapshot' => 'array',
        'due_at' => 'datetime',
        'decided_at' => 'datetime',
        'next_review_at' => 'datetime',
        'last_reminder_at' => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AccessRecertificationCampaign::class, 'campaign_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_user_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(UserRoleAssignment::class, 'assignment_id');
    }

    public function roleContext(): BelongsTo
    {
        return $this->belongsTo(AuthorizationContext::class, 'role_context_id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(AccessRecertificationDecision::class, 'item_id');
    }

    public function latestDecision(): HasOne
    {
        return $this->hasOne(AccessRecertificationDecision::class, 'item_id')->latestOfMany();
    }

    public function revocation(): HasOne
    {
        return $this->hasOne(AccessRecertificationRevocation::class, 'item_id');
    }

    public function exception(): HasOne
    {
        return $this->hasOne(AccessRecertificationException::class, 'item_id')->latestOfMany();
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
