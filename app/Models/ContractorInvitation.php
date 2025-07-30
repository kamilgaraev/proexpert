<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use Carbon\Carbon;

class ContractorInvitation extends Model
{
    use HasFactory;

    const STATUS_PENDING = 'pending';
    const STATUS_ACCEPTED = 'accepted';
    const STATUS_DECLINED = 'declined';
    const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'organization_id',
        'invited_organization_id',
        'invited_by_user_id',
        'token',
        'status',
        'expires_at',
        'accepted_at',
        'accepted_by_user_id',
        'invitation_message',
        'metadata',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($invitation) {
            if (empty($invitation->token)) {
                $invitation->token = Str::random(64);
            }
            if (empty($invitation->expires_at)) {
                $invitation->expires_at = now()->addDays(7);
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function invitedOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'invited_organization_id');
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id');
    }

    public function contractor(): HasOne
    {
        return $this->hasOne(Contractor::class, 'contractor_invitation_id');
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING)
                    ->where('expires_at', '>', now());
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_ACCEPTED])
                    ->where('expires_at', '>', now());
    }

    public function scopeExpired($query)
    {
        return $query->where(function($q) {
            $q->where('expires_at', '<=', now())
              ->orWhere('status', self::STATUS_EXPIRED);
        });
    }

    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeToOrganization($query, int $organizationId)
    {
        return $query->where('invited_organization_id', $organizationId);
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast() || $this->status === self::STATUS_EXPIRED;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING && !$this->isExpired();
    }

    public function canBeAccepted(): bool
    {
        return $this->isPending();
    }

    public function accept(User $acceptedBy): bool
    {
        if (!$this->canBeAccepted()) {
            return false;
        }

        $this->update([
            'status' => self::STATUS_ACCEPTED,
            'accepted_at' => now(),
            'accepted_by_user_id' => $acceptedBy->id,
        ]);

        return true;
    }

    public function decline(): bool
    {
        if (!$this->isPending()) {
            return false;
        }

        $this->update(['status' => self::STATUS_DECLINED]);
        return true;
    }

    public function markAsExpired(): bool
    {
        $this->update(['status' => self::STATUS_EXPIRED]);
        return true;
    }

    public function getInvitationUrl(): string
    {
        return config('app.frontend_url') . '/contractor-invitations/' . $this->token;
    }
}