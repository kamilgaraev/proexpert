<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class PurchaseReceiptLine extends Model
{
    private const IMMUTABLE_SOURCE = [
        'purchase_receipt_id',
        'purchase_order_item_id',
        'quantity_received',
        'price',
        'total_amount',
    ];

    protected $fillable = [
        'purchase_receipt_id',
        'purchase_order_item_id',
        'quantity_received',
        'price',
        'total_amount',
        'metadata',
        'reversed_at',
        'reversed_by_user_id',
        'reversal_reason_code',
        'reversal_warehouse_movement_id',
        'reversal_idempotency_key',
    ];

    protected $casts = [
        'quantity_received' => 'decimal:3',
        'price' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'metadata' => 'array',
        'reversed_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        self::updating(function (self $line): void {
            foreach (self::IMMUTABLE_SOURCE as $attribute) {
                if ($line->isDirty($attribute)) {
                    throw new LogicException('Purchase receipt line source identity is immutable.');
                }
            }
            if ($line->getOriginal('reversed_at') !== null && (
                $line->isDirty('reversed_at')
                || $line->isDirty('reversed_by_user_id')
                || $line->isDirty('reversal_reason_code')
                || $line->isDirty('reversal_warehouse_movement_id')
                || $line->isDirty('reversal_idempotency_key')
            )) {
                throw new LogicException('Purchase receipt reversal identity is immutable.');
            }
        });
    }

    public function purchaseReceipt(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceipt::class);
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    public function inventoryLot(): HasOne
    {
        return $this->hasOne(PurchaseReceiptInventoryLot::class, 'purchase_receipt_line_id');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(PurchaseReceiptReturn::class, 'purchase_receipt_line_id');
    }
}
