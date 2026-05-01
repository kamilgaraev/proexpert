<?php

namespace App\BusinessModules\Features\Procurement\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\BusinessModules\Features\Procurement\Enums\SupplierProposalStatusEnum;
use App\Models\Organization;
use App\Models\Supplier;

/**
 * Модель коммерческого предложения от поставщика
 *
 * @property int $id
 * @property int $organization_id
 * @property int|null $purchase_order_id
 * @property int|null $supplier_request_id
 * @property int|null $supplier_id
 * @property int|null $external_supplier_contact_id
 * @property int|null $supplier_party_id
 * @property array<string, mixed>|null $supplier_snapshot
 * @property string $proposal_number
 * @property \Illuminate\Support\Carbon $proposal_date
 * @property SupplierProposalStatusEnum $status
 * @property numeric-string|float|int $subtotal_amount
 * @property numeric-string|float|int $delivery_amount
 * @property numeric-string|float|int $vat_amount
 * @property numeric-string|float|int $total_amount
 * @property string $currency
 * @property \Illuminate\Support\Carbon|null $valid_until
 * @property string|null $payment_terms
 * @property string|null $delivery_terms
 * @property array<int, array<string, mixed>>|null $items
 * @property string|null $notes
 * @property array<string, mixed>|null $metadata
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class SupplierProposal extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'supplier_proposals';

    protected $fillable = [
        'organization_id',
        'purchase_order_id',
        'supplier_request_id',
        'supplier_id',
        'external_supplier_contact_id',
        'supplier_party_id',
        'supplier_snapshot',
        'proposal_number',
        'proposal_date',
        'status',
        'subtotal_amount',
        'delivery_amount',
        'vat_amount',
        'total_amount',
        'currency',
        'valid_until',
        'payment_terms',
        'delivery_terms',
        'items',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'status' => SupplierProposalStatusEnum::class,
        'proposal_date' => 'date',
        'valid_until' => 'date',
        'subtotal_amount' => 'decimal:2',
        'delivery_amount' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'items' => 'array',
        'supplier_snapshot' => 'array',
        'metadata' => 'array',
    ];

    protected $attributes = [
        'status' => 'draft',
        'currency' => 'RUB',
        'supplier_snapshot' => '{}',
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

    public function supplierRequest(): BelongsTo
    {
        return $this->belongsTo(SupplierRequest::class);
    }

    public function externalSupplierContact(): BelongsTo
    {
        return $this->belongsTo(ExternalSupplierContact::class);
    }

    public function supplierParty(): BelongsTo
    {
        return $this->belongsTo(SupplierParty::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SupplierProposalLine::class);
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
        $status = $this->getAttribute('status');

        return $status instanceof SupplierProposalStatusEnum && $status->canBeAccepted();
    }

    /**
     * Проверка истечения срока действия
     */
    public function isExpired(): bool
    {
        $validUntil = $this->getAttribute('valid_until');

        if (!$validUntil instanceof \Illuminate\Support\Carbon) {
            return false;
        }

        return $validUntil->isPast();
    }
}
