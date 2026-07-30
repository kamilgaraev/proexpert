<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PurchaseReceiptLine extends Model
{
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
}
