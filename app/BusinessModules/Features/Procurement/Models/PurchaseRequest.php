<?php

namespace App\BusinessModules\Features\Procurement\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\BusinessModules\Features\Procurement\Enums\PurchaseRequestStatusEnum;
use App\Models\Organization;
use App\Models\User;

/**
 * Модель заявки на закупку
 * 
 * Связь с SiteRequest (заявка с объекта)
 */
class PurchaseRequest extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'purchase_requests';

    protected $fillable = [
        'organization_id',
        'site_request_id',
        'assigned_to',
        'request_number',
        'status',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'status' => PurchaseRequestStatusEnum::class,
        'metadata' => 'array',
    ];

    protected $attributes = [
        'status' => 'draft',
    ];

    // ============================================
    // RELATIONSHIPS
    // ============================================

    /**
     * Организация-владелец
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Заявка с объекта (SiteRequest)
     */
    public function siteRequest(): BelongsTo
    {
        return $this->belongsTo(\App\BusinessModules\Features\SiteRequests\Models\SiteRequest::class, 'site_request_id');
    }

    /**
     * Исполнитель заявки
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Заказы поставщикам, созданные из этой заявки
     */
    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
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
     * Scope для статуса
     */
    public function scopeWithStatus($query, string|PurchaseRequestStatusEnum $status)
    {
        $value = $status instanceof PurchaseRequestStatusEnum ? $status->value : $status;
        return $query->where('status', $value);
    }

    /**
     * Scope для активных заявок (не завершенных)
     */
    public function scopeActive($query)
    {
        return $query->whereNotIn('status', [
            PurchaseRequestStatusEnum::APPROVED->value,
            PurchaseRequestStatusEnum::REJECTED->value,
            PurchaseRequestStatusEnum::CANCELLED->value,
        ]);
    }

    // ============================================
    // METHODS
    // ============================================

    /**
     * Проверка возможности редактирования
     */
    public function canBeEdited(): bool
    {
        return $this->status->isEditable();
    }

    /**
     * Проверка возможности одобрения
     */
    public function canBeApproved(): bool
    {
        return $this->status === PurchaseRequestStatusEnum::PENDING;
    }

    /**
     * Проверка возможности отклонения
     */
    public function canBeRejected(): bool
    {
        return in_array($this->status, [
            PurchaseRequestStatusEnum::DRAFT,
            PurchaseRequestStatusEnum::PENDING,
        ]);
    }
}

