<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserInvitation\InvitationStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property Carbon|null $expires_at
 * @property string|null $token
 */
class UserInvitation extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'invited_by_user_id',
        'user_id',
        'accepted_by_user_id',
        'email',
        'name',
        'role_slugs',
        'token',
        'expires_at',
        'accepted_at',
        'plain_password',
        'status',
        'sent_at',
        'metadata',
    ];

    protected $casts = [
        'role_slugs' => 'array',
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
        'sent_at' => 'datetime',
        'metadata' => 'array',
        'status' => InvitationStatus::class,
    ];

    protected $appends = [
        'role_names',
        'status_text',
        'status_color',
        'invitation_url',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($invitation) {
            if (!$invitation->token) {
                $invitation->token = Str::random(64);
            }

            if (!$invitation->expires_at) {
                $invitation->expires_at = Carbon::now()->addDays(7);
            }

            if (!$invitation->status) {
                $invitation->status = InvitationStatus::PENDING;
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast() || $this->status === InvitationStatus::EXPIRED;
    }

    public function canBeAccepted(): bool
    {
        return $this->status === InvitationStatus::PENDING && !$this->isExpired();
    }

    public function markAsExpired(): void
    {
        $this->update(['status' => InvitationStatus::EXPIRED]);
    }

    public function markAsAccepted(User $user): void
    {
        $this->update([
            'status' => InvitationStatus::ACCEPTED,
            'accepted_by_user_id' => $user->id,
            'accepted_at' => now(),
        ]);
    }

    public function markAsCancelled(): void
    {
        $this->update(['status' => InvitationStatus::CANCELLED]);
    }

    public function regenerateToken(): void
    {
        $this->update([
            'token' => Str::random(64),
            'expires_at' => Carbon::now()->addDays(7),
        ]);
    }

    public function getRoleNamesAttribute(): array
    {
        $roleMap = [
            'organization_admin' => trans_message('user_invitations.roles.organization_admin'),
            'foreman' => trans_message('user_invitations.roles.foreman'),
            'web_admin' => trans_message('user_invitations.roles.web_admin'),
            'accountant' => trans_message('user_invitations.roles.accountant'),
            'worker' => trans_message('user_invitations.roles.worker'),
            'admin' => trans_message('user_invitations.roles.admin'),
        ];

        return array_map(function ($slug) use ($roleMap) {
            return $roleMap[$slug] ?? $slug;
        }, $this->role_slugs ?? []);
    }

    public function getStatusTextAttribute(): string
    {
        return match ($this->status) {
            InvitationStatus::PENDING => trans_message('user_invitations.statuses.pending'),
            InvitationStatus::ACCEPTED => trans_message('user_invitations.statuses.accepted'),
            InvitationStatus::EXPIRED => trans_message('user_invitations.statuses.expired'),
            InvitationStatus::CANCELLED => trans_message('user_invitations.statuses.cancelled'),
        };
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            InvitationStatus::PENDING => 'warning',
            InvitationStatus::ACCEPTED => 'success',
            InvitationStatus::EXPIRED => 'error',
            InvitationStatus::CANCELLED => 'default',
        };
    }

    public function getInvitationUrlAttribute(): string
    {
        $baseUrl = config('app.url');

        return "{$baseUrl}/invitation/{$this->token}";
    }
}
