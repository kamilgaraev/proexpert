<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Enums\UserInvitation\InvitationStatus;
use Illuminate\Support\Str;
use Carbon\Carbon;

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
        'sent_at'    => 'datetime',
        'metadata'   => 'array',
        'status' => InvitationStatus::class,
    ];

    protected $appends = [
        'role_names',
        'status_text',
        'status_color',
        'invitation_url',
    ];

    // Добавляем token при создании
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

    // Связи
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function invitedBy()
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public function acceptedBy()
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id');
    }

    // Методы проверки статуса
    public function isExpired(): bool
    {
        return $this->expires_at->isPast() || $this->status === InvitationStatus::EXPIRED;
    }

    public function canBeAccepted(): bool
    {
        return $this->status === InvitationStatus::PENDING && !$this->isExpired();
    }

    // Методы изменения статуса
    public function markAsExpired(): void
    {
        $this->update(['status' => InvitationStatus::EXPIRED]);
    }

    public function markAsAccepted(User $user): void
    {
        $this->update([
            'status' => InvitationStatus::ACCEPTED,
            'accepted_by_user_id' => $user->id,
            'accepted_at' => now()
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
            'expires_at' => Carbon::now()->addDays(7)
        ]);
    }

    public function getRoleNamesAttribute(): array
    {
        $roleMap = [
            'organization_admin' => 'Администратор организации',
            'foreman' => 'Прораб',
            'web_admin' => 'Веб-администратор',
            'accountant' => 'Бухгалтер',
            'worker' => 'Рабочий',
            'admin' => 'Администратор',
        ];

        return array_map(function ($slug) use ($roleMap) {
            return $roleMap[$slug] ?? $slug;
        }, $this->role_slugs ?? []);
    }

    public function getStatusTextAttribute(): string
    {
        return match ($this->status) {
            InvitationStatus::PENDING => 'Ожидает принятия',
            InvitationStatus::ACCEPTED => 'Принято',
            InvitationStatus::EXPIRED => 'Истекло',
            InvitationStatus::CANCELLED => 'Отменено',
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
