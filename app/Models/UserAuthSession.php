<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AuthSessionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserAuthSession extends Model
{
    protected $fillable = [
        'user_id',
        'organization_id',
        'session_uuid',
        'device_fingerprint',
        'device_name',
        'user_agent',
        'ip_address',
        'ip_country',
        'ip_city',
        'risk_score',
        'risk_flags',
        'status',
        'is_trusted',
        'first_seen_at',
        'last_seen_at',
        'revoked_at',
        'revoked_reason',
    ];

    protected function casts(): array
    {
        return [
            'risk_score' => 'integer',
            'risk_flags' => 'array',
            'status' => AuthSessionStatus::class,
            'is_trusted' => 'boolean',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function securityEvents(): HasMany
    {
        return $this->hasMany(UserSecurityEvent::class, 'auth_session_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', AuthSessionStatus::Active->value);
    }

    public function isActive(): bool
    {
        return $this->status === AuthSessionStatus::Active && $this->revoked_at === null;
    }
}
