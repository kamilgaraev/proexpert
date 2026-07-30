<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Models;

use Brick\Math\BigDecimal;
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
        'returned_quantity',
        'unit_dimension',
        'unit_code',
        'conversion_version',
    ];

    protected $casts = [
        'original_quantity' => 'decimal:6',
        'reversed_quantity' => 'decimal:6',
        'returned_quantity' => 'decimal:6',
    ];

    protected static function booted(): void
    {
        self::updating(function (self $lot): void {
            foreach (self::IMMUTABLE as $attribute) {
                if ($lot->isDirty($attribute)) {
                    throw new LogicException('Receipt inventory lot identity is immutable.');
                }
            }
            $original = (string) $lot->getOriginal('reversed_quantity');
            $next = (string) $lot->reversed_quantity;
            if ($original !== '0.000000' && $original !== '0' && $next !== $original) {
                throw new LogicException('Receipt inventory reversal is immutable.');
            }
            if (
                in_array($original, ['0', '0.000000'], true)
                && ! in_array($next, [$original, (string) $lot->original_quantity], true)
            ) {
                throw new LogicException('Receipt inventory reversal must be exact.');
            }
            $returned = BigDecimal::of((string) $lot->returned_quantity);
            $originalReturned = BigDecimal::of((string) $lot->getOriginal('returned_quantity'));
            $availableForReturn = BigDecimal::of((string) $lot->original_quantity)
                ->minus((string) $lot->reversed_quantity);
            if ($returned->isLessThan($originalReturned) || $returned->isGreaterThan($availableForReturn)) {
                throw new LogicException('Receipt inventory returned quantity must be cumulative.');
            }
        });
    }

    public function receiptLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceiptLine::class, 'purchase_receipt_line_id');
    }
}
