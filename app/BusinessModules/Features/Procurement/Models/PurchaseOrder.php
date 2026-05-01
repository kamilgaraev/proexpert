<?php

namespace App\BusinessModules\Features\Procurement\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\BusinessModules\Features\Procurement\Enums\PurchaseOrderStatusEnum;
use App\Models\Organization;
use App\Models\Supplier;
use App\Models\Contract;

/**
 * Модель заказа поставщику
 */
class PurchaseOrder extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'purchase_orders';

    protected $fillable = [
        'organization_id',
        'purchase_request_id',
        'accepted_supplier_proposal_id',
        'supplier_id',
        'external_supplier_contact_id',
        'contract_id',
        'order_number',
        'order_date',
        'status',
        'total_amount',
        'currency',
        'pricing_source',
        'delivery_date',
        'sent_at',
        'confirmed_at',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'status' => PurchaseOrderStatusEnum::class,
        'order_date' => 'date',
        'delivery_date' => 'date',
        'sent_at' => 'date',
        'confirmed_at' => 'date',
        'total_amount' => 'decimal:2',
        'metadata' => 'array',
    ];

    protected $attributes = [
        'status' => 'draft',
        'currency' => 'RUB',
        'pricing_source' => 'accepted_supplier_proposal',
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
     * Заявка на закупку
     */
    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    /**
     * Поставщик
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function externalSupplierContact(): BelongsTo
    {
        return $this->belongsTo(ExternalSupplierContact::class);
    }

    public function acceptedSupplierProposal(): BelongsTo
    {
        return $this->belongsTo(SupplierProposal::class, 'accepted_supplier_proposal_id');
    }

    /**
     * Договор поставки
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /**
     * Коммерческие предложения по этому заказу
     */
    public function proposals(): HasMany
    {
        return $this->hasMany(SupplierProposal::class);
    }

    /**
     * Позиции заказа (материалы)
     */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(PurchaseReceipt::class);
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
    public function scopeWithStatus($query, string|PurchaseOrderStatusEnum $status)
    {
        $value = $status instanceof PurchaseOrderStatusEnum ? $status->value : $status;
        return $query->where('status', $value);
    }

    /**
     * Scope для поставщика
     */
    public function scopeForSupplier($query, int $supplierId)
    {
        return $query->where('supplier_id', $supplierId);
    }

    // ============================================
    // METHODS
    // ============================================

    /**
     * Проверка возможности отправки
     */
    public function canBeSent(): bool
    {
        return $this->status->canBeSent();
    }

    /**
     * Проверка возможности подтверждения
     */
    public function canBeConfirmed(): bool
    {
        return $this->status->canBeConfirmed();
    }

    /**
     * Проверка наличия договора
     */
    public function hasContract(): bool
    {
        return $this->contract_id !== null;
    }
}

