<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class PurchaseReceiptInventoryLot extends Model
{
    private const IMMUTABLE = [
        'organization_id',
        'purchase_receipt_line_id',
        'warehouse_balance_id',
        'receipt_warehouse_movement_id',
        'original_quantity',
        'unit_dimension',
        'unit_code',
        'conversion_version',
    ];

    protected $fillable = [
        'organization_id',
        'purchase_receipt_line_id',
        'warehouse_balance_id',
        'receipt_warehouse_movement_id',
        'original_quantity',
        'reversed_quantity',
        'unit_dimension',
        'unit_code',
        'conversion_version',
    ];

    protected $casts = [
        'original_quantity' => 'decimal:6',
        'reversed_quantity' => 'decimal:6',
    ];

    protected static function booted(): void
    {
        self::updating(function (self $lot): void {
            foreach (self::IMMUTABLE as $attribute) {
                if ($lot->isDirty($attribute)) {
                    throw new LogicException('Receipt inventory lot identity is immutable.');
                }
            }
        });
    }

    public function receiptLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceiptLine::class, 'purchase_receipt_line_id');
    }
}
