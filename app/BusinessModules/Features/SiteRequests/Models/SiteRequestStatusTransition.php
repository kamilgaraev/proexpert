<?php

namespace App\BusinessModules\Features\SiteRequests\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Organization;

/**
 * Модель перехода между статусами (workflow)
 */
class SiteRequestStatusTransition extends Model
{
    use HasFactory;

    protected $table = 'site_request_status_transitions';

    protected $fillable = [
        'organization_id',
        'from_status_id',
        'to_status_id',
        'required_permission',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'is_active' => true,
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
     * Исходный статус
     */
    public function fromStatus(): BelongsTo
    {
        return $this->belongsTo(SiteRequestStatus::class, 'from_status_id');
    }

    /**
     * Целевой статус
     */
    public function toStatus(): BelongsTo
    {
        return $this->belongsTo(SiteRequestStatus::class, 'to_status_id');
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
     * Scope для активных переходов
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope для исходного статуса
     */
    public function scopeFromStatus($query, int $statusId)
    {
        return $query->where('from_status_id', $statusId);
    }

    /**
     * Scope для целевого статуса
     */
    public function scopeToStatus($query, int $statusId)
    {
        return $query->where('to_status_id', $statusId);
    }

    // ============================================
    // METHODS
    // ============================================

    /**
     * Проверить, требуется ли разрешение для перехода
     */
    public function requiresPermission(): bool
    {
        return !empty($this->required_permission);
    }
}

