<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\OrganizationBalance;

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
        'is_verified',
        'verified_at',
        'verification_data',
        'verification_status',
        'verification_notes',
        'parent_organization_id',
        'organization_type',
        'is_holding',
        'multi_org_settings',
        'hierarchy_level',
        'hierarchy_path',
        's3_bucket',
        'storage_used_mb',
        'storage_usage_synced_at',
    ];

    /**
     * Атрибуты, которые должны быть приведены к типам.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
        'subscription_expires_at' => 'datetime',
        'is_verified' => 'boolean',
        'verified_at' => 'datetime',
        'verification_data' => 'array',
        'multi_org_settings' => 'array',
        'is_holding' => 'boolean',
        'hierarchy_level' => 'integer',
        'storage_used_mb' => 'integer',
        'storage_usage_synced_at' => 'datetime',
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
     * Получить баланс организации.
     */
    public function balance(): HasOne
    {
        return $this->hasOne(OrganizationBalance::class, 'organization_id');
    }

    /**
     * Получить родительскую организацию.
     */
    public function parentOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'parent_organization_id');
    }

    /**
     * Получить дочерние организации.
     */
    public function childOrganizations(): HasMany
    {
        return $this->hasMany(Organization::class, 'parent_organization_id');
    }

    /**
     * Получить группу организации.
     */
    public function organizationGroup(): HasOne
    {
        return $this->hasOne(OrganizationGroup::class, 'parent_organization_id');
    }

    /**
     * Получить разрешения доступа, предоставленные этой организации.
     */
    public function accessPermissionsGranted(): HasMany
    {
        return $this->hasMany(OrganizationAccessPermission::class, 'granted_to_organization_id');
    }

    /**
     * Получить разрешения доступа, полученные этой организацией.
     */
    public function accessPermissionsReceived(): HasMany
    {
        return $this->hasMany(OrganizationAccessPermission::class, 'target_organization_id');
    }

    /**
     * Получить проекты, связанные с организацией.
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * Получить контракты, связанные с организацией.
     */
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
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

    /**
     * Получить текстовое представление статуса верификации.
     *
     * @return string
     */
    public function getVerificationStatusTextAttribute(): string
    {
        return match($this->verification_status) {
            'verified' => 'Верифицирована',
            'partially_verified' => 'Частично верифицирована',
            'needs_review' => 'Требует проверки',
            'failed' => 'Верификация не пройдена',
            'pending' => 'Ожидает верификации',
            default => 'Неизвестный статус'
        };
    }

    /**
     * Проверить, может ли организация пройти автоматическую верификацию.
     *
     * @return bool
     */
    public function canBeVerified(): bool
    {
        return !empty($this->tax_number) && !empty($this->address);
    }

    /**
     * Получить оценку верификации из данных верификации.
     *
     * @return int
     */
    public function getVerificationScoreAttribute(): int
    {
        if (!$this->verification_data || !is_array($this->verification_data)) {
            // Если верификация не проводилась, рассчитываем базовый рейтинг
            return app(\App\Services\OrganizationVerificationService::class)->calculateBasicScore($this);
        }

        return $this->verification_data['score'] ?? 0;
    }
}
