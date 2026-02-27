<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Models;

use App\Models\EstimateItem;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentDocumentEstimateSplit extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_document_id',
        'estimate_item_id',
        'quantity',
        'unit_price_plan',
        'unit_price_actual',
        'amount',
        'percentage',
        'price_deviation',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'percentage' => 'decimal:2',
        'quantity' => 'decimal:8',
        'unit_price_plan' => 'decimal:4',
        'unit_price_actual' => 'decimal:4',
        'price_deviation' => 'decimal:2',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(PaymentDocument::class, 'payment_document_id');
    }

    public function estimateItem(): BelongsTo
    {
        return $this->belongsTo(EstimateItem::class);
    }
}
