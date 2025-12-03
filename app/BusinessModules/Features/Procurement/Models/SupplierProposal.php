<?php

namespace App\BusinessModules\Features\Procurement\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\BusinessModules\Features\Procurement\Enums\SupplierProposalStatusEnum;
use App\Models\Organization;
use App\Models\Supplier;

/**
 * Модель коммерческого предложения от поставщика
 */
class SupplierProposal extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'supplier_proposals';

    protected $fillable = [
        'organization_id',
        'purchase_order_id',
        'supplier_id',
        'proposal_number',
        'proposal_date',
        'status',
        'total_amount',
        'currency',
        'valid_until',
        'items',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'status' => SupplierProposalStatusEnum::class,
        'proposal_date' => 'date',
        'valid_until' => 'date',
        'total_amount' => 'decimal:2',
        'items' => 'array',
        'metadata' => 'array',
    ];

    protected $attributes = [
        'status' => 'draft',
        'currency' => 'RUB',
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
     * Заказ поставщику
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * Поставщик
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
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
    public function scopeWithStatus($query, string|SupplierProposalStatusEnum $status)
    {
        $value = $status instanceof SupplierProposalStatusEnum ? $status->value : $status;
        return $query->where('status', $value);
    }

    /**
     * Scope для заказа
     */
    public function scopeForOrder($query, int $orderId)
    {
        return $query->where('purchase_order_id', $orderId);
    }

    /**
     * Scope для действительных КП (не истекших)
     */
    public function scopeValid($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('valid_until')
              ->orWhere('valid_until', '>=', now()->toDateString());
        });
    }

    // ============================================
    // METHODS
    // ============================================

    /**
     * Проверка возможности принятия
     */
    public function canBeAccepted(): bool
    {
        return $this->status->canBeAccepted();
    }

    /**
     * Проверка истечения срока действия
     */
    public function isExpired(): bool
    {
        if (!$this->valid_until) {
            return false;
        }

        return $this->valid_until->isPast();
    }
}

