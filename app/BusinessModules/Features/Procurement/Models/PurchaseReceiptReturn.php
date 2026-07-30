<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class PurchaseReceiptReturn extends Model
{
    protected $fillable = [
        'organization_id',
        'purchase_receipt_line_id',
        'warehouse_movement_id',
        'supply_lifecycle_event_id',
        'source_type',
        'source_id',
        'source_version',
        'quantity',
        'reason_code',
        'actor_id',
        'occurred_at',
        'idempotency_key',
        'payload_fingerprint',
    ];

    protected $casts = [
        'quantity' => 'decimal:6',
        'occurred_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new LogicException('Purchase receipt returns are immutable.');
        });
        self::deleting(static function (): never {
            throw new LogicException('Purchase receipt returns are append-only.');
        });
    }

    public function receiptLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceiptLine::class, 'purchase_receipt_line_id');
    }
}
