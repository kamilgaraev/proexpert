<?php

namespace App\BusinessModules\Features\SiteRequests\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestTypeEnum;
use App\Models\Organization;
use App\Models\User;

/**
 * Модель шаблона заявки
 */
class SiteRequestTemplate extends Model
{
    use HasFactory;

    protected $table = 'site_request_templates';

    protected $fillable = [
        'organization_id',
        'user_id',
        'name',
        'description',
        'request_type',
        'template_data',
        'is_active',
        'usage_count',
    ];

    protected $casts = [
        'request_type' => SiteRequestTypeEnum::class,
        'template_data' => 'array',
        'is_active' => 'boolean',
        'usage_count' => 'integer',
    ];

    protected $attributes = [
        'is_active' => true,
        'usage_count' => 0,
    ];

    // ============================================
    // RELATIONSHIPS
    // ============================================

    /**
     * Организация
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Пользователь-создатель
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Заявки, созданные из этого шаблона
     */
    public function siteRequests(): HasMany
    {
        return $this->hasMany(SiteRequest::class, 'template_id');
    }

    // ============================================
    // SCOPES
    // ============================================

    /**
     * Scope для организации
     */
    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Scope для пользователя
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope для типа заявки
     */
    public function scopeOfType($query, string|SiteRequestTypeEnum $type)
    {
        $value = $type instanceof SiteRequestTypeEnum ? $type->value : $type;
        return $query->where('request_type', $value);
    }

    /**
     * Scope для активных шаблонов
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope для сортировки по популярности
     */
    public function scopePopular($query)
    {
        return $query->orderBy('usage_count', 'desc');
    }

    // ============================================
    // METHODS
    // ============================================

    /**
     * Увеличить счетчик использования
     */
    public function incrementUsage(): void
    {
        $this->increment('usage_count');
    }

    /**
     * Получить данные для создания заявки
     */
    public function getRequestData(): array
    {
        return $this->template_data ?? [];
    }

    /**
     * Получить название типа
     */
    public function getTypeNameAttribute(): string
    {
        return $this->request_type->label();
    }
}

