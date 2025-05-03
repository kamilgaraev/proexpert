<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Атрибуты, которые можно массово назначать.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'legal_name',
        'tax_number',
        'registration_number',
        'phone',
        'email',
        'address',
        'city',
        'postal_code',
        'country',
        'description',
        'logo_path',
        'is_active',
        'subscription_expires_at',
    ];

    /**
     * Атрибуты, которые должны быть приведены к типам.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
        'subscription_expires_at' => 'datetime',
    ];

    /**
     * Получить пользователей, связанных с организацией.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['is_owner', 'is_active', 'settings'])
            ->withTimestamps();
    }

    /**
     * Получить владельцев организации.
     */
    public function owners()
    {
        return $this->users()->wherePivot('is_owner', true);
    }

    /**
     * Получить активных пользователей организации.
     */
    public function activeUsers()
    {
        return $this->users()->wherePivot('is_active', true);
    }

    /**
     * Получить роли, принадлежащие этой организации.
     */
    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    /**
     * Проверить, активна ли подписка организации.
     *
     * @return bool
     */
    public function hasActiveSubscription(): bool
    {
        return $this->is_active && 
            ($this->subscription_expires_at === null || 
            $this->subscription_expires_at->isFuture());
    }
}
