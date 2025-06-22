<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Carbon\Carbon;

class UserInvitation extends Model
{
    use HasFactory;

    const STATUS_PENDING = 'pending';
    const STATUS_ACCEPTED = 'accepted';
    const STATUS_EXPIRED = 'expired';
    const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'organization_id',
        'invited_by_user_id',
        'email',
        'name',
        'role_slugs',
        'token',
        'expires_at',
        'accepted_at',
        'accepted_by_user_id',
        'status',
        'metadata',
    ];

    protected $casts = [
        'role_slugs' => 'array',
        'metadata' => 'array',
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($invitation) {
            if (empty($invitation->token)) {
                $invitation->token = Str::random(64);
            }
            if (empty($invitation->expires_at)) {
                $invitation->expires_at = Carbon::now()->addDays(7);
            }
        });
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
        return $this->expires_at->isPast();
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING && !$this->isExpired();
    }

    public function canBeAccepted(): bool
    {
        return $this->isPending();
    }

    public function markAsAccepted(User $user): void
    {
        $this->update([
            'status' => self::STATUS_ACCEPTED,
            'accepted_at' => now(),
            'accepted_by_user_id' => $user->id,
        ]);
    }

    public function markAsExpired(): void
    {
        $this->update([
            'status' => self::STATUS_EXPIRED,
        ]);
    }

    public function markAsCancelled(): void
    {
        $this->update([
            'status' => self::STATUS_CANCELLED,
        ]);
    }

    public function regenerateToken(): string
    {
        $newToken = Str::random(64);
        $this->update([
            'token' => $newToken,
            'expires_at' => Carbon::now()->addDays(7),
            'status' => self::STATUS_PENDING,
        ]);
        return $newToken;
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING)
                    ->where('expires_at', '>', now());
    }

    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeByEmail($query, string $email)
    {
        return $query->where('email', $email);
    }

    public function getInvitationUrlAttribute(): string
    {
        return config('app.frontend_url') . '/invite/' . $this->token;
    }

    public function getRoleNamesAttribute(): array
    {
        $roleNames = [];
        foreach ($this->role_slugs as $slug) {
            switch ($slug) {
                case 'organization_admin':
                    $roleNames[] = 'Администратор';
                    break;
                case 'foreman':
                    $roleNames[] = 'Прораб';
                    break;
                case 'web_admin':
                    $roleNames[] = 'Веб-администратор';
                    break;
                case 'accountant':
                    $roleNames[] = 'Бухгалтер';
                    break;
                default:
                    $orgRole = OrganizationRole::where('organization_id', $this->organization_id)
                        ->where('slug', $slug)
                        ->first();
                    if ($orgRole) {
                        $roleNames[] = $orgRole->name;
                    } else {
                        $roleNames[] = ucfirst($slug);
                    }
            }
        }
        return $roleNames;
    }

    public function getStatusTextAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => $this->isExpired() ? 'Истекло' : 'Ожидает',
            self::STATUS_ACCEPTED => 'Принято',
            self::STATUS_EXPIRED => 'Истекло',
            self::STATUS_CANCELLED => 'Отменено',
            default => 'Неизвестно',
        };
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => $this->isExpired() ? 'red' : 'yellow',
            self::STATUS_ACCEPTED => 'green',
            self::STATUS_EXPIRED => 'red',
            self::STATUS_CANCELLED => 'gray',
            default => 'gray',
        };
    }
}
