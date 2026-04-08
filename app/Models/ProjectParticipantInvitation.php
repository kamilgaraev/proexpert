<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProjectOrganizationRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ProjectParticipantInvitation extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'project_id',
        'organization_id',
        'invited_by_user_id',
        'invited_organization_id',
        'accepted_by_user_id',
        'role',
        'token',
        'status',
        'organization_name',
        'inn',
        'email',
        'contact_name',
        'phone',
        'message',
        'metadata',
        'expires_at',
        'accepted_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (ProjectParticipantInvitation $invitation): void {
            if (empty($invitation->token)) {
                $invitation->token = Str::random(64);
            }

            if ($invitation->expires_at === null) {
                $invitation->expires_at = now()->addDays(14);
            }
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
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

    public function scopePending($query)
    {
        return $query
            ->where('status', self::STATUS_PENDING)
            ->where(function ($builder) {
                $builder
                    ->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    public function isPending(): bool
    {
        if ($this->status !== self::STATUS_PENDING) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    public function roleEnum(): ProjectOrganizationRole
    {
        return ProjectOrganizationRole::from($this->role);
    }
}
