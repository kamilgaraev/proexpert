<?php

namespace App\BusinessModules\Features\SiteRequests\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Organization;

/**
 * Модель настраиваемого статуса заявки
 */
class SiteRequestStatus extends Model
{
    use HasFactory;

    protected $table = 'site_request_statuses';

    protected $fillable = [
        'organization_id',
        'slug',
        'name',
        'description',
        'color',
        'icon',
        'is_initial',
        'is_final',
        'display_order',
    ];

    protected $casts = [
        'is_initial' => 'boolean',
        'is_final' => 'boolean',
        'display_order' => 'integer',
    ];

    protected $attributes = [
        'is_initial' => false,
        'is_final' => false,
        'display_order' => 0,
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
     * Переходы ИЗ этого статуса
     */
    public function transitionsFrom(): HasMany
    {
        return $this->hasMany(SiteRequestStatusTransition::class, 'from_status_id');
    }

    /**
     * Переходы В этот статус
     */
    public function transitionsTo(): HasMany
    {
        return $this->hasMany(SiteRequestStatusTransition::class, 'to_status_id');
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
     * Scope для начального статуса
     */
    public function scopeInitial($query)
    {
        return $query->where('is_initial', true);
    }

    /**
     * Scope для конечных статусов
     */
    public function scopeFinal($query)
    {
        return $query->where('is_final', true);
    }

    /**
     * Scope для сортировки по порядку отображения
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('display_order');
    }

    // ============================================
    // METHODS
    // ============================================

    /**
     * Получить доступные переходы из этого статуса
     */
    public function getAvailableTransitions(): array
    {
        return $this->transitionsFrom()
            ->where('is_active', true)
            ->with('toStatus')
            ->get()
            ->pluck('toStatus')
            ->toArray();
    }

    /**
     * Проверить возможность перехода в указанный статус
     */
    public function canTransitionTo(int $toStatusId): bool
    {
        return $this->transitionsFrom()
            ->where('to_status_id', $toStatusId)
            ->where('is_active', true)
            ->exists();
    }
}

