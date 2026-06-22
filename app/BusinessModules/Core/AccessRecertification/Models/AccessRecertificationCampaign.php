<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\AccessRecertification\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class AccessRecertificationCampaign extends Model
{
    use HasUuids;

    protected $table = 'access_recertification_campaigns';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'organization_id',
        'name',
        'description',
        'type',
        'status',
        'risk_mode',
        'scope',
        'owner_user_id',
        'escalation_user_id',
        'starts_at',
        'due_at',
        'closed_at',
        'created_by_user_id',
        'launched_by_user_id',
        'completed_by_user_id',
        'snapshot_hash',
        'correlation_id',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'owner_user_id' => 'integer',
        'escalation_user_id' => 'integer',
        'created_by_user_id' => 'integer',
        'launched_by_user_id' => 'integer',
        'completed_by_user_id' => 'integer',
        'scope' => 'array',
        'starts_at' => 'datetime',
        'due_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function escalationUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'escalation_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(AccessRecertificationItem::class, 'campaign_id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(AccessRecertificationDecision::class, 'campaign_id');
    }

    public function revocations(): HasMany
    {
        return $this->hasMany(AccessRecertificationRevocation::class, 'campaign_id');
    }

    public function exceptions(): HasMany
    {
        return $this->hasMany(AccessRecertificationException::class, 'campaign_id');
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
