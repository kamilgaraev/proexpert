<?php

namespace App\Domain\Authorization\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

/**
 * Модель назначения роли пользователю
 * 
 * @property int $id
 * @property int $user_id
 * @property string $role_slug Слаг роли
 * @property string $role_type Тип роли: system или custom
 * @property int $context_id Контекст назначения
 * @property int|null $assigned_by Кто назначил
 * @property \Carbon\Carbon|null $expires_at Срок действия
 * @property bool $is_active Активность
 */
class UserRoleAssignment extends Model
{
    protected $fillable = [
        'user_id',
        'role_slug',
        'role_type',
        'context_id',
        'assigned_by',
        'expires_at',
        'is_active'
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'is_active' => 'boolean'
    ];

    /**
     * Типы ролей
     */
    const TYPE_SYSTEM = 'system';
    const TYPE_CUSTOM = 'custom';

    /**
     * Пользователь
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Контекст назначения
     */
    public function context(): BelongsTo
    {
        return $this->belongsTo(AuthorizationContext::class, 'context_id');
    }

    /**
     * Кто назначил роль
     */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /**
     * Условия роли (ABAC)
     */
    public function conditions(): HasMany
    {
        return $this->hasMany(RoleCondition::class, 'assignment_id');
    }

    /**
     * Кастомная роль (если role_type = custom)
     */
    public function customRole(): BelongsTo
    {
        return $this->belongsTo(OrganizationCustomRole::class, 'role_slug', 'slug');
    }

    /**
     * Scope: только активные назначения
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            });
    }

    /**
     * Scope: назначения в определенном контексте
     */
    public function scopeInContext(Builder $query, AuthorizationContext $context): Builder
    {
        return $query->where('context_id', $context->id);
    }

    /**
     * Scope: системные роли
     */
    public function scopeSystemRoles(Builder $query): Builder
    {
        return $query->where('role_type', self::TYPE_SYSTEM);
    }

    /**
     * Scope: кастомные роли
     */
    public function scopeCustomRoles(Builder $query): Builder
    {
        return $query->where('role_type', self::TYPE_CUSTOM);
    }

    /**
     * Проверить, действительно ли назначение
     */
    public function isValid(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Назначить роль пользователю
     */
    public static function assignRole(
        User $user, 
        string $roleSlug, 
        AuthorizationContext $context,
        string $roleType = self::TYPE_SYSTEM,
        ?User $assignedBy = null,
        ?\Carbon\Carbon $expiresAt = null
    ): self {
        return static::create([
            'user_id' => $user->id,
            'role_slug' => $roleSlug,
            'role_type' => $roleType,
            'context_id' => $context->id,
            'assigned_by' => $assignedBy?->id,
            'expires_at' => $expiresAt,
            'is_active' => true
        ]);
    }

    /**
     * Отозвать назначение роли
     */
    public function revoke(): bool
    {
        return $this->update(['is_active' => false]);
    }

    /**
     * Продлить срок действия роли
     */
    public function extend(\Carbon\Carbon $newExpiresAt): bool
    {
        return $this->update(['expires_at' => $newExpiresAt]);
    }
}
